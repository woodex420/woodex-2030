@use('App\Support\Money')
<div>
    <x-admin.page-header title="Recurring expenses"
                         description="Rent, salaries, subscriptions — the overheads that arrive whether or not anyone remembers. Each schedule posts one expense per period at 09:10, and a missed day is caught on the next run, never a backlog."
                         :breadcrumbs="['Accounts' => null, 'Income & expenses' => null, 'Recurring' => null]">
        @if ($canManage)
            <x-slot:actions>
                <x-admin.button icon="plus" wire:click="openForm">New schedule</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Monthly commitment (active schedules)</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($monthlyCommitment)->format($lakh) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Runs</p>
            <p class="text-theme-sm text-gray-800 dark:text-white/90">Daily at 09:10 — <span class="text-gray-500">woodex:expense-recurring</span></p>
        </div>
    </div>

    @if ($showForm)
        <x-admin.card title="New recurring schedule"
                      description="The first run is the start date; each run advances from the date it posted, so a late catch-up does not drift the series."
                      class="mb-6">
            <form wire:submit="save" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <label class="wx-label" for="rec-description">What it is</label>
                    <input id="rec-description" type="text" wire:model="description" class="wx-input" placeholder="Studio rent — DHA Phase 5" />
                    @error('description') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="rec-amount">Amount (Rs)</label>
                    <input id="rec-amount" type="number" step="0.01" min="0.01" wire:model="amountRupees" class="wx-input" />
                    @error('amountRupees') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="rec-account">Cost account</label>
                    <select id="rec-account" wire:model="accountId" class="wx-input">
                        <option value="">Choose an account…</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('accountId') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="rec-vendor">Vendor (optional)</label>
                    <select id="rec-vendor" wire:model="vendorId" class="wx-input">
                        <option value="">No vendor</option>
                        @foreach ($vendors as $vendor)
                            <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                        @endforeach
                    </select>
                    @error('vendorId') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="rec-paid-from">Money moved through</label>
                    <select id="rec-paid-from" wire:model="paidFrom" class="wx-input">
                        <option value="bank">Bank account</option>
                        <option value="cash">Cash in hand</option>
                        <option value="wallet">Mobile wallet</option>
                    </select>
                    @error('paidFrom') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="rec-frequency">How often</label>
                    <select id="rec-frequency" wire:model="frequency" class="wx-input">
                        @foreach ($frequencies as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('frequency') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="rec-start">First run</label>
                    <input id="rec-start" type="date" wire:model="startsOn" class="wx-input" />
                    @error('startsOn') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="rec-end">Stop after (optional)</label>
                    <input id="rec-end" type="date" wire:model="endsOn" class="wx-input" />
                    @error('endsOn') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-center gap-2 md:col-span-3">
                    <x-admin.button type="submit" icon="save">Save schedule</x-admin.button>
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
                        <th class="px-5 py-3">Schedule</th>
                        <th class="hidden px-5 py-3 md:table-cell">Account</th>
                        <th class="px-5 py-3">Rhythm</th>
                        <th class="px-5 py-3">Next run</th>
                        <th class="px-5 py-3 text-right">Amount</th>
                        <th class="px-5 py-3 text-right">Raised</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($schedules as $schedule)
                        <tr wire:key="schedule-{{ $schedule->id }}">
                            <td class="px-5 py-3">
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $schedule->description }}</p>
                                <p class="text-theme-xs text-gray-500">
                                    {{ $schedule->vendor?->name ?? 'No vendor' }} · started {{ $schedule->starts_on->format('j M Y') }}
                                    @if ($schedule->ends_on) · ends {{ $schedule->ends_on->format('j M Y') }} @endif
                                </p>
                            </td>
                            <td class="hidden px-5 py-3 text-gray-600 md:table-cell dark:text-gray-400">{{ $schedule->account?->code }} · {{ $schedule->account?->name }}</td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ $schedule->frequency->description() }}</td>
                            <td class="px-5 py-3 whitespace-nowrap {{ $schedule->is_active ? 'text-gray-800 dark:text-white/90' : 'text-gray-400' }}">
                                {{ $schedule->next_run_on->format('j M Y') }}
                                @unless ($schedule->is_active) <span class="block text-theme-xs">paused</span> @endunless
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $schedule->amount_minor)->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ $schedule->raised_count }}</td>
                            <td class="px-5 py-3 text-right">
                                @if ($canManage)
                                    <div class="flex justify-end gap-2 text-theme-xs">
                                        <button type="button" wire:click="raiseNow({{ $schedule->id }})" class="font-medium text-brand-600 hover:underline">Raise now</button>
                                        <button type="button" wire:click="toggle({{ $schedule->id }})" class="text-gray-500 hover:underline">{{ $schedule->is_active ? 'Pause' : 'Resume' }}</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500">
                                No recurring overheads yet. Add the rent first — it is the line the owner notices when it is missing.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>
