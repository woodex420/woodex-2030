@php
    use App\Support\AdminNavigation;

    $states = [
        AdminNavigation::AVAILABLE => ['label' => 'Available here', 'color' => 'success'],
        AdminNavigation::EXISTING => ['label' => 'In the Agency OS dashboard', 'color' => 'info'],
        AdminNavigation::OFF => ['label' => 'Built, switched off', 'color' => 'warning'],
        AdminNavigation::PLANNED => ['label' => 'Not built yet', 'color' => 'gray'],
    ];
@endphp
{{--
    The page is long by nature: 68 destinations in ten groups, and the release notes above them. Two small
    things keep it usable without a server round trip: a search box over the destination names, and the four
    numbers acting as filters (they are buttons, not badges: a number is what a person clicks to see that
    group). Both are Alpine, the same way the sidebar filters its own list.
--}}
<div x-data="{ q: '', state: '' }" data-feature-total="{{ array_sum($counts) }}">
    <x-admin.page-header title="Feature status"
                         description="Every part of WOODEX OS and where it stands today. The menu shows only what you can open here; this page tells you about the rest."
                         :breadcrumbs="['System' => null, 'Feature status' => null]" />

    <div class="mb-4">
        <label for="feature-search" class="sr-only">Find a destination</label>
        <div class="relative max-w-md">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" />
            <input id="feature-search" type="search" x-model="q" data-feature-search
                   placeholder="Find a destination…"
                   class="h-11 w-full rounded-lg border border-gray-200 bg-white pr-3 pl-9 text-theme-sm text-gray-900 placeholder:text-gray-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white">
        </div>
        <p x-cloak x-show="q.trim()" role="status" class="mt-2 text-theme-xs text-gray-500"
           x-text="Array.from(document.querySelectorAll('[data-feature]')).filter(el => el.dataset.featureLabel.toLowerCase().includes(q.trim().toLowerCase())).length + ' of ' + $root.dataset.featureTotal + ' destinations match'"></p>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" data-feature-summary>
        @foreach ($states as $state => $meta)
            <button type="button" data-feature-tile="{{ $state }}" aria-pressed="false"
                    x-on:click="state = (state === $el.dataset.featureTile ? '' : $el.dataset.featureTile)"
                    x-bind:aria-pressed="state === $el.dataset.featureTile ? 'true' : 'false'"
                    x-bind:class="state === $el.dataset.featureTile ? 'ring-2 ring-brand-500' : ''"
                    class="wx-surface wx-card rounded-2xl border border-gray-200 px-4 py-3 text-left transition hover:border-brand-300 dark:border-gray-800 dark:hover:border-brand-500/50">
                <span class="block text-theme-xs font-medium tracking-wide text-gray-500 uppercase">{{ $meta['label'] }}</span>
                <span class="mt-1 block text-2xl leading-8 font-bold tracking-tight tabular-nums text-gray-900 dark:text-white" data-feature-count="{{ $state }}">{{ $counts[$state] }}</span>
            </button>
        @endforeach
    </div>

    <p x-cloak x-show="state" class="mb-4 text-theme-sm text-gray-600 dark:text-gray-300">
        Showing
        <span x-text="state === 'available' ? 'only what is available here' : (state === 'existing' ? 'only what is still in the Agency OS dashboard' : (state === 'off' ? 'only what is built and switched off' : 'only what is not built yet'))"></span>.
        <button type="button" class="font-medium text-brand-600 underline-offset-2 hover:underline dark:text-brand-300" x-on:click="state = ''">Show all</button>
    </p>

    @forelse ($groups as $group)
        <section class="mb-5" data-feature-group="{{ $group['id'] }}"
                 x-show="Array.from($el.querySelectorAll('[data-feature]')).some(el => (state === '' || el.dataset.state === state) && (q.trim() === '' || el.dataset.featureLabel.toLowerCase().includes(q.trim().toLowerCase())))">
            <x-admin.card :padding="false">
                <h2 class="border-b border-gray-200 px-5 py-3 text-theme-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-white">{{ $group['title'] }}</h2>
                <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                    @foreach ($group['items'] as $item)
                        @php($meta = $states[$item['state']])
                        <li class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:gap-4"
                            data-feature="{{ $item['id'] }}" data-state="{{ $item['state'] }}"
                            data-feature-label="{{ $item['label'] }}"
                            x-show="(state === '' || $el.dataset.state === state) && (q.trim() === '' || $el.dataset.featureLabel.toLowerCase().includes(q.trim().toLowerCase()))">
                            <div class="flex min-w-0 items-center gap-3 sm:w-64 sm:shrink-0">
                                <x-icon :name="$item['icon']" class="size-5 shrink-0 text-gray-500" />
                                <span class="truncate text-theme-sm font-medium text-gray-900 dark:text-white">{{ $item['label'] }}</span>
                            </div>
                            <div class="sm:w-56 sm:shrink-0">
                                <x-admin.badge :color="$meta['color']">{{ $meta['label'] }}</x-admin.badge>
                            </div>
                            <div class="min-w-0 flex-1 text-theme-sm text-gray-600 dark:text-gray-300">
                                @if ($item['state'] === AdminNavigation::AVAILABLE)
                                    <a href="{{ route($item['route']) }}" class="inline-flex min-h-6 items-center font-medium text-brand-600 underline-offset-2 hover:underline dark:text-brand-300"
                                       @unless (in_array($item['route'], AdminNavigation::FULL_DOCUMENT, true)) wire:navigate @endunless>Open {{ $item['label'] }}</a>
                                @else
                                    @if ($item['state'] === AdminNavigation::OFF)
                                        <span>Not adapted to Woodex Furniture yet, so it stays closed.</span>
                                    @elseif ($item['state'] === AdminNavigation::PLANNED)
                                        <span>No screen exists yet.</span>
                                    @endif
                                    @if ($item['legacy_url'])
                                        <a href="{{ $item['legacy_url'] }}" target="_blank" rel="noopener noreferrer"
                                           class="inline-flex min-h-6 items-center font-medium text-brand-600 underline-offset-2 hover:underline dark:text-brand-300">Open in the Agency OS dashboard ↗</a>
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
        </section>
    @empty
        <x-admin.card>
            <x-admin.empty icon="layers" title="Nothing to show" description="Your account has no access to any part of the admin yet. Ask the owner to give you a role." />
        </x-admin.card>
    @endforelse

    @if ($groups !== [])
        <p x-cloak x-show="q.trim() && !Array.from(document.querySelectorAll('[data-feature]')).some(el => el.dataset.featureLabel.toLowerCase().includes(q.trim().toLowerCase()))"
           role="status" class="mb-5 text-theme-sm text-gray-600 dark:text-gray-300">
            No destination of your account matches “<span x-text="q.trim()"></span>”.
        </p>
    @endif

    @if ($groups !== [] && $releases !== [])
        {{-- Release notes (T-056): what changed for the person at the screen, newest first. Folded to the
             newest release, because the destinations below are what the page is for; a person who wants the
             history opens it. --}}
        <x-admin.card class="mb-5" title="What is new" description="What changed for the people who work here, release by release." data-release-notes>
            <div class="space-y-5">
                @foreach ($releases as $release)
                    <section data-release="{{ $release['date'] }}"
                             @if (! $loop->first) x-data="{ open: false }" @endif>
                        @if (! $loop->first)
                            <button type="button" x-on:click="open = !open" :aria-expanded="open ? 'true' : 'false'"
                                    class="flex w-full items-center justify-between gap-3 text-left">
                                <h3 class="text-theme-sm font-semibold text-gray-900 dark:text-white">
                                    <time datetime="{{ $release['date'] }}">{{ \Illuminate\Support\Carbon::parse($release['date'])->format('j F Y') }}</time>
                                    <span class="font-normal text-gray-500">·</span> {{ $release['title'] }}
                                    <span class="block text-theme-xs font-normal text-gray-500">Show what changed ({{ count($release['notes']) }})</span>
                                </h3>
                                <x-icon name="chevron-down" class="size-4 shrink-0 text-gray-400 transition" x-bind:class="open && 'rotate-180'" />
                            </button>
                            <ul x-show="open" x-cloak class="mt-2 list-disc space-y-1.5 pl-5 text-theme-sm text-gray-700 dark:text-gray-200">
                                @foreach ($release['notes'] as $note)
                                    <li>{{ $note }}</li>
                                @endforeach
                            </ul>
                        @else
                            <h3 class="text-theme-sm font-semibold text-gray-900 dark:text-white">
                                <time datetime="{{ $release['date'] }}">{{ \Illuminate\Support\Carbon::parse($release['date'])->format('j F Y') }}</time>
                                <span class="font-normal text-gray-500">·</span> {{ $release['title'] }}
                            </h3>
                            <ul class="mt-2 list-disc space-y-1.5 pl-5 text-theme-sm text-gray-700 dark:text-gray-200">
                                @foreach ($release['notes'] as $note)
                                    <li>{{ $note }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach
            </div>
        </x-admin.card>
    @endif
</div>
