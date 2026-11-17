<div wire:poll.30s.visible>
    <x-admin.page-header title="System health" description="Cron, queue, storage and integrations. Problems here are also sent to Telegram (P5)."
                         :breadcrumbs="['System' => null, 'Health' => null]" />

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($checks as $check)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-theme-sm text-gray-600 dark:text-gray-300">{{ $check['label'] }}</span>
                    <x-admin.badge :color="$check['ok'] ? 'success' : 'error'">{{ $check['ok'] ? 'OK' : 'Check' }}</x-admin.badge>
                </div>
                <p class="mt-3 font-heading text-theme-xl text-gray-900 dark:text-white">{{ $check['value'] }}</p>
                <p class="mt-1 text-theme-xs text-gray-500">{{ $check['hint'] }}</p>
            </div>
        @endforeach
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
