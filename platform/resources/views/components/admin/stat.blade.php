@props(['label', 'value', 'icon' => 'activity', 'hint' => null, 'tone' => 'navy', 'href' => null])
@php
    $tones = [
        'navy' => 'wx-stat-tile',
        'wood' => 'bg-wood-50 text-wood-700 dark:bg-wood-500/10 dark:text-wood-300',
        'success' => 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500',
        'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500',
        'error' => 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-500',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->merge(['class' => 'wx-stat group flex items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white p-5 transition dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    {{-- A figure of the theme (DL-017): a small upper-case label, the figure bold, a line of context, and a
         tinted tile with the icon at the right. --}}
    <div class="min-w-0">
        <p class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $label }}</p>
        <p class="mt-1.5 truncate text-2xl leading-8 font-bold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $value }}</p>
        @if ($hint)
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
        @endif
    </div>
    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $tones[$tone] ?? $tones['navy'] }}">
        <x-icon :name="$icon" class="size-5" />
    </span>
</{{ $tag }}>
