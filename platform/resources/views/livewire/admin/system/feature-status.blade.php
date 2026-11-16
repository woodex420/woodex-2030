@php
    use App\Support\AdminNavigation;

    $states = [
        AdminNavigation::AVAILABLE => ['label' => 'Available here', 'color' => 'success'],
        AdminNavigation::EXISTING => ['label' => 'In the Agency OS dashboard', 'color' => 'info'],
        AdminNavigation::OFF => ['label' => 'Built, switched off', 'color' => 'warning'],
        AdminNavigation::PLANNED => ['label' => 'Not built yet', 'color' => 'gray'],
    ];
@endphp
<div>
    <x-admin.page-header title="Feature status"
                         description="Every part of WOODEX OS and where it stands today. The menu shows only what you can open here; this page tells you about the rest."
                         :breadcrumbs="['System' => null, 'Feature status' => null]" />

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" data-feature-summary>
        @foreach ($states as $state => $meta)
            <div class="wx-surface rounded-2xl border border-gray-200 px-4 py-3 dark:border-gray-800">
                <p class="text-theme-xs text-gray-500">{{ $meta['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white" data-feature-count="{{ $state }}">{{ $counts[$state] }}</p>
            </div>
        @endforeach
    </div>

    @forelse ($groups as $group)
        <x-admin.card class="mb-5" :padding="false">
            <h2 class="border-b border-gray-200 px-5 py-3 text-theme-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-white">{{ $group['title'] }}</h2>
            <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($group['items'] as $item)
                    @php($meta = $states[$item['state']])
                    <li class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:gap-4" data-feature="{{ $item['id'] }}" data-state="{{ $item['state'] }}">
                        <div class="flex min-w-0 items-center gap-3 sm:w-64 sm:shrink-0">
                            <x-icon :name="$item['icon']" class="size-5 shrink-0 text-gray-500" />
                            <span class="truncate text-theme-sm font-medium text-gray-900 dark:text-white">{{ $item['label'] }}</span>
                        </div>
                        <div class="sm:w-56 sm:shrink-0">
                            <x-admin.badge :color="$meta['color']">{{ $meta['label'] }}</x-admin.badge>
                        </div>
                        <div class="min-w-0 flex-1 text-theme-sm text-gray-600 dark:text-gray-300">
                            @if ($item['state'] === AdminNavigation::AVAILABLE)
                                <a href="{{ route($item['route']) }}" class="font-medium text-brand-600 underline-offset-2 hover:underline dark:text-brand-300"
                                   @unless (in_array($item['route'], AdminNavigation::FULL_DOCUMENT, true)) wire:navigate @endunless>Open {{ $item['label'] }}</a>
                            @else
                                @if ($item['state'] === AdminNavigation::OFF)
                                    <span>Not adapted to Woodex Furniture yet, so it stays closed.</span>
                                @elseif ($item['state'] === AdminNavigation::PLANNED)
                                    <span>No screen exists yet.</span>
                                @endif
                                @if ($item['legacy_url'])
                                    <a href="{{ $item['legacy_url'] }}" target="_blank" rel="noopener noreferrer"
                                       class="font-medium text-brand-600 underline-offset-2 hover:underline dark:text-brand-300">Open in the Agency OS dashboard ↗</a>
                                    <span class="text-gray-500">(its own sign-in)</span>
                                @elseif ($item['legacy'])
                                    <span>Done in the Agency OS dashboard today — dashboard address not configured here.</span>
                                @endif
                                @if ($item['wave'])
                                    <span class="block text-theme-xs text-gray-500">{{ 'Arrives here in wave '.$item['wave'].($item['wave_title'] ? ' — '.$item['wave_title'] : '').'.' }}</span>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-admin.card>
    @empty
        <x-admin.card>
            <x-admin.empty icon="layers" title="Nothing to show" description="Your account has no access to any part of the admin yet. Ask the owner to give you a role." />
        </x-admin.card>
    @endforelse
</div>
