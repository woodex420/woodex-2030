<?php

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;
use App\Domain\Website\Models\PageSpeedRun;
use App\Livewire\Admin\Website\SpeedIndex;
use App\Models\User;
use App\Settings\SpeedSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithPublicWebsite;

/*
| V2-3 F9 (docs/14 §5a): the speed dashboard. Internal checks always run — image weight, image
| formats, cached page size — keyless, at home. Google's PageSpeed runs behind the studio's own API
| key, saved on the screen, and every run is kept as history.
*/

uses(InteractsWithPublicWebsite::class);

beforeEach(function () {
    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo('website.edit');

    app(SpeedSettings::class)->fill(['google_api_key' => null])->save();
    PageSpeedRun::query()->delete();

    Storage::fake('public');
});

it('weighs every published page keylessly — images, heavy ones, old formats', function () {
    $heavy = Media::factory()->create(['path' => 'website/media/atrium.jpg']);      // old format AND heavy
    $light = Media::factory()->create(['path' => 'website/media/desk.webp']);       // modern and light

    Storage::disk('public')->put('website/media/atrium.jpg', str_repeat('x', 400 * 1024));
    Storage::disk('public')->put('website/media/desk.webp', str_repeat('x', 40 * 1024));

    $page = Page::factory()->published()->withSlug('/heavy-page/')->create(['title' => 'A heavy page']);
    PageBlock::factory()->for($page)->asType(BlockType::Gallery, ['images' => [['id' => $heavy->id, 'alt' => 'Atrium'], ['id' => $light->id, 'alt' => 'Desk']]])->create(['position' => 0]);

    Page::factory()->published()->withSlug('/light-page/')->create(['title' => 'A light page']);

    $component = Livewire::actingAs($this->editor)->test(SpeedIndex::class);

    // Keyless: internal only, no run buttons, the unlock card.
    $component->assertSee('PageSpeed is asleep')
        ->assertDontSee('Run mobile')
        ->assertSee('internal only')
        // The heavy page tops the table with its numbers.
        ->assertSee('A heavy page')
        ->assertSee('1 heavy')
        ->assertSee('1 old format')
        ->assertSee('440 KB')
        ->assertSee('A light page')
        ->assertSee('Full-page cache:');

});

it('keeps the key off the browser: saved encrypted, never shown back', function () {
    $component = Livewire::actingAs($this->editor)->test(SpeedIndex::class)
        ->set('apiKey', 'AIza-super-secret')
        ->call('saveKey')
        ->assertDispatched('toast', message: 'API key saved — the PageSpeed buttons are awake.');

    // The key lives encrypted and is not the plaintext anywhere in the page.
    $component->assertDontSee('AIza-super-secret')
        ->assertSee('PageSpeed is awake');

    expect(app(SpeedSettings::class)->google_api_key)->toBe('AIza-super-secret');

    // Removing it puts the screen back to internal-only.
    $component->call('clearKey')
        ->assertDispatched('toast', message: 'API key removed — back to internal checks only.')
        ->assertSee('PageSpeed is asleep');

    expect(app(SpeedSettings::class)->google_api_key)->toBeNull();
});

it('runs PageSpeed against the page\'s live URL and keeps the score as history', function () {
    app(SpeedSettings::class)->fill(['google_api_key' => 'AIza-key'])->save();

    $page = Page::factory()->published()->withSlug('/scored-page/')->create(['title' => 'A scored page']);
    PageBlock::factory()->for($page)->asType(BlockType::Text, ['heading' => 'Hello', 'body' => 'World'])->create(['position' => 0]);

    Http::fake(['www.googleapis.com/*' => Http::response([
        'lighthouseResult' => [
            'categories' => ['performance' => ['score' => 0.83]],
            'audits' => [
                'first-contentful-paint' => ['displayValue' => '1.2 s'],
                'largest-contentful-paint' => ['displayValue' => '2.4 s'],
                'total-byte-weight' => ['displayValue' => 'Total size was 320 KiB'],
            ],
        ],
    ])]);

    Livewire::actingAs($this->editor)
        ->test(SpeedIndex::class)
        ->call('run', $page->id, 'mobile')
        ->assertDispatched('toast', message: 'PageSpeed mobile: 83/100.')
        ->assertSee('83/100')
        ->assertSee('Largest contentful paint: 2.4 s');

    $run = PageSpeedRun::query()->first();
    expect($run)->not->toBeNull()
        ->and($run->url)->toBe($page->canonical())
        ->and($run->strategy)->toBe('mobile')
        ->and($run->score)->toBe(83)
        ->and($run->metrics)->toHaveKey('First contentful paint');

    Http::assertSentCount(1);
});

it('refuses to run Google against drafts, and says so plainly', function () {
    app(SpeedSettings::class)->fill(['google_api_key' => 'AIza-key'])->save();

    $draft = Page::factory()->withSlug('/draft-page/')->create(['title' => 'A draft']); // the factory's default state is a draft

    Http::fake();

    Livewire::actingAs($this->editor)
        ->test(SpeedIndex::class)
        ->call('run', $draft->id, 'mobile')
        ->assertDispatched('toast', message: 'Publish the page first — Google cannot see drafts.');

    Http::assertNothingSent();
});
