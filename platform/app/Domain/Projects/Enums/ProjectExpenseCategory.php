<?php

declare(strict_types=1);

namespace App\Domain\Projects\Enums;

enum ProjectExpenseCategory: string
{
    case Material = 'material';
    case Labour = 'labour';
    case Transport = 'transport';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Labour => 'Labour',
            self::Transport => 'Transport',
            self::Other => 'Other',
        };
    }
}
