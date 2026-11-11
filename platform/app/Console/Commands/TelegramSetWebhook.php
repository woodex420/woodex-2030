<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Integrations\Telegram\Contracts\TelegramGateway;
use Illuminate\Console\Command;

final class TelegramSetWebhook extends Command
{
    protected $signature = 'woodex:telegram:set-webhook';

    protected $description = 'Register the Telegram bot webhook URL (uses TELEGRAM_WEBHOOK_SECRET).';

    public function handle(TelegramGateway $telegram): int
    {
        $secret = (string) config('services.telegram.webhook_secret');

        if (! $telegram->isConfigured() || $secret === '') {
            $this->components->error('Set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET in .env first.');

            return self::FAILURE;
        }

        $url = route('webhooks.telegram');

        if (! str_starts_with($url, 'https://')) {
            $this->components->error("Telegram requires HTTPS. APP_URL produced: {$url}");

            return self::FAILURE;
        }

        if (! $telegram->setWebhook($url, $secret)) {
            $this->components->error('Telegram rejected the webhook. Check storage/logs and System → Health.');

            return self::FAILURE;
        }

        $this->components->info('Telegram webhook registered.');

        return self::SUCCESS;
    }
}
