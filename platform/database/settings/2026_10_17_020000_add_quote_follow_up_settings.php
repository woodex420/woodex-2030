<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * How hard a quotation is chased (AUT, quotation step 3).
 *
 * Defaults are the way the studio already works: call after three quiet days, warn three days before the price
 * lapses. Both are Settings, so the studio changes the rhythm without a developer.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sales.quote_follow_ups_enabled', true);
        $this->migrator->add('sales.quote_follow_up_days', 3);
        $this->migrator->add('sales.quote_expiry_warning_days', 3);
    }
};
