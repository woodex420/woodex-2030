@use('App\Support\Money')
<div>
    <x-admin.page-header title="Sales funnel" description="Enquiries in, quotations out, wins and losses — by source, line and owner."
                         :breadcrumbs="['Reports' => null, 'Sales Funnel' => null]">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" onclick="window.print()" class="rounded-lg border border-gray-200 px-3 py-2 text-theme-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Print</button>
                <x-admin.button icon="download" wire:click="export" wire:loading.attr="disabled">CSV</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 flex flex-wrap gap-2 print:hidden">
        @foreach (['today' => 'Today', 'this_month' => 'This month', 'last_month' => 'Last month', 'last_90_days' => 'Last 90 days', 'this_quarter' => 'This quarter', 'this_year' => 'This year'] as $key => $label)
            <button type="button" wire:click="setPreset('{{ $key }}')"
                    class="rounded-lg px-3 py-1.5 text-theme-sm font-medium {{ $preset === $key ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <p class="mb-4 text-theme-xs text-gray-500">Current: {{ $this->range()->from->format('d M Y') }} – {{ $this->range()->to->format('d M Y') }} · Previous: {{ $this->range()->previous()->from->format('d M Y') }} – {{ $this->range()->previous()->to->format('d M Y') }}. These are events in each period, not a tracked conversion cohort.</p>
    @php
        $h = $this->report()['headline'];
        $p = $this->report()['previous'];
    @endphp
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-admin.stat label="Leads" :value="(string) $h['leads']" icon="user-plus" tone="navy" :hint="'was '.$p['leads']" />
        <x-admin.stat label="Quotes sent" :value="(string) $h['quotes_sent']" icon="quote" tone="wood" :hint="'was '.$p['quotes_sent']" />
        <x-admin.stat label="Accepted" :value="(string) $h['accepted']" icon="check" tone="success" :hint="Money::ofMinor($h['accepted_value_minor'])->format($lakh).' · was '.$p['accepted']" />
        <x-admin.stat label="Win rate" :value="$h['win_rate'].'%'" icon="trending-up" :tone="$h['win_rate'] >= 30 ? 'success' : 'warning'" :hint="$h['won'].' won · '.$h['lost'].' lost'" />
    </div>

    <x-admin.card title="By source · {{ $this->range()->label() }}" :padding="false" class="mt-6">
        <div class="overflow-x-auto">
            <table class="w-full text-theme-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-theme-xs uppercase tracking-wide text-gray-500 dark:border-gray-800">
                        <th class="px-5 py-3">Source</th>
                        <th class="px-5 py-3 text-right">Leads</th>
                        <th class="px-5 py-3 text-right">Quotes sent</th>
                        <th class="px-5 py-3 text-right">Accepted</th>
                        <th class="px-5 py-3 text-right">Accepted value</th>
                        <th class="px-5 py-3 text-right">Win rate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->report()['by_source'] as $row)
                        <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                            <td class="px-5 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }}</td>
                            <td class="px-5 py-3 text-right">{{ $row['leads'] }}</td>
                            <td class="px-5 py-3 text-right">{{ $row['quotes_sent'] }}</td>
                            <td class="px-5 py-3 text-right">{{ $row['accepted'] }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['accepted_value_minor'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ $row['win_rate'] }}%</td>
                        </tr>
                    @endforeach
                    @if ($this->report()['by_source'] === [])
                        <tr><td colspan="6" class="px-5 py-8 text-center text-gray-500">No leads in this period.</td></tr>
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
                            <th class="px-5 py-3 text-right">Leads</th>
                            <th class="px-5 py-3 text-right">Sent</th>
                            <th class="px-5 py-3 text-right">Accepted</th>
                            <th class="px-5 py-3 text-right">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->report()['by_line'] as $row)
                            <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                                <td class="px-5 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }}</td>
                                <td class="px-5 py-3 text-right">{{ $row['leads'] }}</td>
                                <td class="px-5 py-3 text-right">{{ $row['quotes_sent'] }}</td>
                                <td class="px-5 py-3 text-right">{{ $row['accepted'] }}</td>
                                <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['accepted_value_minor'])->format($lakh) }}</td>
                            </tr>
                        @endforeach
                        @if ($this->report()['by_line'] === [])
                            <tr><td colspan="5" class="px-5 py-8 text-center text-gray-500">Nothing yet.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </x-admin.card>

        <x-admin.card title="Why leads were lost" :padding="false">
            @forelse ($this->report()['drop_offs'] as $row)
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800">
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['reason'] }}</span>
                    <x-admin.badge :color="$row['count'] >= 3 ? 'error' : 'gray'">{{ $row['count'] }}</x-admin.badge>
                </div>
            @empty
                <x-admin.empty icon="check-circle" title="No losses in this period" description="Every lead is still in play — or already won." />
            @endforelse
        </x-admin.card>
    </div>

    <x-admin.card title="By owner" :padding="false" class="mt-6">
        <div class="overflow-x-auto">
            <table class="w-full text-theme-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-theme-xs uppercase tracking-wide text-gray-500 dark:border-gray-800">
                        <th class="px-5 py-3">Owner</th>
                        <th class="px-5 py-3 text-right">Leads</th>
                        <th class="px-5 py-3 text-right">Quotes sent</th>
                        <th class="px-5 py-3 text-right">Accepted</th>
                        <th class="px-5 py-3 text-right">Accepted value</th>
                        <th class="px-5 py-3 text-right">Win rate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->report()['by_owner'] as $row)
                        <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                            <td class="px-5 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }}</td>
                            <td class="px-5 py-3 text-right">{{ $row['leads'] }}</td>
                            <td class="px-5 py-3 text-right">{{ $row['quotes_sent'] }}</td>
                            <td class="px-5 py-3 text-right">{{ $row['accepted'] }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::ofMinor($row['accepted_value_minor'])->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">{{ $row['win_rate'] }}%</td>
                        </tr>
                    @endforeach
                    @if ($this->report()['by_owner'] === [])
                        <tr><td colspan="6" class="px-5 py-8 text-center text-gray-500">No owned leads in this period.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>
