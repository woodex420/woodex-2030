<?php

use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\CatalogItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    CatalogItem::create(['description' => 'SYNTHETIC Furniture desk', 'category' => 'Synthetic', 'unit' => 'each',
        'rate_minor' => 123456, 'business_line' => 'furniture', 'is_active' => true, 'storefront_product_id' => 'synthetic-desk']);
});

function basketPayload(array $overrides = []): array
{
    return array_merge(['request_key' => (string) Str::uuid(), 'name' => 'Synthetic basket visitor', 'email' => 'basket@example.test',
        'consent' => true, 'preferred_channel' => 'email', 'items' => [['product_id' => 'synthetic-desk', 'qty' => 2]]], $overrides);
}

it('persists an immutable server priced Furniture request and timeline without issuing a quotation', function () {
    $before = DB::table('quotes')->count();
    $payload = basketPayload(['total' => 1, 'items' => [['product_id' => 'synthetic-desk', 'qty' => 2, 'price' => 1, 'name' => 'tampered']]]);
    $response = $this->postJson('/api/v1/quote-requests', $payload)->assertCreated()->assertJsonPath('status', 'pending_review')
        ->assertHeader('Cache-Control', 'no-store, private');
    $row = DB::table('storefront_quote_requests')->sole();
    $line = json_decode($row->lines, true)[0];
    expect($row->estimated_total_minor)->toBe(246912)->and($line['description'])->toBe('SYNTHETIC Furniture desk')
        ->and($line['rate_minor'])->toBe(123456)->and($row->reference)->toBe($response->json('reference'))
        ->and(Lead::query()->sole()->business_line->value)->toBe('furniture')->and(DB::table('quotes')->count())->toBe($before);
    CatalogItem::where('storefront_product_id', 'synthetic-desk')->update(['rate_minor' => 900]);
    expect(json_decode(DB::table('storefront_quote_requests')->sole()->lines, true)[0]['rate_minor'])->toBe(123456);
});

it('replays the same request without duplicate leads activities or repricing and conflicts on changed details', function () {
    $payload = basketPayload();
    $one = $this->postJson('/api/v1/quote-requests', $payload)->assertCreated();
    $count = Activity::count();
    CatalogItem::where('storefront_product_id', 'synthetic-desk')->update(['is_active' => false]);
    $this->postJson('/api/v1/quote-requests', [...$payload, 'turnstile_token' => 'fresh-token'])->assertOk()
        ->assertJsonPath('reference', $one->json('reference'));
    expect(DB::table('storefront_quote_requests')->count())->toBe(1)->and(Lead::count())->toBe(1)->and(Activity::count())->toBe($count);
    $payload['items'][0]['qty'] = 3;
    $this->postJson('/api/v1/quote-requests', $payload)->assertStatus(409);
});

it('rejects unknown inactive and Interior mappings atomically', function () {
    $payload = basketPayload(['items' => [['product_id' => 'synthetic-desk', 'qty' => 1], ['product_id' => 'unknown', 'qty' => 1]]]);
    $this->postJson('/api/v1/quote-requests', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.1.product_id');
    CatalogItem::where('storefront_product_id', 'synthetic-desk')->update(['is_active' => false]);
    $this->postJson('/api/v1/quote-requests', basketPayload())->assertUnprocessable();
    CatalogItem::where('storefront_product_id', 'synthetic-desk')->update(['is_active' => true, 'business_line' => 'interior']);
    $this->postJson('/api/v1/quote-requests', basketPayload())->assertUnprocessable();
    expect(Lead::count())->toBe(0)->and(DB::table('storefront_quote_requests')->count())->toBe(0);
});

it('validates consent bounded quantities duplicate products variants and empty baskets', function () {
    $this->postJson('/api/v1/quote-requests', basketPayload(['consent' => false]))->assertUnprocessable();
    $this->postJson('/api/v1/quote-requests', basketPayload(['items' => []]))->assertUnprocessable();
    $this->postJson('/api/v1/quote-requests', basketPayload(['items' => [['product_id' => 'synthetic-desk', 'qty' => 0]]]))->assertUnprocessable();
    $this->postJson('/api/v1/quote-requests', basketPayload(['items' => [['product_id' => 'synthetic-desk', 'qty' => 101]]]))->assertUnprocessable();
    expect(Lead::count())->toBe(0);
});

it('refuses duplicate lines and unsupported variants before saving', function () {
    $line = ['product_id' => 'synthetic-desk', 'qty' => 1];
    $this->postJson('/api/v1/quote-requests', basketPayload(['items' => [$line, $line]]))->assertUnprocessable();
    $this->postJson('/api/v1/quote-requests', basketPayload(['items' => [[...$line, 'material' => 'made up']]]))->assertUnprocessable();
    $this->postJson('/api/v1/quote-requests', basketPayload(['business_line' => 'interior']))->assertUnprocessable();
    expect(Lead::count())->toBe(0);
});

it('honours honeypot strict provider failures module gates and read restrictions', function () {
    $this->postJson('/api/v1/quote-requests', basketPayload(['company_website' => 'spam']))->assertStatus(202)->assertJsonPath('reference', null);
    config(['services.turnstile.secret_key' => 'synthetic-secret']);
    Http::fake(fn () => throw new ConnectionException('synthetic outage'));
    $this->postJson('/api/v1/quote-requests', basketPayload(['turnstile_token' => 'synthetic']))->assertUnprocessable();
    $this->getJson('/api/v1/quote-requests')->assertStatus(405);
    config(['modules.enabled' => 'lead_api']);
    $this->postJson('/api/v1/quote-requests', basketPayload())->assertNotFound();
    expect(Lead::count())->toBe(0)->and(DB::table('storefront_quote_requests')->count())->toBe(0);
});

it('shows saved basket lines only behind the existing lead policy', function () {
    $response = $this->postJson('/api/v1/quote-requests', basketPayload())->assertCreated();
    $lead = Lead::query()->sole();
    $owner = $this->userWithRole('sales');
    $lead->update(['owner_id' => $owner->id]);
    $this->actingAs($owner)->get(route('admin.leads.show', $lead))->assertOk()
        ->assertSee($response->json('reference'))->assertSee('SYNTHETIC Furniture desk')->assertSee('Pending review');
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.leads.show', $lead))->assertForbidden();
});
