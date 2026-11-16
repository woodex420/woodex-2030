<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;
use App\Domain\Website\Services\PageCache;
use Illuminate\Console\Command;

/**
 * Puts the v26 home hero in place (V2-3, owner answer of 18 Oct): the home page opens with a
 * three-slide slider — the design the studio's old site shipped — using the photos the legacy
 * import already brought over. Guarded: it never touches a home page that already has a slider,
 * and slides whose photo is missing are skipped, so a partial media library cannot build a
 * half-empty carousel.
 */
final class SeedHomeSlider extends Command
{
    protected $signature = 'woodex:home-slider';

    protected $description = 'Give the home page its v26 hero slider (skips a home that already has one)';

    /** The v26 slides, in order: the photo file the legacy import stored, and the copy it carried. */
    private const array SLIDES = [
        [
            'file' => 'img-c349a92a4ae0.webp',
            'kicker' => 'Woodex Interior · Lahore',
            'heading' => 'Spaces shaped by purpose and identity',
            'text' => 'From the first sketch to final handover, design, build and furniture stay with one accountable team.',
            'display' => 'INTERIORS',
            'primary_label' => 'Start your project',
            'primary_url' => '/contact/',
            'secondary_label' => 'Explore services',
            'secondary_url' => '/services/',
        ],
        [
            'file' => 'img-1d6ad6c77d0f.webp',
            'kicker' => 'Commercial spaces · Built to work',
            'heading' => 'Workplaces planned around people and performance',
            'text' => 'Clear layouts, coordinated services and practical details turn an empty shell into a workplace ready for day one.',
            'display' => 'WORKPLACE',
            'primary_label' => 'Start your project',
            'primary_url' => '/contact/',
            'secondary_label' => 'Explore fit-out',
            'secondary_url' => '/fit-out/',
        ],
        [
            'file' => 'img-dce06249b137.webp',
            'kicker' => 'Architecture · Resolved before site',
            'heading' => 'Buildings drawn with the finished space in mind',
            'text' => 'Plans, approvals and construction drawings that make the interior certain before the ground opens.',
            'display' => 'ARCHITECTURE',
            'primary_label' => 'Start your project',
            'primary_url' => '/contact/',
            'secondary_label' => 'Explore architecture',
            'secondary_url' => '/architecture/',
        ],
    ];

    public function handle(): int
    {
        $home = Page::query()->where('slug', '/')->first();

        if ($home === null) {
            $this->components->warn('There is no home page yet — nothing to do.');

            return self::SUCCESS;
        }

        $blocks = $home->blocks()->orderBy('position')->get();

        if ($blocks->contains(fn (PageBlock $block): bool => $block->type === BlockType::Slider)) {
            $this->components->info('The home page already has a hero slider — leaving it alone.');

            return self::SUCCESS;
        }

        $slides = [];

        foreach (self::SLIDES as $slide) {
            $media = Media::query()->where('path', 'like', '%'.$slide['file'])->orderBy('id')->first();

            if ($media === null) {
                $this->components->warn('Photo not in the media library, slide skipped: '.$slide['file']);

                continue;
            }

            $slides[] = ['image_id' => $media->id, ...array_diff_key($slide, ['file' => null])];
        }

        if ($slides === []) {
            $this->components->error('None of the v26 photos are in the media library — run the legacy import first.');

            return self::FAILURE;
        }

        // Whatever the home page already has stays — it just moves one row down, under the slider.
        $home->blocks()->update(['position' => \DB::raw('position + 1')]);

        PageBlock::query()->create([
            'page_id' => $home->id,
            'type' => BlockType::Slider->value,
            'block_key' => 'bhomev26s',
            'position' => 0,
            'data' => ['slides' => $slides],
        ]);

        PageCache::invalidate($home);

        $this->components->info('The home page now opens with its v26 hero slider ('.count($slides).' slides).');

        return self::SUCCESS;
    }
}
