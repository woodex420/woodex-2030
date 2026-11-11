<?php

declare(strict_types=1);

namespace App\Settings;

use App\Support\BusinessHours;
use Spatie\LaravelSettings\Settings;

/**
 * Public business identity (single source of truth for NAP data, docs/00 §6).
 * Defaults are pending owner confirmation (P-01…P-03) and editable in System → Settings.
 *
 * Note: never use @var on properties: spatie/laravel-settings parses @var to infer casts and rejects
 * generic types such as array<string, int>. Use @phpstan-var for those (spatie ignores it).
 */
class BusinessSettings extends Settings
{
    public string $legal_name;

    public string $brand_name;

    public string $furniture_brand_name;

    public string $tagline;

    public string $address;

    public string $city;

    public string $phone;

    public string $whatsapp;

    public string $email;

    public string $website;

    public ?string $ntn;

    public int $founded_year;

    public string $opens_at;

    public string $closes_at;

    /** @phpstan-var list<int> ISO weekdays, 1 = Monday */
    public array $working_days;

    /** @phpstan-var array<string, string> network => URL */
    public array $social_links;

    /** @phpstan-var list<array{date: string, name: string}> public holidays (AUT-004) */
    public array $holidays;

    /**
     * Google review link for the studio (PRJ-007): empty means no review email is ever sent.
     *
     * The default matters: a settings property with no value in the database and no default is uninitialized, and
     * reading (or serializing) it throws — which took the whole public website down on a server whose Database
     * update had not been run yet. Every new property gets a default, so a pending update degrades instead.
     */
    public string $google_review_url = '';

    public static function group(): string
    {
        return 'business';
    }

    public function hours(): BusinessHours
    {
        return new BusinessHours(
            $this->opens_at,
            $this->closes_at,
            $this->working_days,
            holidays: array_values(array_map(fn (array $holiday): string => (string) $holiday['date'], $this->holidays)),
        );
    }
}
