<div class="grid grid-cols-1 gap-6">
    <x-admin.page-header title="Sequences" description="Follow-up on autopilot: every sequence the studio runs, who is enrolled, and exactly what the engine did — each wait, send, skip and hold is logged. Automated sends respect quiet hours and the daily per-client cap (AUT-006); a client's reply stops a sequence."></x-admin.page-header>

    @if (auth()->user()->hasRole('owner'))
        <x-admin.card title="Studio defaults" description="Restore only missing built-in definitions, paused. Existing edits and enrolments are preserved; no clients are enrolled and no messages are sent.">
            <x-admin.button variant="secondary" wire:click="restoreMissingDefaults" wire:loading.attr="disabled" wire:target="restoreMissingDefaults" wire:confirm="Add missing studio defaults in the paused state? Existing definitions, steps and enrolments will not change.">Restore missing defaults (paused)</x-admin.button>
        </x-admin.card>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-3 xl:col-span-1">
            @forelse ($sequences as $sequence)
                <button type="button" wire:key="seq-{{ $sequence->id }}" wire:click="select({{ $sequence->id }})"
                        @class(['w-full rounded-2xl border p-4 text-left transition', 'border-navy-300 bg-navy-50/50 dark:border-navy-500/40 dark:bg-navy-500/5' => $sequence->id === $selectedId, 'border-gray-200 hover:border-navy-200 dark:border-gray-700 dark:hover:border-gray-600' => $sequence->id !== $selectedId])>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-theme-sm font-semibold text-gray-900 dark:text-white">{{ $sequence->name }}
                                @if ($sequence->is_builtin)<x-admin.badge color="gray">built-in</x-admin.badge>@endif
                            </p>
                            <p class="mt-0.5 text-theme-xs text-gray-500">{{ $sequence->trigger->label() }} · {{ $sequence->steps->count() }} steps</p>
                        </div>
                        <span @class(['inline-flex size-2.5 shrink-0 rounded-full', 'bg-success-500' => $sequence->is_active, 'bg-gray-300 dark:bg-gray-600' => ! $sequence->is_active]) title="{{ $sequence->is_active ? 'On' : 'Off' }}"></span>
                    </div>
                    <p class="mt-2 line-clamp-2 text-theme-xs text-gray-500">{{ $sequence->description }}</p>
                    <p class="mt-2 text-theme-xs text-gray-400">{{ $sequence->active_count }} running · {{ $sequence->finished_count }} finished</p>
                </button>
            @empty
                <x-admin.card><p class="py-6 text-center text-theme-sm text-gray-500">No sequences are configured. The owner can restore missing studio defaults above; they start paused for review.</p></x-admin.card>
            @endforelse
        </div>

        <div class="xl:col-span-2">
            @if ($selected === null)
                <x-admin.card title="Pick a sequence">
                    <p class="text-theme-sm text-gray-500">Choose one on the left to see its steps and everyone currently enrolled.</p>
                </x-admin.card>
            @else
                <x-admin.card :title="$selected->name" :description="$selected->description">
                    <div class="mb-4 flex flex-wrap items-center gap-2">
                        <x-admin.badge color="{{ $selected->is_active ? 'success' : 'gray' }}">{{ $selected->is_active ? 'On' : 'Off' }}</x-admin.badge>
                        <x-admin.badge color="gray">{{ $selected->trigger->label() }}</x-admin.badge>
                        @if ($selected->exits_on_reply)<x-admin.badge color="gray">stops when the client replies</x-admin.badge>@endif
                        <div class="ms-auto flex items-center gap-2">
                            @if ($selected->is_active)
                                <x-admin.button variant="secondary" icon="hourglass" wire:click="toggleSequence({{ $selected->id }})" wire:confirm="Pause this sequence? People already enrolled keep running; new leads stop being enrolled.">Pause</x-admin.button>
                            @else
                                <x-admin.button variant="primary" icon="zap" wire:click="toggleSequence({{ $selected->id }})" wire:confirm="Enable new enrolments for this sequence? Its steps may send client messages when the automation engine runs.">Turn on</x-admin.button>
                            @endif
                        </div>
                    </div>

                    <p class="mb-2 text-theme-xs font-medium uppercase tracking-wide text-gray-400">The steps</p>
                    <ol class="space-y-1.5">
                        @foreach ($selected->steps as $step)
                            <li class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-theme-sm text-gray-700 dark:bg-white/5 dark:text-gray-200">
                                <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-white text-theme-xs font-semibold text-gray-500 dark:bg-white/10">{{ $loop->iteration }}</span>
                                {{ $step->describe($enrolments->first()?->lead) }}
                            </li>
                        @endforeach
                    </ol>
                </x-admin.card>

                <x-admin.card class="mt-4" title="Enrolments" description="The 20 most recent. Paused enrolments hold their place; cancelled ones never send again.">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-theme-sm">
                            <thead class="text-theme-xs uppercase text-gray-400">
                                <tr>
                                    <th class="px-2 py-2">Lead</th>
                                    <th class="px-2 py-2">Since</th>
                                    <th class="px-2 py-2">Where it stands</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($enrolments as $enrolment)
                                    <tr wire:key="enr-{{ $enrolment->id }}">
                                        <td class="px-2 py-2.5">
                                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $enrolment->lead?->contact?->name ?? $enrolment->lead?->label() ?? '—' }}</span>
                                        </td>
                                        <td class="px-2 py-2.5 text-gray-500">{{ $enrolment->enrolled_at->diffForHumans(short: true) }}</td>
                                        <td class="px-2 py-2.5">
                                            <x-admin.badge color="{{ match($enrolment->status->value) { 'active' => 'success', 'paused' => 'warning', 'completed' => 'navy', default => 'gray' } }}">{{ $enrolment->status->label() }}</x-admin.badge>
                                            @if ($enrolment->end_reason)<span class="ms-1 text-theme-xs text-gray-400">{{ $enrolment->end_reason }}</span>@endif
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach ($enrolment->runs->take(8) as $run)
                                                    <span title="{{ $run->step?->kind->label() }} · {{ $run->note ?? $run->error ?? '' }} @if($run->ran_at)({{ $run->ran_at->diffForHumans(short: true) }})@endif"
                                                          @class(['rounded px-1.5 py-0.5 text-theme-xs', 'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-400' => $run->status->value === 'done', 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => $run->status->value === 'pending', 'bg-warning-100 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400' => $run->status->value === 'skipped', 'bg-error-100 text-error-700 dark:bg-error-500/15 dark:text-error-400' => $run->status->value === 'failed'])>
                                                        {{ $run->status->value === 'done' && $run->step?->kind->value === 'send_template' ? 'sent' : $run->status->label() }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="px-2 py-2.5 text-right">
                                            @if ($enrolment->status->value === 'active')
                                                <button type="button" wire:click="pauseEnrolment({{ $enrolment->id }})" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5" title="Pause"><x-icon name="hourglass" class="size-4" /></button>
                                            @elseif ($enrolment->status->value === 'paused')
                                                <button type="button" wire:click="resumeEnrolment({{ $enrolment->id }})" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5" title="Resume"><x-icon name="zap" class="size-4" /></button>
                                            @endif
                                            @if (in_array($enrolment->status->value, ['active', 'paused'], true))
                                                <button type="button" wire:click="cancelEnrolment({{ $enrolment->id }})" wire:confirm="Cancel this enrolment? Nothing more is sent to this client by this sequence." class="rounded-lg p-1.5 text-gray-400 hover:bg-error-50 hover:text-error-600" title="Cancel"><x-icon name="x" class="size-4" /></button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-2 py-6 text-center text-gray-500">Nobody enrolled yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-admin.card>
            @endif
        </div>
    </div>
</div>
