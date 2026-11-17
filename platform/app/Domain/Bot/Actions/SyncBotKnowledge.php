<?php

declare(strict_types=1);

namespace App\Domain\Bot\Actions;

use App\Domain\Bot\Models\BotKnowledge;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Models\Page;

/**
 * Mirror the studio's own published words into the assistant's knowledge (BOT-003). A page's
 * title, excerpt and the text of its blocks become one entry; re-running keeps entries current
 * (same page, updated words) and retires the ones whose page went away. Manual Q&A is never
 * touched — it is the studio's own handwriting.
 */
final class SyncBotKnowledge
{
    /**
     * @return array{pages: int, entries: int}
     */
    public function handle(): array
    {
        $pages = Page::query()
            ->where('status', PageStatus::Published->value)
            ->with('blocks')
            ->orderBy('id')
            ->get();

        $seen = [];

        foreach ($pages as $page) {
            $content = trim($page->excerpt."\n".$this->blockText($page));

            if (mb_strlen(trim((string) $page->excerpt)) === 0 && $content === '') {
                continue; // a page with no words teaches the bot nothing
            }

            $entry = BotKnowledge::query()->firstOrNew(['source' => 'cms', 'page_id' => $page->id]);
            $entry->title = (string) $page->title;
            $entry->content = mb_substr($content, 0, 6000);
            $entry->is_active = true;
            $entry->save();

            $seen[] = $page->id;
        }

        // Pages unpublished or deleted: their mirror goes too, so the bot cannot cite gone pages.
        $stale = BotKnowledge::query()->where('source', 'cms');

        if ($seen !== []) {
            $stale->whereNotIn('page_id', $seen);
        }

        $stale->delete();

        return ['pages' => $pages->count(), 'entries' => BotKnowledge::query()->where('source', 'cms')->count()];
    }

    /** The words in a page's blocks: headings, paragraphs, list items — markup stripped. */
    private function blockText(Page $page): string
    {
        return $page->blocks
            ->filter(static fn ($block): bool => (bool) $block->visible)
            ->map(static function ($block): string {
                $data = collect((array) ($block->data ?? []));

                $text = $data
                    ->filter(static fn (mixed $value): bool => is_string($value))
                    ->map(static fn (string $value): string => trim(strip_tags($value)))
                    ->filter(static fn (string $value): bool => $value !== '')
                    ->implode(' · ');

                // Nested lists of strings (items, features, points).
                foreach ($data->filter(static fn (mixed $value): bool => is_array($value)) as $list) {
                    foreach (collect($list)->filter(static fn (mixed $value): bool => is_string($value)) as $item) {
                        $clean = trim(strip_tags((string) $item));

                        if ($clean !== '') {
                            $text .= ' · '.$clean;
                        }
                    }
                }

                return $text;
            })
            ->filter(static fn (string $value): bool => $value !== '')
            ->implode("\n");
    }
}
