@use('App\Support\Money')
<div>
    <x-admin.page-header title="Revenue & collections" description="Quoted, accepted, invoiced, collected — per month, per line, per bank."
                         :breadcrumbs="['Reports' => null, 'Revenue & Collections' => null]">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" onclick="window.print()" class="rounded-lg border border-gray-200 px-3 py-2 text-theme-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Print</button>
                <x-admin.button icon="download" wire:click="export" wire:loading.attr="disabled">CSV</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 flex flex-wrap gap-2 print:hidden">
        @foreach (['today' => 'Today', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'this_year' => 'This year'] as $key => $label)
            <button type="button" wire:click="setPreset('{{ $key }}')"
                    class="rounded-lg px-3 py-1.5 text-theme-sm font-medium {{ $preset === $key ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="mb-5 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <label class="flex flex-wrap items-center gap-3 text-theme-sm">Business line<select wire:model.live="line" aria-label="Revenue business line" class="wx-input w-auto"><option value="">All business lines</option>@foreach (\App\Domain\Crm\Enums\BusinessLine::cases() as $businessLine)<option value="{{ $businessLine->value }}">{{ $businessLine->label() }}</option>@endforeach</select></label>
        <p class="mt-3 text-theme-xs text-gray-500">Current: {{ $this->range()->from->format('d M Y') }} – {{ $this->range()->to->format('d M Y') }} · Previous calendar/comparison period: {{ $this->range()->previous()->from->format('d M Y') }} – {{ $this->range()->previous()->to->format('d M Y') }} · {{ config('app.timezone') }}</p>
        <p class="mt-1 text-theme-xs text-gray-500">Issued invoice values exclude drafts and voids; they are not posted ledger profit. {{ $line === '' ? 'Collections include verified receipts, including unallocated cash.' : 'Collections show verified allocations to this line only; unallocated cash is excluded.' }} Outstanding is the current balance, not a historical period-end balance.</p>
    </div>
    @php
        $t = $this->report()['totals'];
        $p = $this->report()['previous'];
    @endphp
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-admin.stat label="Quoted" :value="Money::ofMinor($t['quoted_minor'])->format($lakh)" icon="quote" tone="wood" :hint="'was '.Money::ofMinor($p['quoted_minor'])->format($lakh)" />
        <x-admin.stat label="Accepted" :value="Money::ofMinor($t['accepted_minor'])->format($lakh)" icon="check" tone="success" :hint="'was '.Money::ofMinor($p['accepted_minor'])->format($lakh)" />
        <x-admin.stat label="Invoiced" :value="Money::ofMinor($t['invoiced_minor'])->format($lakh)" icon="invoice" tone="navy" :hint="'was '.Money::ofMinor($p['invoiced_minor'])->format($lakh)" />
        <x-admin.stat label="Collected" :value="Money::ofMinor($t['collected_minor'])->format($lakh)" icon="wallet" :tone="$t['collected_minor'] >= $t['invoiced_minor'] ? 'success' : 'warning'" :hint="'was '.Money::ofMinor($p['collected_minor'])->format($lakh).(' · outstanding today '.Money::ofMinor($t['outstanding_minor'])->format($lakh))" />
    </div>

    <x-admin.card title="By month · {{ $this->range()->label() }}" :padding="false" class="mt-6">
        <div class="overflow-x-auto">
            <table class="w-full text-theme-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-theme-xs uppercase tracking-wide text-gray-500 dark:border-gray-800">
                        <th class="px-5 py-3">Month</th>
                        <th class="px-5 py-3 text-right">Quoted</th>
                        <th class="px-5 py-3 text-right">Accepted</th>
                        <th class="px-5 py-3 text-right">Invoiced</th>
                        <th class="px-5 py-3 text-right">Collected</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->report()['months'] as $row)
                        <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                            <td class="px-5 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['month'] }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['quoted_minor'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['accepted_minor'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['invoiced_minor'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right {{ $row['collected_minor'] > 0 ? 'font-medium text-success-700 dark:text-success-400' : '' }}">{{ Money::ofMinor($row['collected_minor'])->format($lakh) }}</td>
                        </tr>
                    @endforeach
                    @if ($this->report()['months'] === [])
                        <tr><td colspan="5" class="px-5 py-8 text-center text-gray-500">Nothing in this period.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </x-admin.card>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <x-admin.card title="By business line" :padding="false">
            <div class="overflow-x-auto">
                <table class="w-full text-theme-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-theme-xs uppercase tracking-wide text-gray-500 dark:border-gray-800">
                            <th class="px-5 py-3">Line</th>
                            <th class="px-5 py-3 text-right">Accepted</th>
                            <th class="px-5 py-3 text-right">Invoiced</th>
                            <th class="px-5 py-3 text-right">Collected</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->report()['by_line'] as $row)
                            <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                                <td class="px-5 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }}</td>
                                <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['accepted_minor'])->format($lakh) }}</td>
                                <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['invoiced_minor'])->format($lakh) }}</td>
                                <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['collected_minor'])->format($lakh) }}</td>
                            </tr>
                        @endforeach
                        @if ($this->report()['by_line'] === [])
                            <tr><td colspan="4" class="px-5 py-8 text-center text-gray-500">Nothing in this period.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </x-admin.card>

        <x-admin.card title="Where the money arrived" :padding="false">
            @forelse ($this->report()['by_method'] as $row)
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800">
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }} <span class="text-gray-400">· {{ $row['count'] }} payment(s)</span></span>
                    <span class="font-medium">{{ Money::ofMinor($row['amount_minor'])->format($lakh) }}</span>
                </div>
            @empty
                <x-admin.empty icon="wallet" title="No verified payments in this period" />
            @endforelse
        </x-admin.card>
    </div>
</div>
