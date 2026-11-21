{{-- Money owed, by age: five ages, each with its amount and the invoices behind it; then the two sums. --}}
<x-admin.widget :widget="$widget" :class="$span">
    <ul class="space-y-3.5" aria-label="Money owed by age">
        @foreach ($ages as $age)
            <li>
                <div class="mb-1 flex flex-wrap items-baseline justify-between gap-x-3 text-theme-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ $age->label }}</span>
                    <span class="flex items-baseline gap-3">
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $age->value($lakh) }}</span>
                        <x-admin.figure :figure="$age" class="text-theme-xs tabular-nums" aria-label="{{ $age->label }}: open the {{ $age->counted() }}">{{ $age->counted() }}</x-admin.figure>
                    </span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5" aria-hidden="true">
                    <div @class([
                        'h-full rounded-full',
                        'bg-navy-600' => $age->tone === 'navy',
                        'bg-warning-500' => $age->tone === 'warning',
                        'bg-error-500' => $age->tone === 'error',
                    ]) style="width: {{ round((int) $age->amountMinor / $most * 100) }}%"></div>
                </div>
            </li>
        @endforeach
    </ul>

    <dl class="mt-5 grid grid-cols-1 gap-3 border-t border-gray-100 pt-4 sm:grid-cols-2 dark:border-gray-800">
        @foreach ([$all, $late] as $sum)
            <div>
                <dt class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase">{{ $sum->label }}</dt>
                <dd class="mt-1 flex flex-wrap items-baseline gap-x-3">
                    <span @class(['text-2xl leading-8 font-bold tracking-tight tabular-nums', 'text-error-600 dark:text-error-500' => $sum->tone === 'error', 'text-gray-900 dark:text-white' => $sum->tone !== 'error'])>{{ $sum->value($lakh) }}</span>
                    <x-admin.figure :figure="$sum" class="text-theme-xs tabular-nums" aria-label="{{ $sum->label }}: open the {{ $sum->counted() }}">{{ $sum->counted() }}</x-admin.figure>
                </dd>
            </div>
        @endforeach
    </dl>

    <x-slot:definition>
        As it stands on {{ $as_of }} · amounts in PKR.
        The balance of every issued or part-paid invoice, by days past its due date; an invoice without a due date is not due yet.
        Drafts, paid and void invoices are left out. The five ages add up to all that is owed.
    </x-slot:definition>
</x-admin.widget>
