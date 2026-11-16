<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Support\Str;

/**
 * Shared helpers for backed string enums: labels, select options and value lists.
 */
trait EnumHelpers
{
    public function label(): string
    {
        return Str::headline($this->name);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
