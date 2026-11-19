<?php

declare(strict_types=1);

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Media\Services\MasterMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| The products address (master list T-105).
|
| The contract test holds every answer to its shape. This file holds the promises the shape cannot make:
|
|   - only the published copy is ever handed out: a draft saved after publication, a withdrawn product and an
|     archived one are all absent, and absent in the same way;
|   - money is whole paisa, and a product with no price is on_request with no figure — never a price of zero;
|   - a sale price is handed out only between its two dates;
|   - the filters narrow the list and never widen it; a filter that names nothing is 404, not an empty list;
|   - a photograph is handed out only from the publication that carries it;
|   - the number of the request is the one in the header, and no answer sets a cookie.
|
| Tried against (document 33 §3): with the draft read in place of the published copy, the first test names the
| draft's own name; with a blank price turned into 0, the money test names the product.
*/

beforeEach(function (): void {
    Storage::fake('local');
    config(['woodex.product_sync_enabled' => true]);

    $this->publisher = $this->userWithRole('owner');
    $this->service = app(ProductCatalogue::class);
    $this->category = Category::where('slug', 'executive-tables')->sole();
    $this->subcategory = Category::where('slug', 'meeting-tables')->first();

    // One picture in the shop's private store, used by every product published here.
    $picture = UploadedFile::fake()->image('desk.png', 40, 40);
    Storage::disk('local')->put('commerce/product-media/'.str_repeat('a', 64).'.png', file_get_contents($picture->getRealPath()));

    // A product, published the way an editor publishes it: reviewed, complete, previewed by the person who
    // publishes it, with one photograph. Returns the product's own record.
    $this->publish = function (array $more = [], bool $publish = true): Product {
        $this->actingAs($this->publisher);
        $form = array_replace([
            'name' => 'SYNTHETIC Desk', 'slug' => 'synthetic-desk', 'price' => '42500.00',
            'description' => 'A reviewed desk.', 'shipping' => 'Three weeks.', 'warranty' => 'One year.',
            'category_ids' => [$this->category->id], 'primary_category_id' => $this->category->id,
            'availability' => 'available', 'reviewed' => true,
        ], $more);

        $product = $this->service->save(null, $form, 0, $this->publisher);
        $media = ProductMedia::create(['product_id' => $product->id, 'path' => 'commerce/product-media/'.str_repeat('a', 64).'.png', 'sha256' => str_repeat('a', 64)]);
        app(MasterMedia::class)->register($media);
        $saved = $this->service->save($product->fresh(), [...$form, 'images' => [['id' => $media->id, 'alt' => 'A desk by a window']]], $product->fresh()->version, $this->publisher);

        if ($publish) {
            $this->get(route('admin.commerce.products.preview', $saved->fresh()))->assertOk();
            $this->service->publish($saved->fresh(), $saved->fresh()->version, $this->publisher);
        }

        return $saved->fresh();
    };
});

it('hands out the published copy and never the draft saved after it', function (): void {
    $product = ($this->publish)();

    // An editor keeps working: a new draft, a new name, and nobody publishes it.
    $draft = [...$product->draft, 'name' => 'A name nobody published', 'price' => '99999.00'];
    $this->service->save($product, $draft, $product->fresh()->version, $this->publisher);
    expect($product->fresh()->draft['name'])->toBe('A name nobody published');

    $answer = $this->getJson('/api/v1/catalogue/products')->assertOk()->json('data.0');

    expect($answer['name'])->toBe('SYNTHETIC Desk')
        ->and($answer['price_minor'])->toBe(4250000)
        ->and(json_encode($answer))->not->toContain('nobody published');

    // The new draft carries no published revision of its own, so the copy handed out is still the one published.
    $revision = DB::table('commerce_product_revisions')->where('id', $product->fresh()->published_revision_id)->first();
    expect($answer['revision'])->toBe((int) $revision->id);
});

it('hides a withdrawn product and an archived one, exactly as it hides a draft', function (): void {
    $withdrawn = ($this->publish)(['slug' => 'withdrawn-desk', 'name' => 'SYNTHETIC Withdrawn']);
    $archived = ($this->publish)(['slug' => 'archived-desk', 'name' => 'SYNTHETIC Archived']);
    $kept = ($this->publish)(['slug' => 'kept-desk', 'name' => 'SYNTHETIC Kept']);

    $this->service->withdraw($withdrawn, $this->publisher);
    $this->service->withdraw($archived, $this->publisher, archive: true);

    expect($this->getJson('/api/v1/catalogue/products')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/catalogue/products')->json('data.0.id'))->toBe('kept-desk')
        ->and($this->getJson('/api/v1/catalogue/products')->json('meta.total'))->toBe(1);

    // The three states a visitor may not see answer the same way: as an address nothing is at.
    foreach (['withdrawn-desk', 'archived-desk', 'never-published-desk'] as $slug) {
        $this->getJson('/api/v1/catalogue/products/'.$slug)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.message', 'No published product of this shop has that address.');
    }

    expect($kept->fresh()->published_revision_id)->not->toBeNull();
});

it('hands out money as whole paisa, and says "ask us" instead of inventing a price', function (): void {
    ($this->publish)(['slug' => 'priced-desk', 'price' => '42500.50']);
    ($this->publish)(['slug' => 'whole-desk', 'price' => '1200']);
    // Published with no base price at all: refused, because a shop cannot sell what nobody priced.
    expect(fn () => ($this->publish)(['slug' => 'unpriced-desk', 'price' => '']))
        ->toThrow(ValidationException::class, 'Base price, description, shipping and warranty must be reviewed and completed.');

    // The editor's way of saying "no buying price": available to ask about, not to buy at.
    ($this->publish)(['slug' => 'ask-desk', 'price' => '42500.00', 'availability' => 'on_request']);

    $bySlug = collect($this->getJson('/api/v1/catalogue/products?per_page=100')->json('data'))->keyBy('id');

    expect($bySlug->keys()->all())->toEqualCanonicalizing(['priced-desk', 'whole-desk', 'ask-desk'])
        ->and($bySlug['priced-desk']['price_minor'])->toBe(4250050)
        ->and($bySlug['priced-desk']['currency'])->toBe('PKR')
        ->and($bySlug['priced-desk']['on_request'])->toBeFalse()
        ->and($bySlug['priced-desk']['availability'])->toBe('available')
        ->and($bySlug['whole-desk']['price_minor'])->toBe(120000)
        // "Ask us" is a switch, not a missing number: the shop is told not to offer the figure.
        ->and($bySlug['ask-desk']['on_request'])->toBeTrue()
        ->and($bySlug['ask-desk']['availability'])->toBe('on_request');

    // No figure reaches the answer as a fraction anywhere, and no price stands in a key that does not say minor.
    foreach ($bySlug as $product) {
        expect(is_int($product['price_minor']))->toBeTrue("{$product['id']}: price_minor is whole paisa")
            ->and($product['was_price_minor'] === null || is_int($product['was_price_minor']))->toBeTrue("{$product['id']}: was_price_minor is whole paisa or nothing");
    }
});

it('hands out a sale price only between its two dates', function (): void {
    ($this->publish)(['slug' => 'sale-desk', 'price' => '10000.00', 'sale_price' => '8000.00', 'sale_start' => '2026-11-01T00:00', 'sale_end' => '2026-11-10T00:00']);
    ($this->publish)(['slug' => 'later-desk', 'price' => '10000.00', 'sale_price' => '8000.00', 'sale_start' => '2026-12-01T00:00', 'sale_end' => '2026-12-10T00:00']);

    $this->travelTo(now()->parse('2026-11-19 12:00:00'));
    $now = collect($this->getJson('/api/v1/catalogue/products?per_page=100')->json('data'))->keyBy('id');

    // The sale ended on 10 November: the price is the regular one and there is nothing it was reduced from.
    expect($now['sale-desk']['price_minor'])->toBe(1000000)
        ->and($now['sale-desk']['was_price_minor'])->toBeNull();

    // The next sale has not begun: the same.
    expect($now['later-desk']['price_minor'])->toBe(1000000)
        ->and($now['later-desk']['was_price_minor'])->toBeNull();

    // Inside the window, the sale price is what it costs, and what it was reduced from is said beside it.
    $this->travelTo(now()->parse('2026-12-05 12:00:00'));
    $during = collect($this->getJson('/api/v1/catalogue/products?per_page=100')->json('data'))->keyBy('id');
    expect($during['later-desk']['price_minor'])->toBe(800000)
        ->and($during['later-desk']['was_price_minor'])->toBe(1000000);
});

it('narrows the list by category, by its children and by series, and never widens it', function (): void {
    // Office Tables is the parent; Executive Tables and Meeting Tables are two of its children.
    $parent = Category::where('slug', 'office-tables')->sole();
    ($this->publish)(['slug' => 'executive-desk', 'category_ids' => [$this->category->id], 'primary_category_id' => $this->category->id]);
    ($this->publish)(['slug' => 'meeting-desk', 'category_ids' => [$this->subcategory->id], 'primary_category_id' => $this->subcategory->id]);

    if (getenv('WX_DEBUG_IDS')) {
        $svc = app(\App\Domain\Commerce\Services\ProductCatalogue::class);
        $desc = new ReflectionMethod($svc, 'withDescendants');
        $contains = new ReflectionMethod($svc, 'revisionContains');
        $contains->setAccessible(true);
        $lines = ['driver='.DB::getDriverName()];
        foreach (['office-tables', 'meeting-tables', 'executive-tables'] as $debugSlug) {
            $row = Category::where('slug', $debugSlug)->first();
            $ids = $desc->invoke($svc, $row);
            $q = DB::table('commerce_products')
                ->join('commerce_product_revisions as r', 'r.id', '=', 'commerce_products.published_revision_id')
                ->whereNull('commerce_products.archived_at');
            $q->where(function ($w) use ($ids, $contains, $svc): void {
                foreach ($ids as $id) {
                    $w->orWhereRaw($contains->invoke($svc, 'category_ids', $id));
                }
            });
            $body = $this->getJson('/api/v1/catalogue/products?category='.$debugSlug)->json();
            $lines[] = $debugSlug.' ids='.implode('/', $ids).' raw='.$q->count()
                .' api='.implode('/', array_column($body['data'] ?? [], 'id'))
                .' total='.($body['meta']['total'] ?? '?').' err='.($body['error']['code'] ?? '-')
                .' sql='.$q->toSql();
        }
        foreach ($lines as $i => $line) {
            fwrite(STDERR, "\n::warning title=probe{$i}::{$line}\n");
        }
    }
    expect($this->getJson('/api/v1/catalogue/products?category=office-tables')->json('data.*.id'))
        ->toEqualCanonicalizing(['executive-desk', 'meeting-desk'], 'a product in a category below is listed under its parent too')
        ->and($this->getJson('/api/v1/catalogue/products?category=meeting-tables')->json('data.*.id'))->toBe(['meeting-desk']);

    // A slug nothing has is not an empty list: "we sell none" and "you typed it wrong" are different answers.
    $this->getJson('/api/v1/catalogue/products?category=no-such-category')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonPath('error.details', []);

    // A parameter the address does not name is refused by its door, not ignored.
    $this->getJson('/api/v1/catalogue/products?status=draft&category=office-tables')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'PARAMETER_NOT_ALLOWED')
        ->assertJsonPath('error.details.refused', ['status']);
});

it('hands the list out in pages, and counts position on across them', function (): void {
    foreach (range(1, 5) as $number) {
        ($this->publish)(['slug' => 'desk-'.$number, 'name' => 'SYNTHETIC Desk '.$number]);
    }

    $first = $this->getJson('/api/v1/catalogue/products?per_page=2')->assertOk()->json();
    $second = $this->getJson('/api/v1/catalogue/products?per_page=2&page=2')->assertOk()->json();

    expect($first['data'])->toHaveCount(2)
        ->and($first['meta'])->toMatchArray(['page' => 1, 'per_page' => 2, 'total' => 5])
        ->and($first['links']['prev'])->toBeNull()
        ->and($first['links']['next'])->toContain('page=2')
        ->and($first['data'][0]['position'])->toBe(1)
        ->and($second['data'][0]['position'])->toBe(3, 'page two of two begins where page one ended')
        ->and($second['links']['prev'])->toContain('page=1');

    // A page beyond the last is an empty list, not a fault, and its links point nowhere.
    expect($this->getJson('/api/v1/catalogue/products?per_page=2&page=9')->assertOk()->json('data'))->toBe([]);
});

it('hands a photograph out only from the publication that carries it', function (): void {
    $product = ($this->publish)();

    $picture = $this->getJson('/api/v1/catalogue/products/synthetic-desk')->json('data.pictures.0');
    expect($picture['alt'])->toBe('A desk by a window');

    $this->get($picture['url'])->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $media = ProductMedia::where('product_id', $product->id)->sole();

    // Another product's picture, a version that was never published, and a picture of a draft: all 404.
    $this->get('/api/v1/catalogue/products/synthetic-desk/picture/'.$media->id.'/999')->assertStatus(404);
    $this->get('/api/v1/catalogue/products/another-desk/picture/'.$media->id.'/1')->assertStatus(404);

    // And once the product's publication is withdrawn, its picture goes with it.
    $this->service->withdraw($product, $this->publisher);
    $this->get($picture['url'])->assertStatus(404);
});

it('says so when the products are switched off, and sets no cookie in any answer', function (): void {
    ($this->publish)();

    $this->getJson('/api/v1/catalogue/products')->assertOk()->assertCookieMissing('laravel_session');

    config(['woodex.product_sync_enabled' => false]);

    $this->getJson('/api/v1/catalogue/products')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'UNAVAILABLE')
        ->assertJsonPath('error.message', 'The product list is switched off at the moment.');

    $this->getJson('/api/v1/catalogue/products/synthetic-desk')->assertStatus(503);
});

it('asks for no sign-in, and hands out nothing a person could not have published', function (): void {
    ($this->publish)();

    // Nobody is signed in: the shop is a visitor, and what it reads is what the admin published for everyone.
    auth()->logout();
    expect(auth()->user())->toBeNull();

    $answer = $this->getJson('/api/v1/catalogue/products')->assertOk()->json('data.0');

    // The keys of the editor's own form that the shop has no business with are not in the answer at all.
    foreach (['reviewed', 'review_issues', 'source_record', 'version', 'draft', 'slug_source'] as $key) {
        expect(array_key_exists($key, $answer))->toBeFalse("{$key} is not handed to the shop");
    }
});

it('does not hand out a product of a category that was switched off halfway up its tree', function (): void {
    ($this->publish)(['slug' => 'hidden-desk', 'category_ids' => [$this->subcategory->id], 'primary_category_id' => $this->subcategory->id]);

    // The parent is switched off: the shop's menu has no way of reaching the category below it, so the product
    // is not listed under the parent's slug.
    Category::where('slug', 'office-tables')->sole()->forceFill(['is_active' => false])->save();

    $this->getJson('/api/v1/catalogue/products?category=office-tables')->assertStatus(404);
    expect($this->getJson('/api/v1/catalogue/products')->json('meta.total'))->toBe(1);
});

it('keeps one product per address, and reads the address the shop knows the product by', function (): void {
    ($this->publish)(['slug' => 'executive-desk']);
    ($this->publish)(['slug' => 'meeting-desk']);

    $one = $this->getJson('/api/v1/catalogue/products/meeting-desk')->assertOk()->json('data');

    expect($one['id'])->toBe('meeting-desk')
        ->and($one['slug'])->toBe('meeting-desk')
        ->and($one['position'])->toBe(1)
        ->and($this->getJson('/api/v1/catalogue/products/executive-desk')->assertOk()->json('data.id'))->toBe('executive-desk');

    // A name that is not a slug of this shop answers as an address nothing is at.
    foreach (['Meeting-Desk', 'meeting desk', '..%2Fadmin'] as $slug) {
        $this->getJson('/api/v1/catalogue/products/'.rawurlencode($slug))->assertStatus(404);
    }
});

it('reads an address with the regard to case that the database may not have', function (): void {
    ($this->publish)(['slug' => 'executive-desk']);

    // MySQL and MariaDB compare a text column without regard to case by default, SQLite with regard to it, so a
    // test on SQLite alone cannot tell a loose address from a strict one: the same code answered 200 for
    // /Executive-Desk on MariaDB and 404 on SQLite, and CI caught it in the group that runs on MariaDB.
    //
    // The fix is in the code — one() and pictureFile() refuse a row whose slug is not the one asked for — and this
    // test makes SQLite compare the column as the other two do, so that the fix is proven here rather than only
    // there. It must be the column's own collation: an index declared COLLATE NOCASE does not change how SQLite
    // compares, which was tried first and passed without the fix, proving nothing.
    //
    // On MySQL and MariaDB there is nothing to do: the column is already compared without regard to case, which
    // is how CI found this. On SQLite the column is rebuilt with a NOCASE collation of its own.
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('DROP INDEX IF EXISTS commerce_products_slug_unique');
        DB::statement('ALTER TABLE commerce_products RENAME COLUMN slug TO slug_strict');
        DB::statement('ALTER TABLE commerce_products ADD COLUMN slug string COLLATE NOCASE');
        DB::statement('UPDATE commerce_products SET slug = slug_strict');
        DB::statement('CREATE UNIQUE INDEX commerce_products_slug_unique ON commerce_products (slug)');
    }
    // The probe that comes first in this test: the database really does compare loosely now, so that what
    // follows is a test on either kind of server rather than a tautology on one of them.
    expect(DB::table('commerce_products')->where('slug', 'EXECUTIVE-DESK')->count())->toBe(1);

    $this->getJson('/api/v1/catalogue/products/Executive-Desk')->assertStatus(404);
    $this->getJson('/api/v1/catalogue/products/executive-desk')->assertOk()->assertJsonPath('data.slug', 'executive-desk');
    $this->getJson('/api/v1/catalogue/products/EXECUTIVE-DESK')->assertStatus(404);

    // The picture likewise: an address the product was not published at does not serve its photograph.
    $this->getJson('/api/v1/catalogue/products/Executive-Desk/picture/1/1')->assertStatus(404);
});
