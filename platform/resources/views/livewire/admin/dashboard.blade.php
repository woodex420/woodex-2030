<div wire:poll.60s.visible>
    <x-admin.page-header :title="$greeting"
                         description="Your control centre — selected-period performance above, live operating queues below. Updates every minute.">
        <x-slot:actions>
            @can('reports.view')<x-admin.button variant="secondary" icon="chart" :href="route('admin.reports.index', ['tab' => 'funnel', 'preset' => 'today'])" wire:navigate>Today's reports</x-admin.button>@endcan
            @can('leads.create')<x-admin.button icon="plus" :href="route('admin.leads.index', ['new' => 1])" wire:navigate>New lead</x-admin.button>@endcan
        </x-slot:actions>
    </x-admin.page-header>

    <section class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900" aria-label="Period performance">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="text-theme-xs font-semibold uppercase tracking-widest text-brand-600 dark:text-brand-400">Business performance</p><h2 class="mt-1 text-theme-xl font-semibold">{{ $range->label() }}</h2><p class="text-theme-xs text-gray-500">{{ $range->from->format('d M Y') }} – {{ $range->to->format('d M Y') }} · {{ config('app.timezone') }} · refreshed {{ now()->format('H:i') }}</p></div>
            <div class="flex flex-wrap gap-3">
                <label class="text-theme-xs text-gray-500">Period<select wire:model.live="preset" aria-label="Performance period" class="wx-input mt-1"><option value="today">Today</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="last_90_days">Last90days</option><option value="this_quarter">This quarter</option><option value="this_year">This year</option></select></label>
                <label class="text-theme-xs text-gray-500">Business line<select wire:model.live="line" aria-label="Performance business line" class="wx-input mt-1"><option value="">All business lines</option>@foreach (\App\Domain\Crm\Enums\BusinessLine::cases() as $businessLine)<option value="{{ $businessLine->value }}">{{ $businessLine->label() }}</option>@endforeach</select></label>
            </div>
        </div>
        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @forelse ($periodCards as $card)
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-800/40"><p class="text-theme-xs text-gray-500">{{ $card['label'] }}</p><p class="mt-2 text-theme-xl font-semibold">{{ $card['display'] }}</p><p class="mt-1 text-theme-xs text-gray-500">{{ $card['hint'] }}</p>@if ($card['href'])<a href="{{ $card['href'] }}" wire:navigate class="mt-3 inline-block text-theme-xs font-semibold text-brand-600 dark:text-brand-400">Open matching report →</a>@endif</div>
            @empty
                <p class="text-theme-sm text-gray-500">Your role has no period metrics. Open your permitted workspaces from Operations.</p>
            @endforelse
        </div>
        <p class="mt-4 text-theme-xs text-gray-500">These filters apply only to period performance. Team access follows your existing permissions; no unsupported owner filter is implied. Invoice value excludes drafts/voids and is not ledger revenue.</p>
    </section>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2"><h2 class="text-theme-lg font-semibold">Live operating queues</h2><a href="{{ route('admin.dashboard', ['tab' => 'operations']) }}" wire:navigate class="text-theme-sm text-brand-600 dark:text-brand-400">All operations →</a></div>
    <p class="mb-4 text-theme-xs text-gray-500">Current position across all permitted records. Not filtered by the performance period or business line above.</p>

    {{-- The six control numbers: one query each, from ControlPanel, with the link to the same rows. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
        @foreach ($control as $card)
            <x-admin.stat :label="$card['label']" :value="$card['display']" :icon="$card['icon']" :tone="$card['tone']"
                          :hint="$card['key'] === 'automations_failing' && $card['count'] === 0 ? 'No failures recorded; check task freshness in System.' : $card['hint']" :href="$card['href']" />
        @endforeach
    </div>

    @if (empty($control))
        <x-admin.card>
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                This account has the dashboard but not the leads, quotations, invoices, projects or automation screens behind
                its numbers, so the control tiles are hidden rather than shown as dead ends. The checklist and activity below
                are still yours; ask an owner for the role that matches your work.
            </p>
        </x-admin.card>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-admin.card title="Needs attention" description="Only what is actually waiting — each button opens the screen that filters to it"
                      class="xl:col-span-2" :padding="false">
            @forelse ($attention as $item)
                <div wire:key="attention-{{ $item['key'] }}" class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-3.5 last:border-0 dark:border-gray-800">
                    <span @class([
                        'flex size-9 shrink-0 items-center justify-center rounded-xl',
                        'bg-error-50 text-error-600 dark:bg-error-500/10 dark:text-error-500' => $item['tone'] === 'error',
                        'bg-warning-50 text-warning-600 dark:bg-warning-500/10 dark:text-warning-500' => $item['tone'] === 'warning',
                        'bg-navy-50 text-navy-700 dark:bg-white/5 dark:text-navy-200' => ! in_array($item['tone'], ['error', 'warning'], true),
                    ])>
                        <x-icon :name="$item['icon']" class="size-4.5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</p>
                        <p class="text-theme-xs text-gray-500">{{ $item['detail'] }}</p>
                    </div>
                    <x-admin.button size="sm" :variant="$item['tone'] === 'error' ? 'primary' : 'secondary'" :href="$item['href']" wire:navigate>{{ $item['action'] }}</x-admin.button>
                </div>
            @empty
                <x-admin.empty icon="check-circle" title="Nothing is waiting" description="No overdue follow-up, no quotation held for a decision, no late invoice and no failed automation." />
            @endforelse

            @if ($overdueLeads->isNotEmpty())
                <div class="border-t border-gray-100 px-5 py-3 dark:border-gray-800">
                    <p class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase">Overdue follow-ups — the five to touch first</p>
                </div>
                @foreach ($overdueLeads as $lead)
                    <div wire:key="overdue-{{ $lead->id }}" class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate class="block truncate font-medium text-gray-800 hover:underline dark:text-white/90">{{ $lead->contact->name }}
                                <span class="ml-1 text-theme-xs font-normal text-gray-400">{{ $lead->ref }}</span></a>
                            @php($service = $lead->service ? \Illuminate\Support\Str::headline($lead->service) : null)
                            <p class="text-theme-xs text-gray-500">{{ $lead->business_line->label() }}{{ $service && $service !== $lead->business_line->label() ? ' · '.$service : '' }} · {{ $lead->owner?->name ?? 'Unassigned' }}</p>
                        </div>
                        @if ($lead->isSlaBreached())
                            <x-admin.badge color="error">SLA breached</x-admin.badge>
                        @else
                            <x-admin.badge color="warning">Due {{ $lead->next_action_at?->diffForHumans() }}</x-admin.badge>
                        @endif
                        @if ($wa = \App\Support\Phone::whatsappUrl($lead->contact->whatsappNumber()))
                            <a href="{{ $wa }}" target="_blank" rel="noopener" class="flex size-8 items-center justify-center rounded-lg border border-gray-200 text-success-600 hover:bg-success-50 dark:border-gray-800" title="WhatsApp">
                                <x-icon name="whatsapp" class="size-4" />
                            </a>
                        @endif
                        @if ($lead->contact->phone)
                            <a href="tel:{{ $lead->contact->phone }}" class="flex size-8 items-center justify-center rounded-lg border border-gray-200 text-navy-700 hover:bg-navy-50 dark:border-gray-800 dark:text-gray-300" title="Call">
                                <x-icon name="phone" class="size-4" />
                            </a>
                        @endif
                    </div>
                @endforeach
            @endif
        </x-admin.card>

        @if ($checklist !== [])
        <x-admin.card title="Go-live checklist" description="Turns green as setup completes">
            <ul class="space-y-3">
                @foreach ($checklist as $item)
                    <li class="flex items-start gap-3">
                        <span @class([
                            'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full',
                            'bg-success-500 text-white' => $item['done'],
                            'border border-gray-300 text-transparent dark:border-gray-700' => ! $item['done'],
                        ])><x-icon name="check-circle" class="size-3.5" /></span>
                        <span>
                            <span class="block text-theme-sm text-gray-700 dark:text-gray-300">{{ $item['label'] }}</span>
                            @unless ($item['done'])
                                <span class="block text-theme-xs text-gray-400">{{ $item['hint'] }}</span>
                            @endunless
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-admin.card>
        @endif
    </div>

    @can('leads.view')
    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-admin.card title="New leads, last 14 days" class="min-w-0 xl:col-span-2">
            <div wire:ignore data-chart='@json($chart)' class="-mx-2 min-h-[240px] min-w-0"></div>
        </x-admin.card>

        <x-admin.card title="Pipeline" description="Leads per stage">
            @php($max = max(1, (int) $funnel->max('leads_count')))
            <ul class="space-y-3">
                @foreach ($funnel as $stage)
                    <li>
                        <div class="mb-1 flex justify-between text-theme-sm">
                            <span class="text-gray-600 dark:text-gray-300">{{ $stage->name }}</span>
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $stage->leads_count }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div @class([
                                'h-full rounded-full',
                                'bg-success-500' => $stage->type === \App\Domain\Crm\Enums\StageType::Won,
                                'bg-error-500' => $stage->type === \App\Domain\Crm\Enums\StageType::Lost,
                                'bg-navy-600' => $stage->type === \App\Domain\Crm\Enums\StageType::Open,
                            ]) style="width: {{ round($stage->leads_count / $max * 100) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-admin.card>
    </div>

    <x-admin.card title="Recent activity" class="mt-6" :padding="false">
        @forelse ($recent as $activity)
            <div class="flex gap-3 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
                <span class="mt-1 size-2 shrink-0 rounded-full bg-wood-500"></span>
                <div class="min-w-0">
                    <p class="text-theme-sm text-gray-700 dark:text-gray-300">
                        <span class="font-medium text-gray-900 dark:text-white">{{ $activity->contact?->name ?? 'System' }}</span>
                        · {{ \Illuminate\Support\Str::limit((string) $activity->body, 140) }}
                    </p>
                    <p class="text-theme-xs text-gray-400">{{ $activity->occurred_at?->diffForHumans() }}{{ $activity->user ? ' · '.$activity->user->name : '' }}</p>
                </div>
            </div>
        @empty
            <x-admin.empty icon="activity" title="No activity yet" description="New enquiries from the website, WhatsApp and the team will appear here." />
        @endforelse
    </x-admin.card>
    @endcan
</div>
