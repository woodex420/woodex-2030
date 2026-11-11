<?php

declare(strict_types=1);

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use Illuminate\Support\Str;

/**
 * A city page points at the areas inside it (audit of 16 Oct 2026, docs/10 §3.3).
 *
 * The eight Lahore area pages — DHA, Gulberg, Johar Town and the rest — are the pages a person in Lahore actually
 * searches for, and before this nothing linked to them. Each city page now ends with the areas it covers, which is
 * both the missing link and the sentence a visitor in DHA wants to read.
 *
 * Runs again after every re-import, and never invents an area for a city that has none.
 *
 * @return int how many city pages were given the block
 */
final class LinkCityPagesToAreas
{
    public function __invoke(): int
    {
        $linked = 0;

        $cities = Page::query()
            ->where('status', PageStatus::Published->value)
            ->ofType(PageType::City)
            ->orderBy('sort')
            ->get();

        foreach ($cities as $city) {
            $areas = Page::query()
                ->where('status', PageStatus::Published->value)
                ->ofType(PageType::Area)
                ->where('slug', 'like', $city->slug.'%')
                ->orderBy('sort')
                ->pluck('id')
                ->all();

            if ($areas === []) {
                continue;
            }

            foreach ($city->blocks()->where('type', BlockType::Related->value)->get() as $block) {
                if (array_intersect($areas, (array) ($block->data['page_ids'] ?? [])) !== []) {
                    continue 2;
                }
            }

            $city->blocks()->create([
                'type' => BlockType::Related->value,
                'position' => ((int) $city->blocks()->max('position')) + 1,
                'visible' => true,
                'data' => [
                    'heading' => 'Areas of '.self::cityName($city).' we work in',
                    'page_ids' => $areas,
                ],
            ]);

            $city->touch();
            $linked++;
        }

        return $linked;
    }

    /** The city's own name, from the slug: `/lahore/` -> "Lahore". Page titles read "Interior design in Lahore". */
    private static function cityName(Page $city): string
    {
        return (string) Str::of($city->slug)->trim('/')->afterLast('/')->headline();
    }
}
