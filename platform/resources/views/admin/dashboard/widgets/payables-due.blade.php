{{-- Payables due: what is late, what falls due within a week, and all that is owed to vendors. --}}
<x-admin.widget :widget="$widget" :class="$span">
    <dl class="space-y-4">
        @foreach ([$overdue, $soon, $all] as $sum)
            <div @class(['border-t border-gray-100 pt-4 dark:border-gray-800' => $loop->last])>
                <dt class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase">{{ $sum->label }}</dt>
                <dd class="mt-1 flex flex-wrap items-baseline justify-between gap-x-3">
                    <span @class([
                        'text-2xl leading-8 font-bold tracking-tight tabular-nums',
                        'text-error-600 dark:text-error-500' => $sum->tone === 'error',
                        'text-warning-700 dark:text-warning-500' => $sum->tone === 'warning',
                        'text-gray-900 dark:text-white' => ! in_array($sum->tone, ['error', 'warning'], true),
                    ])>{{ $sum->value($lakh) }}</span>
                    <x-admin.figure :figure="$sum" class="text-theme-xs tabular-nums" aria-label="{{ $sum->label }}: open the {{ $sum->counted() }}">{{ $sum->counted() }}</x-admin.figure>
                </dd>
            </div>
        @endforeach
    </dl>

    <x-slot:definition>
        As it stands on {{ $as_of }} · amounts in PKR.
        The balance of every received or part-paid bill, by the vendor's due date. "Due within {{ \App\Domain\Accounts\Models\Bill::DUE_SOON_DAYS }} days" counts today.
        Drafts, paid and void bills are left out.
    </x-slot:definition>
</x-admin.widget>
