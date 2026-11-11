<div>
    {{-- The active tab renders its own page header (title, description, breadcrumbs and actions), so the hub
         adds only the tab bar above it — never a second copy of the same heading (deep QA, duplicate header). --}}
    <div class="mb-6 overflow-x-auto" role="tablist" aria-label="{{ $title }} views">
        <div class="flex w-max min-w-full items-center gap-1 rounded-xl border border-gray-200 bg-gray-50 p-1 dark:border-gray-800 dark:bg-gray-900/60">
            @foreach ($tabs as $tab)
                @continue(! $tab['visible'])
                <a href="?tab={{ $tab['id'] }}" wire:navigate role="tab"
                   aria-selected="{{ $tab['id'] === $active['id'] ? 'true' : 'false' }}"
                   @class([
                       'rounded-lg px-4 py-2 text-theme-sm font-medium whitespace-nowrap transition',
                       'bg-white text-navy-900 shadow-sm dark:bg-gray-800 dark:text-white' => $tab['id'] === $active['id'],
                       'text-gray-500 hover:text-navy-900 dark:text-gray-400 dark:hover:text-white' => $tab['id'] !== $active['id'],
                   ])>
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
    </div>

    @if (! empty($control))
        {{-- The same six numbers, from the same queries, as the dashboard (deep QA slice D). --}}
        <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6">
            @foreach ($control as $card)
                <a href="{{ $card['href'] }}" wire:navigate
                   class="rounded-xl border border-gray-200 bg-white px-4 py-3 transition hover:shadow-theme-md dark:border-gray-800 dark:bg-white/[0.03]">
                    <span class="flex items-center gap-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        <x-icon :name="$card['icon']" class="size-3.5" />{{ $card['label'] }}
                    </span>
                    <span class="mt-1 block font-heading text-theme-xl text-gray-900 dark:text-white">{{ $card['display'] }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <livewire:dynamic-component :component="$activeAlias" :key="'hub-'.$active['id']" />
</div>
