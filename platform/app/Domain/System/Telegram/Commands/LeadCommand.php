<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;
use App\Support\Money;
use App\Support\Phone;

/**
 * /lead — one lead, by reference, id or phone number in any format (TGM-004).
 *
 * Whatever the caller can see; a lead outside the caller's visibility simply does not exist to them.
 */
final class LeadCommand implements TelegramCommand
{
    public function name(): string
    {
        return '/lead';
    }

    public function usage(): string
    {
        return '/lead <reference|phone>';
    }

    public function description(): string
    {
        return 'one lead: stage, owner, phone, value, next step';
    }

    public function permission(): string
    {
        return 'leads.view';
    }

    public function handle(User $user, string $argument): string
    {
        $term = trim($argument);

        if ($term === '') {
            return 'Give a reference or a phone number, e.g. /lead L-2026-000123 or /lead 0321 4000768.';
        }

        $lead = $this->find($user, $term);

        if ($lead === null) {
            return "No lead matches {$term}. Try the reference (L-2026-…) or the client's phone number.";
        }

        $lines = [
            sprintf('%s · %s', $lead->ref, $lead->contact->name),
            sprintf('Stage: %s · Owner: %s', $lead->stage !== null ? $lead->stage->name : '—', $lead->owner === null ? 'unassigned' : $lead->owner->name),
            sprintf('Score: %d · %s', $lead->score ?? 0, $lead->service !== null && $lead->service !== '' ? $lead->service : $lead->business_line->label()),
        ];

        if ($lead->expected_value_minor !== null && $lead->expected_value_minor > 0) {
            $lines[] = 'Value: '.Money::ofMinor((int) $lead->expected_value_minor)->format();
        }

        $phone = $lead->contact?->phone;
        $whatsapp = $lead->contact?->whatsappNumber();

        if (($phone !== null && $phone !== '') || $whatsapp !== null) {
            $lines[] = 'Phone: '.(($phone !== null && $phone !== '') ? Phone::display($phone) : '—').' · WhatsApp: '.($whatsapp !== null ? Phone::display($whatsapp) : '—');
        }

        $lastActivity = $lead->activities()->latest('id')->first();

        if ($lastActivity !== null) {
            $lines[] = 'Last activity: '.($lastActivity->body !== null && $lastActivity->body !== '' ? mb_strimwidth((string) $lastActivity->body, 0, 90, '…') : $lastActivity->type);
        }

        if ($lead->next_action_at !== null) {
            $lines[] = sprintf('Next step: %s · %s', $lead->next_action_type?->label() ?? 'follow-up', $lead->next_action_at->format('D d M H:i'));
        }

        return implode("\n", $lines);
    }

    private function find(User $user, string $term): ?Lead
    {
        $base = Lead::query()->visibleTo($user)->with(['contact', 'stage:id,name', 'owner:id,name']);

        /** @var Lead|null $byReference */
        $byReference = (clone $base)->where('ref', strtoupper($term))->first();

        if ($byReference !== null) {
            return $byReference;
        }

        if (ctype_digit($term)) {
            /** @var Lead|null $byId */
            $byId = (clone $base)->find((int) $term);

            if ($byId !== null) {
                return $byId;
            }
        }

        $contact = Contact::query()->search($term)->first();

        if ($contact === null) {
            return null;
        }

        /** @var Lead|null $byContact */
        $byContact = (clone $base)->where('contact_id', $contact->id)->latest('id')->first();

        return $byContact;
    }
}
