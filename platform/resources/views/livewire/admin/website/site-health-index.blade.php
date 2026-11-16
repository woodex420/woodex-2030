<div>
    <x-admin.page-header title="Site health" description="The website as a whole, read from the files in public/: links that lead nowhere, redirects pointing at nothing, pages nothing links to, images without alt text, and the two generated files search engines read first. Every page is checked, not just the ones in the database."
                         :breadcrumbs="['Website' => null, 'Site health' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="refresh" wire:click="rescan">Rescan</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-admin.stat label="Verdict" :value="$unhealthy === 0 ? 'Healthy' : $unhealthy.' finding(s)'" icon="health" :tone="$unhealthy === 0 ? 'success' : 'warning'" />
        <x-admin.stat label="Pages checked" :value="$stats['files']" icon="globe" />
        <x-admin.stat label="With findings" :value="$stats['flagged']" icon="alert" />
        <x-admin.stat label="Redirects" :value="$stats['redirects']" icon="refresh" />
        <x-admin.stat label="Media files" :value="$stats['media']" icon="image" />
    </div>

    <x-admin.card title="The checks" description="Green means the check passes today. Anything else says exactly what and where.">
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($findings as $finding)
                <div class="flex flex-wrap items-start gap-3 py-4">
                    <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full {{ $finding['ok'] ? 'bg-success-50 text-success-600 dark:bg-success-500/15' : 'bg-warning-50 text-warning-600 dark:bg-warning-500/15' }}">
                        <x-icon :name="$finding['ok'] ? 'check-circle' : 'alert'" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-theme-sm font-medium text-gray-900 dark:text-white">{{ $finding['label'] }}</p>
                        <p class="mt-0.5 text-theme-sm {{ $finding['ok'] ? 'text-gray-500' : 'text-warning-600 dark:text-warning-400' }}">{{ $finding['message'] }}</p>
                        <p class="mt-1 text-theme-xs text-gray-400">{{ $finding['hint'] }}</p>
                    </div>
                    @if ($finding['action'])
                        <x-admin.button variant="secondary" icon="external" href="{{ route($finding['action']['route']) }}">{{ $finding['action']['label'] }}</x-admin.button>
                    @endif
                </div>
            @endforeach
        </div>
    </x-admin.card>
</div>
