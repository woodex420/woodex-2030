<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\SiteVisit;
use Illuminate\Support\Facades\DB;

/**
 * SYS-013: the erase half of the privacy promise. The row survives — the books
 * and the leads it carries must stay auditable — but the person leaves it:
 * names, numbers, addresses and message bodies become '[removed]', consent
 * flags drop, and `anonymised_at` marks when. Re-export afterwards shows the
 * same shape with the story gone.
 */
final readonly class AnonymiseContact
{
    public function anonymise(Contact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            $contact->forceFill([
                'name' => 'Anonymised contact',
                'phone' => null,
                'alt_phone' => null,
                'whatsapp' => null,
                'email' => null,
                'city' => null,
                'area' => null,
                'address' => null,
                'notes' => null,
                'consent_marketing' => false,
                'consent_whatsapp' => false,
                'anonymised_at' => now(),
            ])->save();

            $contact->leads()->update(['message' => '[removed]']);
            $contact->activities()->update(['body' => '[removed]']);
            SiteVisit::query()->where('contact_id', $contact->id)
                ->update(['address' => null, 'map_url' => null, 'outcome_notes' => null, 'measurements' => null]);
            Conversation::query()->where('contact_id', $contact->id)
                ->update(['subject' => null, 'last_preview' => '[removed]']);
            $contact->conversations()->each(fn (Conversation $conversation) => $conversation->messages()
                ->update(['body' => '[removed]']));
        });
    }
}
