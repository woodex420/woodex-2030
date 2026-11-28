<?php

declare(strict_types=1);

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Crm\Models\Lead;
use App\Domain\Media\Services\MasterMedia;
use App\Domain\Sales\Models\CatalogItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| A basket that names products this admin published (master list T-191).
|
| The shop reads its products from the admin (T-105) and knows each by its address, the slug. A visitor who puts
| such a product into the quotation basket and sends it must be received here: until this file, the only products
| a basket could name were lines of the rate book that somebody had mapped to the shop's own keys by hand, and a
| product that only the admin has was refused (defect D-060).
|
| What is held here:
|
|   - a published product is a line of an enquiry with its name, its code and its price as published at that
|     moment, whatever the page said; a later publication and a later draft change nothing that was saved;
|   - a draft, a withdrawn, an archived and an unavailable product are refused by the name of the line, and
|     nothing at all is written: no enquiry, no basket, no key;
|   - a product priced on request is taken without a figure, and the estimate says which lines it leaves out;
|   - a product sold in options is asked for by the option's code;
|   - the same basket sent twice is one enquiry;
|   - a line names its product once;
|   - the enquiry's screen shows the product, its code and its price on the line;
|   - a basket the shop's server carried is put to the robot check when the visitor's page made a token;
|   - a visitor is counted by his own address when the shop's server carried his request here.
|
| Tried against (release report 47), twelve changes, each noticed by a test of this file: the draft read in place
| of the published copy; the price taken from the request; the figure 0 written for "on request"; "Rs 0" printed
| as an estimate; an unavailable product accepted; a basket taken while the products are switched off; a product
| in options taken without its option; a token of the page not checked, checked for the wrong address, or asked
| of a basket that has none; the visitor's header read from anybody; the reads counted by the sender's address
| (that one by ReadsCountedByVisitorTest).
*/

beforeEach(function (): void {
    Storage::fake('local');
    config(['woodex.product_sync_enabled' => true]);

    $this->publisher = $this->userWithRole('owner');
    $this->service = app(ProductCatalogue::class);
    $this->category = Category::where('slug', 'executive-tables')->sole();

    $picture = UploadedFile::fake()->image('desk.png', 40, 40);
    Storage::disk('local')->put('commerce/product-media/'.str_repeat('b', 64).'.png', file_get_contents($picture->getRealPath()));

    // A product, saved and published the way an editor does it. Returns the product's own record.
    $this->publish = function (array $more = [], bool $publish = true): Product {
        $this->actingAs($this->publisher);
        $form = array_replace([
            'name' => 'SYNTHETIC Desk', 'slug' => 'synthetic-desk', 'price' => '42500.00',
            'description' => 'A reviewed desk.', 'shipping' => 'Three weeks.', 'warranty' => 'One year.',
            'category_ids' => [$this->category->id], 'primary_category_id' => $this->category->id,
            'availability' => 'available', 'reviewed' => true,
            'variants' => [['sku' => 'ET-10010', 'colour' => '', 'material' => '', 'size' => '', 'price' => '', 'availability' => 'available']],
        ], $more);

        $product = $this->service->save(null, $form, 0, $this->publisher);
        $media = ProductMedia::create(['product_id' => $product->id, 'path' => 'commerce/product-media/'.str_repeat('b', 64).'.png', 'sha256' => str_repeat('b', 64)]);
        app(MasterMedia::class)->register($media);
        $saved = $this->service->save($product->fresh(), [...$form, 'images' => [['id' => $media->id, 'alt' => 'A desk by a window']]], $product->fresh()->version, $this->publisher);

        if ($publish) {
            $this->get(route('admin.commerce.products.preview', $saved->fresh()))->assertOk();
            $this->service->publish($saved->fresh(), $saved->fresh()->version, $this->publisher);
        }
        // What follows is asked by a visitor: nobody is signed in.
        auth()->logout();

        return $saved->fresh();
    };

    // A basket as the shop sends it.
    $this->basket = fn (array $items, array $more = []): array => array_merge([
        'name' => 'Synthetic basket visitor', 'email' => 'published-basket@example.test',
        'consent' => true, 'preferred_channel' => 'email', 'items' => $items,
    ], $more);
    // Each basket arrives from another address unless a test names one: five a minute is the limit of one address,
    // and it counts a refused basket too.
    $this->visitors = 0;
    $this->send = function (array $items, array $more = [], ?array $headers = null, ?string $from = null) {
        $this->withServerVariables(['REMOTE_ADDR' => $from ?? '192.0.2.'.(++$this->visitors)]);

        return $this->postJson('/api/v1/quote-requests', ($this->basket)($items, $more), $headers ?? idempotencyKey());
    };
    $this->saved = fn (): array => array_map(fn (object $row): array => json_decode($row->lines, true), DB::table('storefront_quote_requests')->orderBy('id')->get()->all());
});

it('saves a published product as a line of an enquiry, with its name, its code and its price as published', function (): void {
    $product = ($this->publish)();
    // The editor keeps working after the publication, and publishes none of it.
    $this->service->save($product, [...$product->draft, 'name' => 'A name nobody published', 'price' => '99999.00'], $product->fresh()->version, $this->publisher);

    // The page says what it likes about the name and the price: neither is read.
    $answer = ($this->send)([['slug' => 'synthetic-desk', 'qty' => 2, 'price' => 1, 'name' => 'tampered', 'line_total_minor' => 2]], ['total' => 1])
        ->assertCreated()->assertJsonPath('data.status', 'pending_review')->assertHeader('Cache-Control', 'no-store, private');

    $row = DB::table('storefront_quote_requests')->sole();
    $line = json_decode($row->lines, true)[0];

    expect($row->reference)->toBe($answer->json('data.reference'))
        ->and($line['slug'])->toBe('synthetic-desk')
        ->and($line['description'])->toBe('SYNTHETIC Desk')
        ->and($line['sku'])->toBe('ET-10010')
        ->and($line['rate_minor'])->toBe(4250000)
        ->and($line['line_total_minor'])->toBe(8500000)
        ->and($line['qty'])->toBe(2)
        ->and($line['revision'])->toBe($product->fresh()->published_revision_id)
        ->and((int) $row->estimated_total_minor)->toBe(8500000)
        ->and($row->currency)->toBe('PKR')
        ->and(Lead::query()->sole()->business_line->value)->toBe('furniture')
        // A basket is a question, not a document: no quotation is made of it.
        ->and(DB::table('quotes')->count())->toBe(0);
});

it('keeps the price of the moment the basket was sent, whatever is published afterwards', function (): void {
    $product = ($this->publish)();
    ($this->send)([['slug' => 'synthetic-desk', 'qty' => 1]])->assertCreated();

    // Published again at another price and under another name.
    $this->actingAs($this->publisher);
    $changed = $this->service->save($product->fresh(), [...$product->fresh()->draft, 'name' => 'SYNTHETIC Desk II', 'price' => '39000.00', 'reviewed' => true], $product->fresh()->version, $this->publisher);
    $this->get(route('admin.commerce.products.preview', $changed->fresh()))->assertOk();
    $this->service->publish($changed->fresh(), $changed->fresh()->version, $this->publisher);
    auth()->logout();

    ($this->send)([['slug' => 'synthetic-desk', 'qty' => 1]], ['email' => 'second-visitor@example.test'])->assertCreated();

    [$before, $after] = ($this->saved)();
    expect($before[0]['rate_minor'])->toBe(4250000)->and($before[0]['description'])->toBe('SYNTHETIC Desk')
        ->and($after[0]['rate_minor'])->toBe(3900000)->and($after[0]['description'])->toBe('SYNTHETIC Desk II')
        ->and($after[0]['revision'])->toBeGreaterThan($before[0]['revision']);
});

it('refuses a draft, a withdrawn, an archived and an unavailable product by the name of the line, and writes nothing', function (): void {
    ($this->publish)(['slug' => 'kept-desk', 'variants' => []]);
    ($this->publish)(['slug' => 'draft-desk', 'variants' => []], publish: false);
    $withdrawn = ($this->publish)(['slug' => 'withdrawn-desk', 'variants' => []]);
    $archived = ($this->publish)(['slug' => 'archived-desk', 'variants' => []]);
    ($this->publish)(['slug' => 'unavailable-desk', 'availability' => 'unavailable', 'variants' => []]);
    $this->service->withdraw($withdrawn, $this->publisher);
    $this->service->withdraw($archived, $this->publisher, archive: true);

    foreach (['draft-desk', 'withdrawn-desk', 'archived-desk', 'unavailable-desk', 'no-such-desk'] as $slug) {
        // The good line stands first: the refusal names the second line, and the first is not saved either.
        $refused = ($this->send)([['slug' => 'kept-desk', 'qty' => 1], ['slug' => $slug, 'qty' => 1]])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        expect(array_keys($refused->json('error.details')))->toBe(['items.1.slug'], $slug)
            ->and($refused->json('error.details')['items.1.slug'][0])->toBe('This product is not offered at the moment. Please refresh your basket or contact the team.');
    }

    // Nothing is left of a request that was refused: no enquiry, no basket, and no key.
    expect(Lead::count())->toBe(0)
        ->and(DB::table('storefront_quote_requests')->count())->toBe(0)
        ->and(DB::table('api_idempotency_keys')->count())->toBe(0);

    // And with the products switched off for the shop, none of them can be asked for: not even the published one.
    config(['woodex.product_sync_enabled' => false]);
    ($this->send)([['slug' => 'kept-desk', 'qty' => 1]])->assertStatus(422);
    expect(Lead::count())->toBe(0);
});

it('takes a product priced on request without a figure, and never as a price of zero', function (): void {
    ($this->publish)(['slug' => 'ask-desk', 'name' => 'SYNTHETIC Ask Desk', 'price' => '', 'price_on_request' => true,
        'variants' => [['sku' => 'ASK-10020', 'colour' => '', 'material' => '', 'size' => '', 'price' => '', 'availability' => 'on_request']]]);
    ($this->publish)(['slug' => 'priced-desk', 'variants' => []]);

    ($this->send)([['slug' => 'ask-desk', 'qty' => 3], ['slug' => 'priced-desk', 'qty' => 1]])->assertCreated();

    $row = DB::table('storefront_quote_requests')->sole();
    [$asked, $priced] = json_decode($row->lines, true);

    expect($asked['rate_minor'])->toBeNull()
        ->and($asked['line_total_minor'])->toBeNull()
        ->and($asked['sku'])->toBe('ASK-10020')
        ->and($asked['qty'])->toBe(3)
        ->and($priced['rate_minor'])->toBe(4250000)
        // The estimate adds up what has a figure, and nothing else.
        ->and((int) $row->estimated_total_minor)->toBe(4250000);

    // On the enquiry's screen: the words, no figure for that line, and the estimate says what it leaves out.
    $lead = Lead::query()->sole();
    $screen = $this->actingAs($this->publisher)->get(route('admin.leads.show', $lead))->assertOk();
    $screen->assertSee('SYNTHETIC Ask Desk')->assertSee('[ASK-10020]')->assertSee('price on request')
        ->assertSee('Rs 42,500')->assertSee('Saved catalogue estimate: Rs 42,500, without the line priced on request', false)
        // No figure of nothing anywhere in the card: not for the line, and not as its total.
        ->assertDontSee('× Rs 0')->assertDontSee('= Rs 0');

    // A basket in which nothing has a figure has no estimate at all: "Rs 0" would read as free.
    ($this->send)([['slug' => 'ask-desk', 'qty' => 2]], ['name' => 'Second synthetic visitor', 'email' => 'second-visitor@example.test'])->assertCreated();
    $second = Lead::query()->latest('id')->firstOrFail();
    expect($second->id)->not->toBe($lead->id)
        ->and((int) DB::table('storefront_quote_requests')->where('lead_id', $second->id)->value('estimated_total_minor'))->toBe(0);
    $this->actingAs($this->publisher)->get(route('admin.leads.show', $second))->assertOk()
        ->assertSee('2 each × price on request', false)
        ->assertSee('No catalogue estimate: the line is priced on request', false)
        ->assertDontSee('Saved catalogue estimate')->assertDontSee('× Rs 0')->assertDontSee('= Rs 0');
});

it('asks for a product sold in options by the code of the option', function (): void {
    ($this->publish)(['slug' => 'two-finish-desk', 'name' => 'SYNTHETIC Two Finish', 'price' => '90000',
        'variants' => [
            ['sku' => 'TF-OAK', 'colour' => 'Oak', 'material' => 'Veneer', 'size' => '', 'price' => '95000', 'availability' => 'available'],
            ['sku' => 'TF-ASH', 'colour' => 'Ash', 'material' => '', 'size' => '', 'price' => '', 'availability' => 'on_request'],
            ['sku' => 'TF-GONE', 'colour' => 'Walnut', 'material' => '', 'size' => '', 'price' => '', 'availability' => 'unavailable'],
        ]]);
    ($this->publish)(['slug' => 'one-form-desk', 'name' => 'SYNTHETIC One Form', 'price' => '130000']);

    // Two options of one product are two lines; a product sold in one form may name its own code, or none.
    ($this->send)([
        ['slug' => 'two-finish-desk', 'sku' => 'TF-OAK', 'qty' => 1],
        ['slug' => 'two-finish-desk', 'sku' => 'TF-ASH', 'qty' => 2],
        ['slug' => 'one-form-desk', 'sku' => 'ET-10010', 'qty' => 1],
    ])->assertCreated();

    [[$oak, $ash, $one]] = ($this->saved)();
    expect($oak['description'])->toBe('SYNTHETIC Two Finish — Oak · Veneer')->and($oak['sku'])->toBe('TF-OAK')->and($oak['rate_minor'])->toBe(9500000)
        // An option without a price of its own is sold at the product's price.
        ->and($ash['description'])->toBe('SYNTHETIC Two Finish — Ash')->and($ash['rate_minor'])->toBe(9000000)->and($ash['line_total_minor'])->toBe(18000000)
        ->and($one['description'])->toBe('SYNTHETIC One Form')->and($one['sku'])->toBe('ET-10010')->and($one['option'])->toBe('');

    $refusals = [
        'no option named' => [['slug' => 'two-finish-desk', 'qty' => 1], 'This product is sold in options. Choose one on its page and add it to the basket again.'],
        'an option it does not have' => [['slug' => 'two-finish-desk', 'sku' => 'TF-PINE', 'qty' => 1], 'The option chosen is not offered any more. Choose one on the product\'s page and add it to the basket again.'],
        'an option marked unavailable' => [['slug' => 'two-finish-desk', 'sku' => 'TF-GONE', 'qty' => 1], 'The option chosen is not offered any more. Choose one on the product\'s page and add it to the basket again.'],
        'an option of a product sold in one form' => [['slug' => 'one-form-desk', 'sku' => 'TF-OAK', 'qty' => 1], 'The option chosen is not offered any more. Add the product to the basket again from its page.'],
    ];
    foreach ($refusals as $case => [$line, $why]) {
        $refused = ($this->send)([$line], ['email' => 'another-visitor@example.test'])->assertStatus(422);
        expect($refused->json('error.details'))->toBe(['items.0.slug' => [$why]], $case);
    }

    expect(DB::table('storefront_quote_requests')->count())->toBe(1);
});

it('writes the same basket once, however often it is sent with its key', function (): void {
    $product = ($this->publish)();
    $key = idempotencyKey();
    $items = [['slug' => 'synthetic-desk', 'sku' => null, 'qty' => 2]];

    $first = ($this->send)($items, [], $key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    // Withdrawn in between: the second sending is answered as the first was, and is not priced again.
    $this->service->withdraw($product->fresh(), $this->publisher);
    $again = ($this->send)($items, ['turnstile_token' => 'a-new-token'], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data'))->toBe($first->json('data'))
        ->and(DB::table('storefront_quote_requests')->count())->toBe(1)
        ->and(Lead::count())->toBe(1);

    // Another quantity under the same key is another basket: refused, and the first one stands.
    ($this->send)([['slug' => 'synthetic-desk', 'sku' => null, 'qty' => 3]], [], $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    expect(($this->saved)()[0][0]['qty'])->toBe(2);
});

it('lets a line name its product once: by its address or by the shop\'s own key, never both and never neither', function (): void {
    ($this->publish)();
    CatalogItem::create(['description' => 'SYNTHETIC Rate book desk', 'category' => 'Synthetic', 'unit' => 'each',
        'rate_minor' => 123456, 'business_line' => 'furniture', 'is_active' => true, 'storefront_product_id' => 'synthetic-desk']);
    $once = 'Name the product once: by its address (slug), or by the shop\'s own key (product_id).';

    $both = ($this->send)([['slug' => 'synthetic-desk', 'product_id' => 'synthetic-desk', 'qty' => 1]])->assertStatus(422);
    expect($both->json('error.details')['items.0.product_id'] ?? [])->toContain($once);

    $neither = ($this->send)([['qty' => 1]])->assertStatus(422);
    expect($neither->json('error.details')['items.0.slug'] ?? [])->toContain($once)
        ->and($neither->json('error.details')['items.0.product_id'] ?? [])->toContain($once);

    // An option belongs to an address: beside the shop's own key it is refused, not ignored.
    ($this->send)([['product_id' => 'synthetic-desk', 'sku' => 'ET-10010', 'qty' => 1]])->assertStatus(422);
    // An address is small letters, figures and hyphens: nothing else is looked up.
    ($this->send)([['slug' => 'Synthetic-Desk', 'qty' => 1]])->assertStatus(422)->assertJsonPath('error.details', ['items.0.slug' => ['This is not the address of a product.']]);
    ($this->send)([['slug' => '../admin', 'qty' => 1]])->assertStatus(422);

    // The same product in the same option twice: one line is what it should be.
    $twice = ($this->send)([['slug' => 'synthetic-desk', 'qty' => 1], ['slug' => 'synthetic-desk', 'qty' => 4]])->assertStatus(422);
    expect($twice->json('error.details'))->toBe(['items.1.slug' => ['This product stands in the basket twice. Put the two lines together.']]);

    expect(Lead::count())->toBe(0)->and(DB::table('storefront_quote_requests')->count())->toBe(0);

    // The two ways stand side by side in one basket, each read where it belongs: the same word names the
    // published product in the first line and the line of the rate book in the second.
    ($this->send)([['slug' => 'synthetic-desk', 'qty' => 1], ['product_id' => 'synthetic-desk', 'qty' => 1]])->assertCreated();
    [[$published, $mapped]] = ($this->saved)();
    expect($published['rate_minor'])->toBe(4250000)->and($published['description'])->toBe('SYNTHETIC Desk')
        ->and($mapped['rate_minor'])->toBe(123456)->and($mapped['description'])->toBe('SYNTHETIC Rate book desk');
});

it('shows the product, its code and its price on the line of the enquiry, to those who may read the enquiry', function (): void {
    ($this->publish)();
    $answer = ($this->send)([['slug' => 'synthetic-desk', 'qty' => 2]])->assertCreated();

    $lead = Lead::query()->sole();
    $owner = $this->userWithRole('sales');
    $lead->update(['owner_id' => $owner->id]);

    $this->actingAs($owner)->get(route('admin.leads.show', $lead))->assertOk()
        ->assertSee($answer->json('data.reference'))
        ->assertSee('SYNTHETIC Desk')->assertSee('[ET-10010]')
        ->assertSee('× Rs 42,500 = Rs 85,000', false)->assertSee('Saved catalogue estimate: Rs 85,000 ·', false)
        ->assertDontSee('price on request');
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.leads.show', $lead))->assertForbidden();
});

it('puts a basket the shop\'s server carried to the robot check when the visitor\'s page made a token, and not otherwise', function (): void {
    ($this->publish)();
    config(['woodex.public_leads.bridge_key' => 'synthetic-bridge-key-of-thirty-two-chars', 'services.turnstile.secret_key' => 'synthetic-secret']);
    $line = [['slug' => 'synthetic-desk', 'qty' => 1]];
    $carried = fn (): array => [...idempotencyKey(), 'X-Woodex-Key' => 'synthetic-bridge-key-of-thirty-two-chars', 'X-Woodex-Visitor' => '203.0.113.40'];

    // What the check's own service says, set for each part of this test; null stands for no answer at all.
    $says = ['success' => false];
    Http::fake(function () use (&$says) {
        if ($says === null) {
            throw new ConnectionException('synthetic outage');
        }

        return Http::response($says);
    });

    // No token: the shop's server vouches for its visitor, and the check's service is not asked at all.
    ($this->send)($line, ['email' => 'no-token@example.test'], $carried(), '198.51.100.200')->assertCreated();
    Http::assertNothingSent();

    // A token the check does not accept: refused by the name of the field, and nothing is written.
    ($this->send)($line, ['email' => 'bad-token@example.test', 'turnstile_token' => 'not-a-real-token'], $carried(), '198.51.100.200')
        ->assertUnprocessable()->assertJsonPath('error.details.turnstile_token.0', 'The spam check failed. Please refresh and try again.');
    expect(DB::table('storefront_quote_requests')->count())->toBe(1)
        ->and(DB::table('contacts')->where('email', 'bad-token@example.test')->exists())->toBeFalse();

    // A token it accepts: taken, and the check was asked about the visitor, not about the shop's server.
    $says = ['success' => true];
    ($this->send)($line, ['email' => 'good-token@example.test', 'turnstile_token' => 'a-token-of-the-page'], $carried(), '198.51.100.200')->assertCreated();
    Http::assertSent(fn ($asked): bool => $asked['response'] === 'a-token-of-the-page' && $asked['remoteip'] === '203.0.113.40' && $asked['secret'] === 'synthetic-secret');

    // The check's own service does not answer: a basket the shop's server vouches for is taken.
    $says = null;
    ($this->send)($line, ['email' => 'outage@example.test', 'turnstile_token' => 'a-token-of-the-page'], $carried(), '198.51.100.200')->assertCreated();
    expect(DB::table('storefront_quote_requests')->count())->toBe(3);

    // Without the key nothing changed: no token, no basket; and an outage of the service is a refusal.
    ($this->send)($line, ['email' => 'no-key@example.test'], idempotencyKey(), '198.51.100.201')->assertUnprocessable();
    ($this->send)($line, ['email' => 'no-key@example.test', 'turnstile_token' => 'a-token-of-the-page'], idempotencyKey(), '198.51.100.202')->assertUnprocessable();
});

it('counts a visitor by his own address when the shop\'s server carried his request here, and only then', function (): void {
    ($this->publish)();
    config(['woodex.public_leads.bridge_key' => 'synthetic-bridge-key-of-thirty-two-chars']);
    $line = [['slug' => 'synthetic-desk', 'qty' => 1]];
    $from = fn (string $visitor, string $key = 'synthetic-bridge-key-of-thirty-two-chars'): array => [...idempotencyKey(), 'X-Woodex-Key' => $key, 'X-Woodex-Visitor' => $visitor];
    // Every request of this test arrives from one address: the shop's server.
    $shop = '198.51.100.200';

    // Five baskets a minute from one visitor; the sixth is told to wait.
    foreach (range(1, 5) as $n) {
        ($this->send)($line, ['email' => "first-visitor-$n@example.test"], $from('203.0.113.10'), $shop)->assertCreated();
    }
    ($this->send)($line, [], $from('203.0.113.10'), $shop)->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    // Another visitor, through the same server: his own count.
    ($this->send)($line, ['email' => 'other-visitor@example.test'], $from('203.0.113.11'), $shop)->assertCreated();
    // What is not an address is not counted as one: such a request is counted with the server that sent it.
    ($this->send)($line, ['email' => 'no-address@example.test'], $from('not-an-address'), $shop)->assertCreated();

    // Without the key, or with a wrong one, the header is not read: whoever writes it is counted by the address
    // he really has. (That address has sent one basket so far, the one above.)
    foreach (range(1, 4) as $n) {
        ($this->send)($line, ['email' => "plain-visitor-$n@example.test"], $from("198.51.100.$n", 'a-wrong-key'), $shop)->assertCreated();
    }
    ($this->send)($line, [], $from('198.51.100.99', 'a-wrong-key'), $shop)->assertStatus(429);
    ($this->send)($line, [], [...idempotencyKey(), 'X-Woodex-Visitor' => '198.51.100.77'], $shop)->assertStatus(429);

    // And while no key is set here, nobody is vouched for: a key that is sent proves nothing.
    config(['woodex.public_leads.bridge_key' => '']);
    ($this->send)($line, [], [...idempotencyKey(), 'X-Woodex-Key' => '', 'X-Woodex-Visitor' => '198.51.100.78'], $shop)->assertStatus(429);
});
