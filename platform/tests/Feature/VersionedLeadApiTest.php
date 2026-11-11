<?php

use App\Domain\Crm\Models\Lead;

it('captures a Furniture enquiry through v1 with the existing reference response contract', function () {
    config(['modules.enabled' => 'lead_api']);
    $payload = ['name' => 'Synthetic API visitor', 'email' => 'visitor@example.test', 'message' => 'Synthetic furniture enquiry'];
    $first = $this->postJson('/api/v1/leads', $payload)->assertCreated()->assertJsonPath('ok', true)
        ->assertJsonStructure(['reference', 'message', 'whatsapp_url']);
    expect(Lead::query()->sole()->business_line->value)->toBe('furniture');
    $this->postJson('/api/v1/leads', $payload)->assertOk()->assertJsonPath('reference', $first->json('reference'));
    expect(Lead::count())->toBe(1);
});

it('validates v1 enquiries and refuses Interior routing', function () {
    $this->postJson('/api/v1/leads', [])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/leads', ['name' => 'Synthetic visitor', 'email' => 'v@example.test', 'business_line' => 'interior'])
        ->assertUnprocessable()->assertJsonValidationErrors('business_line');
    expect(Lead::count())->toBe(0);
});

it('drops v1 honeypot submissions without persistence', function () {
    $this->postJson('/api/v1/leads', ['name' => 'Synthetic bot', 'email' => 'bot@example.test', 'company_website' => 'spam'])
        ->assertStatus(202)->assertJsonPath('reference', null);
    expect(Lead::count())->toBe(0);
});

it('closes v1 with the module switch and provides no public lead listing', function () {
    $this->getJson('/api/v1/leads')->assertStatus(405)->assertHeader('Allow', 'POST');
    config(['modules.enabled' => '']);
    $this->postJson('/api/v1/leads', [])->assertNotFound();
    $this->getJson('/api/v1/leads')->assertNotFound();
});

it('retains Turnstile verification on the versioned endpoint', function () {
    config(['services.turnstile.secret_key' => 'synthetic-test-secret']);
    \Illuminate\Support\Facades\Http::fake(['challenges.cloudflare.com/*' => \Illuminate\Support\Facades\Http::response(['success' => false])]);
    $this->postJson('/api/v1/leads', ['name' => 'Synthetic visitor', 'email' => 'v@example.test', 'turnstile_token' => 'bad'])
        ->assertUnprocessable()->assertJsonPath('ok', false);
    expect(Lead::count())->toBe(0);
});

it('shares throttling across the old and versioned capture endpoints', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson($i % 2 ? '/api/public/leads' : '/api/v1/leads', [])->assertUnprocessable();
    }
    $this->postJson('/api/v1/leads', [])->assertStatus(429);
});

it('preserves storefront company and preferred channel as metadata without inventing a company link', function () {
    $this->postJson('/api/v1/leads', [
        'name' => 'Synthetic storefront visitor', 'email' => 'shopper@example.test',
        'company' => 'Synthetic Company', 'preferred_channel' => 'whatsapp',
        'source' => 'website_form', 'form' => 'Contact Form', 'consent' => true,
    ])->assertCreated();
    $lead = Lead::query()->sole();
    expect($lead->meta['company'])->toBe('Synthetic Company')
        ->and($lead->meta['preferred_channel'])->toBe('whatsapp')
        ->and($lead->company_id)->toBeNull();
});
