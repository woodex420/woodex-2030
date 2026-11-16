<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Crm\Models\Contact;
use App\Domain\Projects\Mail\PortalCodeMail;
use App\Domain\Projects\Models\PortalAccessCode;
use App\Support\Phone;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * "Send me a code" on the client portal (PRJ-006): the client types the phone number or the email they gave us,
 * and we email six digits to the address on that contact. No client password exists anywhere.
 *
 * The answer is the same whether or not the contact exists, so the form cannot be used to find out who our
 * clients are; and a contact with no project or no email gets nothing.
 */
final class RequestPortalAccess
{
    /** @return array{sent: bool} */
    public function handle(string $identifier, string $ip): array
    {
        $identifier = trim($identifier);
        $contact = $this->find($identifier);

        if ($contact === null || $contact->email === null || $contact->email === '') {
            return ['sent' => false];
        }

        if (! $contact->projects()->exists()) {
            return ['sent' => false];
        }

        $code = (string) random_int(100000, 999999);

        PortalAccessCode::query()->create([
            'contact_id' => $contact->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(PortalAccessCode::LIFETIME_MINUTES),
            'requested_ip' => $ip,
        ]);

        Mail::to($contact->email)->queue(new PortalCodeMail($contact->name, $code));

        return ['sent' => true];
    }

    private function find(string $identifier): ?Contact
    {
        $query = Contact::query();

        return str_contains($identifier, '@')
            ? $query->where('email', Str::lower($identifier))->first()
            : $query->where('phone_e164', Phone::normalize($identifier))->first()
                ?? $query->where('phone', $identifier)->first();
    }
}
