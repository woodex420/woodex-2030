<?php

declare(strict_types=1);

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;

/**
 * Every page asks for the next step (audit of 16 Oct 2026, docs/10 §3.2).
 *
 * A page that answers a question and then offers nothing throws away the only reason it exists. This is the
 * reusable half of the migration that first did it: it runs again after every re-import, because a fresh import
 * brings the old pages back without the closing.
 *
 * Each page without an enquiry block gets one at the end, with a heading written for its kind of page — a cost
 * guide asks "Ask us about your own numbers", a project study "Start a project like this". Whatever the studio
 * arranges afterwards in Website -> Pages is left alone.
 */
final class AddMissingCallToActions
{
    /** @return int how many pages were given a closing */
    public function __invoke(): int
    {
        $added = 0;

        Page::query()
            ->where('status', PageStatus::Published->value)
            ->get()
            ->each(function (Page $page) use (&$added): void {
                if ($page->blocks()->where('type', BlockType::Form->value)->exists()) {
                    return;
                }

                $page->blocks()->create([
                    'type' => BlockType::Form->value,
                    'position' => ((int) $page->blocks()->max('position')) + 1,
                    'visible' => true,
                    'data' => [
                        'heading' => self::HEADINGS[$page->type->value] ?? 'Tell us about your space',
                        'text' => 'Share the location, size and timeline. We reply within one working day.',
                        'show_contact' => true,
                    ],
                ]);

                // The rendered page is cached against updated_at (PageCache); without this the old HTML stays.
                $page->touch();
                $added++;
            });

        return $added;
    }

    /** @var array<string, string> The closing heading, by page type. */
    private const array HEADINGS = [
        PageType::Insight->value => 'Ask us about your own numbers',
        PageType::Project->value => 'Start a project like this',
        PageType::City->value => 'Design in your city',
        PageType::Area->value => 'Design in your area',
        PageType::Service->value => 'Start your project',
        PageType::Listing->value => 'Tell us about your space',
        PageType::Home->value => 'Tell us about your space',
    ];
}
