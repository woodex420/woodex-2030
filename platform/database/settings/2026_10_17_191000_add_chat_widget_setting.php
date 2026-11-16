<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * P8.5 web chat widget. Off by default: a client-facing channel needs the owner's explicit
 * on-switch (A0). Website Settings → Web chat turns it on; nothing renders until then.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('site.chat_widget_enabled', false);
    }

    public function down(): void
    {
        $this->migrator->delete('site.chat_widget_enabled');
    }
};
