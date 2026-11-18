<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // WAP-008: the cost meter's rate table and budget. Zero means "count only, no rupees".
        $this->migrator->add('integrations.whatsapp_text_cost', 0);
        $this->migrator->add('integrations.whatsapp_template_cost', 0);
        $this->migrator->add('integrations.whatsapp_monthly_budget', 0);
        $this->migrator->add('integrations.whatsapp_budget_alerted_month', null);
    }
};
