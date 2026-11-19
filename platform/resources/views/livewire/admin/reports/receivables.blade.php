@use('App\Support\Money')
<div>
    <x-admin.page-header title="Receivables" description="Who owes what, aged by how long it has been owed. The follow-up list, worst first."
                         :breadcrumbs="['Sales' => null, 'Receivables' => null]">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" x-data @click="window.print()" class="rounded-lg border border-gray-200 px-3 py-2 text-theme-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Print</button>
                <x-admin.button icon="download" wire:click="export" wire:loading.attr="disabled">CSV</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    @php
        $t = $this->report()['totals'];
        $rows = $this->report()['rows'];
    @endphp
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-5">
        <x-admin.stat label="Outstanding" :value="Money::ofMinor($t['balance_minor'])->format($lakh)" icon="invoice" tone="navy" />
        <x-admin.stat label="Not due yet" :value="Money::ofMinor($t['current'])->format($lakh)" icon="clock" tone="wood" />
        <x-admin.stat label="1–30 days" :value="Money::ofMinor($t['d1_30'])->format($lakh)" icon="clock" :tone="$t['d1_30'] > 0 ? 'warning' : 'success'" />
        <x-admin.stat label="31–60 days" :value="Money::ofMinor($t['d31_60'])->format($lakh)" icon="clock" :tone="$t['d31_60'] > 0 ? 'warning' : 'success'" />
        <x-admin.stat label="90+ days" :value="Money::ofMinor($t['d90_plus'])->format($lakh)" icon="alert" :tone="$t['d90_plus'] > 0 ? 'error' : 'success'" />
    </div>

    <x-admin.table label="Receivables by client" :rows="$rows" class="mt-6">
        <x-slot:head>
            <x-admin.table.heading>Client</x-admin.table.heading>
            <x-admin.table.heading align="right">Invoices</x-admin.table.heading>
            <x-admin.table.heading align="right">Invoiced</x-admin.table.heading>
            <x-admin.table.heading align="right">Paid</x-admin.table.heading>
            <x-admin.table.heading align="right">Balance</x-admin.table.heading>
            <x-admin.table.heading align="right">Not due</x-admin.table.heading>
            <x-admin.table.heading align="right">1–30</x-admin.table.heading>
            <x-admin.table.heading align="right">31–60</x-admin.table.heading>
            <x-admin.table.heading align="right">61–90</x-admin.table.heading>
            <x-admin.table.heading align="right">90+</x-admin.table.heading>
            <x-admin.table.heading>Oldest due</x-admin.table.heading>
            <x-admin.table.heading label="Remind" class="print:hidden" />
        </x-slot:head>

        @foreach ($rows as $row)
            <x-admin.table.row :hover="false">
                <x-admin.table.cell header class="font-medium text-gray-800 dark:text-white/90">
                    <a href="{{ route('admin.contacts.show', $row['contact_id']) }}" wire:navigate class="hover:underline">{{ $row['contact'] }}</a>
                </x-admin.table.cell>
                <x-admin.table.cell align="right">{{ $row['invoices'] }}</x-admin.table.cell>
                <x-admin.table.cell align="right">{{ Money::ofMinor($row['invoiced_minor'])->format($lakh) }}</x-admin.table.cell>
                <x-admin.table.cell align="right">{{ Money::ofMinor($row['paid_minor'])->format($lakh) }}</x-admin.table.cell>
                <x-admin.table.cell align="right" class="font-medium">{{ Money::ofMinor($row['balance_minor'])->format($lakh) }}</x-admin.table.cell>
                <x-admin.table.cell align="right">{{ Money::ofMinor($row['current'])->format($lakh) }}</x-admin.table.cell>
                <x-admin.table.cell align="right" class="{{ $row['d1_30'] > 0 ? 'text-warning-700 dark:text-warning-400' : 'text-gray-400' }}">{{ Money::ofMinor($row['d1_30'])->format($lakh) }}</x-admin.table.cell>
                <x-admin.table.cell align="right" class="{{ $row['d31_60'] > 0 ? 'text-warning-700 dark:text-warning-400' : 'text-gray-400' }}">{{ Money::ofMinor($row['d31_60'])->format($lakh) }}</x-admin.table.cell>
                <x-admin.table.cell align="right" class="{{ $row['d61_90'] > 0 ? 'text-warning-700 dark:text-warning-400' : 'text-gray-400' }}">{{ Money::ofMinor($row['d61_90'])->format($lakh) }}</x-admin.table.cell>
                <x-admin.table.cell align="right" class="{{ $row['d90_plus'] > 0 ? 'font-medium text-error-600 dark:text-error-400' : 'text-gray-400' }}">{{ Money::ofMinor($row['d90_plus'])->format($lakh) }}</x-admin.table.cell>
                <x-admin.table.cell>{{ $row['oldest_due']?->format('j M Y') ?? '—' }}</x-admin.table.cell>
                <x-admin.table.cell class="print:hidden">
                    @php
                        $whatsapp = $this->whatsappFor((int) $row['contact_id']);
                    @endphp
                    @if ($whatsapp)
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="text-theme-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Nudge</a>
                    @endif
                </x-admin.table.cell>
            </x-admin.table.row>
        @endforeach

        <x-slot:empty>
            <x-admin.empty icon="check-circle" title="Nothing outstanding" description="Every issued invoice is settled." />
        </x-slot:empty>

        <x-slot:foot>
            <tr class="border-t border-gray-200 font-medium dark:border-gray-700">
                <th scope="row" class="px-5 py-3 font-medium">Total</th>
                <td colspan="3"></td>
                <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['balance_minor'])->format($lakh) }}</td>
                <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['current'])->format($lakh) }}</td>
                <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['d1_30'])->format($lakh) }}</td>
                <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['d31_60'])->format($lakh) }}</td>
                <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['d61_90'])->format($lakh) }}</td>
                <td class="px-5 py-3 text-right">{{ Money::ofMinor($t['d90_plus'])->format($lakh) }}</td>
                <td colspan="2"></td>
            </tr>
        </x-slot:foot>
    </x-admin.table>
</div>
