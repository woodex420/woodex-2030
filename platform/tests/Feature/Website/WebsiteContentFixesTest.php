<?php

use App\Domain\Website\Actions\AddMissingCallToActions;
use App\Domain\Website\Actions\LinkCityPagesToAreas;
use App\Domain\Website\Actions\LinkEmptyServiceUrls;
use App\Domain\Website\Actions\RepairImportedCopy;
use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;
use App\Domain\Website\Models\Redirect;
use Illuminate\Support\Facades\DB;

/*
| The fixes the 89-page audit asked for (docs/10 §3), kept in actions so that a re-import — which brings the old
| pages back without them — can apply them again. Each one has to be idempotent and has to leave the studio's own
| editing alone.
*/

it('gives every published page a closing, and leaves a page that already has one', function () {
    $bare = Page::factory()->published()->asType(PageType::Insight)->withSlug('/insights/a-guide/')->create();
    PageBlock::factory()->for($bare)->asType(BlockType::Text, ['heading' => 'The answer', 'body' => 'It depends.'])->create(['position' => 0]);
    $done = Page::factory()->published()->asType(PageType::City)->withSlug('/multan/')->create();
    PageBlock::factory()->for($done)->asType(BlockType::Form, ['heading' => 'Design in your city'])->create(['position' => 0]);

    $added = (new AddMissingCallToActions)();

    expect($added)->toBe(1)
        ->and($bare->blocks()->where('type', BlockType::Form)->count())->toBe(1)
        ->and($done->blocks()->where('type', BlockType::Form)->count())->toBe(1)
        // The heading is written for the kind of page it closes.
        ->and($bare->blocks()->where('type', BlockType::Form)->first()->data['heading'])->toBe('Ask us about your own numbers')
        // And it is the last section, not one stuck in the middle.
        ->and($bare->blocks()->where('type', BlockType::Form)->first()->position)->toBe(1);

    // Running it again changes nothing: it is not a duplicate machine.
    expect((new AddMissingCallToActions)())->toBe(0);
});

it('points a city page at the areas inside it, using the city name from the slug', function () {
    $lahore = Page::factory()->published()->asType(PageType::City)->withSlug('/lahore/')->create(['title' => 'Interior design in Lahore']);
    $dha = Page::factory()->published()->asType(PageType::Area)->withSlug('/lahore/dha/')->create();
    $karachi = Page::factory()->published()->asType(PageType::City)->withSlug('/karachi/')->create();

    $linked = (new LinkCityPagesToAreas)();

    expect($linked)->toBe(1);

    $block = $lahore->blocks()->where('type', BlockType::Related)->first();

    expect($block->data['heading'])->toBe('Areas of Lahore we work in')
        ->and($block->data['page_ids'])->toBe([$dha->id])
        // A city with no areas gets nothing invented for it.
        ->and($karachi->blocks()->where('type', BlockType::Related)->count())->toBe(0);

    expect((new LinkCityPagesToAreas)())->toBe(0);
});

it('fills in a discipline link that was written empty on the old site', function () {
    $page = Page::factory()->published()->asType(PageType::Home)->withSlug('/')->create();
    Page::factory()->published()->asType(PageType::Service)->withSlug('/interior-design/')->create(['title' => 'Interior Design']);
    Page::factory()->published()->asType(PageType::Service)->withSlug('/furniture/')->create(['title' => 'Custom furniture']);

    PageBlock::factory()->for($page)->asType(BlockType::Services, [
        'heading' => 'What we do',
        'items' => [
            ['number' => '1', 'name' => 'Interior Design', 'copy' => '', 'url' => ''],
            ['number' => '2', 'name' => 'Furniture', 'copy' => '', 'url' => ''],
            ['number' => '3', 'name' => 'A guide to small rooms', 'copy' => '', 'url' => ''],
        ],
    ])->create(['position' => 0]);

    $filled = (new LinkEmptyServiceUrls)();

    $items = $page->blocks()->first()->fresh()->data['items'];

    expect($filled)->toBe(2)
        ->and($items[0]['url'])->toBe('/interior-design/')
        ->and($items[1]['url'])->toBe('/furniture/')
        // A name that is not one of our pages keeps the link the studio typed.
        ->and($items[2]['url'])->toBe('');
});

it('corrects a promise the old site made about where data is stored', function () {
    $privacy = Page::factory()->published()->asType(PageType::Legal)->withSlug('/privacy/')->create();
    PageBlock::factory()->for($privacy)->asType(BlockType::Text, [
        'heading' => 'Who stores your data',
        'body' => 'Form and chat data is stored in our database, hosted by Supabase, and the website itself is served by Vercel.',
    ])->create(['position' => 0]);

    expect((new RepairImportedCopy)())->toBe(1);

    $body = $privacy->blocks()->first()->fresh()->data['body'];

    expect($body)->not->toContain('Supabase')
        ->and($body)->not->toContain('Vercel')
        ->and($body)->toContain('our own database');

    expect((new RepairImportedCopy)())->toBe(0);
});

it('empties the website and only with --force, and forgets the import bookkeeping', function () {
    $page = Page::factory()->published()->withSlug('/interior-design/')->create();
    PageBlock::factory()->for($page)->asType(BlockType::Hero, ['heading' => 'Gone'])->create(['position' => 0]);
    $image = Media::factory()->create();
    Redirect::query()->create(['from_path' => '/old/', 'to_path' => '/interior-design/', 'code' => 301, 'hits' => 3]);

    // Without --force it only says what it would take: nothing is deleted (the confirmation defaults to no).
    expect($this->artisan('woodex:reset-website'))->toBe(0);

    expect(Page::query()->count())->toBe(1)->and(Media::query()->count())->toBe(1);

    expect($this->artisan('woodex:reset-website', ['--force' => true]))->toBe(0);

    expect(Page::query()->withTrashed()->count())->toBe(0)
        ->and(PageBlock::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and(Redirect::query()->count())->toBe(0)
        ->and(DB::table('legacy_refs')->where('legacy_table', 'website-image')->count())->toBe(0);

    // Redirects and photographs are the studio's own work: they are kept when asked.
    expect($this->artisan('woodex:reset-website', ['--force' => true, '--keep-media' => true, '--keep-redirects' => true]))->toBe(0);

    expect(Page::query()->count())->toBe(0);
});

it('leaves a page that is still a draft alone', function () {
    $draft = Page::factory()->asType(PageType::Insight)->withSlug('/insights/not-ready/')->create(['status' => PageStatus::Draft]);

    expect((new AddMissingCallToActions)())->toBe(0)
        ->and($draft->blocks()->count())->toBe(0);
});
