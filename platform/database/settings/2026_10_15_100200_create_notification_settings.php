<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('notifications.telegram_enabled', true);
        $this->migrator->add('notifications.quiet_hours_start', '21:00');
        $this->migrator->add('notifications.quiet_hours_end', '09:00');
        $this->migrator->add('notifications.daily_digest_time', '09:00');
        $this->migrator->add('notifications.unassigned_lead_roles', ['sales_manager', 'owner']);
    }
};
