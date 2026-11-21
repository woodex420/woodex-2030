@use('App\Support\Money')
<div>
    <x-admin.page-header title="Payables aging"
                         description="Every rupee Woodex still owes, bucketed by how late it is against the vendor's due date rather than the bill date."
                         :breadcrumbs="['Accounts' => null, 'Payables' => null, 'Aging' => null]" />

    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Total outstanding</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($outstanding)->format($lakh) }}</p>
        </div>
        <div class="rounded-xl border px-4 py-3 {{ $overdue > 0 ? 'border-error-200 bg-error-50 dark:border-error-500/30 dark:bg-error-500/10' : 'border-gray-200 dark:border-gray-800' }}">
            <p class="text-theme-xs text-gray-500">Overdue</p>
            <p class="text-theme-sm font-medium tabular-nums {{ $overdue > 0 ? 'text-error-600' : 'text-gray-800 dark:text-white/90' }}">{{ Money::ofMinor($overdue)->format($lakh) }}</p>
        </div>
    </div>

    <x-admin.filtered :chips="$filtered" />

    {{-- data-list-rows: the bills drawn below, over all the ages. One list in several tables, so the number is
         written once here and each table is told not to count (master list T-044). --}}
    @if ($outstanding === 0)
        <x-admin.card data-list-rows="{{ $rows }}">
            <p class="text-theme-sm text-gray-500">
                Nothing is owed: every received invoice is paid or none has been entered yet.
                <a href="{{ route('admin.accounts.payables', ['tab' => 'bills']) }}" class="font-medium text-brand-600 hover:underline">Open received invoices</a>.
            </p>
        </x-admin.card>
    @else
        <div class="space-y-5" data-list-rows="{{ $rows }}">
            @if ($rows === 0)
                <x-admin.card>
                    <p class="text-theme-sm text-gray-500" role="status">No bill that is owed on matches this filter.</p>
                </x-admin.card>
            @endif
            @foreach ($buckets as $key => $bucket)
                @continue ($bucket['bills'] === [])
                <x-admin.card :padding="false">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $bucket['label'] }}</p>
                        <p class="text-theme-sm tabular-nums {{ $key !== 'current' ? 'text-error-600' : 'text-gray-600 dark:text-gray-400' }}">{{ Money::ofMinor((int) ($bucket['shown'] ?? 0))->format($lakh) }}</p>
                    </div>
                    <x-admin.table :label="'Received invoices: '.\Illuminate\Support\Str::lcfirst($bucket['label'])" :rows="$bucket['bills']" :card="false" :counted="false" dense>
                        <x-slot:head>
                            <x-admin.table.heading>Vendor</x-admin.table.heading>
                            <x-admin.table.heading>Bill</x-admin.table.heading>
                            <x-admin.table.heading class="hidden sm:table-cell">Due</x-admin.table.heading>
                            <x-admin.table.heading align="right">Balance</x-admin.table.heading>
                        </x-slot:head>
                        @foreach ($bucket['bills'] as $bill)
                            <tr wire:key="aging-{{ $bill->id }}">
                                <td class="px-5 py-2.5 text-gray-800 dark:text-white/90">{{ $bill->vendor?->name ?? '—' }}</td>
                                <th scope="row" class="px-5 py-2.5 font-normal text-gray-600 dark:text-gray-400">{{ $bill->number }}</th>
                                <td class="px-5 py-2.5 hidden text-gray-600 sm:table-cell dark:text-gray-400">{{ $bill->due_date?->format('j M Y') ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($bill->balanceMinor())->format($lakh) }}</td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                </x-admin.card>
            @endforeach
        </div>
    @endif
</div>
