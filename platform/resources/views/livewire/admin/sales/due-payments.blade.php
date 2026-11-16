<div>
    <x-admin.page-header title="Due payments" description="What the studio is owed, oldest first. Reminders go one invoice at a time and only where the client consented to WhatsApp — a client without consent shows a reason instead of a send."
                         :breadcrumbs="['Sales' => null, 'Due payments' => null]">
    </x-admin.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
        <x-admin.card class="lg:col-span-2" title="Total outstanding">
            <p class="text-2xl font-semibold">{{ $this->money($totalMinor) }}</p>
            <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $invoices->count() }} open invoices (showing up to 40)</p>
        </x-admin.card>
        @foreach ($buckets as $bucket)
            <x-admin.card :title="$bucket['label']">
                <p class="text-xl font-semibold {{ str_contains($bucket['label'], 'Current') ? '' : 'text-red-600 dark:text-red-400' }}">{{ $bucket['minor'] > 0 ? $this->money($bucket['minor']) : '—' }}</p>
            </x-admin.card>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-admin.card title="Open invoices, oldest first">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-theme-sm divide-y divide-gray-100 dark:divide-gray-800">
                        <thead>
                            <tr>
                                <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Invoice</th>
                                <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Client</th>
                                <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Due</th>
                                <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400 text-right">Outstanding</th>
                                <th class="px-3 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($invoices as $invoice)
                                <tr>
                                    <td class="px-3 py-2.5 font-medium">{{ $invoice->number }}</td>
                                    <td class="px-3 py-2.5">{{ $invoice->contact?->name ?? '—' }}</td>
                                    <td class="px-3 py-2.5">
                                        @if ($invoice->due_date !== null && $invoice->due_date->isPast())
                                            <x-admin.badge color="error">{{ $invoice->due_date->diffForHumans() }}</x-admin.badge>
                                        @else
                                            {{ $invoice->due_date?->format('d M Y') ?? '—' }}
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-medium">{{ $this->money((int) $invoice->balance_minor) }}</td>
                                    <td class="px-3 py-2.5 text-right">
                                        <x-admin.button size="sm" variant="secondary" icon="bell" wire:click="remind({{ $invoice->id }})" wire:key="remind-{{ $invoice->id }}">Remind</x-admin.button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">Nothing outstanding — every invoice is settled.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-admin.card>
        </div>

        <x-admin.card title="Top clients by exposure">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($byClient as $client)
                    <li class="flex items-center justify-between py-2.5">
                        <div>
                            <p class="font-medium">{{ $client['name'] }}</p>
                            <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $client['invoices'] }} {{ Str::plural('invoice', $client['invoices']) }}</p>
                        </div>
                        <p class="font-semibold">{{ $this->money((int) $client['minor']) }}</p>
                    </li>
                @empty
                    <li class="py-8 text-center text-gray-500 dark:text-gray-400">No open invoices.</li>
                @endforelse
            </ul>
        </x-admin.card>
    </div>
</div>
