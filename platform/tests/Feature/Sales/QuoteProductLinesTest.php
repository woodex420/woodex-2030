<?php

declare(strict_types=1);

use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\WoodexCatalogue;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\DuplicateQuote;
use App\Domain\Sales\Actions\ReviseQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\AcceptanceChannel;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteLocked;
use App\Domain\Sales\Models\QuoteItem;
use App\Domain\Sales\Services\CatalogueProducts;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Livewire\Admin\Sales\QuoteBuilder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| T-086: a quotation takes its products from the catalogue.
|
| The catalogue here is the real one: the 170 products of woodexfurniture.pk, loaded as drafts (DL-022). A line
| chosen from it keeps a link to the product and its own copy of the name, the code and the price as they were at
| that moment. The catalogue is never read again for that line: what a client was sent stays what the client was
| sent, whatever happens to the product afterwards.
*/

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 18)->setTime(11, 0));
    Storage::fake('local');
    Mail::fake();
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    app(WoodexCatalogue::class)->import($this->owner);
    $this->lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'business_line' => 'furniture']);
    $this->lead->contact->update(['name' => 'Ayesha Khan', 'phone' => '0322 4000768', 'email' => null]);
    $this->quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Furniture, $this->owner, $this->lead, null, 'Office furniture');
    // The blank line a new quotation starts with is not part of what is tested.
    $this->quote->items()->each(fn (QuoteItem $item) => app(QuoteEditor::class)->deleteLine($item));
    $this->section = $this->quote->sections()->firstOrFail();
    $this->actingAs($this->owner);
});

/** A product of the real catalogue with a price, and the code and the price in paisa the catalogue gives for it. */
function pricedProduct(): array
{
    $source = collect(app(WoodexCatalogue::class)->source()['items'])->first(fn (array $row): bool => ! $row['priceOnRequest'] && (int) $row['price'] > 0);
    $product = Product::query()->where('source_key', $source['id'])->firstOrFail();

    return [$product, (string) $source['sku'], (int) $source['price'] * 100];
}

function productOnRequest(): array
{
    $source = collect(app(WoodexCatalogue::class)->source()['items'])->first(fn (array $row): bool => (bool) $row['priceOnRequest']);

    return [Product::query()->where('source_key', $source['id'])->firstOrFail(), (string) $source['sku']];
}

/** Changes a product in the catalogue the way the product editor does. */
function editProduct(Product $product, array $changes): void
{
    app(ProductCatalogue::class)->save($product->refresh(), [...$product->draft, ...$changes], $product->version, test()->owner);
}

it('adds columns to the lines of quotations and invoices, and takes them away again', function () {
    expect(Schema::hasColumns('quote_items', ['product_id', 'product_code', 'price_on_request']))->toBeTrue()
        ->and(Schema::hasColumns('invoice_items', ['product_id', 'product_code']))->toBeTrue();

    $migration = require database_path('migrations/2026_11_18_010000_add_catalogue_product_to_document_lines.php');
    $migration->down();

    expect(Schema::hasColumn('quote_items', 'product_id'))->toBeFalse()
        ->and(Schema::hasColumn('quote_items', 'product_code'))->toBeFalse()
        ->and(Schema::hasColumn('quote_items', 'price_on_request'))->toBeFalse()
        ->and(Schema::hasColumn('invoice_items', 'product_id'))->toBeFalse()
        ->and(Schema::hasColumn('invoice_items', 'product_code'))->toBeFalse()
        // What was there before is still there.
        ->and(Schema::hasColumns('quote_items', ['product_name', 'rate_minor', 'catalog_item_id']))->toBeTrue();

    $migration->up();

    expect(Schema::hasColumns('quote_items', ['product_id', 'product_code', 'price_on_request']))->toBeTrue()
        ->and(Schema::hasColumns('invoice_items', ['product_id', 'product_code']))->toBeTrue();
});

it('finds a product of the catalogue by its code and by its name, drafts included, archived ones left out', function () {
    [$product, $code, $price] = pricedProduct();
    $catalogue = app(CatalogueProducts::class);

    $byCode = $catalogue->search(strtolower($code));
    expect($byCode)->toHaveCount(1)
        ->and($byCode[0]['product_id'])->toBe($product->id)
        ->and($byCode[0]['code'])->toBe($code)
        ->and($byCode[0]['price_minor'])->toBe($price)
        // Nothing of the real catalogue is published: a quotation may use a draft.
        ->and($byCode[0]['published'])->toBeFalse();

    expect(collect($catalogue->search($product->name))->pluck('product_id'))->toContain($product->id)
        // No search at all offers the catalogue from its beginning, cut to what one screen holds.
        ->and($catalogue->search('', 30))->toHaveCount(30)
        ->and($catalogue->search('no product is called this'))->toBe([]);

    app(ProductCatalogue::class)->withdraw($product, $this->owner, true);

    expect($catalogue->search($code))->toBe([]);
});

it('copies the name, the code and the price of the chosen product onto the line', function () {
    [$product, $code, $price] = pricedProduct();

    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->call('openProducts', $this->section->id)
        ->set('productSearch', $code)
        ->assertSee($product->name)
        ->assertSee($code)
        ->call('addFromProduct', $product->id, $code)
        ->assertHasNoErrors()
        ->assertSee('From the catalogue')
        ->assertDontSee('Price on request');

    $line = $this->quote->items()->sole();

    expect($line->product_id)->toBe($product->id)
        ->and($line->product_code)->toBe($code)
        ->and($line->product_name)->toBe($product->name)
        ->and($line->rate_minor)->toBe($price)
        ->and($line->price_on_request)->toBeFalse()
        ->and((string) $line->qty)->toBe('1.000')
        ->and($line->unit)->toBe('nos')
        // A furniture document prints the name in its own column; the text beside it carries the code.
        ->and($line->description)->toBe("Code {$code}")
        ->and($line->line_total_minor)->toBe($price)
        ->and($this->quote->refresh()->total_minor)->toBe($price);
});

it('says in the search when a product still carries points to check', function () {
    $product = Product::query()->whereNull('archived_at')->orderBy('id')->get()
        ->first(fn (Product $product): bool => count((array) $product->review_issues) > 0);
    expect($product)->not->toBeNull();
    $points = count($product->review_issues);
    $choice = app(CatalogueProducts::class)->choices($product)[0];

    expect($choice['to_check'])->toBe($points);

    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->call('openProducts', $this->section->id)
        ->set('productSearch', $choice['code'])
        ->assertSee($points.' '.($points === 1 ? 'point' : 'points').' of this product still to check in the catalogue');

    // Somebody has looked at the product and said so: the search stops warning.
    editProduct($product, ['reviewed' => true]);

    expect(app(CatalogueProducts::class)->choices($product->refresh())[0]['to_check'])->toBe(0);

    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->call('openProducts', $this->section->id)
        ->set('productSearch', $choice['code'])
        ->assertSee($choice['code'])
        ->assertDontSee('still to check in the catalogue');
});

it('takes the reduced price where the catalogue has one in force', function () {
    $source = collect(app(WoodexCatalogue::class)->source()['items'])->first(fn (array $row): bool => ! $row['priceOnRequest'] && (int) ($row['originalPrice'] ?? 0) > (int) $row['price']);
    if ($source === null) {
        // The capture of the shop holds no reduced product today: one is made.
        [$product, $code, $price] = pricedProduct();
        editProduct($product, ['sale_price' => (string) intdiv($price, 200)]);
        $reduced = intdiv($price, 200) * 100;
    } else {
        $product = Product::query()->where('source_key', $source['id'])->firstOrFail();
        [$code, $price, $reduced] = [(string) $source['sku'], (int) $source['originalPrice'] * 100, (int) $source['price'] * 100];
    }

    $choice = app(CatalogueProducts::class)->choice($product->refresh(), $code);
    $line = app(QuoteEditor::class)->addProductLine($this->section, $product, $code);

    expect($choice['price_minor'])->toBe($reduced)
        ->and($choice['was_minor'])->toBe($price)
        ->and($line->rate_minor)->toBe($reduced);
});

it('keeps a draft and a sent quotation as they were when the product changes afterwards', function () {
    [$product, $code, $price] = pricedProduct();
    $name = $product->name;
    $editor = app(QuoteEditor::class);
    $line = $editor->addProductLine($this->section, $product, $code);
    $editor->updateLine($line, ['qty' => '4']);

    // A second quotation is sent before the catalogue changes.
    $sent = app(DuplicateQuote::class)->handle($this->quote->refresh(), $this->owner);
    $version = app(SendQuote::class)->handle($sent->refresh(), $this->owner, 'link', false);
    $pdfBefore = app(QuotePdf::class)->html($version->snapshot);

    editProduct($product, ['name' => 'Renamed in the catalogue', 'price' => (string) ($price / 100 + 9000), 'sale_price' => '']);
    expect($product->refresh()->name)->toBe('Renamed in the catalogue')
        ->and(app(CatalogueProducts::class)->choice($product, $code)['price_minor'])->toBe($price + 900_000);

    // The draft: its line and its total.
    $line->refresh();
    expect($line->product_name)->toBe($name)
        ->and($line->product_code)->toBe($code)
        ->and($line->rate_minor)->toBe($price)
        ->and($line->line_total_minor)->toBe($price * 4)
        ->and($this->quote->refresh()->total_minor)->toBe($price * 4)
        // The link still leads to the product, which now says something else.
        ->and($line->product->name)->toBe('Renamed in the catalogue');

    // The sent quotation: its lines, its total, the version the client was given and its PDF.
    $sentLine = $sent->items()->sole();
    $frozen = $version->refresh()->snapshot['sections'][0]['items'][0];
    $pdfAfter = app(QuotePdf::class)->html($version->snapshot);

    expect($sentLine->rate_minor)->toBe($price)
        ->and($sentLine->product_name)->toBe($name)
        ->and($sent->refresh()->total_minor)->toBe($price * 4)
        ->and($frozen['product_name'])->toBe($name)
        ->and($frozen['product_code'])->toBe($code)
        ->and($frozen['product_id'])->toBe($product->id)
        ->and($frozen['rate_minor'])->toBe($price)
        ->and($version->snapshot['totals']['total_minor'])->toBe($price * 4)
        ->and($pdfAfter)->toBe($pdfBefore)
        ->and($pdfAfter)->toContain(e($name))
        ->and($pdfAfter)->not->toContain('Renamed in the catalogue');
});

it('leaves the line as it was when the product is archived, and when it is removed', function () {
    [$product, $code, $price] = pricedProduct();
    $name = $product->name;
    $line = app(QuoteEditor::class)->addProductLine($this->section, $product, $code);

    app(ProductCatalogue::class)->withdraw($product, $this->owner, true);
    expect($product->refresh()->archived_at)->not->toBeNull();

    $line->refresh();
    expect($line->product_id)->toBe($product->id)
        ->and($line->product_name)->toBe($name)
        ->and($line->product_code)->toBe($code)
        ->and($line->rate_minor)->toBe($price);

    // An archived product is not offered for a new line.
    expect(fn () => app(QuoteEditor::class)->addProductLine($this->section, $product->refresh(), $code))
        ->toThrow(InvalidArgumentException::class, 'archived');

    // Nothing in the admin removes a product for good. Should the row ever go, the line loses the link and
    // keeps what it says.
    $product->delete();

    $line->refresh();
    expect($line->product_id)->toBeNull()
        ->and($line->product_name)->toBe($name)
        ->and($line->product_code)->toBe($code)
        ->and($line->rate_minor)->toBe($price);
});

it('carries the product into a revision, a copy and the invoice made from the quotation', function () {
    [$product, $code, $price] = pricedProduct();
    $editor = app(QuoteEditor::class);
    $editor->updateLine($editor->addProductLine($this->section, $product, $code), ['qty' => '2']);

    app(SendQuote::class)->handle($this->quote->refresh(), $this->owner, 'link', false);

    // A revision: the same quotation, editable again.
    $revised = app(ReviseQuote::class)->handle($this->quote->refresh(), $this->owner);
    $line = $revised->items()->sole();
    expect($revised->status)->toBe(QuoteStatus::Draft)
        ->and($line->product_id)->toBe($product->id)
        ->and($line->product_code)->toBe($code)
        ->and($line->rate_minor)->toBe($price);
    app(SendQuote::class)->handle($revised->refresh(), $this->owner, 'link', false);

    // A copy.
    $copy = app(DuplicateQuote::class)->handle($this->quote->refresh(), $this->owner)->items()->sole();
    expect($copy->product_id)->toBe($product->id)
        ->and($copy->product_code)->toBe($code)
        ->and($copy->rate_minor)->toBe($price);

    // The invoice, after the catalogue has moved on: it bills what was sent.
    editProduct($product, ['price' => (string) ($price / 100 + 500), 'sale_price' => '']);
    app(AcceptQuote::class)->handle($this->quote->refresh(), 'Ayesha Khan', null, null, null, $this->owner, null, AcceptanceChannel::Phone);
    $invoice = app(CreateFurnitureInvoice::class)->handle($this->quote->refresh(), $this->owner);
    $billed = $invoice->items()->sole();

    expect($billed->product_id)->toBe($product->id)
        ->and($billed->product_code)->toBe($code)
        ->and($billed->product_name)->toBe($line->product_name)
        ->and($billed->rate_minor)->toBe($price)
        ->and($invoice->total_minor)->toBe($price * 2);
});

it('marks a product without a price as price on request and refuses to send it until a rate is typed', function () {
    [$product, $code] = productOnRequest();

    $builder = Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->call('openProducts', $this->section->id)
        ->set('productSearch', $code)
        ->assertSee('Price on request')
        ->call('addFromProduct', $product->id, $code)
        ->assertSee('No price in the catalogue: type the rate before sending.');

    $line = $this->quote->items()->sole();
    expect($line->price_on_request)->toBeTrue()
        ->and($line->rate_minor)->toBe(0)
        ->and($line->product_code)->toBe($code);

    // The reason is on the screen when sending is asked for, and the quotation stays a draft.
    $reason = '"'.str($product->name)->limit(60).'" is price on request in the catalogue: type its rate before sending.';
    expect(app(SendQuote::class)->problems($this->quote->refresh()))->toContain($reason)
        ->not->toContain('1 line has no quantity or rate.');
    $builder->call('openSend')->assertSee($reason)->call('send')->assertHasErrors('send');
    expect(fn () => app(SendQuote::class)->handle($this->quote->refresh(), $this->owner, 'link', false))->toThrow(ValidationException::class);
    expect($this->quote->refresh()->status)->toBe(QuoteStatus::Draft);

    // An optional line would show the client a price of nothing: it is refused as well.
    app(QuoteEditor::class)->updateLine($line, ['is_optional' => true]);
    expect(app(SendQuote::class)->problems($this->quote->refresh()))->toContain($reason);
    app(QuoteEditor::class)->updateLine($line->refresh(), ['is_optional' => false]);

    // A rate is typed: the mark goes and the quotation can be sent.
    $builder->set("lines.{$line->id}.rate", '18,500')->assertHasNoErrors()->assertDontSee('No price in the catalogue');
    expect($line->refresh()->price_on_request)->toBeFalse()
        ->and($line->rate_minor)->toBe(1_850_000);

    app(SendQuote::class)->handle($this->quote->refresh(), $this->owner, 'link', false);
    expect($this->quote->refresh()->status)->toBe(QuoteStatus::Sent);
});

it('lets nobody add a product who may not edit the quotation', function () {
    [$product, $code] = pricedProduct();

    // An accountant reads quotations and edits none.
    $this->actingAs($this->userWithRole('accountant'));
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])->assertOk()
        ->call('openProducts', $this->section->id)->assertForbidden();
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->call('addFromProduct', $product->id, $code)->assertForbidden();

    expect($this->quote->items()->count())->toBe(0);

    // A sent quotation is locked for everybody, its owner included.
    $this->actingAs($this->owner);
    app(QuoteEditor::class)->addProductLine($this->section, $product, $code);
    app(SendQuote::class)->handle($this->quote->refresh(), $this->owner, 'link', false);

    expect(fn () => app(QuoteEditor::class)->addProductLine($this->section, $product, $code))->toThrow(QuoteLocked::class);
    // The owner passes every permission check, so the lock itself is what refuses: nothing is written.
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote->refresh()])
        ->assertDontSee('Add a product')
        ->call('openProducts', $this->section->id)
        ->call('addFromProduct', $product->id, $code);

    expect($this->quote->items()->count())->toBe(1);
});

it('writes the name into the description where the document prints no product column', function () {
    [$product, $code] = pricedProduct();
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'business_line' => 'interior']);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $this->owner, $lead, null, 'Fit-out');

    $line = app(QuoteEditor::class)->addProductLine($quote->sections()->firstOrFail(), $product, $code);

    expect($line->description)->toBe("{$product->name} · Code {$code}")
        ->and($line->product_name)->toBe($product->name);
});

it('asks the catalogue again when the line is written: a code that is gone is refused', function () {
    [$product] = pricedProduct();

    expect(fn () => app(QuoteEditor::class)->addProductLine($this->section, $product, 'NOT-A-CODE'))
        ->toThrow(InvalidArgumentException::class, 'no longer has the code NOT-A-CODE');

    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->call('openProducts', $this->section->id)
        ->call('addFromProduct', $product->id, 'NOT-A-CODE');

    expect($this->quote->items()->count())->toBe(0);
});
