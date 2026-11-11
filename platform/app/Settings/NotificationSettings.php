<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Alert delivery defaults (AUT-003, TGM-002, TGM-008).
 *
 * Note: never use @var on properties: spatie/laravel-settings parses @var to infer casts and rejects
 * generic types such as array<string, int>. Use @phpstan-var for those (spatie ignores it).
 */
class NotificationSettings extends Settings
{
    public bool $telegram_enabled;

    /** P10.2: play a sound when a website chat message arrives (studio-wide, default off). */
    public bool $chat_alert_sound = false;

    public string $quiet_hours_start;

    public string $quiet_hours_end;

    public string $daily_digest_time;

    /** @phpstan-var list<string> roles alerted about unassigned leads and missed response times */
    public array $unassigned_lead_roles;

    /** @phpstan-var list<string> roles alerted when a missed response is still open after the escalation delay */
    public array $sla_final_roles;

    public static function group(): string
    {
        return 'notifications';
    }
}
