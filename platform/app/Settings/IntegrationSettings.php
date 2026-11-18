<?php

declare(strict_types=1);

namespace App\Settings;

use App\Settings\Casts\EncryptedStringCast;
use Spatie\LaravelSettings\Settings;

/**
 * Credentials for the outside services the studio connects itself (WAP-001, P7 later).
 *
 * These used to be .env-only. They are settings now so the owner can connect WhatsApp from the dashboard on
 * shared hosting, while the .env still works as a fallback — see App\Integrations\WhatsApp\WhatsAppCredentials.
 * The three real secrets are encrypted at rest by EncryptedStringCast and are never shown back to the browser:
 * the form offers an empty box with a masked hint, and leaving it empty keeps the saved value (D-115).
 *
 * Note: never use @var on properties: spatie/laravel-settings parses @var to infer casts and rejects
 * generic types such as array<string, int>. Use @phpstan-var for those (spatie ignores it).
 */
class IntegrationSettings extends Settings
{
    /** WhatsApp Business Account id: needed to read and submit templates. */
    public ?string $whatsapp_waba_id = null;

    /** The phone number messages are sent from. */
    public ?string $whatsapp_phone_number_id = null;

    /** A system user token with whatsapp_business_messaging and whatsapp_business_management. */
    public ?string $whatsapp_token = null;

    /** App secret, used only to check the signature of Meta's webhook calls. */
    public ?string $whatsapp_app_secret = null;

    /** A string we choose; Meta asks for it when it verifies the webhook URL. */
    public ?string $whatsapp_verify_token = null;

    public string $whatsapp_api_version = 'v23.0';

    /** Filled by the last successful test connection, so the card can name the number it is using. */
    public ?string $whatsapp_verified_name = null;

    /** WAP-008: rupees Meta charges per successfully sent free-form message. 0 = not tracked. */
    public int $whatsapp_text_cost = 0;

    /** WAP-008: rupees Meta charges per sent template message. 0 = not tracked. */
    public int $whatsapp_template_cost = 0;

    /** WAP-008: monthly WhatsApp spend the studio does not want to cross. 0 = no alert. */
    public int $whatsapp_monthly_budget = 0;

    /** WAP-008: the month whose budget alert already went out ('2026-10'), so it fires once a month at most. */
    public ?string $whatsapp_budget_alerted_month = null;

    public ?string $whatsapp_display_phone = null;

    public ?string $whatsapp_quality_rating = null;

    /**
     * @return array<string, class-string>
     */
    public static function casts(): array
    {
        return [
            'whatsapp_token' => EncryptedStringCast::class,
            'whatsapp_app_secret' => EncryptedStringCast::class,
            'whatsapp_verify_token' => EncryptedStringCast::class,
        ];
    }

    public static function group(): string
    {
        return 'integrations';
    }
}
