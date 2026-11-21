{{-- New leads by day, with the dates the chart covers and the total as the door to those leads. --}}
<x-admin.widget :widget="$widget" :class="$span">
    <x-slot:actions>
        <x-admin.figure :figure="$total" class="text-theme-sm">Open the {{ $total->counted() }} →</x-admin.figure>
    </x-slot:actions>

    <div wire:ignore data-chart='@json($chart)' class="-mx-2 min-h-[240px] min-w-0"></div>

    <x-slot:definition>
        {{ $dates }} · {{ config('app.timezone') }} · {{ $own ? 'your own leads' : 'everybody\'s leads' }} · a count of enquiries, no money.
        Each enquiry is counted on the day it was made.
    </x-slot:definition>
</x-admin.widget>
