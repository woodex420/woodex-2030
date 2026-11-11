<?php

declare(strict_types=1);

use App\Domain\Website\Models\ThemeToken;
use App\Domain\Website\Support\ThemeCss;
use App\Livewire\Admin\Website\ThemeBuilder;
use App\Models\User;
use Database\Seeders\ThemeTokensSeeder;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
| One theme, two URLs (docs/12 §5.1): theme_tokens compile to /assets/theme.css for the v1 pages and
| /theme/{hash}.css for the v2 pages. The builder's Theme tab edits the same tokens — never a second
| theme system.
*/

beforeEach(function () {
    (new ThemeTokensSeeder)->run();

    $this->owner = User::factory()->create();
    $this->owner->givePermissionTo(['website.edit', 'website.publish', 'website.raw_html', 'theme.manage']);

    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo(['website.edit', 'website.publish']);
});

function themeApi(User $user, array $payload): TestResponse
{
    return test()->actingAs($user)->postJson(route('admin.website.builder.api'), $payload);
}

it('serves the live theme at the URL every v1 page links', function () {
    $response = $this->get('/assets/theme.css');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=utf-8');

    expect($response->getContent())
        ->toContain('--navy: #0C1628;')
        ->toContain('--wx-navy: #0C1628;')
        ->toContain('--wx-base-size: 16px;');
});

it('serves the hashed theme immutable, and an old hash with revalidation', function () {
    $hash = app(ThemeCss::class)->hash();

    $fresh = $this->get('/theme/'.$hash.'.css');
    $fresh->assertOk();
    expect($fresh->headers->get('Cache-Control'))->toContain('immutable');

    $stale = $this->get('/theme/000000000000.css');
    $stale->assertOk();
    expect($stale->headers->get('Cache-Control'))->toContain('must-revalidate')
        ->and($stale->getContent())->toBe($fresh->getContent());
});

it('gives the builder every control of the v2.1 theme panel', function () {
    $vars = themeApi($this->editor, ['action' => 'theme_get'])->assertOk()->json('vars');

    expect($vars)->toHaveKeys([
        '--wx-navy', '--wx-ink', '--wx-muted', '--wx-paper', '--wx-surface', '--wx-beige',
        '--wx-font', '--wx-font-head', '--wx-base-size', '--wx-h1', '--wx-h2', '--wx-h3',
        '--wx-section', '--wx-container', '--wx-btn-radius', '--wx-r-lg', '--wx-c1',
    ])->and($vars['--wx-navy'])->toBe('#0C1628');
});

it('saves the builder theme into the one token store', function () {
    themeApi($this->owner, [
        'action' => 'theme',
        'vars' => [
            '--wx-navy' => '#101820',
            '--wx-base-size' => '18px',
            '--wx-section' => '96px',
            '--wx-font-head' => 'Georgia, serif',
        ],
    ])->assertOk()->assertJsonPath('ok', true);

    expect(ThemeToken::query()->where('key', '--navy')->value('value'))->toBe('#101820')
        ->and(ThemeToken::query()->where('key', '--wx-base-size')->value('value'))->toBe('18px')
        ->and(ThemeToken::query()->where('key', '--wx-section')->value('value'))->toBe('96px');

    // The cached CSS is dropped, so the very next request carries the new palette.
    expect($this->get('/assets/theme.css')->getContent())
        ->toContain('--navy: #101820;')
        ->toContain('--wx-section: 96px;');
});

it('lets only a theme manager change the site theme', function () {
    themeApi($this->editor, [
        'action' => 'theme',
        'vars' => ['--wx-navy' => '#000000'],
    ])->assertOk()->assertJsonPath('ok', false);

    expect(ThemeToken::query()->where('key', '--navy')->value('value'))->toBe('#0C1628');
});

it('refuses a value that is not a colour or a size', function () {
    themeApi($this->owner, [
        'action' => 'theme',
        'vars' => ['--wx-navy' => 'red;}</style><script>alert(1)</script>'],
    ])->assertStatus(422);

    expect(ThemeToken::query()->where('key', '--navy')->value('value'))->toBe('#0C1628');
});

it('gives the panel the v2.1 font codes it understands, not a css stack', function () {
    ThemeToken::query()->where('key', '--wx-font')->update(['value' => 'dm']);
    ThemeToken::query()->where('key', '--wx-font-head')->update(['value' => '"Playfair Display", Georgia, serif']);

    $vars = themeApi($this->editor, ['action' => 'theme_get'])->assertOk()->json('vars');

    expect($vars['--wx-font'])->toBe('dm')
        ->and($vars['--wx-font-head'])->toBe('g:Playfair Display:');
});

it('stores a v2.1 font code as a complete css stack', function () {
    themeApi($this->owner, [
        'action' => 'theme',
        'vars' => ['--wx-font' => 'dm', '--wx-font-head' => 'g:Poppins:400;600'],
    ])->assertOk()->assertJsonPath('ok', true);

    expect(ThemeToken::query()->where('key', '--wx-font')->value('value'))
        ->toBe('"DM Sans", system-ui, sans-serif')
        ->and(ThemeToken::query()->where('key', '--wx-font-head')->value('value'))
        ->toBe('"Poppins", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif');

    expect($this->get('/assets/theme.css')->getContent())
        ->toContain('--wx-font: "DM Sans", system-ui, sans-serif;')
        ->toContain('--wx-font-head: "Poppins", system-ui,');
});

it('keeps a pasted stack verbatim and refuses css injection in a font', function () {
    themeApi($this->owner, [
        'action' => 'theme',
        'vars' => ['--wx-font' => '"Brand Sans", "Segoe UI", sans-serif'],
    ])->assertOk()->assertJsonPath('ok', true);

    expect(ThemeToken::query()->where('key', '--wx-font')->value('value'))
        ->toBe('"Brand Sans", "Segoe UI", sans-serif');

    themeApi($this->owner, [
        'action' => 'theme',
        'vars' => ['--wx-font' => 'Georgia; } body { display:none'],
    ])->assertStatus(422);

    themeApi($this->owner, [
        'action' => 'theme',
        'vars' => ['--wx-font' => 'g:Bad<script>:400'],
    ])->assertStatus(422);
});

it('adds newly seeded tokens to an installation that predates them', function () {
    ThemeToken::query()->where('key', '--wx-h1')->delete();

    Livewire::actingAs($this->owner)->test(ThemeBuilder::class);

    expect(ThemeToken::query()->where('key', '--wx-h1')->exists())->toBeTrue()
        ->and(ThemeToken::query()->where('key', '--wx-h1')->value('value'))->toBe('72px');
});
