<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use App\Domain\System\Enums\WhatsAppTemplateEvent;
use App\Domain\System\Enums\WhatsAppTemplateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A message template Meta holds for the studio's WhatsApp number (WAP-005).
 *
 * Meta is the owner of these: they are created there, approved there, and can be rejected or paused there. This
 * table is a mirror, refreshed by SyncWhatsAppTemplates, so the studio can see what it has and decide which
 * template each moment uses. Nothing here is invented — a template that is not approved cannot be sent.
 */
class WhatsAppTemplate extends Model
{
    protected $guarded = ['id'];

    /** Laravel would read the name as "whats_app_templates"; the table is one word, like the product. */
    protected $table = 'whatsapp_templates';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WhatsAppTemplateStatus::class,
            'components' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * The template a given moment should use, if an approved one is mapped to it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query->where('event_key', $event)->where('status', WhatsAppTemplateStatus::Approved->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', WhatsAppTemplateStatus::Approved->value);
    }

    /**
     * Which template should carry this moment? Null when nothing approved is mapped, which is not an error:
     * the caller falls back to the configured name, and a message that cannot be sent is reported, not guessed.
     */
    public static function forEvent(WhatsAppTemplateEvent|string $event): ?self
    {
        $key = $event instanceof WhatsAppTemplateEvent ? $event->value : $event;

        /** @var self|null $template */
        $template = self::query()->forEvent($key)->first();

        return $template;
    }

    /** The body text Meta will send, with its {{1}} variables still in place. */
    public function body(): string
    {
        foreach ((array) ($this->components ?? []) as $component) {
            if (is_array($component) && ($component['type'] ?? null) === 'BODY') {
                return (string) ($component['text'] ?? '');
            }
        }

        return '';
    }

    /**
     * The numbered placeholders in the body, in the order they must be filled: {{1}}, {{2}}, …
     *
     * @return list<int>
     */
    public function variables(): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', $this->body(), $matches);

        $variables = array_map('intval', $matches[1] ?? []);
        sort($variables);

        return array_values(array_unique($variables));
    }

    /**
     * The placeholders as they are written in the body: {{1}}, {{2}}, … — built here rather than in a Blade
     * template, where `{{` starts an echo.
     *
     * @return list<string>
     */
    public function variableTokens(): array
    {
        return array_map(static fn (int $number): string => sprintf('{%s%d%s}', '{', $number, '}'), $this->variables());
    }

    public function isUsable(): bool
    {
        return $this->status instanceof WhatsAppTemplateStatus && $this->status->usable();
    }

    public function eventLabel(): ?string
    {
        return is_string($this->event_key) ? WhatsAppTemplateEvent::tryFrom($this->event_key)?->label() : null;
    }
}
