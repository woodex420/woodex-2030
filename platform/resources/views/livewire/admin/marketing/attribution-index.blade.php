@use('App\Support\Money')
<div>
    <x-admin.page-header title="Attribution"
                         description="Where enquiries came from and what they became — source, quoted, won, and the value of the work won. Cohorts follow the enquiry, so a channel is judged on its outcomes, not its first week."
                         :breadcrumbs="['Marketing' => null, 'Attribution' => null]" />

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="wx-label" for="attribution-range">Enquiry window</label>
            <select id="attribution-range" wire:model.live="range" class="wx-input">
                <option value="month">This month</option>
                <option value="quarter">This quarter</option>
                <option value="year">This year</option>
                <option value="all">All time</option>
            </select>
        </div>
        <p class="pb-2 text-theme-xs text-gray-500">Showing {{ $rangeLabel }}.</p>
        <p class="ml-auto max-w-md pb-2 text-theme-xs text-gray-500">Ad spend is not shown yet — Meta is still awaiting approval. Until real spend exists, this page reports enquiries and won value only, never a guessed cost per lead.</p>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Enquiries</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ $totals['enquiries'] }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Reached a quotation</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ $totals['quoted'] }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Won</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ $totals['won'] }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Won value</p>
            <p class="text-theme-sm font-medium tabular-nums text-success-600">{{ Money::ofMinor($totals['won_minor'])->format($lakh) }}</p>
        </div>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3">Source</th>
                        <th class="px-5 py-3 text-right">Enquiries</th>
                        <th class="px-5 py-3 text-right">Quoted</th>
                        <th class="px-5 py-3 text-right">Won</th>
                        <th class="hidden px-5 py-3 text-right sm:table-cell">Win rate</th>
                        <th class="px-5 py-3 text-right">Won value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr wire:key="source-{{ $row['source']->value }}">
                            <td class="px-5 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['source']->label() }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ $row['enquiries'] }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ $row['quoted'] }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ $row['won'] }}</td>
                            <td class="hidden px-5 py-3 text-right tabular-nums text-gray-600 sm:table-cell dark:text-gray-400">{{ $row['enquiries'] > 0 ? number_format($row['won'] / $row['enquiries'] * 100, 1).'%' : '—' }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($row['won_minor'])->format($lakh) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500">
                                No enquiry arrived in this window, so there is nothing to attribute. Widen the window — the numbers here only ever come from recorded enquiries.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot class="border-t border-gray-200 text-theme-sm font-medium dark:border-gray-700">
                        <tr>
                            <td class="px-5 py-3 text-gray-800 dark:text-white/90">All sources</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ $totals['enquiries'] }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ $totals['quoted'] }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ $totals['won'] }}</td>
                            <td class="hidden px-5 py-3 text-right tabular-nums text-gray-800 sm:table-cell dark:text-white/90">{{ $totals['enquiries'] > 0 ? number_format($totals['won'] / $totals['enquiries'] * 100, 1).'%' : '—' }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($totals['won_minor'])->format($lakh) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-admin.card>

    <p class="mt-4 text-theme-xs text-gray-500">How to read this: <em>Quoted</em> means the enquiry reached at least one quotation; <em>Won</em> means a quotation from it was accepted; <em>Won value</em> is those accepted quotations' totals. Open <a href="{{ route('admin.contacts.index', ['tab' => 'visitors']) }}" wire:navigate class="font-medium text-brand-600 hover:underline">Visitors</a> for the website enquiries still waiting to convert.</p>
</div>
