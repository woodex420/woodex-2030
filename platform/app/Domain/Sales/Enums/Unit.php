<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use App\Support\Concerns\EnumHelpers;

enum Unit: string
{
    use EnumHelpers;

    case Sft = 'sft';
    case Sqmt = 'sqmt';
    case Rft = 'rft';
    case Nos = 'nos';
    case Each = 'each';
    case Set = 'set';
    case Point = 'point';
    case Job = 'job';
    case Lumpsum = 'lumpsum';
    case Views = 'views';

    /** Unit as printed on documents (the v2.1 print module's labels). */
    public function pdfLabel(): string
    {
        return match ($this) {
            self::Lumpsum => 'L/S',
            self::Sqmt => 'sq m',
            default => $this->value,
        };
    }

    /**
     * Map legacy/variant spellings from v2.1 and the estimator to a canonical unit.
     */
    public static function normalize(?string $unit): self
    {
        $value = strtolower(trim((string) $unit));

        return match ($value) {
            'sqft', 'sq ft', 'sq.ft', 'sf' => self::Sft,
            'sqm', 'sq m', 'm2' => self::Sqmt,
            'no', 'no.', 'pcs', 'pc' => self::Nos,
            'lump sum', 'ls', 'l/s' => self::Lumpsum,
            'view' => self::Views,
            default => self::tryFrom($value) ?? self::Job,
        };
    }
}
