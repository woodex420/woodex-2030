@use('App\Support\Money')
<div>
    <x-admin.page-header title="Profit & loss"
                         description="Income minus cost of sales minus overheads, taken from the posted journal lines — never typed. The same range answers on the trial balance; the two can never disagree."
                         :breadcrumbs="['Accounts' => null, 'Profit & loss' => null]" />

    <div class="mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="wx-label" for="pl-from">From</label>
            <input id="pl-from" type="date" wire:model.live="from" class="wx-input" />
        </div>
        <div>
            <label class="wx-label" for="pl-to">To</label>
            <input id="pl-to" type="date" wire:model.live="to" class="wx-input" />
        </div>
        <div class="ml-auto flex flex-wrap gap-2 pb-2">
            <button type="button" wire:click="preset('month')" class="rounded-lg border border-gray-300 px-3 py-2 text-theme-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">This month</button>
            <button type="button" wire:click="preset('quarter')" class="rounded-lg border border-gray-300 px-3 py-2 text-theme-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">This quarter</button>
            <button type="button" wire:click="preset('year')" class="rounded-lg border border-gray-300 px-3 py-2 text-theme-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">This year</button>
            <button type="button" wire:click="preset('all')" class="rounded-lg border border-gray-300 px-3 py-2 text-theme-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">All time</button>
        </div>
    </div>

    @php
        $empty = $income === [] && $costOfSales === [] && $overheads === [];
        $netTone = $netProfit >= 0 ? 'text-success-600' : 'text-error-600';
    @endphp

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-theme-sm
                {{ $empty ? 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-white/5 dark:text-gray-300' : ($netProfit >= 0 ? 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-500' : 'border-error-200 bg-error-50 text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-500') }}">
        <div class="flex items-center gap-2">
            <x-icon :name="$empty ? 'info' : ($netProfit >= 0 ? 'trending-up' : 'alert')" class="size-4" />
            @if ($empty)
                <span><strong>Nothing posted yet.</strong> No income or expense line falls between {{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} and {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}.</span>
            @else
                <span><strong>{{ $netProfit >= 0 ? 'Profit' : 'Loss' }} {{ Money::ofMinor(abs($netProfit))->format($lakh) }}</strong> for {{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}.</span>
            @endif
        </div>
        <div class="tabular-nums">Income {{ Money::ofMinor($totalIncome)->format($lakh) }} · Costs {{ Money::ofMinor($totalCostOfSales + $totalOverheads)->format($lakh) }}</div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <x-admin.card title="Income" description="Client invoices post to this side, and so does other income you record by hand.">
            @if ($income === [])
                <p class="text-theme-sm text-gray-500">Nothing earned in this range.</p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($income as $row)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <span class="text-theme-sm text-gray-600 dark:text-gray-400">{{ $row['account']->code }} · {{ $row['account']->name }}</span>
                            <span class="text-theme-sm tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($row['amount'])->format($lakh) }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-3 flex items-center justify-between border-t border-gray-200 pt-3 dark:border-gray-800">
                    <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">Total income</span>
                    <span class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($totalIncome)->format($lakh) }}</span>
                </div>
            @endif
        </x-admin.card>

        <x-admin.card title="Costs" description="5010–5099 is the direct cost of the work (materials, labour, subcontractors); everything else in 5xxx is overhead.">
            @if ($costOfSales === [] && $overheads === [])
                <p class="text-theme-sm text-gray-500">Nothing spent in this range.</p>
            @else
                @if ($costOfSales !== [])
                    <p class="mb-1 text-theme-xs tracking-wide text-gray-500 uppercase">Cost of sales</p>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($costOfSales as $row)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <span class="text-theme-sm text-gray-600 dark:text-gray-400">{{ $row['account']->code }} · {{ $row['account']->name }}</span>
                                <span class="text-theme-sm tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($row['amount'])->format($lakh) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-2 flex items-center justify-between border-t border-gray-200 pt-2 dark:border-gray-800">
                        <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">Total cost of sales</span>
                        <span class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($totalCostOfSales)->format($lakh) }}</span>
                    </div>
                @endif

                @if ($overheads !== [])
                    <p class="mt-4 mb-1 text-theme-xs tracking-wide text-gray-500 uppercase">Overheads</p>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($overheads as $row)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <span class="text-theme-sm text-gray-600 dark:text-gray-400">{{ $row['account']->code }} · {{ $row['account']->name }}</span>
                                <span class="text-theme-sm tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($row['amount'])->format($lakh) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-2 flex items-center justify-between border-t border-gray-200 pt-2 dark:border-gray-800">
                        <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">Total overheads</span>
                        <span class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($totalOverheads)->format($lakh) }}</span>
                    </div>
                @endif
            @endif
        </x-admin.card>
    </div>

    <x-admin.card class="mt-5" :padding="false">
        <div class="grid grid-cols-1 divide-y divide-gray-100 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-gray-800">
            <div class="px-5 py-4">
                <p class="text-theme-xs text-gray-500">Gross profit (income − cost of sales)</p>
                <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($grossProfit)->format($lakh) }}</p>
            </div>
            <div class="px-5 py-4">
                <p class="text-theme-xs text-gray-500">Overheads</p>
                <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($totalOverheads)->format($lakh) }}</p>
            </div>
            <div class="px-5 py-4">
                <p class="text-theme-xs text-gray-500">Net {{ $netProfit >= 0 ? 'profit' : 'loss' }}</p>
                <p class="text-theme-sm font-medium tabular-nums {{ $netTone }}">{{ Money::ofMinor($netProfit)->format($lakh) }}</p>
            </div>
        </div>
    </x-admin.card>
</div>
