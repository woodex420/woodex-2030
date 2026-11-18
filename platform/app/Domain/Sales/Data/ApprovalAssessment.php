<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

/**
 * Result of QuoteApprovalRules::assess (QUO-007).
 */
final readonly class ApprovalAssessment
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public bool $needed,
        public array $reasons,
        public int $discountBp,
        public int $limitBp,
        public ?int $marginBp,
        public ?int $minMarginBp,
        public string $fingerprint,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reasons' => $this->reasons,
            'discount_bp' => $this->discountBp,
            'limit_bp' => $this->limitBp,
            'margin_bp' => $this->marginBp,
            'min_margin_bp' => $this->minMarginBp,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
