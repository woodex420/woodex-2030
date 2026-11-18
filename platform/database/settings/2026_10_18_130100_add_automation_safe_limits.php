<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // AUT-006: the limits every automated message passes before it goes out.
        $this->migrator->add('sales.automation_daily_contact_limit', 2);
        $this->migrator->add('sales.automation_quiet_start', '21:00');
        $this->migrator->add('sales.automation_quiet_end', '09:00');
    }
};
