@use('App\Support\Money')
<div class="space-y-4">
    @if ($projectId === null)
    <x-admin.page-header title="Project costs" description="One purchase, one accounting source. Supplier bills are unpaid costs; paid costs have already left cash, bank or wallet.">
        @if ($project && $canManage)
            <x-slot:actions><x-admin.button wire:click="newCost" icon="plus">New project cost</x-admin.button></x-slot:actions>
        @endif
    </x-admin.page-header>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-theme-lg font-semibold text-gray-900 dark:text-white">Project costs</h2>@if ($canManage)<x-admin.button wire:click="newCost" icon="plus">New project cost</x-admin.button>@endif</div>
    @endif
    @if ($projectId === null)
        <x-admin.card>
            <label for="cost-project" class="wx-label">Project</label>
            <select id="cost-project" wire:model.live="selectedProjectId" class="wx-input">
                <option value="">Choose a project…</option>
                @foreach ($projects as $option)<option value="{{ $option->id }}">{{ $option->code }} · {{ $option->name }}</option>@endforeach
            </select>
            @error('selectedProjectId') <p class="text-theme-sm text-error-600">{{ $message }}</p> @enderror
        </x-admin.card>
    @endif
    @if ($project && $summary)
        <div class="flex flex-wrap items-center justify-between gap-2 text-theme-sm">
            <a href="{{ route('admin.projects.show', $project) }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-white">{{ $project->code }} · {{ $project->name }}</a>
            <a href="{{ route('admin.accounts.payables', ['billProject' => $project->id]) }}" wire:navigate class="text-gray-600 underline dark:text-gray-300">Manage supplier bills & payments</a>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['linked_cost_minor' => 'Linked costs (excluding recoverable bill tax)', 'supplier_outstanding_minor' => 'Supplier bills still owed (AP)', 'direct_paid_minor' => 'Direct paid costs', 'customer_billed_minor' => 'Issued customer invoices', 'customer_received_minor' => 'Received against customer invoices', 'customer_outstanding_minor' => 'Customer invoices still owed (AR)'] as $key => $label)
                @continue(str_starts_with($key, 'customer_') && ! $canViewInvoices)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500">{{ $label }}</p>
                    <p class="break-words text-theme-lg font-medium tabular-nums text-gray-900 dark:text-white">{{ Money::ofMinor($summary[$key])->format() }}</p>
                </div>
            @endforeach
        </div>
        <p class="text-theme-xs text-gray-500">Supplier bills: {{ Money::ofMinor($summary['supplier_billed_minor'])->format() }} including {{ Money::ofMinor($summary['bill_tax_minor'])->format() }} recoverable tax; paid against bills: {{ Money::ofMinor($summary['supplier_paid_minor'])->format() }}. Draft bills: {{ Money::ofMinor($summary['draft_bills_minor'])->format() }} (not posted). Voids and reversed sources are excluded from linked totals. These are purchase/cash figures, not a profit calculation.</p>
        @if ($canViewInvoices && $summary['customer_credit_notes_minor'] !== 0)
            <p class="text-theme-sm text-warning-700">Customer credit notes: {{ Money::ofMinor($summary['customer_credit_notes_minor'])->format() }}, shown separately and not automatically netted against invoice balances here.</p>
        @endif
        @if ($summary['legacy_count'] > 0)
            <div role="note" class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-theme-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <strong>Legacy project costs need reconciliation.</strong> {{ $summary['legacy_count'] }} entries, {{ Money::ofMinor($summary['legacy_recorded_minor'])->format() }} recorded; {{ $summary['legacy_posted_count'] }} currently posted ({{ Money::ofMinor($summary['legacy_posted_minor'])->format() }}). Their supplier/payment identity is unknown. They are separate from linked totals, not assumed paid, and must not be re-entered as bills or paid expenses. No automatic matching or reposting is performed.
            </div>
        @endif
        @if ($showForm && $canManage)
            <x-admin.card title="Record a project cost" description="Use the supplier's original invoice/reference. For multiple lines, asset purchases or recoverable supplier tax, use Supplier bills and choose this project.">
                <form wire:submit="save" x-on:keydown.enter="if ($event.target.tagName === 'INPUT' &amp;&amp; !['checkbox','radio','file'].includes($event.target.type)) { $event.preventDefault(); $el.querySelector('[data-explicit-save]')?.click(); }" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><label for="cost-kind" class="wx-label">Cost type</label><select id="cost-kind" wire:model.live="kind" class="wx-input"><option value="bill">Unpaid supplier bill</option><option value="paid">Already paid cost</option></select>@error('kind')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror</div>
                    <div><label for="cost-vendor" class="wx-label">Vendor</label><select id="cost-vendor" wire:model="vendorId" class="wx-input"><option value="">Choose a vendor…</option>@foreach ($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->name }}</option>@endforeach</select>@error('vendorId')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror</div>
                    <div><label for="cost-reference" class="wx-label">Supplier reference</label><input id="cost-reference" wire:model="reference" class="wx-input" maxlength="60" />@error('reference')<p role="alert" class="text-theme-xs text-error-600">{{ $message }}</p>@enderror</div>
                    <div><label for="cost-description" class="wx-label">Description</label><input id="cost-description" wire:model="description" class="wx-input" maxlength="255" />@error('description')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror</div>
                    <div><label for="cost-account" class="wx-label">Cost account</label><select id="cost-account" wire:model="accountId" class="wx-input"><option value="">Choose an account…</option>@foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select>@error('accountId')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror</div>
                    <div><label for="cost-amount" class="wx-label">Amount (Rs)</label><input id="cost-amount" type="text" inputmode="decimal" wire:model="amountRupees" class="wx-input" placeholder="12500.50" />@error('amountRupees')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror</div>
                    <div><label for="cost-date" class="wx-label">Cost date</label><input id="cost-date" type="date" wire:model="spentOn" class="wx-input" />@error('spentOn')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror</div>
                    @if ($kind === 'bill')
                        <div><label for="cost-due" class="wx-label">Due date (optional)</label><input id="cost-due" type="date" wire:model="dueDate" class="wx-input" />@error('dueDate')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror</div>
                    @else
                        <div><label for="cost-paid-from" class="wx-label">Paid from</label><select id="cost-paid-from" wire:model="paidFrom" class="wx-input"><option value="bank">Bank</option><option value="cash">Cash</option><option value="wallet">Mobile wallet</option></select>@error('paidFrom')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror</div>
                    @endif
                    <div class="flex flex-wrap gap-2 sm:col-span-2"><x-admin.button type="button" data-explicit-save wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ $kind === 'bill' ? 'Record unpaid bill' : 'Record paid cost' }}</x-admin.button><x-admin.button type="button" variant="secondary" wire:click="$set('showForm', false)">Close</x-admin.button></div>
                </form>
            </x-admin.card>
        @endif
        <x-admin.card title="Linked supplier bills" :padding="false">
            <div class="overflow-x-auto"><table class="min-w-full text-left text-theme-sm"><thead><tr><th class="p-3">Reference / vendor</th><th class="p-3">Status</th><th class="p-3 text-right">Total incl. tax</th><th class="p-3 text-right">Still owed</th></tr></thead><tbody>
            @forelse ($bills as $bill)
                <tr wire:key="cost-bill-{{ $bill->id }}" class="border-t border-gray-100 dark:border-gray-800"><td class="p-3">{{ $bill->number }}<span class="block text-gray-500">{{ $bill->vendor?->name ?? 'Vendor unavailable' }}</span></td><td class="p-3"><x-admin.badge :color="$bill->status->color()">{{ $bill->status->label() }}</x-admin.badge>@if ($bill->journalEntry?->isReversed())<span class="block text-theme-xs text-gray-500">Journal reversed</span>@elseif (! $bill->journal_entry_id)<span class="block text-theme-xs text-gray-500">Not posted</span>@endif</td><td class="p-3 text-right tabular-nums">{{ Money::ofMinor($bill->total_minor)->format() }}</td><td class="p-3 text-right tabular-nums">{{ $bill->status->value === 'void' ? 'Voided' : $bill->balance()->format() }}</td></tr>
            @empty <tr><td colspan="4" class="p-4 text-gray-500">No linked supplier bills. Historical costs are not automatically attached.</td></tr>@endforelse
            </tbody></table></div><div class="p-3">{{ $bills->links() }}</div>
        </x-admin.card>
        <x-admin.card title="Linked paid costs" :padding="false">
            <div class="overflow-x-auto"><table class="min-w-full text-left text-theme-sm"><thead><tr><th class="p-3">Reference / description</th><th class="p-3">Vendor / payment</th><th class="p-3">Ledger</th><th class="p-3 text-right">Amount</th></tr></thead><tbody>
            @forelse ($paidCosts as $expense)
                <tr wire:key="cost-expense-{{ $expense->id }}" class="border-t border-gray-100 dark:border-gray-800"><td class="p-3">{{ $expense->reference ?? 'No reference' }}<span class="block text-gray-500">{{ $expense->description }}</span></td><td class="p-3">{{ $expense->vendor?->name ?? 'No vendor' }}<span class="block text-gray-500">{{ $expense->paid_from->label() }}</span></td><td class="p-3">{{ $expense->journalEntry?->isReversed() ? 'Reversed — excluded' : ($expense->journal_entry_id ? 'Posted' : 'Not posted') }}</td><td class="p-3 text-right tabular-nums">{{ $expense->amount()->format() }}</td></tr>
            @empty <tr><td colspan="4" class="p-4 text-gray-500">No linked paid costs. Paying a bill does not create another expense here.</td></tr>@endforelse
            </tbody></table></div><div class="p-3">{{ $paidCosts->links() }}</div>
        </x-admin.card>
    @else
        <x-admin.card><p class="text-theme-sm text-gray-500">Choose a project to view linked purchases, supplier balances and customer invoice context. Add vendors in the Vendors tab before recording supplier costs.</p></x-admin.card>
    @endif
</div>
