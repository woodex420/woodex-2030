<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // BOT-002/007/008/010. The bot ships OFF and keyless: "none" answers every question
        // with "a human will reply", so the studio can see the machinery before it funds a model.
        $this->migrator->add('bot.enabled', false);
        $this->migrator->add('bot.provider', 'none');
        $this->migrator->add('bot.api_key', null);
        $this->migrator->add('bot.model', '');
        $this->migrator->add('bot.system_prompt', '');
        $this->migrator->add('bot.daily_token_cap', 200000);
        $this->migrator->add('bot.conversation_turn_limit', 12);
        $this->migrator->add('bot.outside_hours_only', false);
        $this->migrator->add('bot.handoff_roles', ['owner', 'sales_manager']);
    }
};
