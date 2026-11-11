<div>
    <x-admin.page-header title="Payment methods" description="Where each payment method's money lands in the ledger. A verified receipt debits the account mapped here — an unmapped method is a hard stop, so keep all eight mapped."
                         :breadcrumbs="['Accounts' => null, 'Payment methods' => null]">
        <x-slot:actions>
            @if ($canManage)
                <x-admin.button icon="check" wire:click="save">Save mapping</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.card title="Method → ledger account">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm divide-y divide-gray-100 dark:divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Payment method</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Ledger account</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Code</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($methods as $method)
                        <tr>
                            <td class="px-3 py-2.5 font-medium">{{ $method->label() }}</td>
                            <td class="px-3 py-2.5">
                                @if ($canManage)
                                    <select wire:model="map.{{ $method->value }}" class="wx-input">
                                        <option value="">— choose an account —</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('map.'.$method->value)<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                                @else
                                    {{ $accounts->firstWhere('id', (int) ($map[$method->value] ?? 0))?->name ?? 'Not mapped' }}
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-gray-500 dark:text-gray-400">
                                {{ $accounts->firstWhere('id', (int) ($map[$method->value] ?? 0))?->code ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>
