@use('App\Support\Money')
<div>
    <x-admin.page-header :title="$heading" :description="$blurb"
                         :breadcrumbs="['Accounts' => null, 'Income & expenses' => null, $heading => null]">
        @if ($canManage)
            <x-slot:actions>
                <x-admin.button icon="plus" wire:click="openForm">New {{ $direction->label() === 'Expense' ? 'expense' : 'income entry' }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    @if ($showForm)
        <x-admin.card :title="$direction->label() === 'Expense' ? 'Record an expense' : 'Record other income'"
                      description="Saved and posted in one step — there is no unposted state here, because a cost the books never saw is exactly what this screen exists to prevent."
                      class="mb-6">
            <form wire:submit="save" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <label class="wx-label" for="exp-description">What it is</label>
                    <input id="exp-description" type="text" wire:model="description" class="wx-input" placeholder="Studio electricity — October" />
                    @error('description') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="exp-amount">Amount (Rs)</label>
                    <input id="exp-amount" type="number" step="0.01" min="0.01" wire:model="amountRupees" class="wx-input" />
                    @error('amountRupees') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="exp-account">{{ $direction->label() === 'Expense' ? 'Cost account' : 'Income account' }}</label>
                    <select id="exp-account" wire:model="accountId" class="wx-input">
                        <option value="">Choose an account…</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('accountId') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="exp-date">Date</label>
                    <input id="exp-date" type="date" wire:model="spentOn" class="wx-input" />
                    @error('spentOn') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="exp-paid-from">Money moved through</label>
                    <select id="exp-paid-from" wire:model="paidFrom" class="wx-input">
                        @foreach ($paidFromOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('paidFrom') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="exp-vendor">Vendor (optional)</label>
                    <select id="exp-vendor" wire:model="vendorId" class="wx-input">
                        <option value="">No vendor</option>
                        @foreach ($vendors as $vendor)
                            <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                        @endforeach
                    </select>
                    @error('vendorId') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="exp-reference">Reference (optional)</label>
                    <input id="exp-reference" type="text" wire:model="reference" class="wx-input" placeholder="Bill / cheque no." />
                    @error('reference') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-3">
                    <label class="wx-label" for="exp-notes">Note (optional)</label>
                    <input id="exp-notes" type="text" wire:model="notes" class="wx-input" placeholder="Anything the accountant will ask about later" />
                    @error('notes') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-center gap-2 md:col-span-3">
                    <x-admin.button type="submit" icon="save">Save &amp; post</x-admin.button>
                    <button type="button" wire:click="closeForm" class="text-theme-sm text-gray-500 hover:underline">Cancel</button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="wx-label" for="exp-from">From</label>
            <input id="exp-from" type="date" wire:model.live="filterFrom" class="wx-input" />
        </div>
        <div>
            <label class="wx-label" for="exp-to">To</label>
            <input id="exp-to" type="date" wire:model.live="filterTo" class="wx-input" />
        </div>
        <div class="ml-auto text-right">
            <p class="text-theme-xs text-gray-500">Total in this range — posted</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($total)->format($lakh) }}</p>
        </div>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3">Date</th>
                        <th class="px-5 py-3">What it was</th>
                        <th class="hidden px-5 py-3 md:table-cell">Account</th>
                        <th class="hidden px-5 py-3 sm:table-cell">Paid through</th>
                        <th class="hidden px-5 py-3 lg:table-cell">Journal</th>
                        <th class="px-5 py-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr wire:key="expense-{{ $row->id }}">
                            <td class="px-5 py-3 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $row->spent_on->format('j M Y') }}</td>
                            <td class="px-5 py-3">
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $row->description }}</p>
                                <p class="text-theme-xs text-gray-500">
                                    {{ $row->vendor?->name ?? 'No vendor' }}@if ($row->reference) · {{ $row->reference }}@endif
                                </p>
                            </td>
                            <td class="hidden px-5 py-3 text-gray-600 md:table-cell dark:text-gray-400">{{ $row->account?->code }} · {{ $row->account?->name }}</td>
                            <td class="hidden px-5 py-3 text-gray-600 sm:table-cell dark:text-gray-400">{{ $row->paid_from->label() }}</td>
                            <td class="hidden px-5 py-3 text-theme-xs text-gray-400 lg:table-cell">
                                {{ $row->journalEntry?->entry_no ?? '—' }}
                                @if ($row->recurring) <span class="block">from a schedule</span> @endif
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $row->amount_minor)->format($lakh) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500">
                                Nothing in this range yet. {{ $direction->label() === 'Expense' ? 'Record the first expense' : 'Record the first income entry' }} above — it posts to the ledger as you save it.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>

    @if ($byAccount->isNotEmpty())
        <x-admin.card title="Where it went" description="The same rows, summed by account — the figure each one posts into the profit & loss." class="mt-6">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($byAccount as $entry)
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <span class="text-theme-sm text-gray-600 dark:text-gray-400">{{ $entry['label'] }}</span>
                        <span class="text-theme-sm tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $entry['total'])->format($lakh) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-admin.card>
    @endif
</div>
