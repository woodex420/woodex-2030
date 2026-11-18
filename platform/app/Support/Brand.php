<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Crm\Enums\BusinessLine;
use App\Settings\BusinessSettings;

/**
 * The name the business goes by in a message a member of staff sends, and under the mark on a page a client
 * opens. It is read from System → Settings; no screen writes a company name of its own. This product is Woodex
 * Furniture's (owner decision D-008), and the code came from the Interior studio's application, where five
 * screens greeted clients "from Woodex Interior" whatever the settings said.
 */
final class Brand
{
    /**
     * The brand a line's messages and documents carry. Without a line: the line this installation sells first.
     */
    public static function name(?BusinessLine $line = null): string
    {
        $settings = app(BusinessSettings::class);
        $line ??= BusinessLine::primary();

        $name = trim($line->brand() === BusinessLine::Furniture ? $settings->furniture_brand_name : $settings->brand_name);

        if ($name === '') {
            $name = trim($settings->brand_name);
        }

        return $name !== '' ? $name : 'Woodex';
    }

    /**
     * The two words under the WOODEX mark on a page a client opens.
     */
    public static function wordmark(?BusinessLine $line = null): string
    {
        return ($line ?? BusinessLine::primary())->brand() === BusinessLine::Furniture ? 'FURNITURE' : 'INTERIOR STUDIO';
    }
}
