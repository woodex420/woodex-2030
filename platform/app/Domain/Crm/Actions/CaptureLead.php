<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Data\CaptureResult;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\LeadPriority;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Enums\LeadType;
use App\Domain\Crm\Enums\NextActionType;
use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Models\User;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use App\Support\Numbering;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * THE single entry point for enquiries from every channel (CAP-003). Never create leads elsewhere.
 *
 * normalise phone → find/create contact → join an open lead (same line, within the merge window)
 * or create a new lead → attribution → SLA → assignment → timeline → LeadCaptured event.
 */
final class CaptureLead
{
    public function __construct(
        private readonly Numbering $numbering,
        private readonly AssignLead $assignLead,
        private readonly BusinessSettings $business,
        private readonly SalesSettings $sales,
        private readonly ScoreLead $scoreLead,
    ) {}

    public function handle(LeadInput $input): CaptureResult
    {
        if ($input->historicalAt !== null && ($input->source !== LeadSource::Import
            || $input->historicalAt->isFuture() || $input->consentMarketing || $input->consentWhatsapp)) {
            throw ValidationException::withMessages(['import' => 'Historical capture requires an import source, a past date and no inferred messaging consent.']);
        }

        $phone = Phone::normalize($input->phone);
        $email = $input->email !== null && trim($input->email) !== '' ? Str::lower(trim($input->email)) : null;

        if ($phone === null && $email === null) {
            throw ValidationException::withMessages([
                'phone' => 'Please enter a valid phone number (e.g. 0322 4000768) or an email address.',
            ]);
        }

        return DB::transaction(function () use ($input, $phone, $email): CaptureResult {
            $contact = $this->findContact($phone, $email);
            $contactCreated = $contact === null;

            // Historical new-only imports must never enrich, reactivate or notify an existing contact.
            if ($input->historicalAt !== null && ! $contactCreated) {
                throw ValidationException::withMessages(['import' => 'An existing contact was found; historical new-only capture refused.']);
            }

            $contact = $contactCreated
                ? $this->createContact($input, $phone, $email)
                : $this->enrichContact($contact, $input, $phone, $email);

            $openLead = $contactCreated ? null : $this->findOpenLead($contact, $input);

            if ($openLead !== null) {
                $this->recordReturningEnquiry($openLead, $input);
                event(new LeadCaptured($openLead, isNewLead: false));

                return new CaptureResult($openLead, $contact, leadCreated: false, contactCreated: false);
            }

            $lead = $this->createLead($contact, $input);

            if ($input->historicalAt !== null) {
                return new CaptureResult($lead, $contact, leadCreated: true, contactCreated: true);
            }

            if ($input->ownerId !== null && ($owner = User::query()->find($input->ownerId)) !== null) {
                $this->assignLead->handle($lead, $owner, reason: "Assigned to {$owner->name} on creation");
            } else {
                $this->assignLead->handle($lead);
            }

            event(new LeadCaptured($lead->refresh(), isNewLead: true));

            return new CaptureResult($lead, $contact, leadCreated: true, contactCreated: $contactCreated);
        });
    }

    private function findContact(?string $phone, ?string $email): ?Contact
    {
        if ($phone !== null) {
            $byPhone = Contact::query()->wherePhone($phone)->oldest('id')->lockForUpdate()->first();

            if ($byPhone !== null) {
                return $byPhone;
            }
        }

        return $email !== null ? Contact::query()->where('email', $email)->oldest('id')->lockForUpdate()->first() : null;
    }

    private function createContact(LeadInput $input, ?string $phone, ?string $email): Contact
    {
        return Contact::create([
            'name' => Str::limit(trim($input->name), 160, ''),
            'phone' => $phone,
            'whatsapp' => $phone,
            'email' => $email,
            'city' => $input->city,
            'area' => $input->area,
            'preferred_language' => $input->preferredLanguage,
            'source' => $input->source->value,
            'consent_marketing' => $input->consentMarketing,
            'consent_whatsapp' => $input->consentWhatsapp,
            'last_activity_at' => $input->historicalAt ?? now(),
            'created_at' => $input->historicalAt ?? now(),
        ]);
    }

    private function enrichContact(Contact $contact, LeadInput $input, ?string $phone, ?string $email): Contact
    {
        // Fill gaps only; never overwrite what the team has already corrected.
        $contact->phone ??= $phone;
        $contact->whatsapp ??= $phone;
        $contact->email ??= $email;
        $contact->city ??= $input->city;
        $contact->area ??= $input->area;

        if ($phone !== null && $contact->phone !== $phone && $contact->alt_phone === null && $contact->whatsapp !== $phone) {
            $contact->alt_phone = $phone;
        }

        $contact->consent_marketing = $contact->opted_out_at === null && ($contact->consent_marketing || $input->consentMarketing);
        $contact->consent_whatsapp = $contact->opted_out_at === null && ($contact->consent_whatsapp || $input->consentWhatsapp);
        $contact->last_activity_at = now();
        $contact->save();

        return $contact;
    }

    private function findOpenLead(Contact $contact, LeadInput $input): ?Lead
    {
        return Lead::query()
            ->open()
            ->where('contact_id', $contact->id)
            ->where('business_line', $input->businessLine->value)
            ->where('created_at', '>=', now()->subDays((int) config('woodex.public_leads.merge_window_days', 30)))
            ->latest('id')
            ->first();
    }

    private function createLead(Contact $contact, LeadInput $input): Lead
    {
        $now = $input->historicalAt ?? CarbonImmutable::now();
        $slaDue = $input->historicalAt !== null ? null : $this->business->hours()->dueAt($now, $this->sales->slaMinutesFor($input->source->value));

        $lead = new Lead([
            'ref' => $this->numbering->next('lead'),
            'contact_id' => $contact->id,
            'company_id' => $contact->company_id,
            'business_line' => $input->businessLine,
            'service' => $input->service,
            'property_type' => $input->propertyType,
            'city' => $input->city ?? $contact->city,
            'area' => $input->area ?? $contact->area,
            'area_sqft' => $input->areaSqft,
            'budget_band' => $input->budgetBand,
            'timeline' => $input->timeline,
            'lead_type' => $contact->is_client ? LeadType::Returning : LeadType::New,
            'priority' => LeadPriority::Normal,
            'stage_id' => PipelineStage::byKey('new')->id,
            'stage_changed_at' => $now,
            'next_action_type' => NextActionType::Call,
            'next_action_at' => $slaDue,
            'sla_due_at' => $slaDue,
            'source' => $input->source,
            'source_detail' => $input->sourceDetail,
            'message' => $input->message,
            'attribution' => ['first_touch' => $input->attribution, 'last_touch' => $input->attribution],
            'meta' => $input->meta === [] ? null : $input->meta,
            'last_activity_at' => $now,
            'created_at' => $now,
        ]);
        $lead->score = $input->historicalAt !== null ? 0 : $this->scoreLead->score($lead);
        $lead->save();

        Activity::create([
            'contact_id' => $contact->id,
            'lead_id' => $lead->id,
            'type' => ActivityType::System,
            'direction' => 'in',
            'body' => ($input->historicalAt !== null ? 'Historical enquiry imported for review' : "New enquiry via {$input->source->label()}").($input->message ? ': '.Str::limit($input->message, 500) : ''),
            'data' => ['source' => $input->source->value, 'service' => $input->service],
            'occurred_at' => $now,
        ]);

        return $lead;
    }

    private function recordReturningEnquiry(Lead $lead, LeadInput $input): void
    {
        $attribution = $lead->attribution ?? [];
        $attribution['last_touch'] = $input->attribution;

        $lead->attribution = $attribution;
        $lead->last_activity_at = now();
        $lead->save();

        Activity::create([
            'contact_id' => $lead->contact_id,
            'lead_id' => $lead->id,
            'type' => ActivityType::System,
            'direction' => 'in',
            'body' => "Returning enquiry via {$input->source->label()}".($input->message ? ': '.Str::limit($input->message, 500) : ''),
            'data' => ['source' => $input->source->value, 'returning' => true],
            'occurred_at' => now(),
        ]);
    }
}
