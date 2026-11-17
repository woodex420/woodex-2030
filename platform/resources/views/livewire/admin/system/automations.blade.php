<div>
    <x-admin.page-header title="Automations"
                         description="What runs by itself, whether it is on, and when it last ran. Pausing is always safe — nothing queues up behind it. A toggle takes effect within a minute."
                         :breadcrumbs="['Automation' => null, 'Automations' => null]">
    </x-admin.page-header>

    <x-admin.card title="Business automations" description="The studio's flows. Switch one off and the scheduler skips it — no messages are sent, no records change." :padding="false">
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($automations as $automation)
                @if (! $automation['toggleable'])
                    @continue
                @endif
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between" wire:key="auto-{{ \Illuminate\Support\Str::slug($automation['key']) }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-gray-900 dark:text-white/90">{{ $automation['name'] }}</span>
                            @if ($automation['dormant'])
                                <x-admin.badge color="gray">Dormant — off by default</x-admin.badge>
                            @else
                                <x-admin.badge :color="$automation['enabled'] ? 'success' : 'warning'">{{ $automation['enabled'] ? 'On' : 'Paused' }}</x-admin.badge>
                            @endif
                            <span class="text-theme-xs text-gray-500">{{ $automation['cadence'] }}</span>
                        </div>
                        <p class="mt-1 text-theme-sm text-gray-600 dark:text-gray-400">{{ $automation['description'] }}</p>
                        <p class="mt-1 text-theme-xs text-gray-500">
                            @if ($automation['last_run'] !== null)
                                Last ran {{ $automation['last_run']->diffForHumans() }}@if ($automation['last_runtime_ms']) · {{ $automation['last_runtime_ms'] }} ms @endif
                            @elseif ($automation['dormant'])
                                Has never run — it is dormant until you switch it on
                            @else
                                Not recorded yet
                            @endif
                            @if ($automation['toggled_at'] !== null)
                                · {{ $automation['enabled'] ? 'resumed' : 'paused' }} {{ $automation['toggled_at']->diffForHumans() }}
                            @endif
                        </p>
                    </div>
                    <div class="shrink-0">
                        @if ($canToggle)
                            <x-admin.button size="sm" :variant="$automation['enabled'] ? 'secondary' : 'primary'" wire:click="toggle('{{ $automation['key'] }}')" wire:loading.attr="disabled">
                                @if ($automation['dormant'])
                                    Switch on
                                @else
                                    {{ $automation['enabled'] ? 'Pause' : 'Resume' }}
                                @endif
                            </x-admin.button>
                        @else
                            <x-admin.button size="sm" variant="secondary" disabled>
                                {{ $automation['enabled'] ? 'Pause' : 'Resume' }}
                            </x-admin.button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-admin.card>

    <div class="mt-6">
        <x-admin.card title="Monthly CRM report"
                      description="On the 1st at 09:00 the owner gets the month that just ended in one message — Telegram, email when Telegram is not linked — and every report is archived here. Pause the row above to stop the monthly send."
                      :padding="false">
            <div class="px-5 py-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-theme-sm text-gray-600 dark:text-gray-400">
                        The digest reuses the Reports screens' own numbers (funnel, revenue &amp; collections), so the message and the dashboards can never disagree.
                    </p>
                    @if ($canToggle)
                        <x-admin.button size="sm" wire:click="generateNow" wire:loading.attr="disabled">
                            Build last month now
                        </x-admin.button>
                    @endif
                </div>
            </div>

            <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                @forelse ($reports as $report)
                    <div wire:key="report-{{ $report->id }}">
                        <button type="button" wire:click="toggleReport({{ $report->id }})"
                                class="flex w-full flex-col gap-2 px-5 py-4 text-left sm:flex-row sm:items-center sm:justify-between hover:bg-gray-50 dark:hover:bg-white/5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-gray-900 dark:text-white/90">{{ $report->periodLabel() }}</span>
                                <x-admin.badge color="gray">{{ $report->notified_at !== null ? 'sent '.$report->notified_at->diffForHumans() : 'archived, not sent' }}</x-admin.badge>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-theme-xs text-gray-600 dark:text-gray-400">
                                <span>{{ $report->headline()['leads'] }} enquiries</span>
                                <span>{{ $report->headline()['won'] }} won</span>
                                <span>invoiced {{ \App\Support\Money::ofMinor($report->headline()['invoiced_minor'])->format($lakh) }}</span>
                                <span>collected {{ \App\Support\Money::ofMinor($report->headline()['collected_minor'])->format($lakh) }}</span>
                            </div>
                        </button>

                        @if ($expandedReport !== null && $expandedReport->id === $report->id)
                            @php
                                $funnel = $report->payload['funnel'] ?? [];
                                $previous = $report->payload['funnel_previous'] ?? [];
                                $money = $report->payload['money'] ?? [];
                                $moneyPrevious = $report->payload['money_previous'] ?? [];
                                $overdue = $report->payload['overdue'] ?? [];
                                $chat = $report->payload['web_chat'] ?? [];
                            @endphp
                            <div class="grid gap-4 bg-gray-50 px-5 py-4 text-theme-sm dark:bg-white/5 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white/90">Enquiries</p>
                                    <p class="mt-1 text-gray-600 dark:text-gray-400">
                                        {{ (int) ($funnel['leads'] ?? 0) }} new
                                        ({{ (int) ($previous['leads'] ?? 0) }} the month before)
                                        · {{ (int) ($funnel['quotes_sent'] ?? 0) }} quotations sent
                                        · {{ (int) ($funnel['won'] ?? 0) }} won, {{ (int) ($funnel['lost'] ?? 0) }} lost
                                    </p>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white/90">Money</p>
                                    <p class="mt-1 text-gray-600 dark:text-gray-400">
                                        Quoted {{ \App\Support\Money::ofMinor((int) ($money['quoted_minor'] ?? 0))->format($lakh) }} ·
                                        invoiced {{ \App\Support\Money::ofMinor((int) ($money['invoiced_minor'] ?? 0))->format($lakh) }} ·
                                        collected {{ \App\Support\Money::ofMinor((int) ($money['collected_minor'] ?? 0))->format($lakh) }}
                                        ({{ \App\Support\Money::ofMinor((int) ($moneyPrevious['collected_minor'] ?? 0))->format($lakh) }} before)
                                    </p>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white/90">Outstanding</p>
                                    <p class="mt-1 text-gray-600 dark:text-gray-400">
                                        {{ \App\Support\Money::ofMinor((int) ($money['outstanding_minor'] ?? 0))->format($lakh) }} today
                                        · {{ (int) ($overdue['count'] ?? 0) }} invoice(s) overdue
                                        @if ((int) ($overdue['oldest_days'] ?? 0) > 0)
                                            · oldest {{ (int) $overdue['oldest_days'] }} days
                                        @endif
                                    </p>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white/90">Website chat</p>
                                    <p class="mt-1 text-gray-600 dark:text-gray-400">
                                        {{ (int) ($chat['threads'] ?? 0) }} thread(s) · {{ (int) ($chat['messages'] ?? 0) }} messages
                                    </p>
                                </div>
                                @if (($report->payload['top_sources'] ?? []) !== [])
                                    <div class="sm:col-span-2 lg:col-span-4">
                                        <p class="font-medium text-gray-900 dark:text-white/90">Top sources</p>
                                        <p class="mt-1 text-gray-600 dark:text-gray-400">
                                            @foreach ($report->payload['top_sources'] as $source)
                                                {{ $source['label'] }} — {{ (int) $source['leads'] }} lead(s), {{ (int) $source['accepted'] }} accepted{{ ! $loop->last ? ' · ' : '' }}
                                            @endforeach
                                        </p>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="px-5 py-4 text-theme-sm text-gray-500">
                        Nothing yet — the first report lands on the 1st, or press "Build last month now".
                    </p>
                @endforelse
            </div>
        </x-admin.card>
    </div>

    <div class="mt-6">
        <x-admin.card title="Platform jobs" description="Keep-the-lights-on work: shown for honesty, not for switching off." :padding="false">
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($automations as $automation)
                    @if ($automation['toggleable'])
                        @continue
                    @endif
                    <div class="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="job-{{ \Illuminate\Support\Str::slug($automation['key']) }}">
                        <div>
                            <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $automation['name'] }}</span>
                            <span class="ml-2 text-theme-xs text-gray-500">{{ $automation['cadence'] }}</span>
                            <p class="mt-0.5 text-theme-xs text-gray-500">{{ $automation['description'] }}</p>
                        </div>
                        <span class="text-theme-xs text-gray-500">
                            @if ($automation['last_run'] !== null)
                                {{ $automation['last_run']->diffForHumans() }}@if ($automation['last_runtime_ms']) · {{ $automation['last_runtime_ms'] }} ms @endif
                            @else
                                not recorded yet
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </x-admin.card>
    </div>

    <p class="mt-4 text-theme-xs text-gray-500">
        Run history comes from the scheduler itself and is kept for a fortnight. System → Health shows the same per-task history alongside cron, queue and backups.
    </p>
</div>
