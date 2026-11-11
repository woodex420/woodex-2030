<div>
    <x-admin.page-header title="Invoice tracking" description="Every invoice with money on it, grouped by where it really is — the board reads status and due date, it never invents them. Collecting from here is the one-act collection: recorded, verified, receipted and booked in the ledger."
                         :breadcrumbs="['Sales' => null, 'Invoice tracking' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" wire:click="export">Export CSV</x-admin.button>
            @if ($canCreate)<x-admin.button icon="plus" :href="route('admin.invoices.create')" wire:navigate>New invoice</x-admin.button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <select wire:model.live="year" class="wx-input w-auto" aria-label="Issue year">
            <option value="">All years</option>
            @foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
        </select>
        <select wire:model.live="line" class="wx-input w-auto" aria-label="Brand line">
            <option value="">All · Interior + Furniture</option>
            @foreach ($lines as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($groups as $key => $summary)
            <button wire:click="showGroup('{{ $key }}')" wire:key="igroup-{{ $key }}"
                    class="rounded-xl border p-3 text-left transition {{ $key === $group ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30' : 'border-gray-200 hover:border-brand-300 dark:border-gray-800' }}">
                <p class="text-theme-sm font-medium">{{ $summary['label'] }}</p>
                <p class="text-xl font-semibold">{{ $summary['count'] }}</p>
                @if ($summary['count'] > 0 && $key !== 'paid' && $summary['minor'] > 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Support\Money::ofMinor((int) $summary['minor'])->format() }}</p>
                @endif
            </button>
        @endforeach
    </div>

    <x-admin.card :title="$groups[$group]['label']" :description="$groups[$group]['hint']">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm divide-y divide-gray-100 dark:divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Invoice</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Client</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Due</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400 text-right">Outstanding</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400 text-right">Paid</th>
                        <th class="px-3 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($active as $invoice)
                        <tr wire:key="inv-{{ $invoice->id }}">
                            <td class="px-3 py-2.5">
                                <span class="font-medium">{{ $invoice->number }}</span>
                                @if ($invoice->status === App\Domain\Sales\Enums\InvoiceStatus::PartiallyPaid)
                                    <x-admin.badge color="info">Partial</x-admin.badge>
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                {{ $invoice->contact?->name ?? '—' }}
                                <a href="{{ route('admin.contacts.statement', $invoice->contact) }}" class="ml-1 text-xs text-brand-600 hover:underline dark:text-brand-400">statement</a>
                            </td>
                            <td class="px-3 py-2.5">
                                @if ($invoice->due_date !== null && $invoice->due_date->isPast() && $invoice->balance_minor > 0)
                                    <x-admin.badge color="error">{{ $invoice->due_date->diffForHumans() }}</x-admin.badge>
                                @else
                                    {{ $invoice->due_date?->format('d M Y') ?? '—' }}
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right font-medium">{{ \App\Support\Money::ofMinor((int) $invoice->balance_minor)->format() }}</td>
                            <td class="px-3 py-2.5 text-right text-gray-500 dark:text-gray-400">{{ \App\Support\Money::ofMinor((int) $invoice->paid_minor)->format() }}</td>
                            <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                @if ($invoice->balance_minor > 0)
                                    @if ($canCollect)
                                        <x-admin.button size="sm" wire:click="startCollect({{ $invoice->id }})" wire:key="collect-{{ $invoice->id }}">Collect</x-admin.button>
                                    @endif
                                    @if ($canRemind)
                                        <x-admin.button size="sm" variant="secondary" wire:click="remind({{ $invoice->id }})">Remind</x-admin.button>
                                    @endif
                                @endif
                                <a href="{{ route('admin.invoices.pdf', $invoice) }}" target="_blank" rel="noopener" class="ml-1 text-xs text-brand-600 hover:underline dark:text-brand-400">PDF</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">Nothing in this group.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>

    @if ($collectId !== 0)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:key="collect-modal">
            <x-admin.card class="w-full max-w-lg" title="Collect a payment">
                <form wire:submit="collect" class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="wx-label">Amount (rupees)</label>
                        <input wire:model="collectAmount" class="wx-input" inputmode="decimal" />
                        @error('collectAmount')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="wx-label">Method</label>
                        <select wire:model="collectMethod" class="wx-input">
                            @foreach ($methods as $method)
                                <option value="{{ $method->value }}">{{ $method->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="wx-label">Reference (optional)</label>
                        <input wire:model="collectReference" class="wx-input" maxlength="120" placeholder="e.g. bank transfer slip #, JazzCash SMS" />
                    </div>
                    <p class="sm:col-span-2 text-theme-sm text-gray-500 dark:text-gray-400">One act: the payment is recorded, verified, allocated, receipted and posted to the ledger — the same rules as Telegram /collect.</p>
                    <div class="sm:col-span-2 flex justify-end gap-2">
                        <x-admin.button variant="secondary" type="button" wire:click="$set('collectId', 0)">Cancel</x-admin.button>
                        <x-admin.button type="submit">Collect &amp; receipt</x-admin.button>
                    </div>
                </form>
            </x-admin.card>
        </div>
    @endif
</div>
