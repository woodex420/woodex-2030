@props(['title' => null, 'description' => null, 'padding' => true])
{{-- A card of the theme (DL-017): white, a 1 px edge, 12 px corners, a hair of shadow; its title is a small
     semibold line above a rule. --}}
<section {{ $attributes->merge(['class' => 'wx-surface wx-card rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    @if ($title || isset($actions))
        <header class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-3.5 dark:border-gray-800">
            <div>
                @if ($title)
                    <h2 class="text-[15px] leading-6 font-semibold text-gray-900 dark:text-white/90">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-theme-xs text-gray-500">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif
    <div @class(['p-5' => $padding])>
        {{ $slot }}
    </div>
</section>
