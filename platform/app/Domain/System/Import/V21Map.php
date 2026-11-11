<?php

declare(strict_types=1);

namespace App\Domain\System\Import;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\CallOutcome;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Enums\VisitStatus;
use Illuminate\Support\Str;

/**
 * Value mapping from Woodex v2.1 (marketingwoodex) to Woodex OS — docs/04-DATABASE-SCHEMA.md §8.
 */
final class V21Map
{
    /** Lahore standard: 1 marla = 225 sq ft, 1 kanal = 20 marla. */
    public const SQFT_PER_MARLA = 225;

    public const STAGES = [
        'new' => 'new',
        'contacted' => 'contacted',
        'visit' => 'site_visit',
        'quote' => 'quotation',
        'hold' => 'on_hold',
        'won' => 'won',
        'lost' => 'lost',
    ];

    public const ROLES = ['owner', 'admin', 'editor', 'sales', 'support'];

    public const CITIES = ['Lahore', 'Islamabad', 'Rawalpindi', 'Karachi', 'Faisalabad', 'Multan', 'Gujranwala', 'Sialkot', 'Peshawar', 'Bahawalpur', 'Sargodha', 'Sheikhupura', 'Kasur', 'Okara', 'Gujrat'];

    public static function stage(?string $value): ?string
    {
        return self::STAGES[Str::lower(trim((string) $value))] ?? null;
    }

    public static function role(?string $value): string
    {
        $role = Str::lower(trim((string) $value));

        return in_array($role, self::ROLES, true) ? $role : 'support';
    }

    /**
     * @return array{0: LeadSource, 1: string|null} source and detail
     */
    public static function source(?string $value): array
    {
        $key = Str::lower(trim((string) $value));

        return match ($key) {
            'contact' => [LeadSource::WebsiteForm, 'Contact form'],
            'brief' => [LeadSource::WebsiteForm, '3D brief'],
            'fitout-hub' => [LeadSource::WebsiteForm, 'Fit-out quote'],
            'office-fitout' => [LeadSource::WebsiteForm, 'Office fit-out quote'],
            'booking' => [LeadSource::Booking, null],
            'estimator' => [LeadSource::Estimator, null],
            'whatsapp' => [LeadSource::WhatsApp, null],
            'chat' => [LeadSource::WebsiteChat, null],
            'client' => [LeadSource::ReturningClient, null],
            'referral' => [LeadSource::Referral, null],
            'architect' => [LeadSource::Referral, 'Architect / consultant'],
            'builder' => [LeadSource::Referral, 'Builder / developer'],
            'walkin' => [LeadSource::WalkIn, null],
            'phone' => [LeadSource::PhoneCall, null],
            'facebook' => [LeadSource::SocialMedia, 'Facebook'],
            'instagram' => [LeadSource::SocialMedia, 'Instagram'],
            'tiktok' => [LeadSource::SocialMedia, 'TikTok / YouTube'],
            'google' => [LeadSource::Google, null],
            'portal' => [LeadSource::Portal, 'Zameen / OLX / portals'],
            'event' => [LeadSource::Event, null],
            'outreach' => [LeadSource::Outreach, null],
            'manual' => [LeadSource::Manual, null],
            'import' => [LeadSource::Import, 'v2.1 CSV import'],
            'other', '' => [LeadSource::Other, null],
            default => [LeadSource::Other, Str::limit((string) $value, 150, '')],
        };
    }

    public static function line(?string $value): string
    {
        $line = Str::lower(trim((string) $value));

        return in_array($line, ['interior', 'furniture', 'project'], true) ? $line : 'interior';
    }

    public static function priority(?string $value): string
    {
        return match (Str::lower(trim((string) $value))) {
            'low' => 'low',
            'high', 'urgent' => 'high',
            default => 'normal',
        };
    }

    public static function leadType(?string $value): string
    {
        $type = Str::lower(trim((string) $value));

        return in_array($type, ['new', 'returning', 'referral'], true) ? $type : 'new';
    }

    public static function clientType(?string $value): string
    {
        return match (Str::lower(trim((string) $value))) {
            'company', 'business', 'corporate' => 'company',
            'developer', 'builder' => 'developer',
            'architect', 'consultant' => 'architect',
            default => 'individual',
        };
    }

    public static function nextAction(?string $value): ?string
    {
        $type = Str::lower(trim((string) $value));

        return in_array($type, ['call', 'whatsapp', 'visit', 'meeting', 'email'], true) ? $type : null;
    }

    public static function noteType(?string $kind, bool $system): ActivityType
    {
        if ($system) {
            return ActivityType::System;
        }

        return match (Str::lower(trim((string) $kind))) {
            'call' => ActivityType::Call,
            'whatsapp' => ActivityType::WhatsApp,
            'visit' => ActivityType::Visit,
            'meeting' => ActivityType::Meeting,
            'email' => ActivityType::Email,
            default => ActivityType::Note,
        };
    }

    /**
     * v2.1 stored call outcomes as free text; recognise the common ones.
     */
    public static function callOutcome(?string $value): ?CallOutcome
    {
        $text = Str::lower(trim((string) $value));

        if ($text === '') {
            return null;
        }

        return match (true) {
            str_contains($text, 'no answer'), str_contains($text, 'not answer'), str_contains($text, 'no response'), str_contains($text, 'not picked'), str_contains($text, 'unanswered') => CallOutcome::NoAnswer,
            str_contains($text, 'busy') => CallOutcome::Busy,
            str_contains($text, 'switch') || str_contains($text, 'off') && str_contains($text, 'phone') => CallOutcome::SwitchedOff,
            str_contains($text, 'wrong') => CallOutcome::WrongNumber,
            str_contains($text, 'call back'), str_contains($text, 'callback'), str_contains($text, 'call later') => CallOutcome::CallBack,
            str_contains($text, 'connected'), str_contains($text, 'spoke'), str_contains($text, 'talked'), str_contains($text, 'interested') => CallOutcome::Connected,
            default => null,
        };
    }

    public static function visitStatus(?string $value): VisitStatus
    {
        return match (Str::lower(trim((string) $value))) {
            'confirmed' => VisitStatus::Confirmed,
            'done', 'completed' => VisitStatus::Completed,
            'cancelled', 'canceled' => VisitStatus::Cancelled,
            'noshow', 'no_show', 'no-show' => VisitStatus::NoShow,
            default => VisitStatus::Scheduled,
        };
    }

    /**
     * "2500", "2,500 sq ft", "10 marla", "1 kanal", "1.5 kanal", "5 marla + basement" → square feet.
     */
    public static function areaToSqft(?string $value): ?int
    {
        $text = Str::lower(str_replace(',', '', trim((string) $value)));

        if ($text === '' || ! preg_match('/(\d+(?:\.\d+)?)/', $text, $number)) {
            return null;
        }

        $amount = (float) $number[1];

        if ($amount <= 0) {
            return null;
        }

        $sqft = match (true) {
            str_contains($text, 'kanal') => $amount * 20 * self::SQFT_PER_MARLA,
            str_contains($text, 'marla') => $amount * self::SQFT_PER_MARLA,
            str_contains($text, 'sq m'), str_contains($text, 'sqm'), str_contains($text, 'square met') => $amount * 10.7639,
            default => $amount,
        };

        $sqft = (int) round($sqft);

        return $sqft > 0 && $sqft <= 10_000_000 ? $sqft : null;
    }

    /**
     * Match a free-text budget to a configured band key ("25 lakh" → 1m-3m, "1.5 crore" → 15m-plus).
     *
     * @param  array<string, string>  $bands  key => label
     */
    public static function budgetBand(?string $value, array $bands): ?string
    {
        $text = Str::lower(trim((string) $value));

        if ($text === '') {
            return null;
        }

        if (array_key_exists($text, $bands)) {
            return $text;
        }

        foreach ($bands as $key => $label) {
            if (Str::lower($label) === $text) {
                return $key;
            }
        }

        $rupees = self::parseRupees($text);

        if ($rupees === null) {
            return null;
        }

        $band = match (true) {
            $rupees < 1_000_000 => 'under-1m',
            $rupees < 3_000_000 => '1m-3m',
            $rupees < 7_000_000 => '3m-7m',
            $rupees < 15_000_000 => '7m-15m',
            default => '15m-plus',
        };

        return array_key_exists($band, $bands) ? $band : null;
    }

    /**
     * "25 lakh", "25L", "1.5 crore", "2,500,000", "Rs 30-40 lakh" (takes the upper figure) → rupees.
     */
    public static function parseRupees(string $text): ?int
    {
        $text = str_replace([',', 'rs.', 'rs', 'pkr'], '', Str::lower($text));

        if (! preg_match_all('/(\d+(?:\.\d+)?)/', $text, $matches)) {
            return null;
        }

        $amount = (float) max(array_map('floatval', $matches[1]));

        $multiplier = match (true) {
            (bool) preg_match('/crore|\bcr\b/', $text) => 10_000_000,
            (bool) preg_match('/lakh|lac|\bl\b|\d\s*l\b/', $text) => 100_000,
            (bool) preg_match('/million|\bm\b/', $text) => 1_000_000,
            (bool) preg_match('/\bk\b|thousand/', $text) => 1_000,
            default => 1,
        };

        $rupees = (int) round($amount * $multiplier);

        return $rupees >= 1_000 ? $rupees : null;
    }

    public static function cityFrom(?string $location): ?string
    {
        $text = Str::lower((string) $location);

        foreach (self::CITIES as $city) {
            if (str_contains($text, Str::lower($city))) {
                return $city;
            }
        }

        return null;
    }
}
