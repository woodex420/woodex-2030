<div>
    <x-admin.page-header title="Vendors"
                         description="Everyone the studio buys from — suppliers, carpenters, subcontractors, the landlord. Type the details once here and every bill and expense picks them up."
                         :breadcrumbs="['Accounts' => null, 'Payables' => null, 'Vendors' => null]">
        @if ($canManage)
            <x-slot:actions>
                <x-admin.button icon="plus" wire:click="openForm">Add vendor</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    @if ($showForm)
        <x-admin.card :title="$editingId ? 'Edit vendor' : 'Add a vendor'"
                      description="Only the name is required. Payment terms decide the due date a new bill starts from; the default expense account pre-fills the first line."
                      class="mb-6">
            <form wire:submit="save" x-on:keydown.enter="if ($event.target.tagName === 'INPUT' &amp;&amp; !['checkbox','radio','file'].includes($event.target.type)) { $event.preventDefault(); $el.querySelector('[data-explicit-save]')?.click(); }" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label class="wx-label" for="vendor-name">Name</label>
                    <input id="vendor-name" type="text" wire:model="name" class="wx-input" placeholder="Chughtai Timber" />
                    @error('name') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="vendor-phone">Phone</label>
                    <input id="vendor-phone" type="text" wire:model="phone" class="wx-input" placeholder="0300 0000000" />
                    @error('phone') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="vendor-email">Email</label>
                    <input id="vendor-email" type="email" wire:model="email" class="wx-input" placeholder="accounts@vendor.pk" />
                    @error('email') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="vendor-city">City</label>
                    <input id="vendor-city" type="text" wire:model="city" class="wx-input" placeholder="Lahore" />
                    @error('city') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="wx-label" for="vendor-address">Address</label>
                    <input id="vendor-address" type="text" wire:model="address" class="wx-input" placeholder="Shop 4, Timber Market" />
                    @error('address') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="vendor-tax">NTN / STRN</label>
                    <input id="vendor-tax" type="text" wire:model="taxNumber" class="wx-input" placeholder="As printed on the bill" />
                    @error('taxNumber') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="vendor-terms">Payment terms (days)</label>
                    <input id="vendor-terms" type="number" min="0" max="365" wire:model="termsDays" class="wx-input" />
                    @error('termsDays') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="vendor-account">Default expense account</label>
                    <select id="vendor-account" wire:model="expenseAccountId" class="wx-input">
                        <option value="">No default</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('expenseAccountId') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-3">
                    <label class="wx-label" for="vendor-notes">Notes</label>
                    <input id="vendor-notes" type="text" wire:model="notes" class="wx-input" placeholder="Who to call, how they invoice, anything worth remembering" />
                    @error('notes') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-center gap-4 md:col-span-3">
                    <label class="inline-flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-400">
                        <input type="checkbox" wire:model="isActive" class="size-4 rounded border-gray-300" />
                        Active
                    </label>
                    <x-admin.button type="button" data-explicit-save wire:click="save" wire:loading.attr="disabled" wire:target="save" icon="save">Save vendor</x-admin.button>
                    <button type="button" wire:click="closeForm" class="text-theme-sm text-gray-500 hover:underline">Cancel</button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3">Vendor</th>
                        <th class="hidden px-5 py-3 sm:table-cell">Contact</th>
                        <th class="hidden px-5 py-3 md:table-cell">Terms</th>
                        <th class="px-5 py-3 text-right">Bills</th>
                        <th class="px-5 py-3 text-right">Expenses</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($vendors as $vendor)
                        <tr wire:key="vendor-{{ $vendor->id }}">
                            <td class="px-5 py-3">
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $vendor->name }}</p>
                                <p class="text-theme-xs text-gray-500">{{ $vendor->city ?: '—' }}@if ($vendor->tax_number) · NTN {{ $vendor->tax_number }}@endif</p>
                            </td>
                            <td class="hidden px-5 py-3 text-gray-600 sm:table-cell dark:text-gray-400">
                                {{ $vendor->phone ?: '—' }}
                                @if ($vendor->email)<span class="block text-theme-xs text-gray-400">{{ $vendor->email }}</span>@endif
                            </td>
                            <td class="hidden px-5 py-3 text-gray-600 md:table-cell dark:text-gray-400">{{ (int) $vendor->payment_terms_days }} days</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ $vendor->bills_count }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ $vendor->expenses_count }}</td>
                            <td class="px-5 py-3">
                                <x-admin.badge :color="$vendor->is_active ? 'success' : 'gray'">{{ $vendor->is_active ? 'Active' : 'Inactive' }}</x-admin.badge>
                            </td>
                            <td class="px-5 py-3 text-right">
                                @if ($canManage)
                                    <div class="flex justify-end gap-2 text-theme-xs">
                                        <button type="button" wire:click="openForm({{ $vendor->id }})" class="text-gray-500 hover:underline">Edit</button>
                                        <button type="button" wire:click="toggle({{ $vendor->id }})" class="text-gray-500 hover:underline">{{ $vendor->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500">
                                No vendors yet. Add the first supplier and the received-invoice screen can name them.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>
