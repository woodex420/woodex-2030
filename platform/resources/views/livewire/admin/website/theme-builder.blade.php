<div>
    <x-admin.page-header title="Theme builder" description="The site's design tokens — v1's own palette. Saving re-skins every public page at once, no rebuild."
                         :breadcrumbs="['Website' => null, 'Theme builder' => null]">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-admin.button variant="secondary" href="{{ url('/') }}" target="_blank">Open the site</x-admin.button>
                <x-admin.button variant="secondary" wire:click="resetToV1" wire:loading.attr="disabled">Back to v1 palette</x-admin.button>
                <x-admin.button icon="save" wire:click="save" wire:loading.attr="disabled">Save theme</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.card class="mb-6" title="Presets" :padding="false">
        <div class="grid grid-cols-1 gap-4 px-5 py-4 md:grid-cols-3">
            @foreach (\App\Domain\Website\Support\ThemePresets::presets() as $preset)
                <button type="button" wire:click="applyPreset('{{ $preset['name'] }}')" wire:loading.attr="disabled"
                        class="rounded-2xl border border-gray-100 bg-white p-4 text-left transition hover:border-brand-500 hover:shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex gap-1.5">
                        @foreach ($preset['swatches'] as $swatch)
                            <span class="size-6 rounded-full ring-1 ring-black/10" style="background: {{ $swatch }}"></span>
                        @endforeach
                    </div>
                    <p class="mt-3 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $preset['name'] }}</p>
                    <p class="mt-1 text-theme-xs text-gray-500">{{ $preset['description'] }}</p>
                </button>
            @endforeach
        </div>
    </x-admin.card>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        @foreach ($this->groups as $group)
            <x-admin.card :title="$group['label']">
                <div class="space-y-4">
                    @foreach ($group['tokens'] as $index => $token)
                        <div wire:key="token-{{ $token['id'] }}">
                            @php($field = "tokens.{$token['flat']}.value")
                            @if ($token['type'] === 'color')
                                <div class="flex items-center justify-between gap-3">
                                    <label for="token-{{ $token['id'] }}" class="text-theme-sm text-gray-600 dark:text-gray-300">{{ $token['label'] }}</label>
                                    <div class="flex items-center gap-2">
                                        <input type="text" id="token-{{ $token['id'] }}" wire:model="tokens.{{ $token['flat'] }}.value"
                                               class="wx-input w-28 text-right text-theme-xs uppercase" />
                                        <input type="color" wire:model="tokens.{{ $token['flat'] }}.value"
                                               class="size-9 cursor-pointer rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-700 dark:bg-gray-900"
                                               aria-label="{{ $token['label'] }} picker" />
                                    </div>
                                </div>
                            @else
                                <div>
                                    <label for="token-{{ $token['id'] }}" class="wx-label">{{ $token['label'] }}</label>
                                    <input type="text" id="token-{{ $token['id'] }}" wire:model="tokens.{{ $token['flat'] }}.value" class="wx-input text-theme-sm" />
                                    <p class="mt-0.5 text-theme-xs text-gray-400">{{ $token['key'] }}</p>
                                </div>
                            @endif
                            @error($field)<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </x-admin.card>
        @endforeach
    </div>

    <x-admin.card class="mt-6">
        <p class="text-theme-sm text-gray-500">
            v2 pages link the theme as <code class="rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs dark:bg-white/10">{{ $this->currentUrl() }}</code> —
            a saved change mints a new URL, so browsers never serve yesterday's colours. The live v1 pages already link
            <code class="rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs dark:bg-white/10">/assets/theme.css</code> last:
            they pick a saved change up on the visitor's next page load, with no deploy. Keep the font a stack the pages
            load — today that is Plus Jakarta Sans from <code>/assets/fonts</code>.
        </p>
    </x-admin.card>
</div>
