@use('App\Support\Money')
<div>
    <x-admin.page-header title="Trial balance"
                         description="Every posted journal line in the range, summed per account. Debits must equal credits — if they do not, this page says so instead of hiding it."
                         :breadcrumbs="['Accounts' => null, 'Trial balance' => null]" />

    <div class="mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="wx-label" for="tb-from">From</label>
            <input id="tb-from" type="date" wire:model.live="from" class="wx-input" />
        </div>
        <div>
            <label class="wx-label" for="tb-to">To</label>
            <input id="tb-to" type="date" wire:model.live="to" class="wx-input" />
        </div>
        <div class="flex flex-wrap gap-2 pb-0.5">
            <button type="button" wire:click="preset('month')" class="rounded-lg border border-gray-300 px-3 py-2 text-theme-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">This month</button>
            <button type="button" wire:click="preset('quarter')" class="rounded-lg border border-gray-300 px-3 py-2 text-theme-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">This quarter</button>
            <button type="button" wire:click="preset('year')" class="rounded-lg border border-gray-300 px-3 py-2 text-theme-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">This year</button>
            <button type="button" wire:click="preset('all')" class="rounded-lg border border-gray-300 px-3 py-2 text-theme-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">All time</button>
        </div>
        <label class="ml-auto inline-flex items-center gap-2 pb-2 text-theme-sm text-gray-600 dark:text-gray-400">
            <input type="checkbox" wire:model.live="showEmpty" class="size-4 rounded border-gray-300" />
            Include accounts with no movement
        </label>
    </div>

    @php
        // An empty range balances at zero by arithmetic, not by proof — say that instead of a green tick.
        $emptyRange = $entryCount === 0;
        $bannerTone = $emptyRange
            ? 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-white/5 dark:text-gray-300'
            : ($difference === 0
                ? 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-500'
                : 'border-error-200 bg-error-50 text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-500');
    @endphp

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-theme-sm {{ $bannerTone }}">
        <div class="flex items-center gap-2">
            <x-icon :name="$emptyRange ? 'info' : ($difference === 0 ? 'check-circle' : 'alert')" class="size-4" />
            @if ($emptyRange)
                <span><strong>Nothing posted yet.</strong> No journal entry falls between {{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} and {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}, so there is nothing to balance.</span>
            @elseif ($difference === 0)
                <span><strong>Balanced.</strong> {{ $entryCount }} {{ \Illuminate\Support\Str::plural('journal entry', $entryCount) }} between {{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} and {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}.</span>
            @else
                <span><strong>Out of balance by {{ Money::ofMinor($difference)->format($lakh) }}.</strong> Do not close a period until this is zero.</span>
            @endif
        </div>
        <div class="tabular-nums">Dr {{ Money::ofMinor($totalDebit)->format($lakh) }} · Cr {{ Money::ofMinor($totalCredit)->format($lakh) }}</div>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Code</th>
                        <th class="px-5 py-3 font-medium">Account</th>
                        <th class="hidden px-5 py-3 font-medium md:table-cell">Type</th>
                        <th class="hidden px-5 py-3 text-right font-medium sm:table-cell">Entries</th>
                        <th class="px-5 py-3 text-right font-medium">Debit</th>
                        <th class="px-5 py-3 text-right font-medium">Credit</th>
                        <th class="px-5 py-3 text-right font-medium">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr wire:key="tb-{{ $row['account']->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3 tabular-nums text-gray-500">{{ $row['account']->code }}</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.accounts.chart') }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $row['account']->name }}</a>
                                @unless ($row['account']->is_active)
                                    <x-admin.badge color="gray" class="ml-1">Inactive</x-admin.badge>
                                @endunless
                            </td>
                            <td class="hidden px-5 py-3 md:table-cell text-gray-500">{{ $row['account']->type->label() }}</td>
                            <td class="hidden px-5 py-3 text-right tabular-nums text-gray-500 sm:table-cell">{{ $row['entries'] }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ $row['debit'] > 0 ? Money::ofMinor($row['debit'])->format($lakh) : '—' }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ $row['credit'] > 0 ? Money::ofMinor($row['credit'])->format($lakh) : '—' }}</td>
                            <td class="px-5 py-3 text-right font-medium tabular-nums">
                                @if ($row['net'] === 0)
                                    <span class="text-gray-400">—</span>
                                @else
                                    {{ Money::ofMinor(abs($row['net']))->format($lakh) }} {{ $row['net'] > 0 ? 'Dr' : 'Cr' }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-admin.empty icon="chart" title="Nothing posted in this range"
                                               description="Issue an invoice or post a journal and its accounts appear here with the amounts that moved." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot class="border-t-2 border-gray-200 text-theme-sm font-semibold dark:border-gray-700">
                        <tr>
                            <td colspan="4" class="px-5 py-3 text-right">Totals</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ Money::ofMinor($totalDebit)->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ Money::ofMinor($totalCredit)->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums {{ $emptyRange ? 'text-gray-500' : ($difference === 0 ? 'text-success-600' : 'text-error-600') }}">
                                {{ $difference === 0 ? '0' : Money::ofMinor(abs($difference))->format($lakh) }}
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-admin.card>
</div>
