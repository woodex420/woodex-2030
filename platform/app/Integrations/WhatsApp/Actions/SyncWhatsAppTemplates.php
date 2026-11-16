<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp\Actions;

use App\Domain\System\Enums\WhatsAppTemplateStatus;
use App\Domain\System\Models\WhatsAppTemplate;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;

/**
 * Mirrors the studio's WhatsApp templates from Meta into our own table (WAP-005).
 *
 * Meta owns templates: they are written there, approved there, and sometimes rejected there. This action reads
 * what the business account holds and refreshes our copy, so the templates screen shows the truth — including
 * a rejection and the reason for it, which is the one thing the studio most needs after submitting a template.
 *
 * Templates that disappear from Meta are marked as removed rather than deleted: a mapping or a log may still
 * point at them, and silently forgetting a template the studio thinks it has would be worse than showing it.
 */
final class SyncWhatsAppTemplates
{
    /** A template we submitted seconds ago may not be in Meta's answer yet; do not call it removed. */
    private const GRACE_MINUTES = 10;

    public function __construct(
        private readonly WhatsAppGateway $whatsapp,
    ) {}

    /**
     * @return array{ok: bool, synced: int, created: int, updated: int, removed: int, error: ?string}
     */
    public function handle(): array
    {
        if (! $this->whatsapp->isConfigured()) {
            return $this->empty('WhatsApp is not connected yet. Add the token and phone number id first.');
        }

        $templates = $this->whatsapp->templates();

        if ($templates === []) {
            return $this->empty('Meta returned no templates. Check the WhatsApp Business Account id, and that the token carries whatsapp_business_management.');
        }

        $now = now();
        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($templates as $template) {
            $name = strtolower(trim((string) ($template['name'] ?? '')));
            $language = strtolower(trim((string) ($template['language'] ?? 'en')));

            if ($name === '' || $language === '') {
                continue;
            }

            $components = $this->components($template);
            $attributes = [
                'meta_id' => is_string($template['id'] ?? null) && $template['id'] !== '' ? $template['id'] : null,
                'category' => strtoupper((string) ($template['category'] ?? '')) ?: null,
                'status' => WhatsAppTemplateStatus::fromMeta($template['status'] ?? null)->value,
                'components' => $components,
                'body' => $this->body($components),
                'rejected_reason' => is_string($template['rejected_reason'] ?? null) ? $template['rejected_reason'] : null,
                'synced_at' => $now,
            ];

            /** @var WhatsAppTemplate $row */
            $row = WhatsAppTemplate::query()->firstOrNew(['name' => $name, 'language' => $language]);
            $existed = $row->exists;
            $row->fill($attributes);
            $row->save();

            $existed ? $updated++ : $created++;
            $seen[] = $row->id;
        }

        $removed = WhatsAppTemplate::query()
            ->whereNotIn('id', $seen)
            ->where('status', '!=', WhatsAppTemplateStatus::Deleted->value)
            ->where(function ($query) use ($now): void {
                $query->whereNull('created_at')->orWhere('created_at', '<=', $now->copy()->subMinutes(self::GRACE_MINUTES));
            })
            ->update(['status' => WhatsAppTemplateStatus::Deleted->value, 'synced_at' => $now]);

        return [
            'ok' => true,
            'synced' => count($seen),
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @return list<array<string, mixed>>
     */
    private function components(array $template): array
    {
        $components = [];

        foreach ((array) ($template['components'] ?? []) as $component) {
            if (is_array($component)) {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    private function body(array $components): ?string
    {
        foreach ($components as $component) {
            if (($component['type'] ?? null) === 'BODY') {
                return (string) ($component['text'] ?? '');
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, synced: int, created: int, updated: int, removed: int, error: ?string}
     */
    private function empty(string $error): array
    {
        return ['ok' => false, 'synced' => 0, 'created' => 0, 'updated' => 0, 'removed' => 0, 'error' => $error];
    }
}
