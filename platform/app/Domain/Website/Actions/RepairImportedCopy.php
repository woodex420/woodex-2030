<?php

declare(strict_types=1);

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\Page;

/**
 * Imported pages say things that were true of the old system and are not true of this one (docs/10 §4).
 *
 * The first one found was the privacy page promising that enquiries were stored in Supabase and the site was
 * served by Vercel: it is not — the data sits in this application's own database and the site is served from the
 * studio's own hosting. A privacy notice is a promise, and this is the one page written to be trusted.
 *
 * A re-import brings the old wording straight back, so the corrections live here and are applied every time.
 *
 * @return int how many passages were corrected
 */
final class RepairImportedCopy
{
    /** @var array<string, array{from: string, to: string}> label => the correction */
    private const array CORRECTIONS = [
        'privacy: who stores your data' => [
            'from' => 'Form and chat data is stored in our database, hosted by Supabase, and the website itself is served by Vercel.',
            'to' => 'Form and chat data is stored in our own database on the server that runs this website. We do not pass it to any other hosting or analytics provider.',
        ],
    ];

    public function __invoke(): int
    {
        $fixed = 0;

        Page::query()->with('blocks')->get()->each(function (Page $page) use (&$fixed): void {
            foreach (self::CORRECTIONS as $correction) {
                foreach ($page->blocks as $block) {
                    $data = $block->data ?? [];

                    foreach (['body', 'text'] as $key) {
                        if (! isset($data[$key]) || ! is_string($data[$key])) {
                            continue;
                        }

                        if (! str_contains($data[$key], $correction['from'])) {
                            continue;
                        }

                        $data[$key] = str_replace($correction['from'], $correction['to'], $data[$key]);
                        $block->data = $data;
                        $block->save();
                        $fixed++;
                    }
                }
            }

            if ($fixed > 0) {
                $page->touch();
            }
        });

        return $fixed;
    }
}
