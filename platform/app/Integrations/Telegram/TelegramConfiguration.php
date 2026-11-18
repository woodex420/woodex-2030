<?php

declare(strict_types=1);

namespace App\Integrations\Telegram;

use App\Support\Install\SiteState;
use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Throwable;

/** Read-only setup diagnostics. Never return .env contents or secret values. */
final class TelegramConfiguration
{
    public function __construct(private readonly Application $app, private readonly SiteState $site) {}

    /**
     * @return array{path: string, alternate_path: ?string, file_status: string, cached: bool, token_loaded: bool, secret_loaded: bool, token_in_file: bool, secret_in_file: bool, file_matches_runtime: ?bool}
     */
    public function inspect(): array
    {
        $path = $this->app->environmentFilePath();
        $contents = @file_get_contents($path);
        $values = [];
        $status = 'unreadable';

        if ($contents !== false) {
            try {
                $values = Dotenv::parse($contents);
                $status = 'readable';
            } catch (Throwable) {
                // Parser exceptions may quote a secret-bearing line. Never report or display them.
                $status = 'invalid';
            }
        }

        $external = $this->site->externalDirectory();
        $alternate = $external !== null ? $external.'/.env' : null;
        $token = (string) config('services.telegram.bot_token');
        $secret = (string) config('services.telegram.webhook_secret');

        return [
            'path' => $this->site->displayPath($path),
            'alternate_path' => $alternate !== null && $alternate !== $path && is_file($alternate)
                ? $this->site->displayPath($alternate) : null,
            'file_status' => $status,
            'cached' => $this->app->configurationIsCached(),
            'token_loaded' => $token !== '',
            'secret_loaded' => $secret !== '',
            'token_in_file' => $this->hasValue($values['TELEGRAM_BOT_TOKEN'] ?? null),
            'secret_in_file' => $this->hasValue($values['TELEGRAM_WEBHOOK_SECRET'] ?? null),
            'file_matches_runtime' => $status === 'readable'
                ? hash_equals((string) ($values['TELEGRAM_BOT_TOKEN'] ?? ''), $token)
                    && hash_equals((string) ($values['TELEGRAM_WEBHOOK_SECRET'] ?? ''), $secret)
                : null,
        ];
    }

    private function hasValue(?string $value): bool
    {
        return $value !== null && trim($value) !== ''
            && ! in_array(strtolower($value), ['null', '(null)', 'empty', '(empty)'], true);
    }
}
