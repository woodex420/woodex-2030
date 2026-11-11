@use('App\Support\Money')
<div>
    <x-admin.page-header title="Journals" description="Every posted entry balances to the paisa and is immutable — corrections are linked reversals, never edits."
                         :breadcrumbs="['Accounts' => null, 'Journals' => null]">
        <x-slot:actions>
            @if ($canManage)
                <x-admin.button icon="plus" wire:click="startEntry">Manual entry</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($showForm)
        <x-admin.card class="mb-6 border-brand-200 dark:border-brand-800" title="Manual journal entry">
            <form wire:submit="post" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="wx-label">Business date</label>
                        <input type="date" wire:model="businessDate" class="wx-input" />
                        @error('businessDate')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="wx-label">Reference</label>
                        <input wire:model="reference" class="wx-input" placeholder="e.g. Aug fuel — Auriga" maxlength="120" />
                        @error('reference')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="wx-label">Note</label>
                        <input wire:model="note" class="wx-input" placeholder="Why this entry exists" maxlength="1000" />
                        @error('note')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-theme-sm divide-y divide-gray-100 dark:divide-gray-800">
                        <thead>
                            <tr>
                                <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Account</th>
                                <th class="w-32">Debit (PKR)</th>
                                <th class="w-32">Credit (PKR)</th>
                                <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Memo</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lines as $index => $line)
                                <tr>
                                    <td class="px-3 py-2.5">
                                        <select wire:model="lines.{{ $index }}.account_id" class="wx-input min-w-56">
                                            <option value="">— account —</option>
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->label() }}</option>
                                            @endforeach
                                        </select>
                                        @error("lines.{$index}.account_id")<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                                    </td>
                                    <td class="px-3 py-2.5"><input wire:model="lines.{{ $index }}.debit" class="wx-input" inputmode="decimal" placeholder="0" /></td>
                                    <td class="px-3 py-2.5"><input wire:model="lines.{{ $index }}.credit" class="wx-input" inputmode="decimal" placeholder="0" /></td>
                                    <td class="px-3 py-2.5"><input wire:model="lines.{{ $index }}.memo" class="wx-input" placeholder="Optional" maxlength="160" /></td>
                                    <td class="text-right">
                                        <x-admin.button size="sm" variant="ghost" type="button" wire:click="removeLine({{ $index }})" aria-label="Remove line">×</x-admin.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between">
                    <x-admin.button size="sm" variant="secondary" type="button" wire:click="addLine">+ line</x-admin.button>
                    <div class="flex gap-2">
                        <x-admin.button variant="secondary" type="button" wire:click="$set('showForm', false)">Cancel</x-admin.button>
                        <x-admin.button type="submit">Post entry</x-admin.button>
                    </div>
                </div>
            </form>
        </x-admin.card>
    @endif

    @if ($reversingId)
        <x-admin.card class="mb-6 border-red-200 dark:border-red-800" title="Reverse entry">
            <p class="mb-4 text-theme-sm text-gray-600 dark:text-gray-400">
                A reversal posts a mirrored entry and keeps the original untouched. Every line swaps sides.
            </p>
            <form wire:submit="reverse" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-64">
                    <label class="wx-label">Reason (kept with the entry)</label>
                    <input wire:model="reversalReason" class="wx-input" placeholder="e.g. posted to the wrong account" maxlength="500" />
                    @error('reversalReason')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex gap-2">
                    <x-admin.button variant="secondary" type="button" wire:click="$set('reversingId', null)">Cancel</x-admin.button>
                    <x-admin.button variant="danger" type="submit">Post reversal</x-admin.button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <x-admin.card title="Posted entries">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm divide-y divide-gray-100 dark:divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Entry</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Date</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Lines</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Amount</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Reference</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">By</th>
                        @if ($canManage)<th></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td class="font-mono text-theme-sm">
                                {{ $entry->entry_no }}
                                @if ($entry->isReversal())<x-admin.badge color="gray">reversal</x-admin.badge>@endif
                                @if ($entry->isReversed())<x-admin.badge color="warning">reversed</x-admin.badge>@endif
                            </td>
                            <td class="text-theme-sm">{{ $entry->business_date->format('d M Y') }}</td>
                            <td class="px-3 py-2.5">
                                <ul class="space-y-0.5">
                                    @foreach ($entry->lines as $line)
                                        <li class="text-theme-sm text-gray-600 dark:text-gray-400">
                                            <span class="font-mono">{{ $line->account->code }}</span>
                                            {{ $line->account->name }}
                                            —
                                            @if ($line->debit_minor > 0)
                                                <span class="font-medium text-gray-900 dark:text-gray-100">Dr {{ Money::ofMinor((int) $line->debit_minor)->format() }}</span>
                                            @else
                                                <span class="font-medium text-gray-900 dark:text-gray-100">Cr {{ Money::ofMinor((int) $line->credit_minor)->format() }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="text-theme-sm font-medium">{{ Money::ofMinor($entry->totals()['debit_minor'])->format() }}</td>
                            <td class="text-theme-sm">
                                {{ $entry->reference ?? '—' }}
                                @if ($entry->note)<p class="text-gray-500">{{ $entry->note }}</p>@endif
                            </td>
                            <td class="text-theme-sm">{{ $entry->creator?->name ?? '—' }}</td>
                            @if ($canManage)
                                <td class="text-right">
                                    @unless ($entry->isReversed() || $entry->isReversal())
                                        <x-admin.button size="sm" variant="ghost" wire:click="askReverse({{ $entry->id }})">Reverse</x-admin.button>
                                    @endunless
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-theme-sm text-gray-500">No journal entries posted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $entries->links('components.admin.pagination') }}</div>
    </x-admin.card>
</div>
