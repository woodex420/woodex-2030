<?php

use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Settings\BusinessSettings;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $business = app(BusinessSettings::class);
    $business->whatsapp = '+923001234567';
    $business->save();
});

function handoffPayload(array $overrides = []): array
{
    return array_merge(['name' => 'Synthetic handoff visitor', 'email' => 'handoff@example.test',
        'consent' => true, 'preferred_channel' => 'whatsapp'], $overrides);
}

it('issues a hashed capability with no public ID and logs only one manual handoff on retry', function () {
    $capture = $this->postJson('/api/v1/leads', handoffPayload())->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('whatsapp_url', null);
    $token = $capture->json('handoff.token');
    expect(strlen($token))->toBe(64)->and($capture->json('id'))->toBeNull();
    $row = DB::table('lead_handoff_capabilities')->sole();
    expect($row->token_hash)->toBe(hash('sha256', $token))->not->toBe($token)->and($row->redeemed_at)->toBeNull();
    $before = Activity::count();
    $reply = $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => $token])->assertOk()
        ->assertJsonPath('logged', true)->assertJsonPath('delivery', 'manual_handoff_not_sent')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($reply->json('whatsapp_url'))->toStartWith('https://wa.me/923001234567?text=')
        ->toContain(rawurlencode($capture->json('reference')));
    $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => $token])->assertOk();
    expect(Activity::count())->toBe($before + 1)
        ->and(Activity::latest('id')->first()->data['delivery'])->toBe('manual_handoff_not_sent');
});

it('does not issue capabilities without current consent and channel or for spam or legacy capture', function () {
    $this->postJson('/api/v1/leads', handoffPayload(['consent' => false]))->assertCreated()->assertJsonPath('handoff', null);
    $this->postJson('/api/v1/leads', handoffPayload(['preferred_channel' => 'email']))->assertOk()->assertJsonPath('handoff', null);
    $this->postJson('/api/v1/leads', handoffPayload(['company_website' => 'spam']))->assertStatus(202)->assertJsonMissingPath('handoff');
    $this->postJson('/api/public/leads', handoffPayload())->assertCreated()->assertJsonMissingPath('handoff');
    expect(DB::table('lead_handoff_capabilities')->count())->toBe(0);
});

it('rejects guessed malformed expired and deleted-lead capabilities without an activity', function () {
    $capture = $this->postJson('/api/v1/leads', handoffPayload())->assertCreated();
    $token = $capture->json('handoff.token');
    $before = Activity::count();
    $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => '1'])->assertUnprocessable();
    $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => str_repeat('0', 64)])->assertNotFound();
    $this->travel(16)->minutes();
    $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => $token])->assertNotFound();
    $this->travelBack();
    Lead::query()->sole()->delete();
    $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => $token])->assertNotFound();
    expect(Activity::count())->toBe($before);
});

it('binds each capability to its own enquiry and ignores caller lead and redirect values', function () {
    $one = $this->postJson('/api/v1/leads', handoffPayload())->assertCreated();
    $two = $this->postJson('/api/v1/leads', handoffPayload(['email' => 'other@example.test']))->assertCreated();
    $reply = $this->postJson('/api/v1/leads/whatsapp-handoff', [
        'token' => $one->json('handoff.token'), 'lead_id' => Lead::latest('id')->first()->id,
        'whatsapp_url' => 'https://evil.example.test',
    ])->assertOk();
    expect($reply->json('whatsapp_url'))->toContain(rawurlencode($one->json('reference')))
        ->not->toContain(rawurlencode($two->json('reference')));
    expect(Activity::latest('id')->first()->lead_id)->toBe(Lead::oldest('id')->first()->id);
});

it('gates redemption with the module switch and refuses GET', function () {
    $capture = $this->postJson('/api/v1/leads', handoffPayload())->assertCreated();
    $this->getJson('/api/v1/leads/whatsapp-handoff')->assertStatus(405);
    config(['modules.enabled' => '']);
    $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => $capture->json('handoff.token')])->assertNotFound();
});

it('withholds the capability for an invalid destination and refuses redemption if configuration disappears', function () {
    $capture = $this->postJson('/api/v1/leads', handoffPayload())->assertCreated();
    $business = app(BusinessSettings::class);
    $business->whatsapp = '';
    $business->save();
    $before = Activity::count();
    $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => $capture->json('handoff.token')])->assertStatus(503);
    expect(Activity::count())->toBe($before);
    $this->postJson('/api/v1/leads', handoffPayload())->assertOk()->assertJsonPath('handoff', null);
});

it('throttles capability redemption independently of capture', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => str_repeat('0', 64)])->assertNotFound();
    }
    $this->postJson('/api/v1/leads/whatsapp-handoff', ['token' => str_repeat('0', 64)])->assertStatus(429);
});
