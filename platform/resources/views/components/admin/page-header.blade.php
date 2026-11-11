@props(['title', 'description' => null, 'breadcrumbs' => []])
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        @if ($breadcrumbs)
            <nav class="mb-1.5 flex items-center gap-1.5 text-theme-xs text-gray-500" aria-label="Breadcrumb">
                @foreach ($breadcrumbs as $label => $url)
                    @if ($url)
                        <a href="{{ $url }}" wire:navigate class="hover:text-navy-700 dark:hover:text-white">{{ $label }}</a>
                    @else
                        <span>{{ $label }}</span>
                    @endif
                    @unless ($loop->last)
                        <x-icon name="chevron-right" class="size-3" />
                    @endunless
                @endforeach
            </nav>
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
