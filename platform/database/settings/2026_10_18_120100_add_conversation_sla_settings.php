<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // CNV-007: reply targets for WhatsApp conversations, in minutes.
        $this->migrator->add('sales.conversation_first_reply_minutes', 30);
        $this->migrator->add('sales.conversation_next_reply_minutes', 15);
    }
};
