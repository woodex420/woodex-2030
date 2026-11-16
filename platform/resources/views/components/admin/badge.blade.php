@props(['color' => 'gray', 'dot' => true])
@php
    $colors = [
        'gray' => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300',
        'info' => 'bg-info-50 text-info-700 dark:bg-info-500/15 dark:text-info-500',
        'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-500',
        'success' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500',
        'error' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-500',
        'navy' => 'bg-navy-50 text-navy-800 dark:bg-white/10 dark:text-white',
        'wood' => 'bg-wood-50 text-wood-700 dark:bg-wood-500/15 dark:text-wood-300',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-theme-xs font-medium whitespace-nowrap '.($colors[$color] ?? $colors['gray'])]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
