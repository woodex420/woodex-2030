<div>
    <x-admin.page-header title="SEO" description="Every page's search listing, read from the file Google reads: the <title> as the blue link, the meta description as the grey text under it, their lengths against the cut-offs (title ~65, description ~165). Change one in the page builder."
                         :breadcrumbs="['Website' => null, 'SEO' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="sparkles" href="{{ route('admin.website.seo') }}">SEO agent</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-5 flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-700" aria-label="SEO audit view">
        <x-admin.button variant="{{ $tab === 'listings' ? 'primary' : 'secondary' }}" wire:click="setTab('listings')" aria-pressed="{{ $tab === 'listings' ? 'true' : 'false' }}">Search listings</x-admin.button>
        <x-admin.button variant="{{ $tab === 'metadata' ? 'primary' : 'secondary' }}" wire:click="setTab('metadata')" aria-pressed="{{ $tab === 'metadata' ? 'true' : 'false' }}">Canonical &amp; social</x-admin.button>
    </div>

    @if ($tab === 'metadata' && $metadata !== null)
        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
            <x-admin.stat label="Files inspected" :value="$metadata['counts']['files']" icon="file" />
            <x-admin.stat label="Canonical findings" :value="$metadata['counts']['canonical']" icon="link" tone="warning" />
            <x-admin.stat label="Social preview gaps" :value="$metadata['counts']['social']" icon="globe" tone="warning" />
            <x-admin.stat label="JSON-LD findings" :value="$metadata['counts']['jsonld']" icon="file" tone="warning" />
        </div>
        <div class="mb-4 flex flex-wrap items-center gap-2" aria-label="Metadata review filters">
            <x-admin.button variant="{{ $filter === 'issues' ? 'primary' : 'secondary' }}" wire:click="setFilter('issues')" aria-pressed="{{ $filter === 'issues' ? 'true' : 'false' }}">Needs review ({{ $metadata['counts']['flagged'] }})</x-admin.button>
            <x-admin.button variant="{{ $filter === 'all' ? 'primary' : 'secondary' }}" wire:click="setFilter('all')" aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}">All pages ({{ $metadata['counts']['files'] }})</x-admin.button>
            <label class="sr-only" for="metadata-group">Finding category</label>
            <select id="metadata-group" wire:change="setMetadataGroup($event.target.value)" class="wx-input w-full md:w-auto">
                @foreach (['all' => 'All findings', 'canonical' => 'Canonical', 'social' => 'Social previews', 'jsonld' => 'JSON-LD', 'indexing' => 'Indexing restrictions', 'unreadable' => 'Unreadable files'] as $key => $label)
                    <option value="{{ $key }}" @selected($metadataGroup === $key)>{{ $label }}{{ $key !== 'all' ? ' ('.$metadata['counts'][$key].')' : '' }}</option>
                @endforeach
            </select>
            <input type="search" wire:model.live.debounce.300ms="search" maxlength="160" placeholder="Find a page, canonical or finding" aria-label="Search metadata" class="wx-input w-full md:ml-auto md:w-72">
            @if ($search !== '' || $metadataGroup !== 'all')
                <x-admin.button variant="ghost" wire:click="clearMetadataFilters">Clear filters</x-admin.button>
            @endif
            <p role="status" class="w-full text-theme-xs text-gray-500">Showing {{ count($metadataRows) }} of {{ $metadata['counts']['files'] }} inspected files. Findings are review prompts, not permission to publish.</p>
        </div>
        <x-admin.card title="Canonical & social metadata" description="Read-only checks against the configured public website, not the preview host. No URLs are fetched, pages edited or indexing rules removed. Cross-page canonicals and noindex can be intentional. JSON-LD checks syntax only.">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-theme-sm">
                    <thead><tr class="border-b border-gray-200 text-theme-xs text-gray-500 dark:border-gray-700"><th class="px-3 py-3">Page</th><th class="px-3 py-3">Canonical</th><th class="px-3 py-3">Review findings</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($metadataRows as $entry)
                            <tr wire:key="metadata-{{ $entry['path'] }}" class="align-top">
                                <td class="px-3 py-4"><a href="{{ route('admin.website.builder', ['path' => $entry['path']]) }}" class="font-medium underline">{{ $entry['url'] }}</a></td>
                                <td class="max-w-xs break-all px-3 py-4 text-theme-xs">{{ $entry['canonical'] ?: 'Not declared' }}</td>
                                <td class="min-w-64 px-3 py-4">
                                    @forelse ($entry['issues'] as $issue)
                                        <p class="mb-2 text-theme-xs text-gray-700 dark:text-gray-300">{{ $issue['message'] }}</p>
                                    @empty
                                        <span class="text-theme-xs text-success-700 dark:text-success-500">No metadata findings in these checks</span>
                                    @endforelse
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="p-5">No pages match these review filters. Clear the filters or choose All pages.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-admin.card>
    @else
    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-admin.stat label="Pages" :value="$counts['files']" icon="layout" />
        <x-admin.stat label="With findings" :value="$counts['flagged']" icon="alert" />
        <x-admin.stat label="No description" :value="$counts['missing_description']" icon="alert" tone="warning" />
        <x-admin.stat label="No title" :value="$counts['missing_title']" icon="alert" tone="warning" />
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-admin.button variant="{{ $filter === 'issues' ? 'primary' : 'secondary' }}" wire:click="setFilter('issues')">Needs work ({{ $counts['flagged'] }})</x-admin.button>
        <x-admin.button variant="{{ $filter === 'all' ? 'primary' : 'secondary' }}" wire:click="setFilter('all')">All {{ $counts['files'] }}</x-admin.button>

        <div class="relative ml-auto w-full md:w-72">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search an address or a title" class="wx-input pl-9" aria-label="Search listings">
        </div>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="w-full text-theme-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-theme-xs uppercase tracking-wider text-gray-400 dark:border-gray-800">
                        <th class="px-5 py-3">Page</th>
                        <th class="px-5 py-3">Search title</th>
                        <th class="hidden px-5 py-3 md:table-cell">Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr wire:key="seo-{{ $row['path'] }}">
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.website.builder', ['path' => $row['path']]) }}" class="font-medium whitespace-nowrap text-gray-900 hover:underline dark:text-white">{{ $row['url'] }}</a>
                                <p class="text-theme-xs text-gray-400">
                                    @forelse ($row['issues'] as $issue)
                                        <span class="mr-1 rounded bg-warning-50 px-1.5 py-0.5 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ \App\Domain\Website\Seo\FileAudit::LABELS[$issue['code']] ?? $issue['code'] }}</span>
                                    @empty
                                        clear
                                    @endforelse
                                </p>
                                <a href="{{ route('admin.website.builder', ['path' => $row['path']]) }}" class="mt-1 inline-block whitespace-nowrap text-theme-xs font-medium text-brand-600 hover:underline">Open in builder</a>
                            </td>
                            <td class="px-5 py-3">
                                @php $titleLength = mb_strlen($row['title']); @endphp
                                @if ($titleLength === 0)
                                    <x-admin.badge color="error">missing</x-admin.badge>
                                @elseif ($titleLength > 65)
                                    <x-admin.badge color="warning">{{ $titleLength }} — cut off</x-admin.badge>
                                @elseif ($titleLength < 20)
                                    <x-admin.badge color="warning">{{ $titleLength }} — thin</x-admin.badge>
                                @else
                                    <x-admin.badge color="success">{{ $titleLength }}</x-admin.badge>
                                @endif
                                <p class="mt-1 max-w-[22rem] truncate text-theme-xs text-gray-500" title="{{ $row['title'] }}">{{ $row['title'] ?: '—' }}</p>
                            </td>
                            <td class="hidden px-5 py-3 md:table-cell">
                                @php $descriptionLength = mb_strlen($row['description']); @endphp
                                @if ($descriptionLength === 0)
                                    <x-admin.badge color="error">missing</x-admin.badge>
                                @elseif ($descriptionLength > 165)
                                    <x-admin.badge color="warning">{{ $descriptionLength }} — cut off</x-admin.badge>
                                @elseif ($descriptionLength < 50)
                                    <x-admin.badge color="warning">{{ $descriptionLength }} — thin</x-admin.badge>
                                @else
                                    <x-admin.badge color="success">{{ $descriptionLength }}</x-admin.badge>
                                @endif
                                <p class="mt-1 max-w-xs truncate text-theme-xs text-gray-500" title="{{ $row['description'] }}">{{ $row['description'] ?: '—' }}</p>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                <x-admin.empty title="Nothing to show" description="No page matches this filter — try All." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>
    @endif
</div>
