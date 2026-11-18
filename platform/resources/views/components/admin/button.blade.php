@props(['variant' => 'primary', 'size' => 'md', 'icon' => null, 'href' => null, 'type' => 'button'])
@php
    $variants = [
        'primary' => 'wx-primary-button',
        'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/5',
        'ghost' => 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5',
        'danger' => 'bg-error-600 text-white hover:bg-error-700',
        'wood' => 'bg-wood-500 text-white hover:bg-wood-600',
    ];
    $sizes = ['sm' => 'h-8 px-3 text-theme-xs gap-1.5', 'md' => 'h-10 px-4 text-theme-sm gap-2'];
    $classes = 'inline-flex items-center justify-center rounded-lg font-medium shadow-theme-xs transition disabled:cursor-not-allowed disabled:opacity-60 '
        .($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </button>
@endif
