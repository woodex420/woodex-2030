<?php

use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Estimate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

function nativeContact51B(array $overrides = []): array
{
    return array_replace([
        'form' => 'contact', 'page' => '/contact/', '_response' => 'html', '_hp' => '',
        'name' => 'QA51B <private> Visitor', 'phone' => '0322 4000768',
        'email' => 'qa51b-private@example.test', 'service' => 'Interior design',
        'message' => 'PRIVATE51B <script>alert("private")</script>',
    ], $overrides);
}

function postNative51B($test, array $overrides = [])
{
    return $test->withHeaders(['Accept' => 'text/html,application/xhtml+xml', 'Content-Type' => 'application/x-www-form-urlencoded'])
        ->post('/api/forms.php', nativeContact51B($overrides));
}

function privateHtml51B($response, int $status): void
{
    $response->assertStatus($status)->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, nosnippet')
        ->assertHeaderMissing('Location')->assertHeaderMissing('Set-Cookie')->assertSee('/contact/#visit-form', false)
        ->assertDontSee('PRIVATE51B')->assertDontSee('qa51b-private@example.test')->assertDontSee('0322 4000768')
        ->assertDontSee('QA51B')->assertDontSee('<script', false)->assertDontSee('Interior design');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
}

it('saves a native Contact enquiry and reuses its lead without financial or consent side effects', function () {
    privateHtml51B(postNative51B($this), 201);
    $lead = Lead::query()->sole();
    expect($lead->source->value)->toBe('website_form')->and($lead->source_detail)->toBe('contact')
        ->and($lead->service)->toBe('Interior design')->and($lead->meta['page'])->toBe('/contact/')
        ->and($lead->meta['fields'])->toBe([])->and($lead->attribution['first_touch']['landing'])->toBe('/contact/');
    $contact = Contact::query()->sole();
    expect($contact->phone)->toBe('+923224000768')->and($contact->consent_marketing)->toBeFalse()->and($contact->consent_whatsapp)->toBeFalse();
    privateHtml51B(postNative51B($this), 200);
    expect(Lead::query()->count())->toBe(1)->and(Contact::query()->count())->toBe(1)->and(Estimate::query()->count())->toBe(0);
    $this->assertDatabaseCount('quotes', 0);
    $this->assertDatabaseCount('invoices', 0);
    expect(session()->has('_old_input'))->toBeFalse();
});

it('maps each existing Contact project option identically', function (string $service) {
    privateHtml51B(postNative51B($this, ['service' => $service]), 201);
    expect(Lead::query()->sole()->service)->toBe($service);
})->with(['Interior design', 'Fit-out', 'Renovation', 'Architecture', '3D visualization', 'Furniture', 'Something else']);

it('accepts email-only native capture under the existing validator', function () {
    privateHtml51B(postNative51B($this, ['phone' => '']), 201);
    expect(Contact::query()->sole()->phone)->toBeNull();
});

it('retains returning contact consent instead of silently resetting it', function () {
    postNative51B($this);
    Contact::query()->sole()->update(['consent_marketing' => true, 'consent_whatsapp' => true]);
    privateHtml51B(postNative51B($this), 200);
    expect(Contact::query()->sole()->consent_marketing)->toBeTrue()->and(Contact::query()->sole()->consent_whatsapp)->toBeTrue();
});

it('validates native input without reflecting it or capturing records', function (array $overrides) {
    privateHtml51B(postNative51B($this, $overrides), 422);
    expect(Lead::query()->count())->toBe(0)->and(Contact::query()->count())->toBe(0);
    expect(session()->has('_old_input'))->toBeFalse();
})->with([
    'missing name' => [['name' => '']], 'short name' => [['name' => 'A']],
    'no contact channel' => [['phone' => '', 'email' => '']], 'invalid phone' => [['phone' => '1234567']],
    'invalid email' => [['email' => 'PRIVATE51B-not-email']], 'long name' => [['name' => str_repeat('x', 161)]],
    'long phone' => [['phone' => str_repeat('1', 41)]], 'long email' => [['email' => str_repeat('x', 150).'@example.test']],
    'long message' => [['message' => str_repeat('x', 5001)]], 'long service' => [['service' => str_repeat('x', 191)]],
    'long page' => [['page' => str_repeat('x', 501)]],
]);

it('keeps both honeypots neutral and stores nothing', function (string $field) {
    $r = postNative51B($this, [$field => 'bot']);
    privateHtml51B($r, 200);
    $r->assertDontSee('Your enquiry has been saved');
    expect(Lead::query()->count())->toBe(0)->and(Contact::query()->count())->toBe(0);
})->with(['_hp', 'company_hp']);

it('preserves the native writing limits without a false recovery promise', function (string $key, int $limit) {
    for ($i = 0; $i < $limit; $i++) {
        RateLimiter::hit($key.':127.0.0.1', 86400);
    }
    $r = postNative51B($this);
    privateHtml51B($r, 429);
    $r->assertDontSee('minute');
    expect(Lead::query()->count())->toBe(0);
})->with([['legacy-leads-minute', 5], ['legacy-leads-day', 50]]);

it('renders the real route throttle as private HTML and keeps its retry headers', function () {
    for ($i = 0; $i < 120; $i++) {
        $this->get('/api/forms.php')->assertOk();
    }
    $r = postNative51B($this);
    privateHtml51B($r, 429);
    $r->assertHeader('Retry-After')->assertHeader('X-RateLimit-Limit');
    expect(Lead::query()->count())->toBe(0);
});

it('renders controlled capture failures safely with the normal exception handler', function (bool $debug) {
    config(['app.debug' => $debug]);
    Event::listen(LeadCaptured::class, function () {
        throw new RuntimeException('PRIVATE51B internal customer or database detail');
    });
    privateHtml51B(postNative51B($this), 500);
    // LeadCaptured is after-commit: a failed acknowledgement must not promise that nothing was saved.
    expect(Lead::query()->count())->toBe(1);
})->with([true, false]);

it('handles capture validation and HTTP exceptions without raw exception text', function (string $kind, int $status) {
    Event::listen(LeadCaptured::class, function () use ($kind) {
        if ($kind === 'validation') {
            throw ValidationException::withMessages(['name' => 'PRIVATE51B secret', '<script>PRIVATE51B</script>' => 'secret']);
        }
        abort(503, 'PRIVATE51B internal error', ['Retry-After' => '123', 'Location' => '/?phone=PRIVATE51B']);
    });
    $r = postNative51B($this);
    privateHtml51B($r, $status);
    if ($status === 503) {
        $r->assertHeader('Retry-After', '123');
    }
})->with([['validation', 422], ['http', 503]]);

it('keeps noneligible native payloads on the legacy JSON contract', function (array $overrides, string $accept, bool $xhr) {
    $headers = ['Accept' => $accept, 'Content-Type' => 'application/x-www-form-urlencoded'];
    if ($xhr) {
        $headers['X-Requested-With'] = 'XMLHttpRequest';
    }
    $r = $this->withHeaders($headers)->post('/api/forms.php', nativeContact51B($overrides));
    $r->assertHeader('Content-Type', 'application/json')->assertJsonPath('ok', true);
    expect($r->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
})->with([
    'no marker' => [['_response' => ''], 'text/html', false], 'wrong marker' => [['_response' => 'other'], 'text/html', false],
    'invalid form marker' => [['form' => 'invalid form'], 'text/html', false], 'widget action' => [['action' => 'wa_click'], 'text/html', false],
    'json accept' => [[], 'application/json', false], 'wildcard only' => [[], '*/*', false],
    'xhr' => [[], 'text/html', true], 'json preferred' => [[], 'application/json,text/html;q=0.5', false],
]);

it('keeps JSON bodies JSON even with forged HTML transport markers and preserves unrelated extras', function () {
    $this->postJson('/api/forms.php', nativeContact51B())->assertCreated()->assertJsonPath('ok', true);
    expect(Lead::query()->sole()->meta['fields'])->toBe(['_response' => 'html']);
});

it('leaves ordinary API validation JSON and configuration headers unchanged', function () {
    $this->postJson('/api/forms.php', nativeContact51B(['name' => '']))->assertUnprocessable()->assertHeader('Content-Type', 'application/json');
    $this->get('/api/forms.php')->assertOk()->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('does not negotiate HTML for JSON bodies even when HTML Accept is explicit', function () {
    $this->postJson('/api/forms.php', nativeContact51B(), ['Accept' => 'text/html'])
        ->assertCreated()->assertHeader('Content-Type', 'application/json')->assertJsonPath('ok', true);
});

it('preserves ordinary JSON exception rendering for unmarked API clients', function () {
    config(['app.debug' => false]);
    Event::listen(LeadCaptured::class, function () {
        throw new RuntimeException('Controlled JSON failure');
    });
    $this->postJson('/api/forms.php', nativeContact51B(['_response' => '']))
        ->assertStatus(500)->assertHeader('Content-Type', 'application/json')->assertDontSee('<!doctype html>', false);
});

it('captures each native public form family through the same private POST pipeline', function (string $form, array $fields, string $service, string $message) {
    $payload = array_merge(nativeContact51B(), $fields, ['form' => $form, 'page' => '/fit-out/']);
    if (in_array($form, ['office-fitout', 'fitout-hub'], true)) {
        unset($payload['service'], $payload['message']);
    }
    if ($form === 'brief') {
        unset($payload['message']);
    }
    $response = $this->withHeaders(['Accept' => 'text/html', 'Content-Type' => 'application/x-www-form-urlencoded'])
        ->post('/api/forms.php', $payload);
    privateHtml51B($response, 201);
    $lead = Lead::query()->sole();
    expect($lead->service)->toBe($service)->and($lead->message)->toBe($message)
        ->and($lead->source->value)->toBe('website_form')
        ->and(array_key_exists('_response', $lead->meta['fields']))->toBeFalse();
    expect(Contact::query()->sole()->consent_marketing)->toBeFalse()
        ->and(Contact::query()->sole()->consent_whatsapp)->toBeFalse();
    $this->assertDatabaseCount('quotes', 0);
    $this->assertDatabaseCount('invoices', 0);
})->with([
    'ordinary service' => ['kitchen-design', ['service' => 'Kitchen design', 'message' => 'PRIVATE51B message'], 'Kitchen design', 'PRIVATE51B message'],
    'digit-led service slug' => ['1-kanal-house-design', ['service' => 'House design', 'message' => 'PRIVATE51B message'], 'House design', 'PRIVATE51B message'],
    'office alias' => ['office-fitout', ['notes' => 'PRIVATE51B office note'], 'Office fit-out', 'PRIVATE51B office note'],
    'fitout sector alias' => ['fitout-hub', ['sector' => 'Office', 'details' => 'PRIVATE51B fitout note'], 'Fit-out: Office', 'PRIVATE51B fitout note'],
    'brief alias' => ['brief', ['service' => '3D visualization', 'details' => 'PRIVATE51B brief note'], '3D visualization', 'PRIVATE51B brief note'],
]);

it('creates an estimate only from a valid native estimator snapshot without reflecting the snapshot in HTML', function () {
    $this->userWithRole('owner');
    $response = postNative51B($this, [
        'form' => 'estimator', 'page' => '/estimator/', 'service' => 'renovation',
        'area' => '1000', 'finish' => 'standard', 'estimate' => 'PRIVATE51B visitor range',
    ]);
    privateHtml51B($response, 201);
    $lead = Lead::query()->sole();
    $estimate = Estimate::query()->sole();
    expect($lead->source->value)->toBe('estimator')->and($estimate->lead_id)->toBe($lead->id)
        ->and($estimate->service_key)->toBe('renovation')->and($estimate->inputs['quantity'])->toBe('1000');
    $this->assertDatabaseCount('quotes', 0);
    $this->assertDatabaseCount('invoices', 0);
});

it('keeps native booking requests private without claiming a confirmed slot', function () {
    $response = postNative51B($this, [
        'form' => 'book-a-visit', 'page' => '/book-a-visit/', 'action' => 'book_create',
        'type' => 'visit', 'date' => '2026-11-03', 'time' => '11:00', 'city' => 'Lahore',
        'address' => 'PRIVATE51B site address',
    ]);
    privateHtml51B($response, 201);
    expect(Lead::query()->sole()->source->value)->toBe('booking');
    $response->assertDontSee('PRIVATE51B site address')->assertDontSee('confirmed slot');
    $this->assertDatabaseCount('estimates', 0);
});
