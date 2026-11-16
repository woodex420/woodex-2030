<?php

use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\System\Models\TelegramLink;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use Illuminate\Support\Facades\Http;

function enquiry(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ayesha Khan',
        'phone' => '0322-4000768',
        'service' => 'renovation',
        'city' => 'Lahore',
        'area' => 'DHA Phase 6',
        'message' => '10 marla house renovation, two washrooms and kitchen.',
        'utm_source' => 'google',
        'utm_campaign' => 'renovation-lahore',
        'page_url' => 'https://woodex.com.pk/renovation/',
        'consent' => true,
    ], $overrides);
}

it('captures a web enquiry as contact + lead with normalised phone and attribution', function () {
    $response = $this->postJson('/api/public/leads', enquiry());

    $response->assertCreated()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('reference', 'L-'.now()->format('Y').'-000001');

    $contact = Contact::query()->sole();
    $lead = Lead::query()->with('stage')->sole();

    expect($contact->phone)->toBe('+923224000768')
        ->and($contact->consent_whatsapp)->toBeTrue()
        ->and($lead->stage->key)->toBe('new')
        ->and($lead->source->value)->toBe('website_form')
        ->and($lead->attribution['first_touch']['utm_campaign'])->toBe('renovation-lahore')
        ->and($lead->sla_due_at)->not->toBeNull()
        ->and(Activity::query()->where('lead_id', $lead->id)->count())->toBe(1);
});

it('joins a repeat enquiry from the same phone to the open lead instead of duplicating it', function () {
    $this->postJson('/api/public/leads', enquiry())->assertCreated();
    $second = $this->postJson('/api/public/leads', enquiry(['phone' => '+92 322 4000768', 'message' => 'Following up, please call.']));

    $second->assertOk()->assertJsonPath('reference', 'L-'.now()->format('Y').'-000001');

    expect(Lead::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1)
        ->and(Activity::query()->where('body', 'like', 'Returning enquiry%')->count())->toBe(1);
});

it('silently drops honeypot and too-fast submissions', function () {
    $this->postJson('/api/public/leads', enquiry(['company_website' => 'http://spam.example']))->assertStatus(202);
    $this->postJson('/api/public/leads', enquiry(['form_started_at' => now()->timestamp]))->assertStatus(202);

    expect(Lead::query()->count())->toBe(0);
});

it('validates phone numbers', function () {
    $this->postJson('/api/public/leads', enquiry(['phone' => '12345']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
});

it('rejects a failed Turnstile check but lets the live-site bridge key through', function () {
    config(['services.turnstile.secret_key' => 'test-secret', 'woodex.public_leads.bridge_key' => 'bridge-123']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    $this->postJson('/api/public/leads', enquiry(['turnstile_token' => 'bad']))->assertUnprocessable();

    $this->postJson('/api/public/leads', enquiry(), ['X-Woodex-Key' => 'bridge-123'])->assertCreated();

    expect(Lead::query()->sole()->meta)->toBe(['via' => 'live-site-bridge']);
});

it('auto-assigns the least-loaded sales user and preserves their in-app alert without staff Telegram', function () {
    $telegram = FakeTelegramGateway::bind();

    $busy = $this->userWithRole('sales');
    Lead::factory()->count(2)->create(['owner_id' => $busy->id]);
    $free = $this->userWithRole('sales');
    TelegramLink::query()->create(['user_id' => $free->id, 'chat_id' => 555001, 'is_active' => true, 'linked_at' => now()]);

    $this->postJson('/api/public/leads', enquiry(['phone' => '0300 1234567']))->assertCreated();

    $lead = Lead::query()->latest('id')->first();

    expect($lead->owner_id)->toBe($free->id)
        ->and($telegram->textsFor(555001))->toBe([])
        ->and($free->notifications()->count())->toBe(1);
});
