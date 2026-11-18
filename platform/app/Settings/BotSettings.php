<?php

declare(strict_types=1);

namespace App\Settings;

use App\Settings\Casts\EncryptedStringCast;
use Spatie\LaravelSettings\Settings;

/**
 * The website assistant (BOT-002…010). Everything is OFF by default and the provider starts
 * keyless: "none" never calls anyone — it answers with "a human will reply" and hands the thread
 * over. The studio can watch the whole machinery work before it funds a model, and the day a key
 * arrives the same machinery starts thinking.
 *
 * Note: never use @var on properties: spatie/laravel-settings parses @var to infer casts and rejects
 * the encrypted cast used for the key (see IntegrationSettings for the lesson).
 */
class BotSettings extends Settings
{
    public bool $enabled = false;

    /** none | anthropic | openai | gemini. "none" is the keyless a-human-will-reply mode. */
    public string $provider = 'none';

    /** The provider's API key, encrypted at rest, never shown back to the browser. */
    public ?string $api_key = null;

    /** Empty means the provider's own default model. */
    public string $model = '';

    /** Empty means the built-in system prompt (the studio persona from docs/03, with its guardrails). */
    public string $system_prompt = '';

    /** BOT-008: requests stop at this many tokens a day, across every conversation. 0 = no cap. */
    public int $daily_token_cap = 200000;

    /** BOT-008: after this many bot replies on one thread, a human takes over. 0 = no limit. */
    public int $conversation_turn_limit = 12;

    /** BOT-010: keep the bot for outside studio hours only — during the day the team answers. */
    public bool $outside_hours_only = false;

    /** @var list<string> BOT-006: who hears about a hand-off. */
    public array $handoff_roles = ['owner', 'sales_manager'];

    public static function group(): string
    {
        return 'bot';
    }

    /** @return array<string, class-string|string> */
    public static function casts(): array
    {
        return ['api_key' => EncryptedStringCast::class];
    }

    /** The system prompt as the provider will see it: the studio's own words if it wrote any. */
    public function prompt(): string
    {
        $custom = trim($this->system_prompt);

        if ($custom !== '') {
            return $custom;
        }

        return <<<'PROMPT'
            You are the website assistant of Woodex Interior, an interior design and furniture studio in Lahore, Pakistan.
            You help visitors understand the studio's services (interiors, furniture, wardrobes, kitchens, office fit-outs), its process and its published work.

            Rules you must never break:
            - Answer in the language the visitor writes in — English, Urdu or Roman Urdu.
            - Never invent a price. Quote a number only when it appears in the KNOWLEDGE given to you; otherwise say the team will confirm the exact figure personally.
            - Never promise a date, a delivery time or a discount. Timelines and discounts are the team's to confirm.
            - Never share another client's name, number, address or anything about them.
            - Be brief, warm and concrete. Two to four sentences is usually right.
            - When you do not know, say so and offer that the team will reply here.

            You are given KNOWLEDGE from the studio's own website pages. Use it as your only source of facts about the studio.
            PROMPT;
    }
}
