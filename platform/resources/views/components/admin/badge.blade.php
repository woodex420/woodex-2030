@props(['color' => 'gray', 'dot' => true])
@php
    $colors = [
        // A pill of the theme (DL-017): a tint, text of the same hue, and an edge one step darker than the tint.
        'gray' => 'border-gray-200 bg-gray-100 text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-300',
        'info' => 'border-info-100 bg-info-50 text-info-700 dark:border-info-500/30 dark:bg-info-500/15 dark:text-info-500',
        'warning' => 'border-warning-100 bg-warning-50 text-warning-700 dark:border-warning-500/30 dark:bg-warning-500/15 dark:text-warning-500',
        'success' => 'border-success-100 bg-success-50 text-success-700 dark:border-success-500/30 dark:bg-success-500/15 dark:text-success-500',
        'error' => 'border-error-100 bg-error-50 text-error-700 dark:border-error-500/30 dark:bg-error-500/15 dark:text-error-500',
        'navy' => 'border-navy-200 bg-navy-50 text-navy-800 dark:border-white/15 dark:bg-white/10 dark:text-white',
        'wood' => 'border-wood-200 bg-wood-50 text-wood-700 dark:border-wood-500/30 dark:bg-wood-500/15 dark:text-wood-300',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-theme-xs font-medium whitespace-nowrap '.($colors[$color] ?? $colors['gray'])]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
