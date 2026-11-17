<?php

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Models\EstimatorRate;
use App\Settings\BusinessSettings;

// The original pages are served exactly as the old site made them, so they keep calling `/api/forms.php` and
// `/api/chat.php` (docs/11 §11.0.1). This file is the contract those pages rely on: if one of these fails, a form
// on woodex.com.pk is broken — even though no file in `public/` changed.
function originalEnquiry(array $overrides = []): array
{
    return array_merge([
        'form' => 'brief',
        'page' => '/kitchen-design/',
        'name' => 'Ayesha Khan',
        'phone' => '0322 4000768',
        'service' => 'Kitchen design',
        'message' => 'Two-bedroom apartment kitchen in DHA, please call.',
        'timeline' => 'Next month',
    ], $overrides);
}

it('tells the pages that no captcha challenge is needed', function () {
    $this->getJson('/api/forms.php')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('turnstile', '');
});

it('saves an enquiry from an original page as a lead in the CRM', function () {
    $response = $this->postJson('/api/forms.php', originalEnquiry());

    $response->assertCreated()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('reference', 'L-'.now()->format('Y').'-000001');

    $lead = Lead::query()->sole();

    expect($lead->source->value)->toBe('website_form')
        ->and($lead->source_detail)->toBe('brief')
        ->and($lead->service)->toBe('Kitchen design')
        ->and($lead->meta['form'])->toBe('brief')
        ->and($lead->meta['page'])->toBe('/kitchen-design/')
        ->and($lead->meta['fields'])->toBe(['timeline' => 'Next month'])
        ->and($lead->attribution['first_touch']['landing'])->toBe('/kitchen-design/')
        ->and(Contact::query()->sole()->phone)->toBe('+923224000768');
});

it('saves the enquiry under the source of the form that sent it', function () {
    $this->postJson('/api/forms.php', originalEnquiry(['form' => 'whatsapp']))->assertCreated();
    $this->postJson('/api/forms.php', originalEnquiry(['form' => 'estimator', 'phone' => '0333 1112233']))->assertCreated();

    expect(Lead::query()->pluck('source_detail')->sort()->values()->all())->toBe(['estimator', 'whatsapp'])
        ->and(Lead::query()->where('source', 'whatsapp')->exists())->toBeTrue()
        ->and(Lead::query()->where('source', 'estimator')->exists())->toBeTrue();
});

it('answers a whatsapp tap without saving a lead', function () {
    $this->postJson('/api/forms.php', ['action' => 'wa_click', 'page' => '/', 'service' => 'Interior design'])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(Lead::query()->count())->toBe(0);
});

it('leaves online booking switched off until the OS has diaries, so the page shows its own paused panel', function () {
    $this->postJson('/api/forms.php', ['action' => 'book_cfg'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('on', false)
        ->assertJsonPath('types', [])
        ->assertJsonPath('areas', []);

    // No times are offered rather than claiming a day is full, which nobody has actually checked.
    $this->postJson('/api/forms.php', ['action' => 'book_slots', 'date' => '2026-10-20'])
        ->assertOk()
        ->assertJsonPath('ok', false);

    expect(Lead::query()->count())->toBe(0);
});

it('keeps a booking request as a lead, since we cannot promise the slot it asked for', function () {
    $this->postJson('/api/forms.php', [
        'action' => 'book_create',
        'type' => 'visit',
        'date' => '2026-10-22',
        'time' => '11:00',
        'name' => 'Ayesha Khan',
        'phone' => '0322 4000768',
        'email' => 'ayesha@example.com',
        'city' => 'Lahore',
        'address' => 'House 12, DHA Phase 6',
        'service' => 'Interior design',
        'note' => 'Bring kitchen samples.',
        'page' => '/book-a-visit/',
    ])->assertCreated()->assertJsonPath('ok', true);

    $lead = Lead::query()->sole();

    expect($lead->source->value)->toBe('booking')
        ->and($lead->source_detail)->toBe('book-a-visit')
        ->and(\App\Support\JsonObjects::canonical($lead->meta['slot']))->toBe(\App\Support\JsonObjects::canonical(['type' => 'visit', 'date' => '2026-10-22', 'time' => '11:00']))
        ->and($lead->message)->toContain('Booking request')
        ->and($lead->message)->toContain('DHA Phase 6')
        ->and($lead->message)->toContain('kitchen samples');
});

it('answers a booking request that forgot a phone number', function () {
    $this->postJson('/api/forms.php', ['action' => 'book_create', 'name' => 'Ayesha Khan', 'phone' => ''])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'The phone field is required.');

    expect(Lead::query()->count())->toBe(0);
});

it('looks successful to a bot that fills the hidden field, and stores nothing', function () {
    $this->postJson('/api/forms.php', originalEnquiry(['_hp' => 'http://spam.example']))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('id', 0);

    expect(Lead::query()->count())->toBe(0);
});

it('answers in the shape the page shows to the visitor when a field is missing', function () {
    $this->postJson('/api/forms.php', originalEnquiry(['name' => '']))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'The name field is required.');

    $this->postJson('/api/forms.php', originalEnquiry(['phone' => '123']))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'Please enter a valid phone number, for example 0322 4000768.');

    expect(Lead::query()->count())->toBe(0);
});

it('accepts an email-only enquiry, since some pages ask for one instead of a phone', function () {
    $this->postJson('/api/forms.php', originalEnquiry(['phone' => '', 'email' => 'ayesha@example.com']))
        ->assertCreated()
        ->assertJsonPath('ok', true);

    expect(Contact::query()->sole()->email)->toBe('ayesha@example.com');
});

it('keeps the chat widget out of the way until website chat is switched on', function () {
    $this->getJson('/api/chat.php')->assertOk()->assertJsonPath('on', false);
    $this->postJson('/api/chat.php', ['action' => 'cfg'])->assertOk()->assertJsonPath('on', false);

    expect(Lead::query()->count())->toBe(0);
});

it('stops a flood of enquiries from one address without stopping the pages', function () {
    // The WhatsApp number in the refusal is the studio's own setting (docs/18 §4), not a copy in the controller.
    $business = app(BusinessSettings::class);
    $business->phone = '+923001234567';
    $business->save();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/forms.php', originalEnquiry(['phone' => '0300 00000'.($i % 10).'1']))->assertSuccessful();
    }

    $this->postJson('/api/forms.php', originalEnquiry(['phone' => '0311 2223334']))
        ->assertStatus(429)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'Too many enquiries from this connection. Please try again in a minute, or send us a WhatsApp message on +92 300 1234567.');

    expect(Lead::query()->count())->toBe(5);

    // The pages keep asking this address on every page view, and the widget taps it: those are not enquiries.
    $this->getJson('/api/forms.php')->assertOk();
    $this->postJson('/api/forms.php', ['action' => 'wa_click'])->assertOk();
});

// 51A: this is the actual v1 estimator payload, not a hand-built LeadInput with meta.fields.service.
function browserEstimatorEnquiry(array $overrides = []): array
{
    return array_merge([
        'form' => 'estimator',
        'page' => '/estimator/',
        'name' => 'QA51A Estimator Visitor',
        'phone' => '+12025550161',
        'email' => 'qa51a-estimator@example.test',
        'service' => 'renovation',
        'area' => '1000',
        'finish' => 'standard',
        'estimate' => 'PKR 1,200,000 to 3,200,000',
        '_hp' => '',
    ], $overrides);
}

it('creates a linked draft from the real browser estimator bridge payload', function () {
    $owner = $this->userWithRole('owner');
    $rate = EstimatorRate::query()->where('key', 'renovation')->sole();

    $this->postJson('/api/forms.php', browserEstimatorEnquiry())->assertCreated()->assertJsonPath('ok', true);

    $lead = Lead::query()->sole();
    expect($lead->service)->toBe('renovation')
        ->and($lead->source->value)->toBe('estimator')
        ->and(\App\Support\JsonObjects::canonical($lead->meta['fields']))->toBe(\App\Support\JsonObjects::canonical([
            'area' => '1000', 'finish' => 'standard', 'estimate' => 'PKR 1,200,000 to 3,200,000',
        ]));

    $estimate = Estimate::query()->sole();
    expect($estimate->lead_id)->toBe($lead->id)
        ->and($estimate->contact_id)->toBe($lead->contact_id)
        ->and($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->service_key)->toBe('renovation')
        ->and($estimate->inputs['quantity'])->toBe('1000')
        ->and($estimate->inputs['finish'])->toBe('standard')
        ->and($estimate->computed['headline_minor'])->toBe($rate->standard_minor * 1000)
        ->and($estimate->created_by)->toBe($owner->id)
        ->and($estimate->assumptions)->toContain('PKR 1,200,000 to 3,200,000')
        ->and(Contact::query()->sole()->consent_marketing)->toBeFalse()
        ->and(Contact::query()->sole()->consent_whatsapp)->toBeFalse();
});

it('reuses the linked draft for a repeated browser estimator submission', function () {
    $this->userWithRole('owner');
    $this->postJson('/api/forms.php', browserEstimatorEnquiry())->assertCreated();
    $first = Estimate::query()->sole();

    $this->postJson('/api/forms.php', browserEstimatorEnquiry())->assertOk();

    expect(Lead::query()->count())->toBe(1)
        ->and(Estimate::query()->sole()->id)->toBe($first->id);
});

it('does not guess an estimate for unpriceable browser estimator inputs', function (array $overrides, bool $inactive) {
    $this->userWithRole('owner');
    if ($inactive) {
        EstimatorRate::query()->where('key', 'renovation')->update(['is_active' => false]);
    }
    $this->postJson('/api/forms.php', browserEstimatorEnquiry($overrides))->assertCreated();

    expect(Lead::query()->count())->toBe(1)
        ->and(Estimate::query()->count())->toBe(0);
})->with([
    'missing service' => [['service' => null], false],
    'unknown service' => [['service' => 'roof-thatching'], false],
    'inactive rate' => [[], true],
    'missing area' => [['area' => null], false],
    'empty area' => [['area' => ''], false],
    'zero area' => [['area' => '0'], false],
    'negative area' => [['area' => '-1'], false],
    'exponent area' => [['area' => '1e3'], false],
    'non-numeric area' => [['area' => 'NaN'], false],
    'over-limit area' => [['area' => '10000001'], false],
]);

it('keeps existing unsupported finish normalization on the browser estimator path', function () {
    $this->userWithRole('owner');
    $this->postJson('/api/forms.php', browserEstimatorEnquiry(['finish' => 'unsupported']))->assertCreated();

    expect(Estimate::query()->sole()->inputs['finish'])->toBe('standard');
});

it('does not draft an estimate from an ordinary contact form carrying estimator-like extras', function () {
    $this->userWithRole('owner');
    $this->postJson('/api/forms.php', browserEstimatorEnquiry(['form' => 'contact']))->assertCreated();

    expect(Lead::query()->sole()->source->value)->toBe('website_form')
        ->and(Estimate::query()->count())->toBe(0);
});

it('does not silently reprice the existing draft when a returning enquiry changes selections', function () {
    $this->userWithRole('owner');
    $firstResponse = $this->postJson('/api/forms.php', browserEstimatorEnquiry())->assertCreated();
    $first = Estimate::query()->sole();
    $inputs = $first->inputs;
    $computed = $first->computed;

    $this->postJson('/api/forms.php', browserEstimatorEnquiry(['area' => '2000', 'finish' => 'premium']))
        ->assertOk()->assertJsonPath('id', $firstResponse->json('id'));

    // This repair does not redefine CaptureLead's returning-enquiry metadata policy or overwrite snapshots.
    expect($first->refresh()->inputs)->toBe($inputs)
        ->and($first->computed)->toBe($computed)
        ->and($first->status)->toBe(EstimateStatus::Draft);
});

it('preserves the Contact JavaScript JSON payload contract without native transport metadata', function () {
    $this->postJson('/api/forms.php', originalEnquiry([
        'form' => 'contact', 'page' => '/contact/', 'service' => 'Fit-out',
    ]))->assertCreated()->assertHeader('Content-Type', 'application/json')->assertJsonStructure(['ok', 'id', 'reference']);

    $lead = Lead::query()->sole();
    expect($lead->source_detail)->toBe('contact')->and($lead->service)->toBe('Fit-out')
        ->and($lead->meta['page'])->toBe('/contact/')->and($lead->meta['fields'])->toBe(['timeline' => 'Next month'])
        ->and(Estimate::query()->count())->toBe(0);
});
