<?php

declare(strict_types=1);

namespace App\Settings;

use App\Settings\Casts\EncryptedStringCast;
use Spatie\LaravelSettings\Settings;

/**
 * The speed dashboard's settings (V2-3 F9, docs/14 §5a). The internal checks — image weight,
 * formats, page size, cache — always run, no key, no one else involved. Google's PageSpeed API is
 * behind this key: empty means the buttons stay hidden and nothing leaves the server (the owner's
 * keyless-first call). The key is encrypted at rest and never shown back to the browser.
 */
final class SpeedSettings extends Settings
{
    public ?string $google_api_key = null;

    /** @return array<string, string> */
    public static function casts(): array
    {
        return ['google_api_key' => EncryptedStringCast::class];
    }

    public static function group(): string
    {
        return 'speed';
    }
}
