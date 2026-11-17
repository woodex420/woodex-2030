@use('App\Support\Money')
<div>
    <x-admin.page-header title="Receivables" description="Who owes what, aged by how long it has been owed. The follow-up list, worst first."
                         :breadcrumbs="['Sales' => null, 'Receivables' => null]">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" onclick="window.print()" class="rounded-lg border border-gray-200 px-3 py-2 text-theme-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Print</button>
                <x-admin.button icon="download" wire:click="export" wire:loading.attr="disabled">CSV</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    @php
        $t = $this->report()['totals'];
    @endphp
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-5">
        <x-admin.stat label="Outstanding" :value="Money::ofMinor($t['balance_minor'])->format($lakh)" icon="invoice" tone="navy" />
        <x-admin.stat label="Not due yet" :value="Money::ofMinor($t['current'])->format($lakh)" icon="clock" tone="wood" />
        <x-admin.stat label="1–30 days" :value="Money::ofMinor($t['d1_30'])->format($lakh)" icon="clock" :tone="$t['d1_30'] > 0 ? 'warning' : 'success'" />
        <x-admin.stat label="31–60 days" :value="Money::ofMinor($t['d31_60'])->format($lakh)" icon="clock" :tone="$t['d31_60'] > 0 ? 'warning' : 'success'" />
        <x-admin.stat label="90+ days" :value="Money::ofMinor($t['d90_plus'])->format($lakh)" icon="alert" :tone="$t['d90_plus'] > 0 ? 'error' : 'success'" />
    </div>

    <x-admin.card :padding="false" class="mt-6">
        <div class="overflow-x-auto">
            <table class="w-full text-theme-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-theme-xs uppercase tracking-wide text-gray-500 dark:border-gray-800">
                        <th class="px-5 py-3">Client</th>
                        <th class="px-5 py-3 text-right">Invoices</th>
                        <th class="px-5 py-3 text-right">Invoiced</th>
                        <th class="px-5 py-3 text-right">Paid</th>
                        <th class="px-5 py-3 text-right">Balance</th>
                        <th class="px-5 py-3 text-right">Not due</th>
                        <th class="px-5 py-3 text-right">1–30</th>
                        <th class="px-5 py-3 text-right">31–60</th>
                        <th class="px-5 py-3 text-right">61–90</th>
                        <th class="px-5 py-3 text-right">90+</th>
                        <th class="px-5 py-3">Oldest due</th>
                        <th class="px-5 py-3 print:hidden"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->report()['rows'] as $row)
                        <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                            <td class="px-5 py-3 font-medium text-gray-800 dark:text-white/90">
                                <a href="{{ route('admin.contacts.show', $row['contact_id']) }}" wire:navigate class="hover:underline">{{ $row['contact'] }}</a>
                            </td>
                            <td class="px-5 py-3 text-right">{{ $row['invoices'] }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['invoiced_minor'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['paid_minor'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right font-medium">{{ Money::ofMinor($row['balance_minor'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['current'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right {{ $row['d1_30'] > 0 ? 'text-warning-700 dark:text-warning-400' : 'text-gray-400' }}">{{ Money::ofMinor($row['d1_30'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right {{ $row['d31_60'] > 0 ? 'text-warning-700 dark:text-warning-400' : 'text-gray-400' }}">{{ Money::ofMinor($row['d31_60'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right {{ $row['d61_90'] > 0 ? 'text-warning-700 dark:text-warning-400' : 'text-gray-400' }}">{{ Money::ofMinor($row['d61_90'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right {{ $row['d90_plus'] > 0 ? 'font-medium text-error-600 dark:text-error-400' : 'text-gray-400' }}">{{ Money::ofMinor($row['d90_plus'])->format($lakh) }}</td>
                            <td class="px-5 py-3">{{ $row['oldest_due']?->format('j M Y') ?? '—' }}</td>
                            <td class="px-5 py-3 print:hidden">
                                @php
                                    $whatsapp = $this->whatsappFor((int) $row['contact_id']);
                                @endphp
                                @if ($whatsapp)
                                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="text-theme-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Nudge</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if ($this->report()['rows'] === [])
                        <tr><td colspan="12" class="px-5 py-10 text-center text-gray-500">Nothing outstanding — every issued invoice is settled.</td></tr>
                    @endif
                </tbody>
                <tfoot>
                    @if ($this->report()['rows'] !== [])
                        <tr class="border-t border-gray-200 font-medium dark:border-gray-700">
                            <td class="px-5 py-3">Total</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['balance_minor'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['current'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['d1_30'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['d31_60'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['d61_90'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['d90_plus'])->format($lakh) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    @endif
                </tfoot>
            </table>
        </div>
    </x-admin.card>
</div>
