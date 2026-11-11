@use('App\Support\Money')
<div>
    <x-admin.page-header title="Payables aging"
                         description="Every rupee the studio still owes, bucketed by how late it is against the vendor's due date rather than the bill date."
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

    @if ($outstanding === 0)
        <x-admin.card>
            <p class="text-theme-sm text-gray-500">
                Nothing is owed: every received invoice is paid or none has been entered yet.
                <a href="{{ route('admin.accounts.payables', ['tab' => 'bills']) }}" class="font-medium text-brand-600 hover:underline">Open received invoices</a>.
            </p>
        </x-admin.card>
    @else
        <div class="space-y-5">
            @foreach ($buckets as $key => $bucket)
                @continue ($bucket['bills'] === [])
                <x-admin.card :padding="false">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $bucket['label'] }}</p>
                        <p class="text-theme-sm tabular-nums {{ $key !== 'current' ? 'text-error-600' : 'text-gray-600 dark:text-gray-400' }}">{{ Money::ofMinor((int) $bucket['total'])->format($lakh) }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-theme-sm">
                            <thead class="text-theme-xs tracking-wide text-gray-500 uppercase">
                                <tr>
                                    <th class="px-5 py-2">Vendor</th>
                                    <th class="px-5 py-2">Bill</th>
                                    <th class="hidden px-5 py-2 sm:table-cell">Due</th>
                                    <th class="px-5 py-2 text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($bucket['bills'] as $bill)
                                    <tr wire:key="aging-{{ $bill->id }}">
                                        <td class="px-5 py-2.5 text-gray-800 dark:text-white/90">{{ $bill->vendor?->name ?? '—' }}</td>
                                        <td class="px-5 py-2.5 text-gray-600 dark:text-gray-400">{{ $bill->number }}</td>
                                        <td class="hidden px-5 py-2.5 text-gray-600 sm:table-cell dark:text-gray-400">{{ $bill->due_date?->format('j M Y') ?? '—' }}</td>
                                        <td class="px-5 py-2.5 text-right tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($bill->balanceMinor())->format($lakh) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-admin.card>
            @endforeach
        </div>
    @endif
</div>
