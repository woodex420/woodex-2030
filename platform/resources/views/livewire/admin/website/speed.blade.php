<div>
    <x-admin.page-header title="Speed" description="What the site weighs and how it loads — measured at home, no key required: every published page's image weight, image formats and cached page size. Google's PageSpeed scores join when the studio's API key is saved."
                         :breadcrumbs="['Website' => null, 'Speed' => null]" />

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
        <x-admin.card><p class="text-theme-xs text-gray-500">Pages</p><p class="mt-1 text-2xl font-medium text-gray-800 dark:text-white/90">{{ $audit['totals']['pages'] }}</p></x-admin.card>
        <x-admin.card><p class="text-theme-xs text-gray-500">Images</p><p class="mt-1 text-2xl font-medium text-gray-800 dark:text-white/90">{{ $audit['totals']['images'] }}</p></x-admin.card>
        <x-admin.card><p class="text-theme-xs text-gray-500">Image weight</p><p class="mt-1 text-2xl font-medium text-gray-800 dark:text-white/90">{{ number_format($audit['totals']['bytes'] / 1024 / 1024, 1) }} MB</p></x-admin.card>
        <x-admin.card><p class="text-theme-xs text-gray-500">Heavy images</p><p class="mt-1 text-2xl font-medium {{ $audit['totals']['heavy'] > 0 ? 'text-error-600' : 'text-gray-800 dark:text-white/90' }}">{{ $audit['totals']['heavy'] }}</p><p class="text-theme-xs text-gray-400">over 300 KB each</p></x-admin.card>
        <x-admin.card><p class="text-theme-xs text-gray-500">Old formats</p><p class="mt-1 text-2xl font-medium {{ $audit['totals']['legacy'] > 0 ? 'text-error-600' : 'text-gray-800 dark:text-white/90' }}">{{ $audit['totals']['legacy'] }}</p><p class="text-theme-xs text-gray-400">not webp/avif/svg</p></x-admin.card>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-theme-xs">
        <span class="rounded-full bg-soft px-3 py-1">{{ $audit['cache_on'] ? 'Full-page cache: ON — pages are served as saved' : 'Full-page cache: OFF — every visit re-renders' }}</span>
    </div>

    @if (! $pageSpeedReady)
        <x-admin.card class="mb-4" title="PageSpeed is asleep">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">The checks above are the studio's own — they always run. A Google PageSpeed API key wakes the score buttons below and adds mobile and desktop runs to each page's history. Save it right here; it is stored encrypted and never shown back.</p>
            <form wire:submit="saveKey" class="mt-3 flex flex-wrap items-end gap-3">
                <x-admin.field label="Google PageSpeed API key" for="apiKey">
                    <input id="apiKey" type="password" wire:model="apiKey" class="wx-input" placeholder="AIza…" autocomplete="off">
                </x-admin.field>
                <x-admin.button type="submit" icon="plus">Save key</x-admin.button>
            </form>
        </x-admin.card>
    @else
        <x-admin.card class="mb-4" title="PageSpeed is awake">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">Every run asks Google to load the page and grade it out of 100 — mobile and desktop each. Runs take up to a minute and are kept as history below.</p>
                <button type="button" wire:click="clearKey" wire:confirm="Remove the API key? The score buttons go back to sleep; internal checks stay." class="wx-mini-btn">Remove key</button>
            </div>
        </x-admin.card>
    @endif

    <x-admin.card :padding="false" title="Pages, heaviest first" class="mb-4">
        <table class="min-w-full text-left text-theme-sm">
            <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                <tr>
                    <th class="px-5 py-3 font-medium">Page</th>
                    <th class="px-5 py-3 font-medium">Images</th>
                    <th class="px-5 py-3 font-medium">Weight</th>
                    <th class="hidden px-5 py-3 font-medium lg:table-cell">Cached page</th>
                    <th class="px-5 py-3"><span class="sr-only">PageSpeed</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($audit['pages'] as $row)
                    <tr wire:key="speed-{{ $row['id'] }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                        <td class="px-5 py-3">
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['title'] }}</p>
                            <p class="text-theme-xs text-gray-400">{{ $row['url'] }}</p>
                        </td>
                        <td class="px-5 py-3 text-gray-600 dark:text-gray-300">
                            {{ $row['images'] }}
                            @if ($row['heavy'] !== [])<span class="ml-1 rounded-full bg-error-50 px-2 py-0.5 text-theme-xs text-error-700 dark:bg-error-500/10 dark:text-error-400">{{ count($row['heavy']) }} heavy</span>@endif
                            @if ($row['legacy'] !== [])<span class="ml-1 rounded-full bg-soft px-2 py-0.5 text-theme-xs text-gray-600 dark:text-gray-300">{{ count($row['legacy']) }} old format</span>@endif
                        </td>
                        <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ number_format($row['bytes'] / 1024, 0) }} KB</td>
                        <td class="hidden px-5 py-3 text-theme-xs text-gray-500 lg:table-cell">
                            @if ($row['cached_kb'] !== null)
                                {{ $row['cached_kb'] }} KB
                            @else
                                not cached yet
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            @if ($pageSpeedReady)
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="run({{ $row['id'] }}, 'mobile')" class="wx-mini-btn" wire:loading.attr="disabled">Run mobile</button>
                                    <button type="button" wire:click="run({{ $row['id'] }}, 'desktop')" class="wx-mini-btn" wire:loading.attr="disabled">Run desktop</button>
                                </div>
                            @else
                                <span class="text-theme-xs text-gray-400">internal only</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-admin.card>

    <x-admin.card :padding="false" title="Recent PageSpeed runs">
        @if ($runs->isEmpty())
            <div class="px-5 py-6">
                <x-admin.empty icon="zap" title="No runs yet" description="Save an API key above and run a page — every score lands here, building the studio's speed history." />
            </div>
        @else
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Page</th>
                        <th class="px-5 py-3 font-medium">Strategy</th>
                        <th class="px-5 py-3 font-medium">Score</th>
                        <th class="hidden px-5 py-3 font-medium md:table-cell">Highlights</th>
                        <th class="px-5 py-3 font-medium">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($runs as $run)
                        <tr wire:key="run-{{ $run->id }}">
                            <td class="px-5 py-3 text-gray-800 dark:text-white/90">{{ $run->url }}</td>
                            <td class="px-5 py-3 text-gray-500">{{ $run->strategy }}</td>
                            <td class="px-5 py-3">
                                <span @class(['font-medium' => true, 'text-success-600' => $run->score >= 90, 'text-warning-600' => $run->score >= 50 && $run->score < 90, 'text-error-600' => $run->score < 50])>{{ $run->score }}/100</span>
                            </td>
                            <td class="hidden px-5 py-3 text-theme-xs text-gray-500 md:table-cell">{{ collect($run->metrics)->take(3)->map(fn ($value, $key) => $key.': '.$value)->implode(' · ') }}</td>
                            <td class="px-5 py-3 text-theme-xs text-gray-400">{{ $run->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-admin.card>
</div>
