<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\System\Models\IntegrationLog;
use App\Integrations\Mcp\IssueMcpToken;
use App\Integrations\Mcp\McpAccessToken;
use App\Livewire\Admin\System\Integrations;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function bridgeKeyFor($user, array $abilities = ['status.read', 'leads.read', 'quotes.read']): string
{
    $plain = 'wx_mcp_'.bin2hex(random_bytes(32));
    McpAccessToken::query()->create(['user_id' => $user->id, 'name' => 'Test client',
        'token_hash' => hash('sha256', $plain), 'abilities' => $abilities, 'expires_at' => now()->addDay()]);

    return $plain;
}

it('requires an explicit bearer key even for signed-in owners', function () {
    $this->actingAs($this->userWithRole('owner'))->getJson('/api/bridge/v1/status')->assertUnauthorized();
    $this->getJson('/api/bridge/v1/status?token=wx_mcp_'.str_repeat('a', 64))->assertUnauthorized();
    $this->withToken('wrong')->getJson('/api/bridge/v1/status')->assertUnauthorized();
});

it('issues only hashed scoped keys and audits without storing the key', function () {
    $owner = $this->userWithRole('owner', ['password' => 'test-password']);
    $plain = app(IssueMcpToken::class)->handle($owner, 'test-password', 'Studio client', ['status.read'], 30);
    $key = McpAccessToken::query()->sole();
    expect($key->token_hash)->toBe(hash('sha256', $plain))->not->toBe($plain)
        ->and($key->toJson())->not->toContain('token_hash')->not->toContain($plain);
    $this->withToken($plain)->getJson('/api/bridge/v1/status')->assertOk()->assertJsonPath('read_only', true)
        ->assertJsonPath('abilities', ['status.read'])->assertHeader('Cache-Control', 'no-store, private');
    expect($key->fresh()->last_used_at)->not->toBeNull()
        ->and(IntegrationLog::query()->where('integration', 'mcp')->get()->toJson())->not->toContain($plain);
});

it('rejects revoked expired inactive and deleted-user credentials', function (string $state) {
    $user = $this->userWithRole('owner');
    $plain = bridgeKeyFor($user);
    $key = McpAccessToken::query()->sole();
    match ($state) {
        'revoked' => $key->update(['revoked_at' => now()]),
        'expired' => $key->update(['expires_at' => now()->subMinute()]),
        'inactive' => $user->update(['is_active' => false]),
        'deleted' => $user->delete(),
    };
    $this->withToken($plain)->getJson('/api/bridge/v1/status')->assertUnauthorized();
})->with(['revoked', 'expired', 'inactive', 'deleted']);

it('requires both the key scope and the current user permission', function () {
    $owner = $this->userWithRole('owner');
    $plain = bridgeKeyFor($owner, ['status.read']);
    $this->withToken($plain)->getJson('/api/bridge/v1/leads')->assertForbidden();
    $editor = $this->userWithRole('editor');
    $this->withToken(bridgeKeyFor($editor, ['leads.read']))->getJson('/api/bridge/v1/leads')->assertForbidden();
});

it('limits lead data to permitted records and omits contact and private fields', function () {
    $sales = $this->userWithRole('sales');
    $other = $this->userWithRole('sales');
    $own = Lead::factory()->create(['owner_id' => $sales->id, 'message' => 'PRIVATE-NOTE']);
    $hidden = Lead::factory()->create(['owner_id' => $other->id]);
    $plain = bridgeKeyFor($sales);
    $response = $this->withToken($plain)->getJson('/api/bridge/v1/leads?limit=5')->assertOk()
        ->assertJsonCount(1, 'items')->assertJsonPath('items.0.reference', $own->ref);
    expect($response->getContent())->not->toContain($hidden->ref)->not->toContain('PRIVATE-NOTE')
        ->not->toContain('phone')->not->toContain('email')->not->toContain('contact_id');
});

it('paginates bounded lists and rejects invalid limits or mutation methods', function () {
    $plain = bridgeKeyFor($this->userWithRole('owner'));
    $first = Lead::factory()->create();
    $second = Lead::factory()->create();
    $this->withToken($plain)->getJson('/api/bridge/v1/leads?limit=1')->assertOk()
        ->assertJsonCount(1, 'items')->assertJsonPath('next_after_id', $first->id);
    $this->withToken($plain)->getJson('/api/bridge/v1/leads?limit=1&after_id='.$first->id)->assertOk()
        ->assertJsonPath('items.0.id', $second->id)->assertJsonPath('next_after_id', null);
    $this->withToken($plain)->getJson('/api/bridge/v1/leads?limit=500')->assertUnprocessable();
    $this->withToken($plain)->getJson('/api/bridge/v1/quotes?after_id=-1')->assertUnprocessable();
    $this->withToken($plain)->postJson('/api/bridge/v1/leads')->assertStatus(405);
});

it('scopes quotes and returns integer monetary units without client data', function () {
    $sales = $this->userWithRole('sales');
    $other = $this->userWithRole('sales');
    $ownLead = Lead::factory()->create(['owner_id' => $sales->id]);
    $otherLead = Lead::factory()->create(['owner_id' => $other->id]);
    $own = app(CreateQuote::class)->handle($ownLead->contact, BusinessLine::Interior, $sales, $ownLead);
    $hidden = app(CreateQuote::class)->handle($otherLead->contact, BusinessLine::Interior, $other, $otherLead);
    $response = $this->withToken(bridgeKeyFor($sales))->getJson('/api/bridge/v1/quotes')->assertOk()
        ->assertJsonCount(1, 'items')->assertJsonPath('items.0.number', $own->number);
    expect($response->json('items.0.total_minor'))->toBeInt()
        ->and($response->getContent())->not->toContain($hidden->number)->not->toContain('contact');
});

it('does not permit admin key issuance or owner password bypass', function () {
    $owner = $this->userWithRole('owner', ['password' => 'test-password']);
    $this->actingAs($owner);
    Livewire::test(Integrations::class)->set('bridgeName', 'Studio')->set('bridgePassword', 'wrong')
        ->call('createBridgeKey')->assertHasErrors('bridgePassword')->assertSet('bridgePassword', '');
    expect(McpAccessToken::query()->count())->toBe(0);
    $this->actingAs($this->userWithRole('admin'));
    Livewire::test(Integrations::class)->call('createBridgeKey')->assertForbidden();
});

it('shows a new key once and allows owners to revoke it', function () {
    $this->actingAs($this->userWithRole('owner', ['password' => 'test-password']));
    $screen = Livewire::test(Integrations::class)->call('openMcpSetup')->set('bridgeName', 'Studio')
        ->set('bridgePassword', 'test-password')->call('createBridgeKey')->assertHasNoErrors();
    $plain = $screen->get('newBridgeToken');
    expect($plain)->toStartWith('wx_mcp_');
    $screen->assertSee($plain)->call('$refresh')->assertDontSee($plain)->assertSet('newBridgeToken', null);
    $key = McpAccessToken::query()->sole();
    $screen->call('revokeBridgeKey', $key->id);
    $this->withToken($plain)->getJson('/api/bridge/v1/status')->assertUnauthorized();
});

it('rejects write scopes instead of silently widening a key', function () {
    $this->actingAs($this->userWithRole('owner', ['password' => 'test-password']));
    Livewire::test(Integrations::class)->set('bridgeName', 'Studio')->set('bridgePassword', 'test-password')
        ->set('bridgeAbilities', ['leads.write'])->call('createBridgeKey')->assertHasErrors();
    expect(McpAccessToken::query()->count())->toBe(0);
});

it('shows one grid of real integrations without roadmap cards', function () {
    $this->actingAs($this->userWithRole('owner'));
    Livewire::test(Integrations::class)
        ->assertSee('Google Drive')->assertSee('GitHub')->assertSee('Custom API')
        ->assertDontSee('Other connections')->assertDontSee('Planned')
        ->set('appCategory', 'social')->assertSee('Facebook Page')->assertDontSee('Telegram bot')
        ->set('appCategory', 'all')->set('appSearch', 'Notion')
        ->assertSee('No integrations match your search');
});

it('rechecks visibility after the issuing owner is demoted', function () {
    $owner = $this->userWithRole('owner');
    $hidden = Lead::factory()->create();
    $plain = bridgeKeyFor($owner);
    $owner->removeRole('owner')->assignRole('sales');
    $this->withToken($plain)->getJson('/api/bridge/v1/leads')->assertOk()->assertJsonCount(0, 'items');
});

it('enforces per-key rate limiting after authentication', function () {
    $plain = bridgeKeyFor($this->userWithRole('owner'));
    $key = McpAccessToken::query()->sole();
    for ($i = 0; $i < 60; $i++) {
        RateLimiter::hit('mcp-key:'.$key->id, 60);
    }
    $this->withToken($plain)->getJson('/api/bridge/v1/status')->assertStatus(429)->assertHeader('Retry-After');
});

it('refuses plaintext transport in production', function () {
    $plain = bridgeKeyFor($this->userWithRole('owner'));
    app()->detectEnvironment(fn () => 'production');
    $this->withToken($plain)->getJson('http://woodex.test/api/bridge/v1/status')->assertBadRequest();
});

it('keeps revocation owner-only and idempotent', function () {
    $owner = $this->userWithRole('owner');
    bridgeKeyFor($owner);
    $key = McpAccessToken::query()->sole();
    $this->actingAs($this->userWithRole('admin'));
    Livewire::test(Integrations::class)->call('revokeBridgeKey', $key->id)->assertForbidden();
    expect($key->fresh()->revoked_at)->toBeNull();
    $this->actingAs($owner);
    Livewire::test(Integrations::class)->call('revokeBridgeKey', $key->id)->call('revokeBridgeKey', $key->id);
    expect($key->fresh()->revoked_at)->not->toBeNull()
        ->and(IntegrationLog::query()->where('action', 'key.revoked')->count())->toBe(1);
});
