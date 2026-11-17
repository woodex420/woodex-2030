<?php

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Livewire\Admin\Sales\RateBook;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'business_line' => 'interior']);
    $template = QuoteTemplate::query()->where('slug', 'interior')->firstOrFail();
    $this->quote = app(CreateQuote::class)->handle($this->lead->contact, $template->business_line, $this->owner, $this->lead, $template);
    $this->actingAs($this->owner);
    Storage::fake('public');
});

it('attaches a picture to a rate book item', function () {
    $file = UploadedFile::fake()->image('wardrobe.jpg', 320, 320);

    Livewire::test(RateBook::class)
        ->set('formCategory', 'Wardrobes')
        ->set('formDescription', 'Laminated wardrobe with internal fittings')
        ->set('formUnit', 'sft')
        ->set('formRate', '4,500')
        ->set('formImage', $file)
        ->call('save')
        ->assertHasNoErrors();

    $item = CatalogItem::query()->where('description', 'Laminated wardrobe with internal fittings')->firstOrFail();

    expect($item->image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($item->image_path);
});

it('rejects anything that is not a small picture', function () {
    Livewire::test(RateBook::class)
        ->set('formCategory', 'Wardrobes')
        ->set('formDescription', 'Wardrobe line')
        ->set('formUnit', 'sft')
        ->set('formImage', UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['formImage']);
});

it('copies the picture onto the quotation line and embeds it in the frozen snapshot', function () {
    Storage::disk('public')->put('catalog/wardrobe.jpg', 'picture-bytes');
    $catalog = CatalogItem::query()->firstOrFail();
    $catalog->update(['image_path' => 'catalog/wardrobe.jpg']);

    $section = $this->quote->sections->first();

    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->call('openCatalog', $section->id)
        ->call('addFromCatalog', $catalog->id);

    $line = $section->items()->where('catalog_item_id', $catalog->id)->firstOrFail();
    expect($line->image_path)->toBe('catalog/wardrobe.jpg');

    $snapshot = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $item = collect($snapshot['sections'])->flatMap(fn (array $s): array => $s['items'])->firstWhere('description', $catalog->description);

    expect($item['image'])->toStartWith('data:image/jpeg;base64,')
        ->and(app(QuotePdf::class)->render($snapshot))->toBeString();
});

it('keeps the picture on existing quote lines when the catalogue picture changes later', function () {
    Storage::disk('public')->put('catalog/original.jpg', 'picture-bytes');
    $catalog = CatalogItem::query()->firstOrFail();
    $catalog->update(['image_path' => 'catalog/original.jpg']);

    $section = $this->quote->sections->first();
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->call('openCatalog', $section->id)
        ->call('addFromCatalog', $catalog->id);

    $line = $section->items()->where('catalog_item_id', $catalog->id)->firstOrFail();

    $catalog->update(['image_path' => 'catalog/replaced.jpg']);

    expect($line->fresh()->image_path)->toBe('catalog/original.jpg');
});

it('keeps the rate book picture flow behind the catalog permission', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('quotes.view');

    Livewire::actingAs($viewer)
        ->test(RateBook::class)
        ->assertOk()
        ->call('openCreate')
        ->assertForbidden();
});
