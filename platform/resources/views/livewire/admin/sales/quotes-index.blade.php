@use('App\Support\Money')
<div>
    <x-admin.page-header title="Quotations" description="Every quotation with its status, value and age. Start from a template to have a priced BOQ in minutes."
                         :breadcrumbs="['Sales' => null, 'Quotations' => null]">
        <x-slot:actions>
            @can('create', \App\Domain\Sales\Models\Quote::class)
                <x-admin.button icon="plus" :href="route('admin.quotes.create')" wire:navigate>New quotation</x-admin.button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Status chips (QUO-016) --}}
    <div class="mb-4 flex gap-2 overflow-x-auto pb-1 no-scrollbar" role="tablist" aria-label="Filter by status">
        @php
            $chip = fn (bool $active) => 'inline-flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-theme-sm font-medium transition '
                .($active ? 'border-navy-900 bg-navy-900 text-white dark:border-white dark:bg-white dark:text-navy-900' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300');
        @endphp
        <button type="button" wire:click="$set('status', '')" class="{{ $chip($status === '') }}" role="tab" aria-selected="{{ $status === '' ? 'true' : 'false' }}">
            All <span class="tabular-nums opacity-70">{{ $total }}</span>
        </button>
        <button type="button" wire:click="$set('status', 'follow_up')" class="{{ $chip($status === 'follow_up') }}" role="tab" aria-selected="{{ $status === 'follow_up' ? 'true' : 'false' }}">
            Needs follow-up <span class="tabular-nums opacity-70">{{ $followUp }}</span>
        </button>
        @foreach ($chips as $chipStatus)
            <button type="button" wire:click="$set('status', '{{ $chipStatus->value }}')" class="{{ $chip($status === $chipStatus->value) }}" role="tab" aria-selected="{{ $status === $chipStatus->value ? 'true' : 'false' }}">
                {{ $chipStatus->label() }} <span class="tabular-nums opacity-70">{{ (int) ($counts[$chipStatus->value] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <x-admin.filtered :chips="$filtered" />

    {{-- On a phone each quotation is a card (cards): its number, title and client, then its status, its value and
         when it was last touched: the cells that carry a data-label. The owner is left off a card on purpose, as
         it is left off the table below 1024 px. --}}
    <x-admin.table label="Quotations" :rows="$quotes" cards>
        <x-slot:filters class="grid grid-cols-1 gap-3 md:grid-cols-5">
            <div class="relative md:col-span-2">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Number, title, client or phone" class="wx-input pl-10" aria-label="Search quotations">
            </div>
            @if (count($lines) > 1)
                <select wire:model.live="line" class="wx-input" aria-label="Line">
                    <option value="">All lines</option>
                    @foreach ($lines as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            @endif
            @if ($seesAll)
                <select wire:model.live="owner" class="wx-input" aria-label="Owner">
                    <option value="">Everyone</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="sort" class="wx-input {{ $seesAll ? '' : 'md:col-span-2' }}" aria-label="Sort">
                <option value="updated">Sort: recently updated</option>
                <option value="newest">Sort: newest first</option>
                <option value="value">Sort: highest value</option>
                <option value="number">Sort: number</option>
            </select>
        </x-slot:filters>

        {{-- The headings sort by the list's own words (QuotesIndex::$sort); "newest first" has no column and stays in the choice above. --}}
        <x-slot:head>
            <x-admin.table.heading :sort="['number' => 'descending']" :current="$sort">Quotation</x-admin.table.heading>
            <x-admin.table.heading class="hidden md:table-cell">Client</x-admin.table.heading>
            <x-admin.table.heading>Status</x-admin.table.heading>
            <x-admin.table.heading :sort="['value' => 'descending']" :current="$sort" align="right">Value</x-admin.table.heading>
            <x-admin.table.heading class="hidden lg:table-cell">Owner</x-admin.table.heading>
            <x-admin.table.heading :sort="['updated' => 'descending']" :current="$sort" class="hidden sm:table-cell">Updated</x-admin.table.heading>
        </x-slot:head>

        @foreach ($quotes as $quote)
            <tr wire:key="quote-{{ $quote->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                <th scope="row" class="px-5 py-3 font-normal">
                    <a href="{{ route('admin.quotes.show', $quote) }}" wire:navigate class="block">
                        <span class="block font-medium text-gray-800 hover:underline dark:text-white/90">{{ $quote->label() }}</span>
                        <span class="block max-w-xs truncate text-theme-xs text-gray-500">{{ $quote->title }}</span>
                        <span class="block text-theme-xs text-gray-500 md:hidden">{{ $quote->contact?->name }}</span>
                    </a>
                </th>
                <td class="px-5 py-3 hidden md:table-cell">
                    <span class="block text-gray-700 dark:text-gray-300">{{ $quote->contact?->name }}</span>
                    <span class="block text-theme-xs text-gray-500">{{ $quote->company?->name ?? ($quote->lead ? 'Enquiry '.$quote->lead->ref : '') }}</span>
                </td>
                <td data-label="Status" class="px-5 py-3"><x-admin.badge :color="$quote->status->color()">{{ $quote->status->label() }}</x-admin.badge></td>
                <td data-label="Value" class="px-5 py-3 text-right tabular-nums font-medium text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $quote->total_minor)->format($lakh) }}</td>
                <td class="px-5 py-3 hidden text-gray-600 lg:table-cell dark:text-gray-400">{{ $quote->owner?->name ?? '—' }}</td>
                <td data-label="Updated" class="px-5 py-3 hidden text-gray-500 sm:table-cell" title="{{ $quote->updated_at?->format('j M Y, g:i a') }}">{{ $quote->updated_at?->diffForHumans() }}</td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-admin.empty icon="quote" :title="$search !== '' || $status !== '' || $line !== '' || $owner !== '' ? 'No quotations match' : 'No quotations yet'"
                           description="Start one from a template, from a blank page or from an enquiry's page." />
        </x-slot:empty>
    </x-admin.table>
</div>
