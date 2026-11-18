<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use App\Support\Concerns\EnumHelpers;

enum MilestonePlanKey: string
{
    use EnumHelpers;

    case Execution = 'execution_50_40_10';
    case Design = 'design_75_25';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Execution => 'Execution 50 / 40 / 10',
            self::Design => 'Design 75 / 25',
            self::Custom => 'Custom',
        };
    }
}
