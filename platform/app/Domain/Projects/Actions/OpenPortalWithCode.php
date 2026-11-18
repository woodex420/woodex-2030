<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Crm\Models\Contact;
use App\Domain\Projects\Models\PortalAccessCode;
use App\Support\Phone;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Trading six digits for the client's own page (PRJ-006): the newest code we sent them, checked against the
 * contact it belongs to, dead the moment it is used — and after five wrong tries, so it cannot be guessed.
 */
final class OpenPortalWithCode
{
    public function handle(string $identifier, string $code): ?Contact
    {
        $contact = Contact::query()
            ->when(
                str_contains($identifier, '@'),
                fn ($q) => $q->where('email', Str::lower(trim($identifier))),
                fn ($q) => $q->where('phone_e164', Phone::normalize($identifier))
                    ->orWhere('phone', trim($identifier)),
            )
            ->first();

        if ($contact === null) {
            return null;
        }

        /** @var PortalAccessCode|null $latest */
        $latest = $contact->portalCodes()->whereNull('used_at')->latest()->first();

        if ($latest === null) {
            return null;
        }

        $latest->forceFill(['attempts' => (int) $latest->attempts + 1])->save();

        if (! $latest->fresh()->isUsable() || ! Hash::check(trim($code), (string) $latest->code_hash)) {
            return null;
        }

        $latest->forceFill(['used_at' => now()])->save();

        return $contact;
    }
}
