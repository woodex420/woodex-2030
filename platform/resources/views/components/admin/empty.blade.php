@props(['icon' => 'inbox', 'title', 'description' => null])
{{-- "Nothing here yet", as in the theme (DL-017): the icon on a pale green tile, one line that says what is
     missing, one that says what would put something here, and the way to do it. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="wx-stat-tile mb-3 flex size-12 items-center justify-center rounded-xl">
        <x-icon :name="$icon" class="size-6" />
    </span>
    <h3 class="text-[15px] leading-6 font-semibold text-gray-900 dark:text-white/90">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-md text-theme-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif
    @if (trim($slot) !== '')
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
