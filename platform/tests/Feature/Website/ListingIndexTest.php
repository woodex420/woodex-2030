<?php

declare(strict_types=1);

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Services\ListingIndex;

/*
| The blog and portfolio index pages carry a hand-curated card list (Frontend v1 is final), so the
| content screens need a way to keep that list true: is this post on /insights/, and can we add or
| take its card off without opening the index and retyping it?
*/

beforeEach(function () {
    $this->actingAs($this->userWithRole('owner'));

    $this->listing = Page::query()->create([
        'type' => PageType::Listing,
        'slug' => '/insights/',
        'title' => 'Insights',
        'status' => PageStatus::Published,
    ]);

    $this->listing->blocks()->create([
        'type' => 'services',
        'block_key' => 'blist0001',
        'data' => ['heading' => 'Featured guides', 'items' => [
            ['number' => '1', 'name' => 'Cost guide', 'copy' => '6 min read', 'url' => '/insights/cost-guide/'],
            ['number' => '2', 'name' => 'Material guide', 'copy' => '4 min read', 'url' => '/insights/materials/'],
        ]],
        'visible' => true,
        'position' => 0,
    ]);

    $this->listed = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/cost-guide/',
        'title' => 'Cost guide',
        'status' => PageStatus::Published,
    ]);

    $this->unlisted = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/new-post/',
        'title' => 'A new post',
        'status' => PageStatus::Published,
    ]);

    $this->draft = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/unfinished/',
        'title' => 'Unfinished',
        'status' => PageStatus::Draft,
    ]);
});

function listingItems(Page $listing): array
{
    $block = $listing->blocks()->where('type', 'services')->firstOrFail();

    return $block->refresh()->data['items'];
}

it('finds the index through the parent when the page has one', function () {
    $this->unlisted->forceFill(['parent_id' => $this->listing->id])->save();

    expect(ListingIndex::listingFor($this->unlisted)?->id)->toBe($this->listing->id);
});

it('finds the index by convention for pages imported without a parent', function () {
    expect($this->unlisted->parent_id)->toBeNull()
        ->and(ListingIndex::listingFor($this->unlisted)?->slug)->toBe('/insights/');
});

it('knows whether a post is already on its index', function () {
    expect(ListingIndex::isListed($this->listed))->toBeTrue()
        ->and(ListingIndex::isListed($this->unlisted))->toBeFalse();
});

it('adds a card at the end, once', function () {
    expect(ListingIndex::add($this->unlisted))->toBeTrue()
        ->and(ListingIndex::add($this->unlisted))->toBeTrue();

    $items = listingItems($this->listing);

    expect($items)->toHaveCount(3)
        ->and($items[2]['url'])->toBe('/insights/new-post/')
        ->and($items[2]['name'])->toBe('A new post')
        ->and($items[2]['number'])->toBe('3')
        ->and(ListingIndex::isListed($this->unlisted))->toBeTrue();
});

it('never adds a draft to a live index', function () {
    expect(ListingIndex::add($this->draft))->toBeFalse()
        ->and(listingItems($this->listing))->toHaveCount(2);
});

it('takes a card off and keeps the numbers in order', function () {
    expect(ListingIndex::remove($this->listed))->toBeTrue();

    $items = listingItems($this->listing);

    expect($items)->toHaveCount(1)
        ->and($items[0]['url'])->toBe('/insights/materials/')
        ->and($items[0]['number'])->toBe('1')
        ->and(ListingIndex::remove($this->listed))->toBeFalse();
});

it('says so when there is no card list to write to', function () {
    $bare = Page::query()->create([
        'type' => PageType::Listing,
        'slug' => '/projects/',
        'title' => 'Projects',
        'status' => PageStatus::Published,
    ]);

    $study = Page::query()->create([
        'type' => PageType::Project,
        'slug' => '/projects/cafe/',
        'title' => 'Cafe',
        'status' => PageStatus::Published,
    ]);

    expect(ListingIndex::listingFor($study)?->id)->toBe($bare->id)
        ->and(ListingIndex::add($study))->toBeFalse()
        ->and(ListingIndex::remove($study))->toBeFalse();
});
