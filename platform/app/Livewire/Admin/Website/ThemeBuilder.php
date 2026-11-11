<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Domain\Website\Models\ThemeToken;
use App\Domain\Website\Support\ThemeCss;
use App\Domain\Website\Support\ThemePresets;
use Database\Seeders\ThemeTokensSeeder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Theme Builder (V2-1, docs/12 §5.1, D-116): v1's palette as editable tokens. Saving re-skins every
 * public page at once — the pages read these values as CSS custom properties, no rebuild, no republish.
 * The v1 palette ships as the seed, so "back to v1" is always one button away.
 */
#[Layout('layouts::admin')]
#[Title('Theme builder')]
class ThemeBuilder extends Component
{
    /** @var array<int, array{id: int, key: string, value: string, label: string, type: string, group: string}> */
    public array $tokens = [];

    public function mount(): void
    {
        $this->authorize('theme.manage');
        $this->loadTokens();
    }

    public function save(ThemeCss $theme): void
    {
        $this->authorize('theme.manage');

        $this->validate([
            'tokens.*.value' => ['required', 'string', 'max:200'],
        ], [], ['tokens.*.value' => 'token value']);

        foreach ($this->tokens as $token) {
            ThemeToken::query()->where('id', $token['id'])->update([
                'value' => $this->normalise($token['value'], $token['type']),
            ]);
        }

        $theme->flush();
        $this->loadTokens();
        $this->dispatch('toast', message: 'Theme saved — every page now wears it.', type: 'success');
    }

    /**
     * Wear one of the shipped presets (V2-3 F11): the preset's keys are written, every other token
     * keeps its value — and "Back to v1 palette" still undoes the whole look in one click.
     */
    public function applyPreset(string $preset, ThemeCss $theme): void
    {
        $this->authorize('theme.manage');

        $found = ThemePresets::find($preset);

        if ($found === null) {
            $this->dispatch('toast', message: 'That preset is not on the shelf.', type: 'error');

            return;
        }

        foreach ($found['tokens'] as $key => $value) {
            ThemeToken::query()->where('key', $key)->update(['value' => $this->normalise($value, $this->typeOf($key))]);
        }

        $theme->flush();
        $this->loadTokens();
        $this->dispatch('toast', message: $found['name'].' applied — every page now wears it.', type: 'success');
    }

    /**
     * A preset value goes through the same normalisation as a hand-edited one; unknown keys keep text.
     */
    private function typeOf(string $key): string
    {
        foreach ($this->tokens as $token) {
            if ($token['key'] === $key) {
                return $token['type'];
            }
        }

        return 'text';
    }

    /**
     * Back to the studio's own v1 look (D-116): restore every token to the seeded palette.
     */
    public function resetToV1(ThemeCss $theme): void
    {
        $this->authorize('theme.manage');

        foreach (ThemeTokensSeeder::tokens() as $token) {
            ThemeToken::query()->where('key', $token['key'])->update(['value' => $token['value']]);
        }

        $theme->flush();
        $this->loadTokens();
        $this->dispatch('toast', message: 'Back to the v1 palette.', type: 'success');
    }

    /**
     * Tokens grouped for the cards, each carrying its index in the flat $tokens array so the editor
     * inputs bind to the right property.
     *
     * @return list<array{label: string, tokens: list<array{id: int, key: string, value: string, label: string, type: string, group: string, flat: int}>}>
     */
    #[Computed]
    public function groups(): array
    {
        $labels = ['colour' => 'Colour', 'typography' => 'Typography', 'shape' => 'Shape & radius'];

        $indexed = array_map(fn (array $token, int $flat): array => $token + ['flat' => $flat], $this->tokens, array_keys($this->tokens));

        return collect($indexed)
            ->groupBy('group')
            ->map(fn ($tokens, string $group): array => ['label' => $labels[$group] ?? ucfirst($group), 'tokens' => $tokens->values()->all()])
            ->values()
            ->all();
    }

    /**
     * The stylesheet URL the site wears right now — for the preview link.
     */
    #[Computed]
    public function currentUrl(): string
    {
        return app(ThemeCss::class)->url();
    }

    private function loadTokens(): void
    {
        // A token added after this install was seeded (the v2.1 panel's type scale, for example)
        // appears here on the next visit instead of waiting for a manual db:seed.
        foreach (ThemeTokensSeeder::tokens() as $index => $token) {
            ThemeToken::query()->firstOrCreate(
                ['key' => $token['key']],
                $token + ['sort' => 500 + $index],
            );
        }

        $this->tokens = ThemeToken::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (ThemeToken $token): array => [
                'id' => $token->id,
                'key' => $token->key,
                'value' => $token->value,
                'label' => $token->label,
                'type' => $token->type,
                'group' => $token->group,
            ])
            ->all();
    }

    /**
     * Colours get a canonical lowercase hex; fonts and shapes keep their shape.
     */
    private function normalise(string $value, string $type): string
    {
        $value = trim($value);

        if ($type === 'color' && preg_match('/^#([0-9a-fA-F]{6})$/', $value, $match) === 1) {
            return '#'.strtoupper($match[1]);
        }

        return $value;
    }

    public function render(): View
    {
        return view('livewire.admin.website.theme-builder');
    }
}
