<?php

use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\CatalogItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| POST /api/v1/quote-requests, the basket of a visitor of the shop. Since the rules of /api/v1 (master list T-063)
| the key that makes a second sending harmless is the Idempotency-Key header and no longer a field of the form,
| and the answers are {data, meta} and {error, meta}; what each test proves is what it proved before.
*/

beforeEach(function () {
    CatalogItem::create(['description' => 'SYNTHETIC Furniture desk', 'category' => 'Synthetic', 'unit' => 'each',
        'rate_minor' => 123456, 'business_line' => 'furniture', 'is_active' => true, 'storefront_product_id' => 'synthetic-desk']);
});

function basketPayload(array $overrides = []): array
{
    return array_merge(['name' => 'Synthetic basket visitor', 'email' => 'basket@example.test',
        'consent' => true, 'preferred_channel' => 'email', 'items' => [['product_id' => 'synthetic-desk', 'qty' => 2]]], $overrides);
}

it('persists an immutable server priced Furniture request and timeline without issuing a quotation', function () {
    $before = DB::table('quotes')->count();
    $payload = basketPayload(['total' => 1, 'items' => [['product_id' => 'synthetic-desk', 'qty' => 2, 'price' => 1, 'name' => 'tampered']]]);
    $response = $this->postJson('/api/v1/quote-requests', $payload, idempotencyKey())->assertCreated()->assertJsonPath('data.status', 'pending_review')
        ->assertHeader('Cache-Control', 'no-store, private');
    $row = DB::table('storefront_quote_requests')->sole();
    $line = json_decode($row->lines, true)[0];
    expect($row->estimated_total_minor)->toBe(246912)->and($line['description'])->toBe('SYNTHETIC Furniture desk')
        ->and($line['rate_minor'])->toBe(123456)->and($row->reference)->toBe($response->json('data.reference'))
        ->and(Lead::query()->sole()->business_line->value)->toBe('furniture')->and(DB::table('quotes')->count())->toBe($before);
    CatalogItem::where('storefront_product_id', 'synthetic-desk')->update(['rate_minor' => 900]);
    expect(json_decode(DB::table('storefront_quote_requests')->sole()->lines, true)[0]['rate_minor'])->toBe(123456);
});

it('replays the same request without duplicate leads activities or repricing and conflicts on changed details', function () {
    $payload = basketPayload();
    $key = idempotencyKey();
    $one = $this->postJson('/api/v1/quote-requests', $payload, $key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    $count = Activity::count();
    CatalogItem::where('storefront_product_id', 'synthetic-desk')->update(['is_active' => false]);
    // Sent again with its key (and a new token of the robot check): the same answer, and the header that says so.
    $again = $this->postJson('/api/v1/quote-requests', [...$payload, 'turnstile_token' => 'fresh-token'], $key)->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');
    expect($again->json('data'))->toBe($one->json('data'))
        ->and(DB::table('storefront_quote_requests')->count())->toBe(1)->and(Lead::count())->toBe(1)->and(Activity::count())->toBe($count);
    $payload['items'][0]['qty'] = 3;
    $this->postJson('/api/v1/quote-requests', $payload, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    expect(DB::table('storefront_quote_requests')->count())->toBe(1);
});

it('rejects unknown inactive and Interior mappings atomically', function () {
    $payload = basketPayload(['items' => [['product_id' => 'synthetic-desk', 'qty' => 1], ['product_id' => 'unknown', 'qty' => 1]]]);
    $refused = $this->postJson('/api/v1/quote-requests', $payload, idempotencyKey())->assertUnprocessable();
    expect(array_keys($refused->json('error.details')))->toBe(['items.1.product_id']);
    CatalogItem::where('storefront_product_id', 'synthetic-desk')->update(['is_active' => false]);
    $this->postJson('/api/v1/quote-requests', basketPayload(), idempotencyKey())->assertUnprocessable();
    CatalogItem::where('storefront_product_id', 'synthetic-desk')->update(['is_active' => true, 'business_line' => 'interior']);
    $this->postJson('/api/v1/quote-requests', basketPayload(), idempotencyKey())->assertUnprocessable();
    // Nothing is left of a request that was refused: no enquiry, no basket, and no key.
    expect(Lead::count())->toBe(0)->and(DB::table('storefront_quote_requests')->count())->toBe(0)->and(DB::table('api_idempotency_keys')->count())->toBe(0);
});

it('validates consent bounded quantities duplicate products variants and empty baskets', function () {
    $this->postJson('/api/v1/quote-requests', basketPayload(['consent' => false]), idempotencyKey())->assertUnprocessable();
    $this->postJson('/api/v1/quote-requests', basketPayload(['items' => []]), idempotencyKey())->assertUnprocessable();
    $this->postJson('/api/v1/quote-requests', basketPayload(['items' => [['product_id' => 'synthetic-desk', 'qty' => 0]]]), idempotencyKey())->assertUnprocessable();
    $this->postJson('/api/v1/quote-requests', basketPayload(['items' => [['product_id' => 'synthetic-desk', 'qty' => 101]]]), idempotencyKey())->assertUnprocessable();
    expect(Lead::count())->toBe(0);
});

it('refuses duplicate lines and unsupported variants before saving', function () {
    $line = ['product_id' => 'synthetic-desk', 'qty' => 1];
    $this->postJson('/api/v1/quote-requests', basketPayload(['items' => [$line, $line]]), idempotencyKey())->assertUnprocessable();
    $this->postJson('/api/v1/quote-requests', basketPayload(['items' => [[...$line, 'material' => 'made up']]]), idempotencyKey())->assertUnprocessable();
    $this->postJson('/api/v1/quote-requests', basketPayload(['business_line' => 'interior']), idempotencyKey())->assertUnprocessable();
    expect(Lead::count())->toBe(0);
});

it('honours honeypot strict provider failures module gates and read restrictions', function () {
    $this->postJson('/api/v1/quote-requests', basketPayload(['company_website' => 'spam']), idempotencyKey())->assertStatus(202)->assertJsonPath('data.reference', null);
    config(['services.turnstile.secret_key' => 'synthetic-secret']);
    Http::fake(fn () => throw new ConnectionException('synthetic outage'));
    $this->postJson('/api/v1/quote-requests', basketPayload(['turnstile_token' => 'synthetic']), idempotencyKey())->assertUnprocessable();
    $this->getJson('/api/v1/quote-requests')->assertStatus(405)->assertHeader('Allow', 'POST');
    config(['modules.enabled' => 'lead_api']);
    $this->postJson('/api/v1/quote-requests', basketPayload(), idempotencyKey())->assertNotFound();
    $this->getJson('/api/v1/quote-requests')->assertNotFound();
    expect(Lead::count())->toBe(0)->and(DB::table('storefront_quote_requests')->count())->toBe(0);
});

it('shows saved basket lines only behind the existing lead policy', function () {
    $response = $this->postJson('/api/v1/quote-requests', basketPayload(), idempotencyKey())->assertCreated();
    $lead = Lead::query()->sole();
    $owner = $this->userWithRole('sales');
    $lead->update(['owner_id' => $owner->id]);
    $this->actingAs($owner)->get(route('admin.leads.show', $lead))->assertOk()
        ->assertSee($response->json('data.reference'))->assertSee('SYNTHETIC Furniture desk')->assertSee('Pending review');
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.leads.show', $lead))->assertForbidden();
});
