<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Credentials the studio enters itself, starting with WhatsApp (WAP-001, D-115).
 *
 * Everything is nullable and empty by default: with nothing saved here the app falls back to the server .env
 * exactly as it did before, so this migration changes no behaviour on its own.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('integrations.whatsapp_waba_id', null);
        $this->migrator->add('integrations.whatsapp_phone_number_id', null);
        $this->migrator->add('integrations.whatsapp_token', null);
        $this->migrator->add('integrations.whatsapp_app_secret', null);
        $this->migrator->add('integrations.whatsapp_verify_token', null);
        $this->migrator->add('integrations.whatsapp_api_version', 'v23.0');
        $this->migrator->add('integrations.whatsapp_verified_name', null);
        $this->migrator->add('integrations.whatsapp_display_phone', null);
        $this->migrator->add('integrations.whatsapp_quality_rating', null);
    }
};
