@use('App\Domain\Accounts\Enums\AccountType')
<div>
    <x-admin.page-header title="Chart of accounts" description="The studio ledger's accounts. Groups organise the chart; only active leaf accounts receive postings. Balances appear once journals are posted — no opening amounts are invented here."
                         :breadcrumbs="['Accounts' => null, 'Chart of accounts' => null]">
        <x-slot:actions>
            @if ($canManage)
                <x-admin.button icon="plus" wire:click="create">New account</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($showForm)
        <x-admin.card class="mb-6 border-brand-200 dark:border-brand-800" title="{{ $editingId ? 'Edit account' : 'New account' }}">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="wx-label">Code</label>
                    <input wire:model="code" class="wx-input" placeholder="e.g. 1050" maxlength="12" />
                    @error('code')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="wx-label">Name</label>
                    <input wire:model="name" class="wx-input" placeholder="e.g. Bank — savings" maxlength="120" />
                    @error('name')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="wx-label">Type</label>
                    <select wire:model="type" class="wx-input">
                        @foreach (AccountType::balanced() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('type')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="wx-label">Parent group</label>
                    <select wire:model="parentId" class="wx-input">
                        <option value="">— none (creates a group) —</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}">{{ $group->code }} · {{ $group->name }}</option>
                        @endforeach
                    </select>
                    @error('parentId')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="wx-label">Business line</label>
                    <select wire:model="businessLine" class="wx-input">
                        <option value="">Both</option>
                        <option value="interior">Interior</option>
                        <option value="furniture">Furniture</option>
                    </select>
                    @error('businessLine')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-end justify-between gap-4">
                    <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="isActive" class="wx-checkbox" />
                        Active
                    </label>
                    <div class="flex gap-2">
                        <x-admin.button variant="secondary" type="button" wire:click="cancel">Cancel</x-admin.button>
                        <x-admin.button type="submit">Save</x-admin.button>
                    </div>
                </div>
            </form>
        </x-admin.card>
    @endif

    <div class="space-y-6">
        @foreach ($byType as $label => $accounts)
            @if ($accounts->isNotEmpty())
                <x-admin.card :title="$label">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-theme-sm divide-y divide-gray-100 dark:divide-gray-800">
                            <thead>
                                <tr>
                                    <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Code</th>
                                    <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Account</th>
                                    <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Business line</th>
                                    <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Postable</th>
                                    <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Status</th>
                                    @if ($canManage)<th></th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($accounts as $account)
                                    <tr>
                                        <td class="font-mono text-theme-sm">{{ $account->code }}</td>
                                        <td class="{{ $account->is_group ? 'font-semibold' : 'pl-6' }}">{{ $account->name }}</td>
                                        <td class="text-theme-sm">{{ $account->business_line?->label() ?? '—' }}</td>
                                        <td class="text-theme-sm">
                                            @if ($account->is_group)
                                                <x-admin.badge color="gray">Group</x-admin.badge>
                                            @elseif ($account->is_active)
                                                <x-admin.badge color="success">Leaf</x-admin.badge>
                                            @else
                                                <x-admin.badge color="gray">Inactive</x-admin.badge>
                                            @endif
                                        </td>
                                        <td class="text-theme-sm">{{ $account->is_active ? 'Active' : 'Inactive' }}</td>
                                        @if ($canManage)
                                            <td class="text-right">
                                                <div class="flex justify-end gap-1">
                                                    <x-admin.button size="sm" variant="ghost" wire:click="edit({{ $account->id }})">Edit</x-admin.button>
                                                    <x-admin.button size="sm" variant="ghost" wire:click="toggleActive({{ $account->id }})">{{ $account->is_active ? 'Deactivate' : 'Activate' }}</x-admin.button>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-admin.card>
            @endif
        @endforeach
    </div>
</div>
