<?php

use App\Domain\System\Models\IntegrationLog;
use App\Integrations\Mcp\IssueMcpToken;
use App\Integrations\Mcp\McpAccessToken;

function mcpKey($user, array $abilities): string
{
    $plain = 'wx_mcp_'.bin2hex(random_bytes(32));
    McpAccessToken::query()->create(['user_id' => $user->id, 'name' => 'HTTP client',
        'token_hash' => hash('sha256', $plain), 'abilities' => $abilities, 'expires_at' => now()->addDay()]);

    return $plain;
}

function rpc(string $method, array $params = [], int|string|null $id = 1): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
}

it('requires a bearer key for the remote MCP endpoint', function () {
    $this->postJson('/api/mcp', rpc('initialize'))->assertUnauthorized();
    $this->withToken('wrong')->postJson('/api/mcp', rpc('initialize'))->assertUnauthorized();
    $this->withToken('wx_mcp_'.str_repeat('a', 64))->postJson('/api/mcp', rpc('initialize'))->assertUnauthorized();
    $this->getJson('/api/mcp')->assertStatus(405);
});

it('answers initialize and accepts the initialized notification', function () {
    $key = mcpKey($this->userWithRole('owner'), ['status.read']);

    $this->withToken($key)->postJson('/api/mcp', rpc('initialize'))->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'woodex')
        ->assertJsonPath('result.capabilities.tools.listChanged', false)
        ->assertJsonStructure(['result' => ['protocolVersion', 'instructions']]);

    $this->withToken($key)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
        ->assertStatus(202);
});

it('lists only the tools the key may use', function () {
    $statusOnly = mcpKey($this->userWithRole('owner'), ['status.read']);
    $response = $this->withToken($statusOnly)->postJson('/api/mcp', rpc('tools/list'))->assertOk();
    expect(array_column($response->json('result.tools'), 'name'))->toBe(['woodex_status']);

    $siteKey = mcpKey($this->userWithRole('owner'), ['status.read', 'site.read', 'pages.read']);
    $names = array_column($this->withToken($siteKey)->postJson('/api/mcp', rpc('tools/list'))->json('result.tools'), 'name');
    expect($names)->toBe(['woodex_status', 'woodex_site_status', 'woodex_list_pages', 'woodex_get_page', 'woodex_seo_report']);
});

it('reads site health and pages from the files the site serves', function () {
    $key = mcpKey($this->userWithRole('owner'), ['status.read', 'site.read', 'pages.read']);

    $status = $this->withToken($key)->postJson('/api/mcp', rpc('tools/call', ['name' => 'woodex_site_status', 'arguments' => []]))
        ->assertOk()->assertJsonPath('result.isError', false);
    expect($status->json('result.structuredContent.pages'))->toBeGreaterThan(100)
        ->and($status->json('result.structuredContent'))->toHaveKeys(['sitemap_urls', 'broken_links', 'images_without_alt', 'redirects_astray'])
        ->and($status->json('result.content.0.text'))->toContain('"pages"');

    $pages = $this->withToken($key)->postJson('/api/mcp', rpc('tools/call', ['name' => 'woodex_list_pages', 'arguments' => ['limit' => 3]]))
        ->assertOk()->assertJsonCount(3, 'result.structuredContent.items');
    expect($pages->json('result.structuredContent.total'))->toBeGreaterThan(100);

    $page = $this->withToken($key)->postJson('/api/mcp', rpc('tools/call', ['name' => 'woodex_get_page', 'arguments' => ['url' => '/about/']]))
        ->assertOk()->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.path', 'about/index.html');
    expect($page->json('result.structuredContent.title'))->not->toBe('');
});

it('returns a tool error for a page that does not exist', function () {
    $key = mcpKey($this->userWithRole('owner'), ['pages.read']);

    $this->withToken($key)->postJson('/api/mcp', rpc('tools/call', ['name' => 'woodex_get_page', 'arguments' => ['path' => '../.env']]))
        ->assertOk()->assertJsonPath('result.isError', true);
});

it('refuses a tool the key does not carry', function () {
    $key = mcpKey($this->userWithRole('owner'), ['status.read']);

    $response = $this->withToken($key)->postJson('/api/mcp', rpc('tools/call', ['name' => 'woodex_list_pages', 'arguments' => []]))
        ->assertOk();

    expect($response->json('error.code'))->toBe(-32003)
        ->and($response->json('error.message'))->toContain('pages.read');
});

it('returns a protocol error for an unknown method or tool', function () {
    $key = mcpKey($this->userWithRole('owner'), ['status.read']);

    expect($this->withToken($key)->postJson('/api/mcp', rpc('nope'))->json('error.code'))->toBe(-32601)
        ->and($this->withToken($key)->postJson('/api/mcp', rpc('tools/call', ['name' => 'woodex_nope']))->json('error.code'))->toBe(-32602);
});

it('audits every tool call without storing the key or page contents', function () {
    $key = mcpKey($this->userWithRole('owner'), ['site.read']);

    $this->withToken($key)->postJson('/api/mcp', rpc('tools/call', ['name' => 'woodex_site_status', 'arguments' => []]))->assertOk();

    $log = IntegrationLog::query()->where('integration', 'mcp')->where('action', 'rpc.woodex_site_status')->sole();
    expect($log->ok)->toBeTrue()
        ->and($log->toJson())->not->toContain($key);
});

it('issues keys that can carry the website scopes', function () {
    $owner = $this->userWithRole('owner', ['password' => 'test-password']);
    $plain = app(IssueMcpToken::class)->handle($owner, 'test-password', 'Website client', ['status.read', 'site.read', 'pages.read'], 10);

    $this->withToken($plain)->postJson('/api/mcp', rpc('tools/call', ['name' => 'woodex_list_pages', 'arguments' => ['limit' => 1]]))
        ->assertOk()->assertJsonPath('result.isError', false);
});
