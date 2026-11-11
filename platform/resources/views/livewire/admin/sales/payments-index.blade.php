@use('App\Support\Money')
<div>
    <x-admin.page-header title="Payments" description="Money received, with the proof the client sent and the receipt we issued. A proof waits here until accounts confirm it."
                         :breadcrumbs="['Sales' => null, 'Payments' => null]">
        @can('create', App\Domain\Sales\Models\Payment::class)
            <x-slot:actions>
                <x-admin.button icon="plus" wire:click="$toggle('showForm')">{{ $showForm ? 'Close' : 'Record payment' }}</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    @if ($showForm)
        <x-admin.card title="Record a payment" description="Cash, a transfer you saw in the bank, a cheque. It is verified at once and the receipt is written.">
            <form wire:submit="save" class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <div class="relative md:col-span-2">
                    <label for="contact-search" class="mb-1 block text-theme-xs text-gray-500">Client</label>
                    <input id="contact-search" type="search" wire:model.live.debounce.300ms="contactSearch" autocomplete="off"
                           placeholder="Search a client" class="wx-input" @if ($contactId) disabled @endif>
                    @if ($contacts->isNotEmpty())
                        <ul class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900">
                            @foreach ($contacts as $contact)
                                <li><button type="button" wire:click="pickContact({{ $contact->id }})" class="block w-full px-3 py-2 text-left text-theme-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                    {{ $contact->name }} <span class="text-theme-xs text-gray-500">{{ $contact->phone }}</span>
                                </button></li>
                            @endforeach
                        </ul>
                    @endif
                    @error('contactId')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="amount" class="mb-1 block text-theme-xs text-gray-500">Amount (PKR)</label>
                    <input id="amount" wire:model="amount" type="number" step="0.01" min="1" inputmode="decimal" class="wx-input">
                    @error('amount')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="received-on" class="mb-1 block text-theme-xs text-gray-500">Received on</label>
                    <input id="received-on" wire:model="receivedOn" type="date" class="wx-input">
                    @error('receivedOn')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="method" class="mb-1 block text-theme-xs text-gray-500">Method</label>
                    <select id="method" wire:model="method" class="wx-input">
                        @foreach ($methods as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="account" class="mb-1 block text-theme-xs text-gray-500">Received in</label>
                    <select id="account" wire:model="bankAccountId" class="wx-input">
                        <option value="">—</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->title }} · {{ $account->bank_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="invoice" class="mb-1 block text-theme-xs text-gray-500">Against invoice</label>
                    <select id="invoice" wire:model="invoiceId" class="wx-input">
                        <option value="">Oldest open invoice first</option>
                        @foreach ($openInvoices as $invoice)
                            <option value="{{ $invoice->id }}">{{ $invoice->number }} · {{ Money::ofMinor((int) $invoice->balance_minor)->format($lakh) }} due</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label for="reference" class="mb-1 block text-theme-xs text-gray-500">Reference</label>
                    <input id="reference" wire:model="reference" maxlength="120" class="wx-input">
                </div>

                <div class="md:col-span-4">
                    <label for="notes" class="mb-1 block text-theme-xs text-gray-500">Notes (internal)</label>
                    <input id="notes" wire:model="notes" maxlength="1000" class="wx-input">
                </div>

                <div class="md:col-span-4 flex items-center gap-3">
                    <x-admin.button type="submit">Record and issue receipt</x-admin.button>
                    @if ($contactId)
                        <button type="button" wire:click="$set('contactId', null)" class="text-theme-sm text-gray-500 hover:underline">Change client</button>
                    @endif
                </div>
            </form>
        </x-admin.card>
    @endif

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
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Client, invoice, reference or receipt" class="wx-input pl-10" aria-label="Search payments">
        </div>
        @if ($pending > 0)
            <div class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2 text-theme-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                <x-icon name="clock" class="size-4" />
                <span>{{ $pending }} waiting for accounts</span>
            </div>
        @endif
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Payment</th>
                        <th class="hidden px-5 py-3 font-medium md:table-cell">Client</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Amount</th>
                        <th class="hidden px-5 py-3 font-medium lg:table-cell">Against</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($payments as $payment)
                        <tr wire:key="payment-{{ $payment->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <span class="block font-medium text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $payment->amount_minor)->format($lakh) }}</span>
                                <span class="block text-theme-xs text-gray-500">{{ $payment->method->label() }} · {{ $payment->received_on?->format('j M Y') }}</span>
                                <span class="block text-theme-xs text-gray-500">{{ $payment->reference ? 'Ref '.$payment->reference : '—' }}</span>
                            </td>
                            <td class="hidden px-5 py-3 md:table-cell">
                                <span class="block text-gray-700 dark:text-gray-300">{{ $payment->contact?->name }}</span>
                                <span class="block text-theme-xs text-gray-500">{{ $payment->receipt_number ?? 'No receipt yet' }}</span>
                            </td>
                            <td class="px-5 py-3"><x-admin.badge :color="$payment->status->color()">{{ $payment->status->label() }}</x-admin.badge></td>
                            <td class="px-5 py-3 text-right font-medium tabular-nums text-gray-800 dark:text-white/90">
                                {{ Money::ofMinor((int) $payment->amount_minor)->format($lakh) }}
                            </td>
                            <td class="hidden px-5 py-3 text-theme-xs text-gray-500 lg:table-cell">
                                @forelse ($payment->allocations as $allocation)
                                    <div>{{ $allocation->invoice?->number }} · {{ Money::ofMinor((int) $allocation->amount_minor)->format($lakh) }}</div>
                                @empty
                                    {{ $payment->invoice?->number ?? 'Unallocated (credit)' }}
                                @endforelse
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-3 text-theme-xs">
                                    @if ($payment->getMedia('files')->isNotEmpty())
                                        <a href="{{ route('admin.media.show', $payment->getMedia('files')->first()) }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline dark:text-brand-400">Proof</a>
                                    @endif
                                    @if ($payment->receipt_pdf_path)
                                        <button type="button" wire:click="receipt({{ $payment->id }})" class="text-brand-600 hover:underline dark:text-brand-400">Receipt</button>
                                    @endif
                                    @can('verify', $payment)
                                        @if ($payment->status === App\Domain\Sales\Enums\PaymentStatus::PendingVerification)
                                            <button type="button" wire:click="verify({{ $payment->id }})" class="font-medium text-green-700 hover:underline dark:text-green-400">Confirm</button>
                                            <button type="button" wire:click="openReject({{ $payment->id }})" class="font-medium text-error-600 hover:underline">Send back</button>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-admin.empty icon="wallet" :title="$search !== '' || $status !== '' ? 'No payments match' : 'No payments yet'"
                                               description="Payments appear here when a client reports one from the invoice page, or when you record one." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $payments->links('components.admin.pagination') }}
    </x-admin.card>

    {{-- Sending a payment back needs a reason the client will read --}}
    @if ($showReject)
        <x-admin.modal name="showReject" title="Send this payment back" description="The client receives an email with this reason: what did not match?">
            <label for="reject-reason" class="mb-1 block text-theme-xs text-gray-500">Reason</label>
            <textarea id="reject-reason" wire:model="rejectReason" rows="4" maxlength="500" class="wx-input"
                      placeholder="We cannot see this transfer in our account yet. Please check the reference and send the slip again."></textarea>
            @error('rejectReason')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror

            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showReject', false)">Cancel</x-admin.button>
                <x-admin.button variant="danger" wire:click="reject">Send back to client</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
