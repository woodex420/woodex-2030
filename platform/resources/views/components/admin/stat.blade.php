@props(['label', 'value', 'icon' => 'activity', 'hint' => null, 'tone' => 'navy', 'href' => null])
@php
    $tones = [
        'navy' => 'bg-navy-50 text-navy-700 dark:bg-white/5 dark:text-navy-200',
        'wood' => 'bg-wood-50 text-wood-700 dark:bg-wood-500/10 dark:text-wood-300',
        'success' => 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500',
        'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500',
        'error' => 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-500',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->merge(['class' => 'wx-stat group flex flex-col justify-between gap-4 rounded-2xl border border-gray-200 bg-white p-5 transition hover:shadow-theme-md dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="flex items-center justify-between gap-3">
        <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
        <span class="flex size-10 items-center justify-center rounded-xl {{ $tones[$tone] ?? $tones['navy'] }}">
            <x-icon :name="$icon" class="size-5" />
        </span>
    </div>
    <div>
        <p class="font-heading text-title-sm leading-none tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $value }}</p>
        @if ($hint)
            <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
        @endif
    </div>
</{{ $tag }}>
