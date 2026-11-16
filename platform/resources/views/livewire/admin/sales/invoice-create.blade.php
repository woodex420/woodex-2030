@use('App\Support\Money')
<div>
    <x-admin.page-header title="New invoice" description="Bill work that has no quotation — a repair, a supply, a fee. Save it as a draft, or issue it now and it is frozen and booked like every other invoice."
                         :breadcrumbs="['Sales' => null, 'Invoices' => route('admin.invoices.index'), 'New invoice' => null]">

    </x-admin.page-header>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <div class="space-y-6 min-w-0">
            <x-admin.card title="Client and dates">
                <div class="space-y-4">
                    <div>
                        <span class="wx-label">Client</span>
                        @if ($selectedContact)
                            <div class="mt-1 flex items-center justify-between rounded-lg border border-gray-200 px-3.5 py-2.5 dark:border-gray-800">
                                <span class="text-theme-sm"><strong>{{ $selectedContact->name }}</strong> <span class="text-gray-500">{{ $selectedContact->phone }}</span></span>
                                <button type="button" wire:click="clearContact" class="text-theme-xs font-medium text-gray-500 hover:text-gray-900 dark:hover:text-white">Change</button>
                            </div>
                        @else
                            <input type="search" wire:model.live.debounce.300ms="contactSearch" placeholder="Search by name or phone" class="wx-input mt-1" aria-label="Search clients">
                            @if ($contactResults->isNotEmpty())
                                <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                                    @foreach ($contactResults as $contact)
                                        <li wire:key="contact-{{ $contact->id }}">
                                            <button type="button" wire:click="selectContact({{ $contact->id }})" class="flex w-full items-center justify-between px-3.5 py-2.5 text-left text-theme-sm hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                                                <span>{{ $contact->name }}</span><span class="text-gray-500">{{ $contact->phone }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif (trim($contactSearch) !== '')
                                <p class="mt-2 text-theme-xs text-gray-500">No client matches. Add them under Contacts first.</p>
                            @endif
                        @endif
                        @error('contactId')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-admin.field label="Brand / line" for="line">
                            <select id="line" wire:model.live="line" class="wx-input">@foreach ($lines as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                        </x-admin.field>
                        <x-admin.field label="Bank account (Pay to)" for="bankAccountId" hint="Printed on the invoice. Empty uses the brand default.">
                            <select id="bankAccountId" wire:model.live.debounce.350ms="bankAccountId" class="wx-input">
                                <option value="">Brand default</option>
                                @foreach ($bankAccounts as $account)<option value="{{ $account->id }}">{{ $account->title }} · {{ $account->bank_name }}</option>@endforeach
                            </select>
                        </x-admin.field>
                        <x-admin.field label="Invoice date" for="issueDate" error="issueDate"><input id="issueDate" type="date" wire:model.live.debounce.350ms="issueDate" class="wx-input"></x-admin.field>
                        <x-admin.field label="Due date" for="dueDate" error="dueDate"><input id="dueDate" type="date" wire:model.live.debounce.350ms="dueDate" class="wx-input"></x-admin.field>
                    </div>
                </div>
            </x-admin.card>

            <x-admin.card title="Lines" description="Rates are before tax; the tax below is charged on the subtotal after any discount.">
                <div class="space-y-3">
                    @foreach ($items as $i => $row)
                        <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-800" wire:key="item-{{ $i }}">
                            <div class="flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    @if ($line === 'furniture')
                                        <input type="text" wire:model.live.debounce.350ms="items.{{ $i }}.product_name" placeholder="Product name · chair, sofa, workstation" maxlength="160" class="wx-input mb-2" aria-label="Line {{ $i + 1 }} product name">
                                    @endif
                                    <input type="text" wire:model.live.debounce.350ms="items.{{ $i }}.description" placeholder="Description, e.g. Wardrobe door repair" class="wx-input" aria-label="Line {{ $i + 1 }} description">
                                    @error('items.'.$i.'.description')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                                </div>
                                @if (count($items) > 1)
                                    <x-admin.button size="sm" variant="ghost" icon="trash" wire:click="removeItem({{ $i }})">Remove</x-admin.button>
                                @endif
                            </div>
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <div>
                                    <input type="text" inputmode="decimal" wire:model.live.debounce.350ms="items.{{ $i }}.qty" placeholder="Qty" class="wx-input text-right tabular-nums" aria-label="Line {{ $i + 1 }} quantity">
                                    @error('items.'.$i.'.qty')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                                </div>
                                <select wire:model.live.debounce.350ms="items.{{ $i }}.unit" class="wx-input" aria-label="Line {{ $i + 1 }} unit">@foreach ($units as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                                <div>
                                    <input type="text" inputmode="decimal" wire:model.live.debounce.350ms="items.{{ $i }}.rate" placeholder="Rate (Rs)" class="wx-input text-right tabular-nums" aria-label="Line {{ $i + 1 }} rate">
                                    @error('items.'.$i.'.rate')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3">
                    <x-admin.button size="sm" variant="secondary" icon="plus" wire:click="addItem">Add line</x-admin.button>
                </div>
            </x-admin.card>
            <x-admin.card title="Totals">
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.field label="Discount (Rs)" for="discount" error="discount"><input id="discount" type="text" inputmode="decimal" wire:model.live.debounce.350ms="discount" placeholder="0" class="wx-input text-right tabular-nums"></x-admin.field>
                        <x-admin.field label="Tax %" for="taxPercent" error="taxPercent" hint="Empty = no tax."><input id="taxPercent" type="text" inputmode="decimal" wire:model.live.debounce.350ms="taxPercent" placeholder="0" class="wx-input text-right tabular-nums"></x-admin.field>
                    </div>
                    @if ($line === 'furniture')
                        <div class="grid grid-cols-2 gap-3">
                            @foreach (['rent' => 'Rent / Transport (Rs)', 'advance' => 'Advance payment (%)'] as $field => $label)
                                <x-admin.field :label="$label" :for="$field" :error="$field"><input id="{{ $field }}" wire:model.live.debounce.350ms="{{ $field }}" class="wx-input" inputmode="decimal"></x-admin.field>
                            @endforeach
                        </div>
                        @foreach (['document_title' => 'Printed heading (not document type)', 'delivery_text' => 'Delivery time', 'transport_text' => 'Transport note', 'tax_note' => 'Tax note', 'po_reference' => 'PO reference'] as $field => $label)
                            <x-admin.field :label="$label" :for="'furniture.'.$field"><input id="furniture.{{ $field }}" wire:model.live.debounce.350ms="furniture.{{ $field }}" class="wx-input" maxlength="{{ $field === 'document_title' ? 60 : 300 }}"></x-admin.field>
                        @endforeach
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach (\App\Domain\Sales\Services\FurnitureDocument::OPTIONS as $field => $label)
                            <x-admin.field :label="$label" :for="'furniture.'.$field"><select id="furniture.{{ $field }}" wire:model.live="furniture.{{ $field }}" class="wx-input"><option value="yes">Show</option><option value="no">Hide when optional</option></select></x-admin.field>
                        @endforeach
                        </div>
                        <x-admin.field label="Centred signatories — Name | Title, one person per line" for="furniture.signatories_text"><textarea id="furniture.signatories_text" wire:model.live.debounce.350ms="furniture.signatories_text" rows="4" maxlength="2000" class="wx-input h-auto"></textarea></x-admin.field>
                        <p class="text-theme-xs text-gray-500">The advance percentage also supplies the payment term. Applied charges always print. Advance requested is not paid. Actual balance changes only after a payment is verified.</p>
                    @endif
                    <dl class="space-y-1.5 border-t border-gray-100 pt-3 text-theme-sm dark:border-gray-800">
                        <div class="flex justify-between"><dt class="text-gray-500">Subtotal</dt><dd class="tabular-nums">{{ Money::ofMinor($totals['subtotal'])->format($lakh) }}</dd></div>
                        @if ($totals['discount'] > 0)<div class="flex justify-between"><dt class="text-gray-500">Discount</dt><dd class="tabular-nums">− {{ Money::ofMinor($totals['discount'])->format($lakh) }}</dd></div>@endif
                        @if ($totals['tax'] > 0)<div class="flex justify-between"><dt class="text-gray-500">Tax</dt><dd class="tabular-nums">{{ Money::ofMinor($totals['tax'])->format($lakh) }}</dd></div>@endif
                        <div class="flex justify-between border-t border-gray-100 pt-2 font-medium dark:border-gray-800"><dt>Total</dt><dd class="tabular-nums">{{ Money::ofMinor($totals['total'])->format($lakh) }}</dd></div>
                    </dl>
                    <x-admin.field label="Notes for the client" for="notes" error="notes"><textarea id="notes" wire:model.live.debounce.350ms="notes" rows="2" class="wx-input h-auto py-2.5"></textarea></x-admin.field>
                    <x-admin.field :label="$line === 'furniture' ? 'Terms and conditions — additional terms (advance line is automatic)' : 'Terms'" for="terms" error="terms"><textarea id="terms" wire:model.live.debounce.350ms="terms" rows="3" class="wx-input h-auto py-2.5"></textarea></x-admin.field>
                    <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <x-admin.button variant="secondary" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft,saveAndIssue">Save draft</x-admin.button>
                        <x-admin.button icon="check" wire:click="saveAndIssue" wire:confirm="Issue now? The invoice is frozen and booked in the ledger immediately." wire:loading.attr="disabled" wire:target="saveDraft,saveAndIssue">Save &amp; issue</x-admin.button>
                        <x-admin.button variant="ghost" :href="route('admin.invoices.index')" wire:navigate>Cancel</x-admin.button>
                    </div>
                </div>
            </x-admin.card>
        </div>

        <div class="min-w-0 space-y-6 xl:sticky xl:top-24 xl:self-start">

    <div class="mt-0" aria-label="Invoice live preview pane">
        <p class="mb-2 text-theme-sm font-medium">Live invoice preview · updates as you edit</p>
        <iframe wire:ignore wire:key="invoice-live-preview" sandbox="allow-same-origin" x-data="{ fit() { const d = this.$el.contentDocument; if (d?.body) d.documentElement.style.zoom = String(Math.min(1, Math.max(.2, (this.$el.clientWidth - 20) / 794))); }, init() { this.resizeObserver = new ResizeObserver(() => this.fit()); this.resizeObserver.observe(this.$el); }, destroy() { this.resizeObserver?.disconnect(); } }" x-on:load="fit()" x-on:preview-refresh.window.debounce.150ms="const d = $event.detail; const h = d.html || (d[0] && d[0].html); if (h) $el.srcdoc = h;" srcdoc="{{ $previewHtml }}" class="h-[680px] w-full rounded-lg border border-gray-200 bg-gray-100" title="Invoice live preview"></iframe>
    </div>
        </div>
    </div>

</div>
