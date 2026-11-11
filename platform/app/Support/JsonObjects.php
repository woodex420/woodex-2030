<?php

declare(strict_types=1);

namespace App\Support;

/** JSON objects have unordered keys; arrays and scalar types remain significant. */
final class JsonObjects
{
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
