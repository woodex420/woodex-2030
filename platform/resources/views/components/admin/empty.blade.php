@props(['icon' => 'inbox', 'title', 'description' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-14 text-center']) }}>
    <span class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-sand-100 text-wood-600 dark:bg-wood-500/10 dark:text-wood-300">
        <x-icon :name="$icon" class="size-7" />
    </span>
    <h3 class="text-theme-xl font-medium text-gray-800 dark:text-white/90">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1.5 max-w-md text-theme-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif
    @if (trim($slot) !== '')
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
