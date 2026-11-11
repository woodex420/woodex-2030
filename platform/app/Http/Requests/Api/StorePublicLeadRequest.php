<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Support\Phone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/public/leads (CAP-001, CAP-002).
 */
final class StorePublicLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $publicSources = array_map(static fn (LeadSource $s): string => $s->value, LeadSource::publicSources());

        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && $value !== '' && ! Phone::isValid((string) $value)) {
                    $fail('Please enter a valid phone number, for example 0322 4000768.');
                }
            }],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:120'],
            'service' => ['nullable', 'string', 'max:60'],
            'business_line' => ['nullable', Rule::enum(BusinessLine::class)],
            'property_type' => ['nullable', 'string', 'max:30'],
            'area_sqft' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'budget_band' => ['nullable', 'string', 'max:30'],
            'timeline' => ['nullable', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', Rule::in($publicSources)],
            'form' => ['nullable', 'string', 'max:80'],
            'page_url' => ['nullable', 'string', 'max:500'],
            'referrer' => ['nullable', 'string', 'max:500'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:120'],
            'utm_content' => ['nullable', 'string', 'max:120'],
            'utm_term' => ['nullable', 'string', 'max:120'],
            'gclid' => ['nullable', 'string', 'max:255'],
            'fbclid' => ['nullable', 'string', 'max:255'],
            'consent' => ['nullable', 'boolean'],
            'language' => ['nullable', Rule::in(['en', 'ur'])],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
            'form_started_at' => ['nullable', 'integer'],
        ];
    }

    /**
     * Honeypot filled, or the form was submitted faster than a human could (CAP-002).
     */
    public function isSpam(): bool
    {
        $honeypot = (string) config('woodex.public_leads.honeypot_field', 'company_website');

        if (filled($this->input($honeypot))) {
            return true;
        }

        $startedAt = $this->integer('form_started_at');

        if ($startedAt > 0) {
            // Accept seconds or milliseconds since epoch.
            $startedAt = $startedAt > 20_000_000_000 ? intdiv($startedAt, 1000) : $startedAt;

            if (now()->timestamp - $startedAt < (int) config('woodex.public_leads.min_fill_seconds', 3)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Server-to-server calls from the current live site (bridge mode, CAP-007) skip Turnstile.
     */
    public function hasValidBridgeKey(): bool
    {
        $expected = (string) config('woodex.public_leads.bridge_key');
        $given = (string) $this->header('X-Woodex-Key');

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    public function toLeadInput(): LeadInput
    {
        $consent = $this->boolean('consent');
        $source = LeadSource::tryFrom((string) $this->input('source')) ?? LeadSource::WebsiteForm;

        return new LeadInput(
            name: (string) $this->string('name')->trim(),
            phone: $this->filled('phone') ? (string) $this->input('phone') : null,
            email: $this->filled('email') ? (string) $this->input('email') : null,
            source: $source,
            businessLine: BusinessLine::tryFrom((string) $this->input('business_line')) ?? BusinessLine::Interior,
            service: $this->filled('service') ? (string) $this->input('service') : null,
            propertyType: $this->filled('property_type') ? (string) $this->input('property_type') : null,
            city: $this->filled('city') ? (string) $this->input('city') : null,
            area: $this->filled('area') ? (string) $this->input('area') : null,
            areaSqft: $this->filled('area_sqft') ? $this->integer('area_sqft') : null,
            budgetBand: $this->filled('budget_band') ? (string) $this->input('budget_band') : null,
            timeline: $this->filled('timeline') ? (string) $this->input('timeline') : null,
            message: $this->filled('message') ? (string) $this->input('message') : null,
            sourceDetail: $this->filled('form') ? (string) $this->input('form') : null,
            attribution: array_filter([
                'landing' => $this->input('page_url'),
                'referrer' => $this->input('referrer'),
                'utm_source' => $this->input('utm_source'),
                'utm_medium' => $this->input('utm_medium'),
                'utm_campaign' => $this->input('utm_campaign'),
                'utm_content' => $this->input('utm_content'),
                'utm_term' => $this->input('utm_term'),
                'gclid' => $this->input('gclid'),
                'fbclid' => $this->input('fbclid'),
                'user_agent' => substr((string) $this->userAgent(), 0, 255),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            consentMarketing: $consent,
            consentWhatsapp: $consent,
            preferredLanguage: (string) ($this->input('language') ?? 'en'),
            meta: $this->hasValidBridgeKey() ? ['via' => 'live-site-bridge'] : [],
        );
    }
}
