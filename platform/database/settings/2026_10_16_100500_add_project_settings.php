<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * P4: what the client is told while their project moves (PRJ-002, PRJ-004).
 *
 * Stage changes are announced by email (WhatsApp templates arrive with the conversations phase); the owner can
 * turn it off in Settings → Sales if their team prefers to write every message by hand.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sales.project_stage_notifies_client', true);
    }
};
