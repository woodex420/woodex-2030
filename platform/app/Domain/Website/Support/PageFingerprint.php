<?php

declare(strict_types=1);

namespace App\Domain\Website\Support;

use App\Domain\Website\Models\Page;

/**
 * Phase 2 conflict detection without a schema change: a hash of everything a save can change on a
 * page. The editor keeps the hash it opened and compares it before writing; a different hash means
 * somebody else saved first, so the write is refused instead of silently overwriting their work.
 *
 * Settings are normalised through SectionStyle first, so a value that cleans to the same thing is
 * not reported as a change. Block order, keys and visibility are part of the hash.
 */
final class PageFingerprint
{
    public static function of(Page $page): string
    {
        $blocks = $page->relationLoaded('blocks') ? $page->blocks : $page->blocks()->get();

        $payload = [
            'type' => $page->type?->value,
            'title' => (string) $page->title,
            'slug' => (string) $page->slug,
            'excerpt' => (string) $page->excerpt,
            'seo' => $page->seo ?? [],
            'template' => $page->template,
            'canvas_html' => (string) $page->canvas_html,
            'blocks' => $blocks->map(fn ($block): array => [
                'key' => (string) $block->block_key,
                'type' => $block->type->value,
                'data' => $block->data ?? [],
                'settings' => SectionStyle::clean($block->settings),
                'visible' => (bool) $block->visible,
                'position' => (int) $block->position,
            ])->values()->all(),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
