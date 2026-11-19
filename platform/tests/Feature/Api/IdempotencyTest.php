<?php

declare(strict_types=1);

use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\CatalogItem;
use App\Settings\BusinessSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
| A create under /api/v1 that is sent twice is written once and answered the same (master list T-063;
| App\Support\Api\Idempotency). A visitor presses "Send", the answer is lost on the way, the shop sends again:
| before this the second sending of an enquiry was written on the enquiry's timeline as a second one and told the
| team again. Each test here fails when the key is not looked up, not kept, or kept apart from the record.
|
| Two requests with one key in the same MOMENT are a matter for two processes and a real database server:
| tools/ci/two-writers.php, steps "one-key-two-enquiries" and "one-key-two-baskets".
*/

beforeEach(function () {
    config(['modules.enabled' => 'lead_api,quote_requests']);
    $business = app(BusinessSettings::class);
    $business->whatsapp = '+923001234567';
    $business->save();
    CatalogItem::create(['description' => 'SYNTHETIC Furniture desk', 'category' => 'Synthetic', 'unit' => 'each',
        'rate_minor' => 123456, 'business_line' => 'furniture', 'is_active' => true, 'storefront_product_id' => 'synthetic-desk']);

    $this->enquiry = ['name' => 'Ayesha Khan', 'email' => 'ayesha@example.test', 'message' => 'A desk for six.', 'consent' => true, 'preferred_channel' => 'whatsapp'];
    $this->basket = ['name' => 'Bilal Ahmed', 'email' => 'bilal@example.test', 'consent' => true, 'items' => [['product_id' => 'synthetic-desk', 'qty' => 2]]];
});

it('writes an enquiry once however often it is sent with its key, and answers the same each time', function () {
    Event::fake([LeadCaptured::class]);
    $key = idempotencyKey();

    $first = $this->postJson('/api/v1/leads', $this->enquiry, $key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    foreach ([1, 2, 3] as $sending) {
        // Sent again as a shop sends again: the same form, a new token of the robot check.
        $again = $this->postJson('/api/v1/leads', [...$this->enquiry, 'turnstile_token' => "token-{$sending}"], $key)
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        expect($again->json('data'))->toBe($first->json('data'), 'the same reference and the same hand-off token')
            ->and($again->json('meta.request_id'))->not->toBe($first->json('meta.request_id'), 'each sending is a request of its own');
    }

    expect(Lead::count())->toBe(1)
        ->and(Activity::where('body', 'like', 'New enquiry%')->count())->toBe(1)
        ->and(Activity::where('body', 'like', 'Returning enquiry%')->count())->toBe(0, 'a second sending is not a second enquiry')
        ->and(DB::table('lead_handoff_capabilities')->count())->toBe(1)
        ->and(DB::table('api_idempotency_keys')->count())->toBe(1);
    Event::assertDispatchedTimes(LeadCaptured::class, 1);

    // The token that was given four times is one token, and it works.
    $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => $first->json('data.handoff.token')])->assertOk();
});

it('takes the same enquiry for the same however the client wrote it down', function () {
    $key = idempotencyKey();
    $first = $this->postJson('/api/v1/leads', ['name' => 'Ayesha Khan', 'email' => 'ayesha@example.test', 'city' => null, 'consent' => false, 'area_sqft' => 400], $key)->assertCreated();

    // Another order, the empty fields left out, a number as text, the key in capitals.
    $this->postJson('/api/v1/leads', ['area_sqft' => '400', 'email' => 'ayesha@example.test', 'name' => 'Ayesha Khan'], ['Idempotency-Key' => strtoupper($key['Idempotency-Key'])])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.reference', $first->json('data.reference'));

    expect(Lead::count())->toBe(1);
});

it('refuses a key that was used for other content, and writes nothing', function (string $address, string $body, array $change) {
    $key = idempotencyKey();
    $this->postJson($address, $this->{$body}, $key)->assertCreated();
    $written = [Lead::count(), Activity::count(), DB::table('storefront_quote_requests')->count(), DB::table('api_idempotency_keys')->count()];

    $this->postJson($address, [...$this->{$body}, ...$change], $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

    expect([Lead::count(), Activity::count(), DB::table('storefront_quote_requests')->count(), DB::table('api_idempotency_keys')->count()])->toBe($written);

    // What was written the first time is still what the key answers.
    $this->postJson($address, $this->{$body}, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
})->with([
    'an enquiry with another message' => ['/api/v1/leads', 'enquiry', ['message' => 'Twelve chairs instead.']],
    'an enquiry from another person' => ['/api/v1/leads', 'enquiry', ['email' => 'someone.else@example.test']],
    'a basket with another quantity' => ['/api/v1/quote-requests', 'basket', ['items' => [['product_id' => 'synthetic-desk', 'qty' => 3]]]],
]);

it('leaves the key free when the request was refused', function () {
    $key = idempotencyKey();

    $this->postJson('/api/v1/leads', [...$this->enquiry, 'email' => 'not-an-address'], $key)->assertStatus(422);
    expect(DB::table('api_idempotency_keys')->count())->toBe(0);

    $this->postJson('/api/v1/leads', $this->enquiry, $key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect(Lead::count())->toBe(1);
});

it('leaves nothing behind when the work fails half way, and the same key then does the work', function () {
    $key = idempotencyKey();
    $calls = 0;
    $rowsWhenItFailed = null;
    // The first time, writing the enquiry's first line on its timeline fails: the key's row and the enquiry are
    // written by then, and nothing is committed. The second time it works.
    Activity::creating(function () use (&$calls, &$rowsWhenItFailed): void {
        if ($calls++ === 0) {
            $rowsWhenItFailed = [DB::table('api_idempotency_keys')->count(), Lead::count()];

            throw new RuntimeException('the disk is full');
        }
    });

    $this->postJson('/api/v1/leads', $this->enquiry, $key)->assertStatus(500)->assertJsonPath('error.code', 'SERVER_ERROR');
    expect($rowsWhenItFailed)->toBe([1, 1], 'it failed with the key and the enquiry written')
        ->and(DB::table('api_idempotency_keys')->count())->toBe(0, 'the key went with the work')
        ->and(Lead::count())->toBe(0);

    $this->postJson('/api/v1/leads', $this->enquiry, $key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect(Lead::count())->toBe(1)->and(DB::table('api_idempotency_keys')->count())->toBe(1);
});

it('tells a second try what the first one saved, when the first was saved and then failed', function () {
    // What the key is for. The enquiry is saved; telling the team about it fails afterwards (LeadCaptured is heard
    // once the work is committed), and the visitor is shown an error. The shop sends again with the same key.
    $key = idempotencyKey();
    $told = 0;
    Event::listen(LeadCaptured::class, function () use (&$told): void {
        if ($told++ === 0) {
            throw new RuntimeException('the mail server did not answer');
        }
    });

    $this->postJson('/api/v1/leads', $this->enquiry, $key)->assertStatus(500);
    expect(Lead::count())->toBe(1, 'the enquiry was saved before anything failed');
    $activities = Activity::count();

    $again = $this->postJson('/api/v1/leads', $this->enquiry, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data.reference'))->toBe(Lead::query()->sole()->ref)
        ->and($again->json('data.handoff.token'))->toMatch('/^[a-f0-9]{64}$/')
        ->and(Activity::count())->toBe($activities, 'nothing more is written on the timeline')
        ->and($told)->toBe(1, 'and the team is not told a second time');
});

it('keeps a key apart for each kind of create', function () {
    $key = idempotencyKey();

    $this->postJson('/api/v1/leads', $this->enquiry, $key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    $this->postJson('/api/v1/quote-requests', $this->basket, $key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect(DB::table('api_idempotency_keys')->pluck('scope')->all())->toBe(['leads', 'quote-requests']);
});

it('keeps neither the key, nor what was sent, nor the hand-off token', function () {
    $key = idempotencyKey();
    $token = $this->postJson('/api/v1/leads', $this->enquiry, $key)->assertCreated()->json('data.handoff.token');
    $row = DB::table('api_idempotency_keys')->sole();
    $kept = json_encode($row);

    expect($row->key_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($row->request_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($kept)->not->toContain($key['Idempotency-Key'])->not->toContain($token)
        ->not->toContain('ayesha@example.test')->not->toContain('Ayesha')->not->toContain('A desk for six')
        ->and((int) $row->status)->toBe(201);
});

it('answers a second sending without asking the robot check again', function () {
    config(['services.turnstile.secret_key' => 'synthetic-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
    $key = idempotencyKey();

    $this->postJson('/api/v1/leads', [...$this->enquiry, 'turnstile_token' => 'good-once'], $key)->assertCreated();
    // A token of the robot check is good once. The second sending carries the same one, or none at all.
    $this->postJson('/api/v1/leads', [...$this->enquiry, 'turnstile_token' => 'good-once'], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    $this->postJson('/api/v1/leads', $this->enquiry, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    Http::assertSentCount(1);

    // A new request is asked, and refused without a token.
    $this->postJson('/api/v1/leads', $this->enquiry, idempotencyKey())->assertStatus(422);
});

it('stops a create without a UUID for its key at the door', function (?string $key) {
    $this->postJson('/api/v1/leads', $this->enquiry, $key === null ? [] : ['Idempotency-Key' => $key])
        ->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

    expect(Lead::count())->toBe(0);
})->with([
    'no key' => [null],
    'an empty key' => [''],
    'a word' => ['enquiry-1'],
    'a UUID with a character missing' => ['123e4567-e89b-42d3-a456-42661417400'],
    'two UUIDs' => ['123e4567-e89b-42d3-a456-426614174000, 123e4567-e89b-42d3-a456-426614174001'],
]);

it('keeps no key of a request it took for a robot\'s', function () {
    $key = idempotencyKey();

    $this->postJson('/api/v1/leads', [...$this->enquiry, 'company_website' => 'https://robot.example'], $key)->assertStatus(202);
    expect(DB::table('api_idempotency_keys')->count())->toBe(0)->and(Lead::count())->toBe(0);
});
