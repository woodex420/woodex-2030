<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\SiteVisit;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Models\Quote;

/**
 * SYS-013: everything Woodex knows about one person, in one honest JSON — the
 * export half of the privacy promise. Money rows keep their refs and totals so
 * the books stay auditable; the personal story travels with the person.
 */
final readonly class ExportContactData
{
    /**
     * @return array<string, mixed>
     */
    public function export(Contact $contact): array
    {
        $contact->loadMissing(['company:id,name', 'owner:id,name']);

        return [
            'exported_at' => now()->toIso8601String(),
            'contact' => [
                'name' => $contact->name,
                'phone' => $contact->phone,
                'alt_phone' => $contact->alt_phone,
                'whatsapp' => $contact->whatsapp,
                'email' => $contact->email,
                'city' => $contact->city,
                'area' => $contact->area,
                'address' => $contact->address,
                'client_type' => $contact->client_type,
                'preferred_language' => $contact->preferred_language,
                'is_client' => $contact->is_client,
                'became_client_at' => $contact->became_client_at?->toIso8601String(),
                'source' => $contact->source,
                'company' => $contact->company?->name,
                'owner' => $contact->owner?->name,
                'consent_marketing' => $contact->consent_marketing,
                'consent_whatsapp' => $contact->consent_whatsapp,
                'opted_out_at' => $contact->opted_out_at?->toIso8601String(),
                'anonymised_at' => $contact->anonymised_at?->toIso8601String(),
                'notes' => $contact->notes,
                'created_at' => $contact->created_at?->toIso8601String(),
            ],
            'leads' => $contact->leads()
                ->with('stage:id,name')
                ->get()
                ->map(fn (Lead $lead): array => [
                    'ref' => $lead->ref,
                    'service' => $lead->service,
                    'stage' => $lead->stage?->name,
                    'message' => $lead->message,
                    'expected_value' => $lead->expected_value_minor !== null ? (string) $lead->expected_value_minor : null,
                    'created_at' => $lead->created_at?->toIso8601String(),
                ])->values()->all(),
            'activities' => $contact->activities()
                ->get()
                ->map(fn (Activity $activity): array => [
                    'type' => $activity->type,
                    'body' => $activity->body,
                    'occurred_at' => $activity->occurred_at?->toIso8601String(),
                ])->values()->all(),
            'visits' => SiteVisit::query()->where('contact_id', $contact->id)
                ->get()
                ->map(fn (SiteVisit $visit): array => [
                    'scheduled_at' => $visit->scheduled_at?->toIso8601String(),
                    'status' => $visit->status?->value,
                    'address' => $visit->address,
                    'outcome_notes' => $visit->outcome_notes,
                ])->values()->all(),
            'projects' => Project::query()->where('contact_id', $contact->id)
                ->get()
                ->map(fn (Project $project): array => [
                    'code' => $project->code,
                    'name' => $project->name,
                    'status' => $project->status,
                    'created_at' => $project->created_at?->toIso8601String(),
                ])->values()->all(),
            'quotes' => $contact->quotes()
                ->get()
                ->map(fn (Quote $quote): array => [
                    'number' => $quote->number,
                    'title' => $quote->title,
                    'status' => $quote->status,
                    'total' => $quote->total_minor !== null ? (string) $quote->total_minor : null,
                    'created_at' => $quote->created_at?->toIso8601String(),
                ])->values()->all(),
            'conversations' => $contact->conversations()
                ->with('messages:id,conversation_id,direction,body,created_at')
                ->get()
                ->map(fn (Conversation $conversation): array => [
                    'channel' => $conversation->channel,
                    'subject' => $conversation->subject,
                    'messages' => $conversation->messages
                        ->map(fn (Message $message): array => [
                            'direction' => $message->direction,
                            'body' => $message->body,
                            'at' => $message->created_at?->toIso8601String(),
                        ])->values()->all(),
                ])->values()->all(),
        ];
    }
}
