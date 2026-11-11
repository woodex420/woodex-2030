<div @if (in_array($status['state'] ?? null, ['queued', 'running'], true)) wire:poll.5s @endif>
    <x-admin.page-header title="Data import" description="Bring clients, enquiries, notes and bookings over from Woodex v2.1. Always start with a dry run; nothing is saved until the real run."
                         :breadcrumbs="['System' => null, 'Data import' => null]" />

    @if ($status)
        <div @class([
            'mb-6 flex items-start gap-3 rounded-2xl border p-4 text-theme-sm',
            'border-navy-200 bg-navy-50 text-navy-900 dark:border-white/10 dark:bg-white/5 dark:text-white' => in_array($status['state'], ['queued', 'running'], true),
            'border-success-200 bg-success-50 text-success-800' => $status['state'] === 'finished',
            'border-error-200 bg-error-50 text-error-800' => $status['state'] === 'failed',
        ]) role="status">
            <x-icon :name="$status['state'] === 'failed' ? 'alert' : ($status['state'] === 'finished' ? 'check-circle' : 'hourglass')" class="mt-0.5 size-5" />
            <div>
                <p class="font-medium">{{ $status['message'] }}</p>
                <p class="text-theme-xs opacity-75">{{ \Illuminate\Support\Carbon::parse($status['at'])->diffForHumans() }}</p>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6">
            <x-admin.card title="1 · Connection">
                @if ($configured)
                    <p class="flex items-center gap-2 text-theme-sm text-success-700"><x-icon name="check-circle" class="size-4" /> v2.1 database settings found.</p>
                    <p class="mt-1 text-theme-xs text-gray-500">Test it under Integrations → Woodex v2.1 database.</p>
                @else
                    <p class="text-theme-sm text-warning-700">Add LEGACY_DB_* (a read-only MySQL user) to the server .env first. See docs/08 (import runbook).</p>
                @endif
            </x-admin.card>

            <x-admin.card title="2 · What to import">
                <div class="space-y-2">
                    @foreach ($allSteps as $step)
                        <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="steps" value="{{ $step }}" class="rounded border-gray-300"> {{ ucfirst($step) }}</label>
                    @endforeach
                    @error('steps') <p class="text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    <p class="text-theme-xs text-gray-500">Quotations arrive with P2, invoices with P3, chats with P4. Re-running is safe: records already imported are updated, not duplicated.</p>
                </div>
                <div class="mt-4"><x-admin.button icon="search" wire:click="dryRun" :disabled="! $configured">Start dry run</x-admin.button></div>
            </x-admin.card>

            <x-admin.card title="4 · Run the import">
                <p class="text-theme-sm text-gray-600 dark:text-gray-400">Needs a dry run from the last 24 hours. Take a database backup first (System → Health).</p>
                <form wire:submit="import" class="mt-3 space-y-3">
                    <x-admin.field label="Type IMPORT to confirm" for="confirmation">
                        <input id="confirmation" type="text" wire:model="confirmation" class="wx-input" autocomplete="off">
                    </x-admin.field>
                    <x-admin.button type="submit" variant="danger" icon="upload" :disabled="! $configured">Run import</x-admin.button>
                </form>
            </x-admin.card>
        </div>

        <div class="xl:col-span-2">
            <x-admin.card title="3 · Reports" description="Newest first. Download warnings.csv to fix data in v2.1 or accept it." :padding="false">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($reports as $report)
                        <li wire:key="report-{{ $report['directory'] }}" class="px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-medium text-gray-800 dark:text-white/90">
                                    {{ $report['started_at']?->format('j M Y, g:i a') ?? $report['directory'] }}
                                    <x-admin.badge :color="$report['dry_run'] ? 'navy' : 'success'">{{ $report['dry_run'] ? 'Dry run' : 'Imported' }}</x-admin.badge>
                                </p>
                                <div class="flex gap-2">
                                    <x-admin.button variant="secondary" size="sm" icon="download" wire:click="download('{{ $report['directory'] }}', 'warnings.csv')">Warnings ({{ $report['warnings'] }})</x-admin.button>
                                    <x-admin.button variant="secondary" size="sm" icon="download" wire:click="download('{{ $report['directory'] }}', 'duplicates.csv')">Duplicates ({{ $report['duplicates'] }})</x-admin.button>
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-5">
                                @foreach ($report['counts'] as $step => $numbers)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                                        <p class="text-theme-xs text-gray-500">{{ ucfirst($step) }}</p>
                                        <p class="text-theme-sm text-gray-800 dark:text-white/90">{{ collect($numbers)->map(fn ($n, $k) => $n.' '.str_replace('_', ' ', $k))->implode(' · ') }}</p>
                                    </div>
                                @endforeach
                            </div>
                            <p class="mt-2 text-theme-xs text-gray-400">{{ number_format($report['seconds'], 1) }} s</p>
                        </li>
                    @empty
                        <li><x-admin.empty icon="database" title="No reports yet" description="Start with a dry run. Its report shows how many records would be created or updated, plus every warning." /></li>
                    @endforelse
                </ul>
            </x-admin.card>
        </div>
    </div>
</div>
