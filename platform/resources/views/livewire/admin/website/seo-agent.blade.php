<div>
    <x-admin.page-header title="SEO agent" description="Every finding, on the page that has it. The rules are plain checks a person can repeat by hand — and the fix happens in the builder, on the file a visitor gets."
                         :breadcrumbs="['Website' => null, 'SEO agent' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="target" href="{{ route('admin.website.seo.overview') }}">All listings</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-admin.stat label="Pages checked" :value="$counts['files']" icon="globe" />
        <x-admin.stat label="With findings" :value="$counts['flagged']" icon="alert" />
        <x-admin.stat label="Findings" :value="$counts['issues']" icon="search" />
        <x-admin.stat label="Broken links" :value="$counts['broken_links']" icon="external" />
    </div>

    @if ($site !== [])
        <x-admin.card title="The whole site" description="Findings that belong to the website, not to one page.">
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($site as $finding)
                    <div class="flex items-start gap-3 py-3">
                        <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-warning-50 text-warning-600 dark:bg-warning-500/15">
                            <x-icon name="alert" class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-theme-sm font-medium text-gray-900 dark:text-white">{{ \App\Domain\Website\Seo\FileAudit::LABELS[$finding['code']] ?? Str::headline($finding['code']) }}</p>
                            <p class="mt-0.5 text-theme-sm text-warning-600 dark:text-warning-400">{{ $finding['message'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-admin.card>
    @endif

    <x-admin.card title="By rule" description="Click a rule to see the pages that break it.">
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="setCode('')"
                    class="rounded-full px-3 py-1.5 text-theme-xs font-medium {{ $code === '' ? 'bg-navy-900 text-white dark:bg-white dark:text-navy-900' : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300' }}">
                Every finding ({{ $counts['issues'] }})
            </button>
            @foreach ($byCode as $rule => $count)
                <button type="button" wire:click="setCode('{{ $rule }}')"
                        class="rounded-full px-3 py-1.5 text-theme-xs font-medium {{ $code === $rule ? 'bg-navy-900 text-white dark:bg-white dark:text-navy-900' : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300' }}">
                    {{ \App\Domain\Website\Seo\FileAudit::LABELS[$rule] ?? Str::headline($rule) }} ({{ $count }})
                </button>
            @endforeach
        </div>
    </x-admin.card>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="w-full text-theme-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-theme-xs uppercase tracking-wider text-gray-400 dark:border-gray-800">
                        <th class="px-5 py-3">Page</th>
                        <th class="px-5 py-3">Findings</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($pages as $page)
                        <tr wire:key="seo-page-{{ $page['path'] }}">
                            <td class="px-5 py-3 align-top">
                                <a href="{{ route('admin.website.builder', ['path' => $page['path']]) }}" class="font-medium text-gray-900 hover:underline dark:text-white">{{ $page['url'] }}</a>
                                <p class="mt-0.5 max-w-xs truncate text-theme-xs text-gray-400">{{ $page['title'] ?: 'No title' }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <ul class="space-y-1">
                                    @foreach ($page['issues'] as $issue)
                                        <li class="flex items-start gap-2 text-theme-sm">
                                            <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-warning-500"></span>
                                            <span class="text-gray-600 dark:text-gray-300">{{ $issue['message'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="px-5 py-3 align-top text-right">
                                <a href="{{ route('admin.website.builder', ['path' => $page['path']]) }}" class="whitespace-nowrap font-medium text-theme-sm text-brand-600 hover:underline">Open in builder</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                <x-admin.empty title="Nothing found" description="Every page passes the rules this filter covers." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>
