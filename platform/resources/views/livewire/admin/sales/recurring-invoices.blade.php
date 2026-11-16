<div>
    <x-admin.page-header title="Recurring invoices"
                         description="Standing charges that bill themselves on their own rhythm — a retainer, a maintenance contract. Each schedule raises one invoice per period, with its own number series and PDF.">
        <x-slot:actions>
            <x-admin.button icon="plus" wire:click="openForm">New schedule</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($showForm)
        <x-admin.card title="New recurring schedule" description="Set it once — the scheduler bills it every period" class="mb-6">
            <form wire:submit="save" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="wx-label" for="ri-contact">Client</label>
                    <select id="ri-contact" wire:model="contactId" class="wx-input">
                        <option value="">Choose a contact…</option>
                        @foreach ($contacts as $contact)
                            <option value="{{ $contact->id }}">{{ $contact->name }}</option>
                        @endforeach
                    </select>
                    @error('contactId') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="wx-label" for="ri-title">What it is</label>
                    <input id="ri-title" type="text" wire:model="title" class="wx-input" placeholder="Monthly maintenance — DHA Phase 5" />
                    @error('title') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="wx-label" for="ri-line">Line</label>
                    <input id="ri-line" type="text" wire:model="lineDescription" class="wx-input" placeholder="Monthly maintenance visit" />
                    @error('lineDescription') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="wx-label" for="ri-qty">Qty</label>
                        <input id="ri-qty" type="number" min="1" wire:model="lineQty" class="wx-input" />
                        @error('lineQty') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="wx-label" for="ri-rate">Rate (Rs)</label>
                        <input id="ri-rate" type="number" step="0.01" min="0" wire:model="lineRate" class="wx-input" />
                        @error('lineRate') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="wx-label" for="ri-tax">Tax %</label>
                        <input id="ri-tax" type="number" step="0.01" min="0" max="100" wire:model="taxPercent" class="wx-input" />
                        @error('taxPercent') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="wx-label" for="ri-frequency">Rhythm</label>
                        <select id="ri-frequency" wire:model="frequency" class="wx-input">
                            @foreach ($frequencies as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('frequency') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="wx-label" for="ri-line-2">Business line</label>
                        <select id="ri-line-2" wire:model="businessLine" class="wx-input">
                            @foreach ($lines as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('businessLine') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="wx-label" for="ri-start">First invoice</label>
                        <input id="ri-start" type="date" wire:model="startsOn" class="wx-input" />
                        @error('startsOn') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="wx-label" for="ri-due">Payable within (days)</label>
                        <input id="ri-due" type="number" min="0" max="365" wire:model="dueDays" class="wx-input" />
                        @error('dueDays') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="wx-label" for="ri-notes">Note on the invoice (optional)</label>
                    <input id="ri-notes" type="text" wire:model="notes" class="wx-input" placeholder="Thanks for keeping the maintenance plan with us." />
                    @error('notes') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-3 md:col-span-2">
                    <x-admin.button type="submit">Start billing</x-admin.button>
                    <x-admin.button variant="secondary" wire:click="closeForm" type="button">Cancel</x-admin.button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <x-admin.card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
            <p class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $schedules->count() }} schedule{{ $schedules->count() === 1 ? '' : 's' }}</p>
            <input type="search" wire:model.live.debounce.300ms="search" class="wx-input max-w-xs" placeholder="Search client or title…" aria-label="Search recurring invoices" />
        </div>

        @if ($schedules->isEmpty())
            <x-admin.empty icon="refresh" title="No recurring invoices yet"
                           description="Start one for a retainer or a maintenance contract — the scheduler raises the invoice each period with its own number and PDF.">
                <x-admin.button icon="plus" wire:click="openForm">New schedule</x-admin.button>
            </x-admin.empty>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="border-b border-gray-100 text-theme-xs text-gray-500 uppercase dark:border-gray-800">
                        <tr>
                            <th class="px-5 py-3 font-medium">Client</th>
                            <th class="px-5 py-3 font-medium">What</th>
                            <th class="px-5 py-3 text-right font-medium">Amount</th>
                            <th class="px-5 py-3 font-medium">Rhythm</th>
                            <th class="px-5 py-3 font-medium">Next</th>
                            <th class="px-5 py-3 text-center font-medium">Raised</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($schedules as $schedule)
                            <tr wire:key="ri-{{ $schedule->id }}" class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="px-5 py-3 text-theme-sm text-gray-800 dark:text-gray-200">{{ $schedule->contact->name }}</td>
                                <td class="px-5 py-3 text-theme-sm text-gray-600 dark:text-gray-400">
                                    {{ $schedule->title }}
                                    <span class="block text-theme-xs text-gray-400">{{ $schedule->lines[0]['description'] ?? '' }}</span>
                                </td>
                                <td class="px-5 py-3 text-right text-theme-sm text-gray-800 dark:text-gray-200">{{ \App\Support\Money::ofMinor($schedule->totalMinor())->format($lakh) }}</td>
                                <td class="px-5 py-3 text-theme-sm text-gray-600 dark:text-gray-400">{{ $schedule->frequency->label() }}</td>
                                <td class="px-5 py-3 text-theme-sm text-gray-600 dark:text-gray-400">{{ $schedule->next_issue_on->format('j M Y') }}</td>
                                <td class="px-5 py-3 text-center text-theme-sm text-gray-600 dark:text-gray-400">
                                    {{ $schedule->invoice_count }}
                                    @if ($schedule->last_issued_at)
                                        <span class="block text-theme-xs text-gray-400">{{ $schedule->last_issued_at->diffForHumans() }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <x-admin.badge :color="$schedule->is_active ? 'success' : 'warning'">{{ $schedule->is_active ? 'Billing' : 'Paused' }}</x-admin.badge>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <x-admin.button size="sm" variant="secondary" wire:click="issueNow({{ $schedule->id }})" wire:confirm="Bill this period now?">Bill now</x-admin.button>
                                        <x-admin.button size="sm" variant="ghost" wire:click="toggle({{ $schedule->id }})">{{ $schedule->is_active ? 'Pause' : 'Resume' }}</x-admin.button>
                                        <x-admin.button size="sm" variant="ghost" wire:click="delete({{ $schedule->id }})" wire:confirm="Delete this schedule? Invoices it already raised stay.">Delete</x-admin.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>
</div>
