{{-- Site visit calendar (CRM-008): week columns or a month grid. Click a day in month view to open that week. --}}
@php
    $statusClasses = [
        'scheduled' => 'border-navy-200 bg-navy-50 text-navy-800 dark:border-navy-500/30 dark:bg-navy-500/10 dark:text-navy-200',
        'confirmed' => 'border-success-200 bg-success-50 text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300',
        'rescheduled' => 'border-warning-200 bg-warning-50 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300',
        'completed' => 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-white/5 dark:text-gray-300',
        'no_show' => 'border-error-200 bg-error-50 text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-300',
    ];
@endphp
<div wire:loading.class="opacity-60">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-theme-xl font-medium text-gray-900 dark:text-white">{{ $calendar['title'] }}</h2>
        <div class="flex items-center gap-2">
            <x-admin.button variant="secondary" size="sm" wire:click="shiftCalendar(-1)" aria-label="Previous"><x-icon name="chevron-left" class="size-4" /></x-admin.button>
            <x-admin.button variant="secondary" size="sm" wire:click="today">Today</x-admin.button>
            <x-admin.button variant="secondary" size="sm" wire:click="shiftCalendar(1)" aria-label="Next"><x-icon name="chevron-right" class="size-4" /></x-admin.button>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="hidden grid-cols-7 border-b border-gray-200 bg-gray-50 text-center text-theme-xs font-medium tracking-wide text-gray-500 uppercase md:grid dark:border-gray-800 dark:bg-white/[0.02]">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName)
                <div class="py-2">{{ $dayName }}</div>
            @endforeach
        </div>
        <div class="grid grid-cols-1 md:grid-cols-7">
            @foreach ($calendar['days'] as $day)
                @php
                    $key = $day->format('Y-m-d');
                    $dayVisits = $calendar['visits']->get($key, collect());
                    $outside = $mode === 'month' && $day->month !== $calendar['anchor']->month;
                    $limit = $mode === 'month' ? 3 : 50;
                @endphp
                <div wire:key="day-{{ $key }}" @class([
                    'border-b border-gray-100 p-2 md:border-r dark:border-gray-800',
                    'min-h-28' => $mode === 'month',
                    'min-h-56' => $mode === 'week',
                    'bg-gray-50/60 dark:bg-white/[0.01]' => $outside || $day->isSunday(),
                    'max-md:hidden' => $mode === 'month' && $dayVisits->isEmpty(),
                ])>
                    <div class="mb-1.5 flex items-center justify-between">
                        <button type="button" wire:click="showWeekOf('{{ $key }}')" @class([
                            'flex size-7 items-center justify-center rounded-full text-theme-sm',
                            'bg-navy-900 font-semibold text-white dark:bg-white dark:text-navy-900' => $day->isToday(),
                            'text-gray-400' => ! $day->isToday() && $outside,
                            'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => ! $day->isToday() && ! $outside,
                        ]) title="Open the week of {{ $day->format('j M') }}">{{ $day->day }}</button>
                        <span class="text-theme-xs text-gray-400 md:hidden">{{ $day->format('l') }}</span>
                    </div>
                    <div class="space-y-1">
                        @foreach ($dayVisits->take($limit) as $visit)
                            <a href="{{ route('admin.leads.show', $visit->lead_id) }}" wire:navigate wire:key="cal-visit-{{ $visit->id }}"
                               class="block truncate rounded-md border px-1.5 py-1 text-theme-xs {{ $statusClasses[$visit->status->value] ?? $statusClasses['scheduled'] }}"
                               title="{{ $visit->scheduled_at->format('g:i a') }} · {{ $visit->contact?->name }} · {{ $visit->assignee?->name ?? 'Unassigned' }}{{ $visit->address ? ' · '.$visit->address : '' }}">
                                <span class="font-medium">{{ $visit->scheduled_at->format('g:i') }}</span> {{ $visit->contact?->name }}
                                @if ($mode === 'week')
                                    <span class="block truncate opacity-75">{{ $visit->assignee?->name ?? 'Unassigned' }}</span>
                                @endif
                            </a>
                        @endforeach
                        @if ($dayVisits->count() > $limit)
                            <button type="button" wire:click="showWeekOf('{{ $key }}')" class="text-theme-xs font-medium text-navy-700 hover:underline dark:text-gray-300">+{{ $dayVisits->count() - $limit }} more</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    <p class="mt-3 text-theme-xs text-gray-500">Book visits from a lead or by dragging a card to “Site visit” on the pipeline. Team members get Telegram reminders the day before and 2 hours before.</p>
</div>
