{{-- The sales funnel of the period: three counts, each a door to its rows, and the rate between the last two. --}}
<x-admin.widget :widget="$widget" :class="$span">
    <ol class="space-y-3.5">
        @foreach ($steps as $step)
            <li>
                <div class="mb-1 flex items-baseline justify-between gap-3 text-theme-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ $step->label }}</span>
                    <x-admin.figure :figure="$step" class="tabular-nums" aria-label="{{ $step->label }}: open the {{ $step->counted() }}">{{ number_format($step->count) }}</x-admin.figure>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5" aria-hidden="true">
                    <div @class(['h-full rounded-full', 'bg-navy-600' => ! $loop->last, 'bg-success-500' => $loop->last]) style="width: {{ round($step->count / $most * 100) }}%"></div>
                </div>
            </li>
        @endforeach
    </ol>

    <dl class="mt-5 grid grid-cols-2 gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
        <div>
            <dt class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase">Conversion</dt>
            <dd class="mt-1 text-2xl leading-8 font-bold tracking-tight tabular-nums text-gray-900 dark:text-white" data-funnel-rate>{{ $rate === null ? '—' : $rate.'%' }}</dd>
        </div>
        <div>
            <dt class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase">Accepted value</dt>
            <dd class="mt-1 text-theme-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $accepted_value }}</dd>
        </div>
    </dl>

    <x-slot:definition>
        {{ $dates }} · {{ $line ?? 'all business lines' }} · {{ $own ? 'your own leads and quotations' : 'everybody\'s leads and quotations' }} · value in PKR.
        Conversion is quotations accepted ÷ quotations sent, both in the period{{ $rate === null ? '; none was sent, so there is no rate' : '' }}.
        A quotation sent before the period and accepted in it counts as accepted here. Void quotations are left out.
    </x-slot:definition>
</x-admin.widget>
