<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Recycle bin rules (GLB-010): deleting a contact also bins its enquiries (a lead never outlives its
 * contact); restoring brings them back together. "Erase" is the owner-only privacy request: personal
 * data is anonymised, files are removed, money totals and the audit trail stay.
 */
final class TrashRecords
{
    public function deleteContact(Contact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            $contact->leads()->get()->each->delete();
            $contact->delete();
        });
    }

    public function restoreContact(Contact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            $deletedAt = $contact->deleted_at;
            $contact->restore();

            // Enquiries binned together with the contact come back with it.
            Lead::onlyTrashed()
                ->where('contact_id', $contact->id)
                ->when($deletedAt !== null, fn ($q) => $q->where('deleted_at', '>=', $deletedAt->copy()->subMinute()))
                ->get()
                ->each->restore();
        });
    }

    public function restoreLead(Lead $lead): void
    {
        DB::transaction(function () use ($lead): void {
            Contact::onlyTrashed()->whereKey($lead->contact_id)->first()?->restore();
            $lead->restore();
        });
    }

    public function eraseContact(Contact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            $label = 'Erased contact #'.$contact->id;

            foreach (Lead::withTrashed()->where('contact_id', $contact->id)->get() as $lead) {
                $lead->forceFill(['message' => null, 'address' => null, 'attribution' => null, 'referrer_name' => null, 'meta' => null])->saveQuietly();
                $lead->clearMediaCollection('files');
            }

            Activity::query()->where('contact_id', $contact->id)->update(['body' => null, 'data' => null]);

            $contact->clearMediaCollection('files');
            $contact->tags()->detach();
            $contact->forceFill([
                'name' => $label,
                'phone' => null,
                'alt_phone' => null,
                'whatsapp' => null,
                'email' => null,
                'address' => null,
                'area' => null,
                'notes' => null,
                'meta' => ['erased_at' => now()->toIso8601String()],
                'consent_marketing' => false,
                'consent_whatsapp' => false,
                'opted_out_at' => now(),
            ])->save();

            if (! $contact->trashed()) {
                $contact->delete();
            }
        });
    }
}
