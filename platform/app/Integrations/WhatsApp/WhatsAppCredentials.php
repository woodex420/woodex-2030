<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

use App\Settings\IntegrationSettings;
use Illuminate\Container\Container;
use Throwable;

/**
 * Where WhatsApp's credentials come from: the dashboard first, the server .env second (WAP-001, D-115).
 *
 * One place decides, so the gateway, the webhook and the admin screens can never disagree about whether
 * WhatsApp is connected. The dashboard wins when it holds a value; anything it leaves empty falls back to the
 * .env, which is how the studio ran before this screen existed and how a developer may still prefer to run it.
 *
 * Reading the settings touches the database, so every read is defensive: before the migrations have been run —
 * during a deploy, or in a command that boots early — this behaves as if nothing were saved and the .env
 * decides. It never throws, because "is WhatsApp connected?" is a question the app must always be able to answer.
 */
final class WhatsAppCredentials
{
    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function token(): ?string
    {
        return $this->value('whatsapp_token', 'token');
    }

    public function phoneNumberId(): ?string
    {
        return $this->value('whatsapp_phone_number_id', 'phone_number_id');
    }

    public function wabaId(): ?string
    {
        return $this->value('whatsapp_waba_id', 'waba_id');
    }

    public function appSecret(): ?string
    {
        return $this->value('whatsapp_app_secret', 'app_secret');
    }

    public function verifyToken(): ?string
    {
        return $this->value('whatsapp_verify_token', 'verify_token');
    }

    public function apiVersion(): string
    {
        return (string) ($this->value('whatsapp_api_version', 'api_version') ?? 'v23.0');
    }

    public function apiBase(): string
    {
        return (string) config('services.whatsapp.api_base', 'https://graph.facebook.com');
    }

    public function isConfigured(): bool
    {
        return filled($this->token()) && filled($this->phoneNumberId());
    }

    /** Can templates be read and submitted? They live on the business account, not the phone number. */
    public function canManageTemplates(): bool
    {
        return $this->isConfigured() && filled($this->wabaId());
    }

    /**
     * Where the values in use came from: 'database' (saved here), 'env' (server .env), or 'mixed'.
     */
    public function source(): string
    {
        $saved = array_filter([
            $this->stored()['whatsapp_token'] ?? null,
            $this->stored()['whatsapp_phone_number_id'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        return match (count($saved)) {
            0 => 'env',
            2 => 'database',
            default => 'mixed',
        };
    }

    private function value(string $setting, string $configKey): ?string
    {
        $saved = $this->stored()[$setting] ?? null;

        if (is_string($saved) && trim($saved) !== '') {
            return trim($saved);
        }

        $fallback = config('services.whatsapp.'.$configKey);

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    /**
     * Settings as saved in the dashboard, or an empty array when they cannot be read.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        if ($this->stored === null) {
            try {
                /** @var IntegrationSettings $settings */
                $settings = Container::getInstance()->make(IntegrationSettings::class);

                $this->stored = [
                    'whatsapp_waba_id' => $settings->whatsapp_waba_id,
                    'whatsapp_phone_number_id' => $settings->whatsapp_phone_number_id,
                    'whatsapp_token' => $settings->whatsapp_token,
                    'whatsapp_app_secret' => $settings->whatsapp_app_secret,
                    'whatsapp_verify_token' => $settings->whatsapp_verify_token,
                    'whatsapp_api_version' => $settings->whatsapp_api_version,
                ];
            } catch (Throwable) {
                // No settings table yet, or a deploy is half way through: the .env decides.
                $this->stored = [];
            }
        }

        return $this->stored;
    }
}
