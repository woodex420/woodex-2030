<div wire:poll.30s.visible>
    <x-admin.page-header title="System health" description="Cron, queue, storage and integrations. Problems here are also sent to Telegram (P5)."
                         :breadcrumbs="['System' => null, 'Health' => null]" />

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($checks as $check)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]"
                 data-health-check="{{ $check['label'] }}" data-health-state="{{ $check['ok'] ? 'ok' : 'check' }}">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-theme-sm text-gray-600 dark:text-gray-300">{{ $check['label'] }}</span>
                    <x-admin.badge :color="$check['ok'] ? 'success' : 'error'">{{ $check['ok'] ? 'OK' : 'Check' }}</x-admin.badge>
                </div>
                <p class="mt-3 font-heading text-theme-xl text-gray-900 dark:text-white">{{ $check['value'] }}</p>
                <p class="mt-1 text-theme-xs text-gray-500">{{ $check['hint'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- The two lists the dashboard links to (master list T-044). "Automations failing" counts the first: each
         scheduled task whose latest run ended in error, and each job in the failed queue. "Has stopped
         reporting" names the second. Each says how many rows it holds in data-list-rows, also when it holds
         none, so that a figure of 0 opens a list that says 0. --}}
    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <x-admin.card id="failing" title="Failing now" description="The latest run of a scheduled task ended in error, or a job is in the failed queue"
                      :padding="false" data-list-rows="{{ $failures['total'] }}">
            @foreach ($failures['tasks'] as $failed)
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800" data-failing="task">
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $failed['task'] }}</span>
                    <x-admin.badge color="error">Last run failed</x-admin.badge>
                    <span class="ml-auto text-theme-xs text-gray-500">{{ $failed['ran_at']?->diffForHumans() }}</span>
                </div>
            @endforeach
            @foreach ($failures['jobs'] as $job)
                <div class="border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800" data-failing="job">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-gray-800 dark:text-white/90">Queued job · {{ $job['queue'] }}</span>
                        <x-admin.badge color="error">Failed</x-admin.badge>
                        <span class="ml-auto text-theme-xs text-gray-500">{{ \Carbon\CarbonImmutable::parse($job['failed_at'])->diffForHumans() }}</span>
                    </div>
                    <p class="mt-1 break-words text-theme-xs text-gray-500">{{ $job['exception'] }}</p>
                </div>
            @endforeach
            @if ($failures['more_jobs'] > 0)
                <p class="px-5 py-3 text-theme-xs text-gray-500">and {{ number_format($failures['more_jobs']) }} older {{ $failures['more_jobs'] === 1 ? 'job' : 'jobs' }} in the failed queue.</p>
            @endif
            @if ($failures['total'] === 0)
                <p class="px-5 py-4 text-theme-sm text-gray-500" role="status">Nothing is failing: the latest run of every scheduled task was clean and the failed queue is empty.</p>
            @endif
        </x-admin.card>

        <x-admin.card id="stale" title="Stopped reporting" description="A scheduled task with no run in the last {{ \App\Domain\System\Control\ControlPanel::STALE_HOURS }} hours"
                      :padding="false" data-list-rows="{{ count($failures['stale']) }}">
            @forelse ($failures['stale'] as $silent)
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800" data-failing="stale">
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $silent['task'] }}</span>
                    <x-admin.badge color="warning">Silent</x-admin.badge>
                    <span class="ml-auto text-theme-xs text-gray-500">last run {{ $silent['ran_at']?->diffForHumans() }}</span>
                </div>
            @empty
                <p class="px-5 py-4 text-theme-sm text-gray-500" role="status">Every scheduled task has reported within the last {{ \App\Domain\System\Control\ControlPanel::STALE_HOURS }} hours.</p>
            @endforelse
        </x-admin.card>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <x-admin.card title="Scheduled tasks" :padding="false">
            @forelse ($taskRuns as $task => $run)
                <div class="border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800">
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $task }}</span>
                    <span class="float-right text-gray-500">
                        {{ $run->ran_at?->diffForHumans() }}
                        @if ($run->runtime_ms)
                            · {{ $run->runtime_ms }} ms
                        @endif
                    </span>
                </div>
            @empty
                <x-admin.empty icon="clock" title="No runs recorded yet" description="They appear once the scheduler has ticked." />
            @endforelse
        </x-admin.card>

        <x-admin.card title="Environment">
            <div class="mb-4">
                <button type="button" wire:click="sendTestMail" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-3 py-2 text-theme-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                    Send test email
                </button>
                <p class="mt-1 text-theme-xs text-gray-500">Sends to your own email address, so you know the mail settings work.</p>
            </div>
            <dl class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($environment as $label => $value)
                    <div class="flex justify-between py-2 text-theme-sm">
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-admin.card>

        <x-admin.card title="Recent integration errors" :padding="false">
            @forelse ($integrationErrors as $log)
                <div class="border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800">
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ \Illuminate\Support\Str::headline($log->integration) }} · {{ $log->action }}</span>
                    <span class="block text-theme-xs text-gray-500">{{ $log->error ?? ($log->response['description'] ?? 'HTTP '.$log->status_code) }} · {{ $log->created_at?->diffForHumans() }}</span>
                </div>
            @empty
                <x-admin.empty icon="check-circle" title="No integration errors" />
            @endforelse
        </x-admin.card>
    </div>
</div>
