@props(['title' => null, 'description' => null, 'padding' => true])
<section {{ $attributes->merge(['class' => 'wx-surface rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    @if ($title || isset($actions))
        <header class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
            <div>
                @if ($title)
                    <h2 class="text-theme-xl font-medium text-gray-800 dark:text-white/90">{{ $title }}</h2>
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
