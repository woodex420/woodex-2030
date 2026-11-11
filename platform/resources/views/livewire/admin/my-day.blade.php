@use('App\Support\Phone')
<div>
    <x-admin.page-header :title="$greeting.', '.auth()->user()->name" :description="now()->format('l j F').' · Work top to bottom: new leads first, then overdue follow-ups.'" />

    <p class="mb-4 text-theme-sm text-gray-500">Full queue totals are shown below. Lists preview up to20 new leads and30 follow-ups/tasks; use the workspace for the complete list. Uncontacted leads appear only in the first-contact queue.</p>
    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-admin.stat label="Not contacted yet" :value="$queueTotals['uncontacted']" icon="hourglass" :tone="$uncontacted->isNotEmpty() ? 'error' : 'success'" hint="Respond within the SLA" />
        <x-admin.stat label="Overdue follow-ups" :value="$queueTotals['overdue']" icon="alert" :tone="$overdue->isNotEmpty() ? 'warning' : 'success'" />
        <x-admin.stat label="Site visits today" :value="$visits->count()" icon="map-pin" tone="wood" :href="route('admin.tasks.index', ['tab' => 'visits'])" />
        <x-admin.stat label="Tasks due" :value="$queueTotals['tasks']" icon="tasks" :href="route('admin.tasks.index')" />
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            {{-- SLA first --}}
            <x-admin.card title="New leads to contact" description="Call or WhatsApp now. The first contact stops the response timer." :padding="false">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($uncontacted as $lead)
                        <x-admin.lead-row :lead="$lead" wire:key="u-{{ $lead->id }}">
                            @if ($lead->isSlaBreached())
                                <x-admin.badge color="error" :dot="false">Late {{ $lead->sla_due_at->diffForHumans(null, true) }}</x-admin.badge>
                            @elseif ($lead->sla_due_at)
                                <x-admin.badge color="warning" :dot="false">Due {{ $lead->sla_due_at->diffForHumans() }}</x-admin.badge>
                            @endif
                        </x-admin.lead-row>
                    @empty
                        <li class="px-5 py-6 text-center text-theme-sm text-gray-500">Every new lead has been contacted.</li>
                    @endforelse
                </ul>
            </x-admin.card>

            <x-admin.card title="Overdue follow-ups" :padding="false">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($overdue as $lead)
                        <x-admin.lead-row :lead="$lead" wire:key="o-{{ $lead->id }}">
                            <span class="text-theme-xs font-medium text-error-600">{{ $lead->next_action_type?->label() ?? 'Follow up' }} · {{ $lead->next_action_at->diffForHumans() }}</span>
                        </x-admin.lead-row>
                    @empty
                        <li class="px-5 py-6 text-center text-theme-sm text-gray-500">Nothing overdue. Well done.</li>
                    @endforelse
                </ul>
            </x-admin.card>

            <x-admin.card title="Later today" :padding="false">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($today as $lead)
                        <x-admin.lead-row :lead="$lead" wire:key="t-{{ $lead->id }}">
                            <span class="text-theme-xs text-gray-600 dark:text-gray-400">{{ $lead->next_action_type?->label() ?? 'Follow up' }} · {{ $lead->next_action_at->format('g:i a') }}</span>
                        </x-admin.lead-row>
                    @empty
                        <li class="px-5 py-6 text-center text-theme-sm text-gray-500">No more follow-ups scheduled today.</li>
                    @endforelse
                </ul>
            </x-admin.card>
        </div>

        <div class="space-y-6">
            <x-admin.card title="Today's site visits">
                @forelse ($visits as $visit)
                    <a href="{{ route('admin.leads.show', $visit->lead_id) }}" wire:navigate class="flex items-center gap-3 rounded-lg py-2 hover:bg-gray-50 dark:hover:bg-white/5 {{ $loop->first ? '' : 'border-t border-gray-100 dark:border-gray-800' }}" wire:key="v-{{ $visit->id }}">
                        <span class="w-16 shrink-0 text-theme-sm font-semibold text-navy-900 dark:text-white">{{ $visit->scheduled_at->format('g:i a') }}</span>
                        <span class="min-w-0">
                            <span class="block truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $visit->contact->name }}</span>
                            <span class="block truncate text-theme-xs text-gray-500">{{ $visit->address ?? $visit->lead->ref }}</span>
                        </span>
                    </a>
                @empty
                    <p class="text-theme-sm text-gray-500">No visits today.</p>
                @endforelse
            </x-admin.card>

            <x-admin.card title="Tasks">
                <ul class="space-y-1">
                    @forelse ($tasks as $task)
                        <li class="flex items-start gap-2.5 py-1.5" wire:key="task-{{ $task->id }}">
                            <button type="button" wire:click="completeTask({{ $task->id }})" class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md border border-gray-300 text-transparent hover:border-success-500 hover:text-success-500 dark:border-gray-600" aria-label="Complete task">
                                <x-icon name="check" class="size-3.5" />
                            </button>
                            <div class="min-w-0 text-theme-sm">
                                <p class="text-gray-800 dark:text-white/90">{{ $task->title }}</p>
                                <p class="text-theme-xs text-gray-500">
                                    @if ($task->due_at)<span @class(['text-error-600' => $task->due_at->isPast()])>{{ $task->due_at->isToday() ? $task->due_at->format('g:i a') : $task->due_at->format('j M') }}</span>@endif
                                    @if ($task->lead) · <a href="{{ route('admin.leads.show', $task->lead) }}" wire:navigate class="hover:underline">{{ $task->lead->contact->name }}</a>@endif
                                </p>
                            </div>
                        </li>
                    @empty
                        <li class="text-theme-sm text-gray-500">No tasks due today.</li>
                    @endforelse
                </ul>
            </x-admin.card>

            @if ($unassigned->isNotEmpty())
                <x-admin.card title="Unassigned leads" description="Pick an owner so nobody waits.">
                    @foreach ($unassigned as $lead)
                        <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate class="flex items-center justify-between rounded-lg px-2 py-1.5 text-theme-sm hover:bg-gray-50 dark:hover:bg-white/5" wire:key="un-{{ $lead->id }}">
                            <span class="truncate text-gray-800 dark:text-white/90">{{ $lead->contact->name }}</span>
                            <span class="text-theme-xs text-gray-400">{{ $lead->created_at?->diffForHumans(null, true) }}</span>
                        </a>
                    @endforeach
                </x-admin.card>
            @endif
        </div>
    </div>
</div>
