<?php

use App\Domain\Crm\Models\Lead;
use App\Integrations\Turnstile\TurnstileVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| POST /api/v1/leads, the shop's enquiry address. Since the rules of /api/v1 (master list T-063) its answers are
| {data, meta} and {error, meta}, and every request carries an Idempotency-Key; what each test proves is what it
| proved before. The shape itself, key by key, is held by tests/Feature/Api/V1ContractTest.php.
*/

it('captures a Furniture enquiry through v1 with the existing reference response contract', function () {
    config(['modules.enabled' => 'lead_api']);
    $payload = ['name' => 'Synthetic API visitor', 'email' => 'visitor@example.test', 'message' => 'Synthetic furniture enquiry'];
    $first = $this->postJson('/api/v1/leads', $payload, idempotencyKey())->assertCreated()
        ->assertJsonStructure(['data' => ['reference', 'handoff'], 'meta' => ['request_id']]);
    expect($first->json('data.reference'))->toBeString()->not->toBe('')
        ->and(Lead::query()->sole()->business_line->value)->toBe('furniture');
    // The same visitor asks again, with another request: it joins the enquiry that is open.
    $this->postJson('/api/v1/leads', $payload, idempotencyKey())->assertOk()->assertJsonPath('data.reference', $first->json('data.reference'));
    expect(Lead::count())->toBe(1);
});

it('validates v1 enquiries and refuses Interior routing', function () {
    $this->postJson('/api/v1/leads', [], idempotencyKey())->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['name']]]);
    $this->postJson('/api/v1/leads', ['name' => 'Synthetic visitor', 'email' => 'v@example.test', 'business_line' => 'interior'], idempotencyKey())
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['business_line']]]);
    expect(Lead::count())->toBe(0);
});

it('drops v1 honeypot submissions without persistence', function () {
    $this->postJson('/api/v1/leads', ['name' => 'Synthetic bot', 'email' => 'bot@example.test', 'company_website' => 'spam'], idempotencyKey())
        ->assertStatus(202)->assertJsonPath('data.reference', null);
    expect(Lead::count())->toBe(0);
});

it('closes v1 with the module switch and provides no public lead listing', function () {
    $this->getJson('/api/v1/leads')->assertStatus(405)->assertHeader('Allow', 'POST');
    config(['modules.enabled' => '']);
    $this->postJson('/api/v1/leads', [], idempotencyKey())->assertNotFound();
    $this->getJson('/api/v1/leads')->assertNotFound();
});

it('retains Turnstile verification on the versioned endpoint', function () {
    config(['services.turnstile.secret_key' => 'synthetic-test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
    $this->postJson('/api/v1/leads', ['name' => 'Synthetic visitor', 'email' => 'v@example.test', 'turnstile_token' => 'bad'], idempotencyKey())
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['turnstile_token']]]);
    expect(Lead::count())->toBe(0);
});

it('shares throttling across the old and versioned capture endpoints', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson($i % 2 ? '/api/public/leads' : '/api/v1/leads', [], idempotencyKey())->assertUnprocessable();
    }
    $this->postJson('/api/v1/leads', [], idempotencyKey())->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});

it('preserves storefront company and preferred channel as metadata without inventing a company link', function () {
    $this->postJson('/api/v1/leads', [
        'name' => 'Synthetic storefront visitor', 'email' => 'shopper@example.test',
        'company' => 'Synthetic Company', 'preferred_channel' => 'whatsapp',
        'source' => 'website_form', 'form' => 'Contact Form', 'consent' => true,
    ], idempotencyKey())->assertCreated();
    $lead = Lead::query()->sole();
    expect($lead->meta['company'])->toBe('Synthetic Company')
        ->and($lead->meta['preferred_channel'])->toBe('whatsapp')
        ->and($lead->company_id)->toBeNull();
});

it('fails closed on v1 verification outages without storing a lead', function () {
    config(['services.turnstile.secret_key' => 'synthetic-secret']);
    Http::fake(fn () => throw new ConnectionException('Synthetic provider outage'));
    $this->postJson('/api/v1/leads', ['name' => 'Synthetic visitor', 'email' => 'v@example.test', 'turnstile_token' => 'synthetic-token'], idempotencyKey())
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect(Lead::count())->toBe(0);
});

it('requires a verification secret outside local and testing for strict verification', function () {
    config(['services.turnstile.secret_key' => '']);
    $this->app->instance('env', 'production');
    expect(app(TurnstileVerifier::class)->verify('synthetic-token', failClosed: true))->toBeFalse();
});

it('accepts a successful v1 challenge and refuses a missing token when configured', function () {
    config(['services.turnstile.secret_key' => 'synthetic-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
    $payload = ['name' => 'Synthetic visitor', 'email' => 'challenge@example.test'];
    // The same key both times: the request that was refused left nothing behind, so its key is still free.
    $key = idempotencyKey();
    $this->postJson('/api/v1/leads', $payload, $key)->assertUnprocessable();
    $this->postJson('/api/v1/leads', [...$payload, 'turnstile_token' => 'synthetic-token'], $key)->assertCreated();
    expect(Lead::count())->toBe(1);
});
