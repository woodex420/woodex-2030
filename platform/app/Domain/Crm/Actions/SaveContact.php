<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Exceptions\DuplicateContact;
use App\Domain\Crm\Models\Contact;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a contact with normalised phones and duplicate protection (CRM-001, CRM-012).
 */
final class SaveContact
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws DuplicateContact
     */
    public function handle(array $data, ?Contact $contact = null): Contact
    {
        return DB::transaction(function () use ($data, $contact): Contact {
            if ($contact !== null) {
                $contact = Contact::query()->lockForUpdate()->findOrFail($contact->id);
                if ($contact->opted_out_at !== null && (! empty($data['consent_whatsapp']) || ! empty($data['consent_marketing']))) {
                    throw ValidationException::withMessages(['form.consent_whatsapp' => 'This contact opted out. Ordinary edits cannot restore messaging consent.']);
                }
            }
            $phone = Phone::normalize($data['phone'] ?? null);
            $altPhone = Phone::normalize($data['alt_phone'] ?? null);
            $whatsapp = Phone::normalize($data['whatsapp'] ?? null) ?? $phone;
            $email = isset($data['email']) && trim((string) $data['email']) !== '' ? Str::lower(trim((string) $data['email'])) : null;

            foreach (array_filter([$phone, $altPhone]) as $number) {
                $existing = Contact::query()->wherePhone($number)
                    ->when($contact !== null, fn ($q) => $q->whereKeyNot($contact->id))
                    ->first();

                if ($existing !== null) {
                    throw new DuplicateContact($existing, 'phone');
                }
            }

            if ($email !== null) {
                $existing = Contact::query()->where('email', $email)
                    ->when($contact !== null, fn ($q) => $q->whereKeyNot($contact->id))
                    ->first();

                if ($existing !== null) {
                    throw new DuplicateContact($existing, 'email');
                }
            }

            $contact ??= new Contact(['source' => 'manual', 'last_activity_at' => now()]);

            $contact->fill([
                'name' => trim((string) $data['name']),
                'phone' => $phone,
                'alt_phone' => $altPhone,
                'whatsapp' => $whatsapp,
                'email' => $email,
                'city' => self::blankToNull($data['city'] ?? null),
                'area' => self::blankToNull($data['area'] ?? null),
                'address' => self::blankToNull($data['address'] ?? null),
                'client_type' => $data['client_type'] ?? 'individual',
                'preferred_language' => $data['preferred_language'] ?? 'en',
                'consent_whatsapp' => $contact->opted_out_at === null && (bool) ($data['consent_whatsapp'] ?? $contact->consent_whatsapp ?? false),
                'consent_marketing' => $contact->opted_out_at === null && (bool) ($data['consent_marketing'] ?? $contact->consent_marketing ?? false),
                'notes' => self::blankToNull($data['notes'] ?? null),
            ]);

            $contact->save();

            return $contact;
        });
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
