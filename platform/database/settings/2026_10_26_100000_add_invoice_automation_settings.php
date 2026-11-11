<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Slice E: how the overdue ladder behaves (docs/audit/31).
 *
 * The rungs are days past due — one reminder per rung, never twice. Clients are messaged on WhatsApp only with
 * their consent (the action enforces it); this switch lets the owner keep the ladder internal without turning
 * it off altogether.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sales.invoice_reminder_rungs', [7, 14, 30]);
        $this->migrator->add('sales.invoice_reminder_send_client', true);
    }
};
