<?php

declare(strict_types=1);

namespace App\Domain\Crm\Data;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use Carbon\CarbonImmutable;

/**
 * Normalised enquiry from any channel (web form, estimator, chat, WhatsApp, Lead Ads, manual…).
 */
final readonly class LeadInput
{
    /**
     * @param  array<string, mixed>  $attribution  utm_*, gclid, fbclid, landing, referrer, device
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $name,
        public ?string $phone,
        public ?string $email,
        public LeadSource $source,
        public BusinessLine $businessLine = BusinessLine::Interior,
        public ?string $service = null,
        public ?string $propertyType = null,
        public ?string $city = null,
        public ?string $area = null,
        public ?int $areaSqft = null,
        public ?string $budgetBand = null,
        public ?string $timeline = null,
        public ?string $message = null,
        public ?string $sourceDetail = null,
        public array $attribution = [],
        public bool $consentMarketing = false,
        public bool $consentWhatsapp = false,
        public ?int $ownerId = null,
        public string $preferredLanguage = 'en',
        public array $meta = [],
        public ?CarbonImmutable $historicalAt = null,
    ) {}
}
