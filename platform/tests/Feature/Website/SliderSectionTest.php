<?php

use App\Console\Commands\SeedHomeSlider;
use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithPublicWebsite;

/*
| V2-3 (docs/14): the v26 hero slider, as a reusable section. Alpine rotates the slides, autoplay
| pauses on hover and for prefers-reduced-motion, the first photo is the LCP element, and the page
| keeps exactly one H1. The seeder gives the home page the three v26 slides, guarded against
| double runs and missing photos.
*/

uses(InteractsWithPublicWebsite::class);

function sliderMedia(): array
{
    $one = Media::factory()->create(['path' => 'website/imported/2026/10/img-c349a92a4ae0.webp']);
    $two = Media::factory()->create(['path' => 'website/imported/2026/10/img-1d6ad6c77d0f.webp']);
    $three = Media::factory()->create(['path' => 'website/imported/2026/10/img-dce06249b137.webp']);

    return [$one->id, $two->id, $three->id];
}

it('gives the home page the v26 slider, once, and skips slides without photos', function () {
    sliderMedia();

    $home = Page::factory()->published()->asType(PageType::Home)->withSlug('/')->create(['title' => 'Woodex Interior']);
    PageBlock::factory()->for($home)->asType(BlockType::Text, ['heading' => 'Welcome', 'body' => 'Body'])->create(['position' => 0]);

    Artisan::call(SeedHomeSlider::class);
    Artisan::call(SeedHomeSlider::class); // a second run must not add a second slider

    $blocks = $home->fresh(['blocks'])->blocks->values();

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0]->type)->toBe(BlockType::Slider)
        ->and($blocks[0]->position)->toBe(0)
        ->and(count($blocks[0]->data['slides']))->toBe(3)
        ->and($blocks[0]->data['slides'][0]['display'])->toBe('INTERIORS')
        ->and($blocks[0]->data['slides'][2]['display'])->toBe('ARCHITECTURE')
        // What was there moved down exactly one row, under the slider.
        ->and($blocks[1]->type)->toBe(BlockType::Text)
        ->and($blocks[1]->position)->toBe(1);

    // A home without the v26 photos gets no half-empty carousel: the command skips cleanly.
    Media::query()->where('path', 'like', '%img-%')->delete();
    PageBlock::query()->where('page_id', $home->id)->delete();
    Artisan::call(SeedHomeSlider::class);

    expect($home->fresh(['blocks'])->blocks)->toBeEmpty();
});
