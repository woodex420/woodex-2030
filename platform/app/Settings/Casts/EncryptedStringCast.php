<?php

declare(strict_types=1);

namespace App\Settings\Casts;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelSettings\SettingsCasts\SettingsCast;

/**
 * Keeps a secret encrypted in the `settings` table and readable in PHP (WAP-001, D-115).
 *
 * Integration keys used to live only in the server .env because nothing in the dashboard could hold them safely.
 * This cast changes that: the property is plain text while the app is running, and cipher text in the database,
 * encrypted with the app key — the same mechanism that protects cookies, so there is no new secret to keep.
 *
 * A value that cannot be decrypted is treated as lost, never as plain text: after an app-key change the studio
 * simply re-enters the token, instead of the app quietly sending cipher text to Meta.
 */
final class EncryptedStringCast implements SettingsCast
{
    public function get(mixed $payload): ?string
    {
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException) {
            Log::warning('A saved integration secret could not be decrypted. It was encrypted with a different app key and must be entered again.');

            return null;
        }
    }

    public function set(mixed $payload): ?string
    {
        if (! is_string($payload)) {
            return null;
        }

        $value = trim($payload);

        return $value === '' ? null : Crypt::encryptString($value);
    }
}
