@use('App\Support\Money')
<div>
    <x-admin.page-header title="Invoices" description="Every invoice — from quotations, projects and written by hand — with what is still owed. The advance one appears the moment a client accepts."
                         :breadcrumbs="['Sales' => null, 'Invoices' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" wire:click="export">Export CSV</x-admin.button>
            @if ($canCreate)<x-admin.button icon="plus" :href="route('admin.invoices.create')" wire:navigate>New invoice</x-admin.button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Status chips --}}
    <div class="mb-4 flex gap-2 overflow-x-auto pb-1 no-scrollbar" role="tablist" aria-label="Filter by status">
        @php
            $chip = fn (bool $active) => 'inline-flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-theme-sm font-medium transition '
                .($active ? 'border-navy-900 bg-navy-900 text-white dark:border-white dark:bg-white dark:text-navy-900' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300');
        @endphp
        <button type="button" wire:click="$set('status', '')" class="{{ $chip($status === '') }}" role="tab" aria-selected="{{ $status === '' ? 'true' : 'false' }}">
            All <span class="tabular-nums opacity-70">{{ $total }}</span>
        </button>
        @foreach ($chips as $chipStatus)
            <button type="button" wire:click="$set('status', '{{ $chipStatus->value }}')" class="{{ $chip($status === $chipStatus->value) }}" role="tab" aria-selected="{{ $status === $chipStatus->value ? 'true' : 'false' }}">
                {{ $chipStatus->label() }} <span class="tabular-nums opacity-70">{{ (int) ($counts[$chipStatus->value] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-3">
        <div class="relative md:col-span-2">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Invoice number, client or quotation" class="wx-input pl-10" aria-label="Search invoices">
        </div>
        @if ($overdue > 0)
            <div class="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3.5 py-2 text-theme-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                <x-icon name="clock" class="size-4" />
                <span>Overdue: <strong>{{ Money::ofMinor($overdue)->format($lakh) }}</strong></span>
            </div>
        @endif
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Invoice</th>
                        <th class="hidden px-5 py-3 font-medium md:table-cell">Client</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Amount</th>
                        <th class="px-5 py-3 text-right font-medium">Balance</th>
                        <th class="hidden px-5 py-3 font-medium sm:table-cell">Due</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($invoices as $invoice)
                        @php $late = $invoice->due_date !== null && $invoice->due_date->lt(today()) && ! in_array($invoice->status, [\App\Domain\Sales\Enums\InvoiceStatus::Paid, \App\Domain\Sales\Enums\InvoiceStatus::Void], true); @endphp
                        <tr wire:key="invoice-{{ $invoice->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.invoices.show', $invoice) }}" wire:navigate class="block font-medium text-gray-800 hover:text-brand-600 hover:underline dark:text-white/90 dark:hover:text-brand-400">{{ $invoice->number }}</a>
                                <span class="block max-w-xs truncate text-theme-xs text-gray-500">
                                    {{ $invoice->project?->code ?? '—' }}{{ $invoice->quote ? ' · quotation '.$invoice->quote->number : '' }}
                                </span>
                                @if ($invoice->pdf_path)
                                    <a href="{{ route('admin.invoices.pdf', $invoice) }}" class="text-theme-xs text-brand-600 hover:underline dark:text-brand-400" x-on:click.prevent="$dispatch('document-preview', { url: $el.href })">PDF</a>
                                @endif
                            </td>
                            <td class="hidden px-5 py-3 md:table-cell">
                                <span class="block text-gray-700 dark:text-gray-300">{{ $invoice->contact?->name }}</span>
                                <span class="block text-theme-xs text-gray-500">{{ $invoice->project?->name ?? $invoice->quote?->title }}</span>
                            </td>
                            <td class="px-5 py-3"><x-admin.badge :color="$invoice->status->color()">{{ $invoice->status->label() }}</x-admin.badge></td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $invoice->total_minor)->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right font-medium tabular-nums {{ (int) $invoice->balance_minor > 0 ? 'text-gray-800 dark:text-white/90' : 'text-gray-400' }}">
                                {{ Money::ofMinor((int) $invoice->balance_minor)->format($lakh) }}
                            </td>
                            <td class="hidden px-5 py-3 sm:table-cell {{ $late ? 'text-red-600 dark:text-red-400' : 'text-gray-500' }}">
                                {{ $invoice->due_date?->format('j M Y') ?? '—' }}@if ($late) · overdue @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-admin.empty icon="invoice" :title="$search !== '' || $status !== '' ? 'No invoices match' : 'No invoices yet'"
                                               description="The advance invoice appears here as soon as a client accepts a quotation." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $invoices->links('components.admin.pagination') }}
    </x-admin.card>
</div>
