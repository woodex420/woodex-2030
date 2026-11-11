<?php

use App\Domain\Website\Data\LegacyUrlMap;
use App\Domain\Website\Enums\PageType;

/*
| The URL map is the contract with the previous website (docs/09 §3, D-68): all 142 URLs are pages now (the owner
| asked for the complete old website to run in the CMS, 17 Oct 2026). If a slug is both a page and a redirect, or
| a redirect points at a page that does not exist, visitors get a 404 where the old site had a page — so the map
| is checked itself, before anything is imported.
*/
it('holds every URL of the old site as a page', function () {
    expect(LegacyUrlMap::pages())->toHaveCount(142)
        ->and(LegacyUrlMap::redirects())->toHaveCount(0)
        ->and(LegacyUrlMap::count())->toBe(142);
});

it('gives every URL one home: a slug is either a page or a redirect, never both', function () {
    $pages = array_keys(LegacyUrlMap::pages());
    $redirects = array_keys(LegacyUrlMap::redirects());

    expect(array_intersect($pages, $redirects))->toBe([])
        ->and(array_unique($pages))->toHaveCount(count($pages))
        ->and(array_unique($redirects))->toHaveCount(count($redirects));
});

it('redirects only to pages that exist in the map', function () {
    foreach (LegacyUrlMap::redirects() as $from => $to) {
        expect(LegacyUrlMap::pages())->toHaveKey($to, message: "{$from} redirects to {$to}, which is not a page");
    }

    // Every old URL is a page now, so the map carries no redirects of its own (17 Oct 2026).
    expect(LegacyUrlMap::redirects())->toBeArray();
});

it('keeps the 54 old service URLs as service pages, each with its own old HTML', function () {
    $services = array_keys(array_filter(
        LegacyUrlMap::pages(),
        fn (array $page): bool => $page['type'] === PageType::Service->value,
    ));

    // The six disciplines the studio sells, plus the old site's specialist URLs (one fewer since the owner
    // deleted `/furniture/` on 21 Oct 2026).
    expect($services)->toHaveCount(59)
        ->and($services)->toContain('/kitchen-design/', '/office-fit-out/', '/1-kanal-house-design/', '/wardrobe-design/');

    foreach ($services as $slug) {
        expect(LegacyUrlMap::typeFor($slug))->toBe(PageType::Service);
    }
});

it('stores every slug with its trailing slash and the page type of the old page', function () {
    foreach (array_keys(LegacyUrlMap::pages()) as $slug) {
        expect($slug)->toEndWith('/')->and($slug)->toStartWith('/');
    }

    expect(LegacyUrlMap::typeFor('/'))->toBe(PageType::Home)
        ->and(LegacyUrlMap::typeFor('/lahore/'))->toBe(PageType::City)
        ->and(LegacyUrlMap::typeFor('/lahore/dha/'))->toBe(PageType::Area)
        ->and(LegacyUrlMap::typeFor('/insights/'))->toBe(PageType::Listing)
        ->and(LegacyUrlMap::typeFor('/insights/small-space-ideas/'))->toBe(PageType::Insight)
        ->and(LegacyUrlMap::typeFor('/projects/courtyard-house/'))->toBe(PageType::Project)
        ->and(LegacyUrlMap::typeFor('/privacy/'))->toBe(PageType::Legal)
        ->and(LegacyUrlMap::typeFor('/contact/'))->toBe(PageType::Contact)
        ->and(LegacyUrlMap::typeFor('/estimator/'))->toBe(PageType::Estimator)
        ->and(LegacyUrlMap::typeFor('/book-a-visit/'))->toBe(PageType::Booking);
});

it('keeps the home page and knows which page has no old file behind it', function () {
    expect(LegacyUrlMap::sourceFor('/'))->toBe('index.html')
        ->and(LegacyUrlMap::sourceFor('/interior-design/'))->toBe('interior-design/index.html')
        // The old site had no furniture page: it is written from the closest old page and then rewritten.
        ->and(LegacyUrlMap::sourceFor('/furniture/'))->toBeNull()
        // A wardrobe page of its own: the complete old website runs in the CMS, so nothing 301s away (17 Oct 2026).
        ->and(LegacyUrlMap::sourceFor('/wardrobe-design/'))->toBe('wardrobe-design/index.html')
        ->and(LegacyUrlMap::isRedirect('/wardrobe-design/'))->toBeFalse();
});
