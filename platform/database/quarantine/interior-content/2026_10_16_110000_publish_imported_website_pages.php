<?php

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Models\Page;
use Illuminate\Database\Migrations\Migration;

/**
 * The imported website goes live.
 *
 * The legacy import (docs/09 §4) brought every page in as a draft, which is the safe default: nothing appears on
 * woodex.com.pk until a person has looked at it. The first twelve were published by hand; this puts the rest of
 * the real content — the home page, the guides, the projects, the city and area pages — where visitors can read
 * it. Pages with no visible block stay drafts: an empty shell helps nobody.
 *
 * Nothing here touches the owner's own edits later: unpublishing a page in Website -> Pages is final, this runs
 * once.
 */
return new class extends Migration
{
    /** The page types the import produced that are complete enough to show. */
    private const array TYPES = ['home', 'listing', 'insight', 'project', 'city', 'area'];

    public function up(): void
    {
        $now = now();

        Page::query()
            ->whereIn('type', self::TYPES)
            ->where('status', '!=', PageStatus::Published->value)
            ->each(function (Page $page) use ($now): void {
                if (! $page->blocks()->where('visible', true)->exists()) {
                    return;
                }

                $page->update([
                    'status' => PageStatus::Published,
                    'published_at' => $page->published_at ?? $now,
                ]);
            });
    }

    public function down(): void
    {
        Page::query()
            ->whereIn('type', self::TYPES)
            ->where('status', PageStatus::Published->value)
            ->update(['status' => PageStatus::Draft, 'published_at' => null]);
    }
};
