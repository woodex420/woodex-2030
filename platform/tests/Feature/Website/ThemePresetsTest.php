<?php

use App\Domain\Website\Models\ThemeToken;
use App\Domain\Website\Support\ThemeCss;
use App\Domain\Website\Support\ThemePresets;
use App\Livewire\Admin\Website\ThemeBuilder;
use App\Models\User;
use Database\Seeders\ThemeTokensSeeder;
use Livewire\Livewire;

/*
| V2-3 F11 (docs/14 §5a): three complete looks beside the studio's own v1 palette. A preset is
| only the token keys it changes — applying one is editing every token by hand, and "Back to v1
| palette" still undoes the whole look in one click.
*/

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->owner->assignRole('owner');
});

it('keeps every preset honest: its keys exist, its values fit the token it lands on', function () {
    $keys = ThemeToken::query()->pluck('key')->all();

    foreach (ThemePresets::presets() as $preset) {
        expect($preset['name'])->not->toBe('')
            ->and($preset['description'])->not->toBe('')
            ->and($preset['swatches'])->toHaveCount(4);

        foreach ($preset['tokens'] as $key => $value) {
            expect($keys)->toContain($key);
            expect($value)->not->toBe('');

            if (str_starts_with($value, '#')) {
                expect($value)->toMatch('/^#[0-9A-F]{6}$/i');
            }
        }
    }

    expect(ThemePresets::presets())->toHaveCount(3)
        ->and(ThemePresets::find('No such preset'))->toBeNull();
});

it('applies a preset and re-skins every page, then back to v1 undoes it', function () {
    $before = ThemeToken::query()->where('key', '--navy')->value('value');

    $component = Livewire::actingAs($this->owner)
        ->test(ThemeBuilder::class)
        ->call('applyPreset', 'Warm walnut')
        ->assertDispatched('toast', message: 'Warm walnut applied — every page now wears it.');

    expect(ThemeToken::query()->where('key', '--navy')->value('value'))->toBe('#3E2B1F')
        ->and(ThemeToken::query()->where('key', '--r-lg')->value('value'))->toBe('12px')
        // The studio's v1 navy is remembered only by the seed.
        ->and($before)->not->toBe('#3E2B1F');

    // The stylesheet the site links really carries the preset.
    $css = app(ThemeCss::class)->css();
    expect($css)->toContain('#3E2B1F');

    // The inputs on screen show the preset too (no stale state).
    $component->assertSee('#3E2B1F');

    // One click home.
    $component->call('resetToV1')
        ->assertDispatched('toast', message: 'Back to the v1 palette.');

    expect(ThemeToken::query()->where('key', '--navy')->value('value'))->toBe($before)
        ->and(ThemeToken::query()->where('key', '--r-lg')->value('value'))->toBe(collect(ThemeTokensSeeder::tokens())->firstWhere('key', '--r-lg')['value']);
});

it('refuses preset names that are not on the shelf', function () {
    Livewire::actingAs($this->owner)
        ->test(ThemeBuilder::class)
        ->call('applyPreset', 'No such preset')
        ->assertDispatched('toast', message: 'That preset is not on the shelf.');

    expect(ThemeToken::query()->where('key', '--navy')->value('value'))->toBe(ThemeTokensSeeder::tokens()[0]['value']);
});
