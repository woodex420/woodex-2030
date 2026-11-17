@props(['title', 'description' => null, 'breadcrumbs' => []])
@php
    // The nearest step of the trail that is a link: the way back up, shown here on a phone.
    $back = collect($breadcrumbs)->filter()->map(fn ($url, $label) => ['label' => $label, 'url' => $url])->last();
@endphp
{{--
    The trail itself is shown in the top bar (components/admin/header), which has no room for it on a phone.
    There the page keeps one link: back to the nearest step above it.
--}}
@if ($breadcrumbs)
    {{-- Once: the first page header of a page gives the trail. --}}
    @pushOnce('wx-breadcrumbs')
        @foreach ($breadcrumbs as $label => $url)
            @if ($url)
                <a href="{{ $url }}" wire:navigate class="wx-topbar-crumb">{{ $label }}</a>
            @else
                <span class="wx-topbar-crumb @if ($loop->last) is-current @endif" @if ($loop->last) aria-current="page" @endif>{{ $label }}</span>
            @endif
            @unless ($loop->last)
                <x-icon name="chevron-right" class="size-3.5 shrink-0" />
            @endunless
        @endforeach
    @endPushOnce
@endif
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back['url'] }}" wire:navigate class="wx-page-back mb-1 inline-flex min-h-6 items-center gap-1 text-theme-xs lg:hidden">
                <x-icon name="chevron-left" class="size-3.5" /> {{ $back['label'] }}
            </a>
        @endif
        <h1 class="font-heading text-title-sm font-normal tracking-tight text-gray-900 dark:text-white">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1 max-w-2xl text-theme-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
