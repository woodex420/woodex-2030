@use('App\Support\Money')
<div>
    <x-admin.page-header title="Received invoices"
                         description="The bills suppliers send the studio. Posting one books the cost and the payable; paying it clears the payable — the ledger is written by the same buttons you press here."
                         :breadcrumbs="['Accounts' => null, 'Payables' => null, 'Received invoices' => null]">
        @if ($canManage)
            <x-slot:actions>
                <x-admin.button icon="plus" wire:click="openForm">New bill</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Outstanding to suppliers</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($outstanding)->format($lakh) }}</p>
        </div>
        <div class="rounded-xl border px-4 py-3 {{ $overdueTotal > 0 ? 'border-error-200 bg-error-50 dark:border-error-500/30 dark:bg-error-500/10' : 'border-gray-200 dark:border-gray-800' }}">
            <p class="text-theme-xs text-gray-500">Overdue</p>
            <p class="text-theme-sm font-medium tabular-nums {{ $overdueTotal > 0 ? 'text-error-600' : 'text-gray-800 dark:text-white/90' }}">{{ Money::ofMinor($overdueTotal)->format($lakh) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Paid this month</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($paidThisMonth)->format($lakh) }}</p>
        </div>
    </div>

    @if ($showForm)
        <x-admin.card :title="$editingId ? 'Edit draft bill' : 'New received invoice'"
                      description="The vendor's own number is kept. Tax is added on top of the lines and booked to input tax (1210) — leave it at zero when the vendor charged none."
                      class="mb-6">
            <form wire:submit="saveReceived" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                @if ($projects->isNotEmpty())
                    <div>
                        <label class="wx-label" for="bill-project">Project (optional, whole bill)</label>
                        <select id="bill-project" wire:model="projectId" class="wx-input"><option value="">No project</option>@foreach ($projects as $option)@if (! $option->deleted_at)<option value="{{ $option->id }}">{{ $option->code }} · {{ $option->name }}</option>@endif @endforeach</select>
                        @error('projectId')<p role="alert" class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                    </div>
                @endif
                @error('bill')<p role="alert" class="text-theme-sm text-error-600 md:col-span-3">{{ $message }}</p>@enderror
                @error('items')<p role="alert" class="text-theme-sm text-error-600 md:col-span-3">{{ $message }}</p>@enderror
                <div>
                    <label class="wx-label" for="bill-vendor">Vendor</label>
                    <select id="bill-vendor" wire:model="vendorId" class="wx-input">
                        <option value="">Choose a vendor…</option>
                        @foreach ($vendors as $vendor)
                            <option value="{{ $vendor->id }}">{{ $vendor->name }}@unless ($vendor->is_active) (inactive)@endunless</option>
                        @endforeach
                    </select>
                    @error('vendorId') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="bill-number">Vendor's invoice number</label>
                    <input id="bill-number" type="text" wire:model="number" class="wx-input" placeholder="INV-2291" />
                    @error('number') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="bill-tax">Tax on the bill (Rs)</label>
                    <input id="bill-tax" type="number" step="0.01" min="0" wire:model="taxRupees" class="wx-input" />
                    @error('taxRupees') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="bill-date">Bill date</label>
                    <input id="bill-date" type="date" wire:model="billDate" class="wx-input" />
                    @error('billDate') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="bill-due">Due date</label>
                    <input id="bill-due" type="date" wire:model="dueDate" class="wx-input" />
                    @error('dueDate') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="bill-notes">Note (optional)</label>
                    <input id="bill-notes" type="text" wire:model="notes" class="wx-input" placeholder="What it was for" />
                    @error('notes') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>

                <div class="md:col-span-3">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">Lines — each one names the account the cost belongs to</p>
                        <button type="button" wire:click="addLine" class="text-theme-sm font-medium text-brand-600 hover:underline">Add line</button>
                    </div>

                    <div class="space-y-3">
                        @foreach ($lines as $index => $line)
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-12" wire:key="bill-line-{{ $index }}">
                                <div class="md:col-span-4">
                                    <select wire:model="lines.{{ $index }}.accountId" class="wx-input" aria-label="Account">
                                        <option value="">Account…</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                    @error("lines.{$index}.accountId") <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-5">
                                    <input type="text" wire:model="lines.{{ $index }}.description" class="wx-input" placeholder="What was supplied" aria-label="Description" />
                                    @error("lines.{$index}.description") <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-2">
                                    <input type="number" step="0.01" min="0" wire:model="lines.{{ $index }}.amountRupees" class="wx-input" placeholder="Rs" aria-label="Amount" />
                                    @error("lines.{$index}.amountRupees") <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-1 flex items-center">
                                    <button type="button" wire:click="removeLine({{ $index }})" class="text-gray-400 hover:text-error-600" aria-label="Remove line"><x-icon name="x" class="size-4" /></button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @error('lines') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap items-center gap-2 md:col-span-3">
                    <x-admin.button type="submit" icon="save">Save &amp; post to the books</x-admin.button>
                    <x-admin.button type="button" variant="secondary" wire:click="saveDraft">Save as draft</x-admin.button>
                    <button type="button" wire:click="closeForm" class="text-theme-sm text-gray-500 hover:underline">Cancel</button>
                </div>
            </form>
        </x-admin.card>
    @endif

    @if ($payingId)
        @php $payingBill = $bills->firstWhere('id', $payingId); @endphp
        <x-admin.card title="Pay a received invoice" description="Dr Accounts payable / Cr the account the money left. Pay part of it now and the rest later — the bill stays open until the balance is zero." class="mb-6">
            <form wire:submit="pay" class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div>
                    <label class="wx-label" for="pay-amount">Amount (Rs)</label>
                    <input id="pay-amount" type="number" step="0.01" min="0.01" wire:model="payAmount" class="wx-input" />
                    @error('payAmount') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    @error('amount') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="pay-method">Paid by</label>
                    <select id="pay-method" wire:model="payMethod" class="wx-input">
                        @foreach ($methods as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('payMethod') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="pay-ref">Reference</label>
                    <input id="pay-ref" type="text" wire:model="payReference" class="wx-input" placeholder="Cheque / TRX" />
                    @error('payReference') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end gap-2">
                    <x-admin.button type="submit" icon="banknote">Record payment</x-admin.button>
                    <button type="button" wire:click="closePay" class="text-theme-sm text-gray-500 hover:underline">Cancel</button>
                </div>
            </form>
        </x-admin.card>
    @endif

    @if ($voidingId)
        <x-admin.card title="Void this bill" description="The document stays, the reason is recorded and its journal entry is reversed. A bill with money against it cannot be voided." class="mb-6">
            <form wire:submit="voidBill" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <label class="wx-label" for="void-reason">Why is it void?</label>
                    <input id="void-reason" type="text" wire:model="voidReason" class="wx-input" placeholder="Duplicate of INV-2291" />
                    @error('voidReason') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end gap-2">
                    <x-admin.button type="submit" variant="danger" icon="x">Void the bill</x-admin.button>
                    <button type="button" wire:click="closeVoid" class="text-theme-sm text-gray-500 hover:underline">Cancel</button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <div class="mb-4 flex flex-wrap items-end gap-3">
        @if ($projects->isNotEmpty())
            <div><label class="wx-label" for="bill-project-filter">Project</label><select id="bill-project-filter" wire:model.live="projectFilter" class="wx-input"><option value="">All projects / unlinked</option>@foreach ($projects as $option)<option value="{{ $option->id }}">{{ $option->code }} · {{ $option->name }}{{ $option->deleted_at ? ' (archived)' : '' }}</option>@endforeach</select></div>
        @endif

        <div>
            <label class="wx-label" for="bill-filter">Show</label>
            <select id="bill-filter" wire:model.live="statusFilter" class="wx-input">
                <option value="open">Open (unpaid or part-paid)</option>
                <option value="all">All bills</option>
                <option value="draft">Drafts</option>
                <option value="paid">Paid</option>
                <option value="void">Void</option>
            </select>
        </div>
        <div>
            <label class="wx-label" for="bill-vendor-filter">Vendor</label>
            <select id="bill-vendor-filter" wire:model.live="vendorFilter" class="wx-input">
                <option value="">All vendors</option>
                @foreach ($vendors as $vendor)
                    <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3">Vendor's number</th>
                        <th class="px-5 py-3">Vendor</th>
                        <th class="hidden px-5 py-3 sm:table-cell">Bill date</th>
                        <th class="hidden px-5 py-3 sm:table-cell">Due</th>
                        <th class="px-5 py-3 text-right">Total</th>
                        <th class="px-5 py-3 text-right">Balance</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($bills as $bill)
                        <tr wire:key="bill-{{ $bill->id }}" class="align-top">
                            <td class="px-5 py-3 font-medium text-gray-800 dark:text-white/90">
                                {{ $bill->number }}
                                @if ($bill->project_id && auth()->user()->can('projects.view'))<span class="block text-theme-xs text-gray-500">{{ $bill->project?->code ?? 'Archived project' }}</span>@endif
                                @if ($bill->journalEntry)
                                    <span class="block text-theme-xs text-gray-400">{{ $bill->journalEntry->entry_no }}@if ($bill->journalEntry->isReversed()) · reversed @endif</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ $bill->vendor?->name ?? '—' }}</td>
                            <td class="hidden px-5 py-3 text-gray-600 sm:table-cell dark:text-gray-400">{{ $bill->bill_date->format('j M Y') }}</td>
                            <td class="hidden px-5 py-3 sm:table-cell {{ $bill->isOverdue() ? 'text-error-600' : 'text-gray-600 dark:text-gray-400' }}">
                                {{ $bill->due_date?->format('j M Y') ?? '—' }}
                                @if ($bill->isOverdue())
                                    <span class="block text-theme-xs">{{ $bill->daysOverdue() }} days late</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $bill->total_minor)->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums {{ $bill->balanceMinor() > 0 ? 'text-gray-800 dark:text-white/90' : 'text-gray-400' }}">{{ Money::ofMinor($bill->balanceMinor())->format($lakh) }}</td>
                            <td class="px-5 py-3">
                                <x-admin.badge :color="$bill->status->color()">{{ $bill->status->label() }}</x-admin.badge>
                                @if ($bill->status->value === 'void' && $bill->void_reason)
                                    <span class="block text-theme-xs text-gray-400">{{ $bill->void_reason }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right">
                                @if ($canManage)
                                    <div class="flex flex-wrap justify-end gap-2 text-theme-xs">
                                        @if ($bill->status->value === 'draft')
                                            <button type="button" wire:click="post({{ $bill->id }})" class="font-medium text-brand-600 hover:underline">Post</button>
                                            <button type="button" wire:click="openForm({{ $bill->id }})" class="text-gray-500 hover:underline">Edit</button>
                                        @endif
                                        @if ($bill->isOpen())
                                            <button type="button" wire:click="openPay({{ $bill->id }})" class="font-medium text-brand-600 hover:underline">Pay</button>
                                        @endif
                                        @if ($bill->canVoid())
                                            <button type="button" wire:click="openVoid({{ $bill->id }})" class="text-gray-500 hover:text-error-600">Void</button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-10 text-center text-theme-sm text-gray-500">
                                Nothing here yet. Add a vendor, then enter the first bill the studio received — it posts to the ledger as you save it.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>
