<?php

declare(strict_types=1);

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Import\ImportLegacyPage;
use App\Domain\Website\Import\LegacyHtml;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Models\User;

/*
| One page of the previous website becomes one page in the CMS (docs/09 §13, D-70): the old HTML is read into
| sections by what they contain rather than by their classes, images come with their alt text, and the page lands
| as a draft — nothing is published before a person has read it.
*/

/** A page of the old site, small enough to read in a test but shaped like the real ones. */
function legacyHtml(): string
{
    return <<<'HTML'
    <!DOCTYPE html>
    <html lang="en"><head>
    <title>Kitchen Design in Lahore | Woodex Interior</title>
    <meta name="description" content="Modular, custom and modern kitchen design in Lahore: layouts, storage, finishes and lighting planned together, then drawn in 3D." />
    <link rel="canonical" href="https://woodex.com.pk/kitchen-design/" />
    <meta property="og:image" content="https://woodex.com.pk/assets/img/svc-kitchen.webp" />
    </head>
    <body><main>
    <section class="kd-hero" id="hero">
      <p class="kd-label">Kitchens</p>
      <h1>Kitchen Design in Lahore</h1>
      <p class="kd-lead">We plan the work triangle, storage and light first, then draw the kitchen you will actually cook in.</p>
      <img src="/assets/img/svc-kitchen.webp" alt="A modern kitchen with timber cabinets" width="1600" height="900" />
      <a class="btn" href="/contact/">Book a consultation</a>
      <a class="btn btn-light" href="https://wa.me/923224000768">WhatsApp</a>
    </section>
    <section class="wx-short" aria-label="In short">
      <p class="wx-label">In short</p>
      <p>Modular, custom and modern kitchen design in Lahore, with layouts, storage, finishes and lighting planned together.</p>
      <ul><li>Based in Lahore, working across Pakistan</li><li>3D views before anything is built</li></ul>
    </section>
    <section class="kd-section">
      <h2>What we deliver</h2>
      <div class="kd-card"><h3>Layout planning</h3><p>Sink, hob and fridge placed as one working triangle.</p></div>
      <div class="kd-card"><h3>Storage</h3><p>Drawers, corners and pantry planned to what you own.</p></div>
      <div class="kd-card"><h3>Finishes</h3><p>Laminate, lacquer or veneer, sampled before we build.</p></div>
    </section>
    <section class="kd-process">
      <h2>How it runs</h2>
      <div class="wx-steps">
        <ol><li><strong>Brief</strong><p>We measure the room and talk through how you cook.</p></li>
        <li><strong>Drawings</strong><p>Layouts, elevations and 3D views.</p></li>
        <li><strong>Build</strong><p>Joinery, installation and handover.</p></li></ol>
      </div>
    </section>
    <section class="kd-faq">
      <h2>Kitchen design FAQs</h2>
      <div class="faq-item"><button class="faq-q" aria-expanded="false"><span>Modular or custom?</span><span class="plus">+</span></button>
        <div class="faq-a"><div><p>Both can be refined; the difference is how the cabinets are engineered.</p></div></div></div>
      <div class="faq-item"><button class="faq-q" aria-expanded="false"><span>How long does it take?</span><span class="plus">+</span></button>
        <div class="faq-a"><div><p>Four to six weeks from approved drawings.</p></div></div></div>
    </section>
    <section class="wx-talk">
      <h2>Talk to Woodex</h2>
      <p>Tell us the room and we will measure it.</p>
      <form class="wx-form"><input name="name" /><button>Send</button></form>
    </section>
    </main></body></html>
    HTML;
}

/** The smallest PNG there is, so the importer has a real file to copy. */
function legacyImageBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==',
        true,
    ) ?: '';
}

it('reads the head of an old page', function () {
    $page = new LegacyHtml(legacyHtml());

    expect($page->title())->toBe('Kitchen Design in Lahore | Woodex Interior')
        ->and($page->metaDescription())->toStartWith('Modular, custom and modern kitchen design in Lahore')
        ->and($page->canonical())->toBe('https://woodex.com.pk/kitchen-design/')
        ->and($page->ogImage())->toBe('https://woodex.com.pk/assets/img/svc-kitchen.webp');
});

it('turns the sections of an old page into blocks', function () {
    $blocks = (new LegacyHtml(legacyHtml()))->blocks();

    expect(array_map(fn (array $block): string => $block['type']->value, $blocks))
        ->toBe(['hero', 'intro', 'services', 'process', 'faq', 'form']);

    $hero = $blocks[0]['data'];
    expect($hero['heading'])->toBe('Kitchen Design in Lahore')
        ->and($hero['eyebrow'])->toBe('Kitchens')
        ->and($hero['text'])->toContain('work triangle')
        ->and($hero['image'])->toBe('assets/img/svc-kitchen.webp')
        ->and($hero['primary_label'])->toBe('Book a consultation')
        ->and($hero['primary_url'])->toBe('/contact/');

    expect($blocks[1]['data']['body'])->toContain('Modular, custom and modern kitchen design')
        ->and($blocks[1]['data']['facts'])->toHaveCount(2);

    expect($blocks[2]['data']['heading'])->toBe('What we deliver')
        ->and($blocks[2]['data']['items'])->toHaveCount(3)
        ->and($blocks[2]['data']['items'][0]['name'])->toBe('Layout planning')
        ->and($blocks[2]['data']['items'][0]['copy'])->toBe('Sink, hob and fridge placed as one working triangle.');

    expect($blocks[3]['data']['stages'])->toHaveCount(3)
        ->and($blocks[3]['data']['stages'][0]['name'])->toBe('Brief');

    expect($blocks[4]['data']['items'])->toHaveCount(2)
        ->and($blocks[4]['data']['items'][0]['question'])->toBe('Modular or custom?')
        ->and($blocks[4]['data']['items'][0]['answer'])->toContain('engineered');

    expect($blocks[5]['data']['heading'])->toBe('Talk to Woodex');
});

it('imports an old page as a draft with its images and alt text', function () {
    $root = sys_get_temp_dir().'/legacy-site-'.bin2hex(random_bytes(4));
    mkdir($root.'/assets/img', 0o777, true);
    mkdir($root.'/kitchen-design', 0o777, true);
    file_put_contents($root.'/kitchen-design/index.html', legacyHtml());
    file_put_contents($root.'/assets/img/svc-kitchen.webp', legacyImageBytes());

    try {
        $user = User::factory()->create();
        $result = app(ImportLegacyPage::class)($root, 'kitchen-design/index.html', '/kitchen-design/', PageType::Service, $user);

        expect($result['status'])->toBe('created')
            ->and($result['blocks'])->toBe(6)
            ->and($result['issues'])->toBe([]);

        $page = Page::query()->where('slug', '/kitchen-design/')->firstOrFail();

        expect($page->status)->toBe(PageStatus::Draft)
            ->and($page->type)->toBe(PageType::Service)
            ->and($page->title)->toBe('Kitchen Design in Lahore') // without the brand at the end of the old <title>
            ->and($page->excerpt)->toContain('work triangle')
            ->and($page->seo['title'])->toBe('Kitchen Design in Lahore | Woodex Interior')
            ->and($page->seo['description'])->toStartWith('Modular, custom and modern kitchen design')
            ->and($page->blocks)->toHaveCount(6);

        $image = Media::query()->firstOrFail();
        expect($image->alt)->toBe('A modern kitchen with timber cabinets')
            ->and($page->blocks->firstWhere('type', BlockType::Hero)->data['image_id'])->toBe($image->id);

        // A second run changes nothing: importing is idempotent, so an edited page is never overwritten.
        $again = app(ImportLegacyPage::class)($root, 'kitchen-design/index.html', '/kitchen-design/', PageType::Service, $user);
        expect($again['status'])->toBe('unchanged')
            ->and(Page::query()->where('slug', '/kitchen-design/')->count())->toBe(1)
            ->and(Media::count())->toBe(1);
    } finally {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($root);
    }
});

it('reports a page whose file is missing instead of creating an empty one', function () {
    $result = app(ImportLegacyPage::class)('/tmp/nowhere-'.bin2hex(random_bytes(3)), 'about/index.html', '/about/', PageType::About, User::factory()->create());

    expect($result['status'])->toBe('missing')
        ->and($result['issues'])->not->toBe([])
        ->and(Page::query()->where('slug', '/about/')->exists())->toBeFalse();
});
