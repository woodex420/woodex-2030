@use('App\Support\Phone')
<div>
    <x-admin.page-header title="Site visits" :description="$todayCount ? $todayCount.' visit'.($todayCount > 1 ? 's' : '').' today' : 'No visits today'"
                         :breadcrumbs="['CRM' => null, 'Site visits' => null]" />

    <div class="mb-5 flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-white/[0.03]" role="tablist">
            @foreach (['upcoming' => 'Upcoming', 'past' => 'Past 60 days', 'week' => 'Week', 'month' => 'Month'] as $value => $label)
                <button type="button" wire:click="$set('range', '{{ $value }}')" role="tab" aria-selected="{{ $range === $value ? 'true' : 'false' }}"
                        @class(['rounded-md px-3 py-1.5 text-theme-sm font-medium transition', 'bg-navy-900 text-white dark:bg-white dark:text-navy-900' => $range === $value, 'text-gray-600 hover:text-gray-900 dark:text-gray-400' => $range !== $value])>{{ $label }}</button>
            @endforeach
        </div>
        @if ($canSeeTeam)
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-white/[0.03]" role="tablist">
                @foreach (['mine' => 'Mine', 'team' => 'Whole team'] as $value => $label)
                    <button type="button" wire:click="$set('scope', '{{ $value }}')" role="tab" aria-selected="{{ $scope === $value ? 'true' : 'false' }}"
                            @class(['rounded-md px-3 py-1.5 text-theme-sm font-medium transition', 'bg-navy-900 text-white dark:bg-white dark:text-navy-900' => $scope === $value, 'text-gray-600 hover:text-gray-900 dark:text-gray-400' => $scope !== $value])>{{ $label }}</button>
                @endforeach
            </div>
        @endif
    </div>

    @if ($calendar)
        @include('livewire.admin.crm.partials.visit-calendar', ['calendar' => $calendar, 'mode' => $range])
    @endif

    <div class="space-y-6" wire:loading.class="opacity-60" @if ($calendar) hidden @endif>
        @forelse ($groups as $label => $visits)
            <section>
                <h2 @class(['mb-3 text-theme-sm font-semibold', 'text-error-600' => $label === 'Needs an update', 'text-gray-800 dark:text-white/90' => $label !== 'Needs an update'])>{{ $label }}</h2>
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    @foreach ($visits as $visit)
                        @php $isOpen = $visit->status->isOpen(); @endphp
                        <x-admin.card wire:key="visit-{{ $visit->id }}">
                            <div class="flex items-start gap-4">
                                <div class="flex w-16 shrink-0 flex-col items-center rounded-xl bg-sand-100 py-2 text-navy-900 dark:bg-white/5 dark:text-white">
                                    <span class="text-theme-xl leading-none font-semibold">{{ $visit->scheduled_at->format('g:i') }}</span>
                                    <span class="text-theme-xs uppercase">{{ $visit->scheduled_at->format('a') }}</span>
                                    <span class="mt-1 text-[10px] text-gray-500">{{ $visit->duration_minutes }} min</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('admin.leads.show', $visit->lead_id) }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $visit->contact->name }}</a>
                                        <x-admin.badge :color="$visit->status->color()">{{ $visit->status->label() }}</x-admin.badge>
                                    </div>
                                    <p class="mt-0.5 text-theme-xs text-gray-500">{{ $visit->lead->ref }} · {{ $visit->assignee?->name ?? 'Unassigned' }}</p>
                                    @if ($visit->address)
                                        <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($visit->address) }}" target="_blank" rel="noopener" class="mt-2 flex items-start gap-1.5 text-theme-sm text-gray-600 hover:text-navy-800 dark:text-gray-400">
                                            <x-icon name="map-pin" class="mt-0.5 size-4 shrink-0" /> {{ $visit->address }}
                                        </a>
                                    @endif
                                    <div class="mt-2 flex items-center gap-3 text-theme-sm">
                                        @if ($visit->contact->phone)
                                            <a href="{{ Phone::telUrl($visit->contact->phone) }}" class="flex items-center gap-1 text-navy-700 hover:underline dark:text-gray-300"><x-icon name="phone" class="size-3.5" /> {{ Phone::display($visit->contact->phone) }}</a>
                                        @endif
                                        @if ($wa = Phone::whatsappUrl($visit->contact->whatsappNumber(), 'Assalam o Alaikum, this is Woodex Interior confirming your site visit on '.$visit->scheduled_at->format('l j M \a\t g:i a').'.'))
                                            <a href="{{ $wa }}" target="_blank" rel="noopener" class="flex items-center gap-1 text-success-700 hover:underline"><x-icon name="whatsapp" class="size-3.5" /> Confirm</a>
                                        @endif
                                    </div>
                                    @if (! $isOpen && $visit->outcome_notes)
                                        <p class="mt-3 rounded-lg bg-gray-50 p-2.5 text-theme-sm text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">{{ $visit->outcome_notes }}</p>
                                    @endif
                                    @if ($visit->measurements)
                                        <p class="mt-2 flex items-center gap-1.5 text-theme-xs text-gray-500"><x-icon name="ruler" class="size-3.5" /> {{ count($visit->measurements) }} areas · {{ number_format(collect($visit->measurements)->sum('area_sqft')) }} sq ft</p>
                                    @endif
                                </div>
                            </div>
                            @if ($isOpen)
                                <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                                    <x-admin.button size="sm" icon="check-circle" wire:click="openComplete({{ $visit->id }})">Record outcome</x-admin.button>
                                    @if ($visit->status !== \App\Domain\Crm\Enums\VisitStatus::Confirmed)
                                        <x-admin.button size="sm" variant="secondary" wire:click="confirm({{ $visit->id }})">Client confirmed</x-admin.button>
                                    @endif
                                    <x-admin.button size="sm" variant="secondary" wire:click="openReschedule({{ $visit->id }})">Reschedule</x-admin.button>
                                    <x-admin.button size="sm" variant="ghost" wire:click="noShow({{ $visit->id }})" wire:confirm="Mark as no-show?">No-show</x-admin.button>
                                    <x-admin.button size="sm" variant="ghost" class="text-error-600" wire:click="cancel({{ $visit->id }})" wire:confirm="Cancel this visit?">Cancel</x-admin.button>
                                </div>
                            @endif
                        </x-admin.card>
                    @endforeach
                </div>
            </section>
        @empty
            <x-admin.card>
                <x-admin.empty icon="calendar" title="{{ $range === 'past' ? 'No past visits' : 'No upcoming visits' }}" description="Book visits from a lead page. They appear here with directions and one-tap WhatsApp confirmation." />
            </x-admin.card>
        @endforelse
    </div>

    {{-- Record outcome --}}
    @if ($completing)
        <div class="fixed inset-0 z-[9999] flex items-end justify-center bg-gray-900/50 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="complete-title"
             x-data x-on:keydown.escape.window="$wire.closeModals()">
            <form wire:submit="complete" class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-theme-lg dark:bg-gray-900">
                <div class="mb-5 flex items-start justify-between">
                    <div>
                        <h2 id="complete-title" class="text-theme-xl font-medium text-gray-900 dark:text-white">Visit outcome · {{ $completing->contact->name }}</h2>
                        <p class="text-theme-xs text-gray-500">Measurements are saved on the lead and used for the quotation.</p>
                    </div>
                    <button type="button" wire:click="closeModals" class="text-gray-400 hover:text-gray-600" aria-label="Close"><x-icon name="x" /></button>
                </div>
                <div class="space-y-4">
                    <x-admin.field label="What happened?" for="notes">
                        <textarea id="notes" wire:model="notes" rows="3" class="wx-input h-auto py-2.5" placeholder="Client wants a modern kitchen with island, budget around 25 lakh, decision with spouse…"></textarea>
                    </x-admin.field>
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="wx-label mb-0">Measurements (feet)</span>
                            <span class="text-theme-xs text-gray-500">Total {{ number_format($measuredArea, 0) }} sq ft</span>
                        </div>
                        <div class="space-y-2">
                            @foreach ($measurements as $i => $row)
                                <div class="grid grid-cols-[1fr_5rem_5rem_2rem] gap-2" wire:key="m-{{ $i }}">
                                    <input type="text" wire:model="measurements.{{ $i }}.room" class="wx-input" placeholder="Room (e.g. Kitchen)" aria-label="Room">
                                    <input type="number" step="0.1" min="0" wire:model.live.debounce.400ms="measurements.{{ $i }}.length_ft" class="wx-input" placeholder="L" aria-label="Length (ft)">
                                    <input type="number" step="0.1" min="0" wire:model.live.debounce.400ms="measurements.{{ $i }}.width_ft" class="wx-input" placeholder="W" aria-label="Width (ft)">
                                    <button type="button" wire:click="removeRow({{ $i }})" class="text-gray-400 hover:text-error-600" aria-label="Remove row"><x-icon name="x" class="size-4" /></button>
                                </div>
                            @endforeach
                        </div>
                        @error('measurements.*') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="addRow" class="mt-2 inline-flex items-center gap-1 text-theme-sm font-medium text-navy-700 hover:underline dark:text-gray-300"><x-icon name="plus" class="size-4" /> Add room</button>
                    </div>
                    <x-admin.field label="Next step" for="nextStep">
                        <input id="nextStep" type="text" wire:model="nextStep" class="wx-input">
                    </x-admin.field>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <x-admin.button variant="secondary" wire:click="closeModals">Cancel</x-admin.button>
                    <x-admin.button type="submit" icon="check">Save outcome</x-admin.button>
                </div>
            </form>
        </div>
    @endif

    {{-- Reschedule --}}
    @if ($reschedulingId)
        <div class="fixed inset-0 z-[9999] flex items-end justify-center bg-gray-900/50 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="reschedule-title"
             x-data x-on:keydown.escape.window="$wire.closeModals()">
            <form wire:submit="reschedule" class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-theme-lg dark:bg-gray-900">
                <h2 id="reschedule-title" class="mb-4 text-theme-xl font-medium text-gray-900 dark:text-white">Move visit</h2>
                <x-admin.field label="New date and time" for="rescheduleAt">
                    <input id="rescheduleAt" type="datetime-local" wire:model="rescheduleAt" class="wx-input">
                </x-admin.field>
                <div class="mt-6 flex justify-end gap-3">
                    <x-admin.button variant="secondary" wire:click="closeModals">Cancel</x-admin.button>
                    <x-admin.button type="submit">Move visit</x-admin.button>
                </div>
            </form>
        </div>
    @endif
</div>
