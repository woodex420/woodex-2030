<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;

/**
 * Woodex business lines. Interior and Project share the WI- series, Furniture uses WF-.
 */
enum BusinessLine: string
{
    use EnumHelpers;

    case Interior = 'interior';
    case Furniture = 'furniture';
    case Project = 'project';

    /**
     * Number sequence used for quotations of this line (D-16).
     */
    public function quoteSequenceKey(): string
    {
        return $this === self::Furniture ? 'quote.furniture' : 'quote.interior';
    }

    public function label(): string
    {
        return match ($this) {
            self::Interior => 'Interior',
            self::Furniture => 'Furniture',
            self::Project => 'Project (turnkey)',
        };
    }
}
