<div>
    <x-admin.page-header title="Bank accounts"
                         description="Where clients pay. The account chosen for a business line is printed on its quotations and invoices, and shown on the client's invoice page — nothing here is ever seeded, so it stays empty until you type the real numbers.">
        @if ($canManage)
            <x-slot:actions>
                <x-admin.button icon="plus" wire:click="openForm">Add bank account</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-500">
            {{ session('status') }}
        </div>
    @endif

    @if ($showForm)
        <x-admin.card :title="$editingId ? 'Edit bank account' : 'Add a bank account'"
                      description="Only the title and the bank or wallet name are required — add the account number or IBAN so the client can actually pay." class="mb-6">
            <form wire:submit="save" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="wx-label" for="ba-title">Account title</label>
                    <input id="ba-title" type="text" wire:model="title" class="wx-input" placeholder="WOODEX INTERIOR" />
                    @error('title') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="ba-bank">Bank / wallet name</label>
                    <input id="ba-bank" type="text" wire:model="bankName" class="wx-input" placeholder="Meezan Bank" />
                    @error('bankName') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="ba-kind">Type</label>
                    <select id="ba-kind" wire:model="kind" class="wx-input">
                        <option value="bank">Bank account</option>
                        <option value="wallet">Mobile wallet</option>
                    </select>
                    @error('kind') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="ba-line">Business line</label>
                    <select id="ba-line" wire:model="businessLine" class="wx-input">
                        <option value="">Both lines</option>
                        <option value="interior">Interior</option>
                        <option value="furniture">Furniture</option>
                    </select>
                    @error('businessLine') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="ba-number">Account number</label>
                    <input id="ba-number" type="text" wire:model="accountNumber" class="wx-input" inputmode="numeric" placeholder="01234567890123" />
                    @error('accountNumber') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="ba-iban">IBAN</label>
                    <input id="ba-iban" type="text" wire:model="iban" class="wx-input" placeholder="PK00 MEZN 0000 0000 0000 0000" />
                    @error('iban') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="ba-branch">Branch</label>
                    <input id="ba-branch" type="text" wire:model="branch" class="wx-input" placeholder="DHA Phase 5, Lahore" />
                    @error('branch') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="ba-branch-code">Branch code</label>
                    <input id="ba-branch-code" type="text" wire:model="branchCode" class="wx-input" placeholder="0123" />
                    @error('branchCode') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="ba-wallet">Wallet provider <span class="text-gray-400">(for mobile wallets)</span></label>
                    <input id="ba-wallet" type="text" wire:model="walletProvider" class="wx-input" placeholder="JazzCash" />
                    @error('walletProvider') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col justify-end gap-2">
                    <label class="inline-flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="isDefault" class="size-4 rounded border-gray-300" />
                        Default for its business line
                    </label>
                    <label class="inline-flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="isActive" class="size-4 rounded border-gray-300" />
                        Active — offered on new invoices
                    </label>
                </div>

                <div class="flex gap-2 md:col-span-2">
                    <x-admin.button type="submit" icon="check">Save account</x-admin.button>
                    <x-admin.button variant="ghost" wire:click="closeForm">Cancel</x-admin.button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Account</th>
                        <th class="hidden px-5 py-3 font-medium md:table-cell">Bank</th>
                        <th class="hidden px-5 py-3 font-medium lg:table-cell">Number / IBAN</th>
                        <th class="px-5 py-3 font-medium">Line</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        @if ($canManage)
                            <th class="px-5 py-3 text-right font-medium">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($accounts as $account)
                        <tr wire:key="bank-{{ $account->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <span class="block font-medium text-gray-800 dark:text-white/90">{{ $account->title }}</span>
                                <span class="block text-theme-xs text-gray-500">{{ $account->kind === 'wallet' ? 'Mobile wallet' : 'Bank account' }}{{ $account->branch ? ' · '.$account->branch : '' }}</span>
                            </td>
                            <td class="hidden px-5 py-3 md:table-cell">{{ $account->bank_name }}</td>
                            <td class="hidden px-5 py-3 lg:table-cell">
                                {{ $account->iban ?? $account->account_number ?? '—' }}
                                @if ($account->wallet_provider)
                                    <span class="block text-theme-xs text-gray-500">{{ $account->wallet_provider }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                {{ $account->business_line?->label() ?? 'Both' }}
                                @if ($account->is_default)
                                    <x-admin.badge color="success" class="ml-1">Default</x-admin.badge>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <x-admin.badge :color="$account->is_active ? 'success' : 'gray'">{{ $account->is_active ? 'Active' : 'Inactive' }}</x-admin.badge>
                            </td>
                            @if ($canManage)
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="openForm({{ $account->id }})" class="text-theme-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Edit</button>
                                    @unless ($account->is_default)
                                        <button type="button" wire:click="makeDefault({{ $account->id }})" class="ml-3 text-theme-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Make default</button>
                                    @endunless
                                    <button type="button" wire:click="toggle({{ $account->id }})" class="ml-3 text-theme-xs font-medium text-gray-500 hover:underline">
                                        {{ $account->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 6 : 5 }}">
                                <x-admin.empty icon="wallet" title="No bank account yet"
                                               description="Quotations and invoices print no payment details until you add the account clients should pay into." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>
