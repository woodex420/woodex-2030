<div data-unsaved="save">
    <x-admin.page-header :title="$estimateId !== null ? 'Estimate '.$number : 'New estimate'" description="Pick the service, give the size and the finish, and the estimate gives a range from essential to premium. Add known rate book items and an explicit contingency. Converting creates a quotation draft; nothing reaches a client from here."
                         :breadcrumbs="['Sales' => null, 'Estimations' => route('admin.estimations.index'), $estimateId !== null ? $number : 'New' => null]">
        <x-slot:actions>
            @if ($estimateId !== null && $estimate?->quote !== null)
                <a href="{{ route('admin.quotes.show', $estimate->quote) }}"><x-admin.button variant="secondary" icon="quote">Quotation {{ $estimate->quote->number }}</x-admin.button></a>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($lead)
        <div class="mb-5 flex items-start gap-3 rounded-xl border border-brand-500/20 bg-brand-50/60 px-4 py-3 text-theme-sm dark:bg-white/5">
            <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-500/10 text-brand-600 dark:text-brand-400">
                <x-icon name="users" class="size-4" />
            </span>
            <p class="min-w-0 flex-1 text-gray-600 dark:text-gray-300">
                Started from enquiry
                <a href="{{ route('admin.leads.show', $lead) }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $lead->ref }}</a>
                — the estimate and the quotation it becomes stay linked to it. The service and size came from the enquiry; check them against the brief before saving.
            </p>
        </div>
    @endif

    @error('estimate')<p role="alert" class="mb-4 text-error-600">{{ $message }}</p>@enderror
    <div class="grid min-w-0 gap-6 lg:grid-cols-3">
        <fieldset @disabled($status === 'converted') class="min-w-0 lg:col-span-2 space-y-6">
            <x-admin.card title="The job">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="wx-label" for="est-service">Service</label>
                        <select id="est-service" wire:model.live="serviceKey" class="wx-input">
                            <option value="">— choose the service —</option>
                            @if ($serviceKey !== '' && ! $services->contains('key', $serviceKey))<option value="{{ $serviceKey }}">{{ $estimate?->computed['service_label'] ?? $serviceKey }} (saved / retired)</option>@endif
                            @foreach ($services as $service)
                                <option value="{{ $service->key }}">{{ $service->label }} ({{ $service->unit_label ?? $service->unit }})</option>
                            @endforeach
                        </select>
                        @error('serviceKey')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                        {{-- Said on the field itself: an empty list with no reason reads as a fault (DL-046). --}}
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400" data-no-services>{{ $services->isEmpty() ? 'No service has a rate yet, so an estimate cannot be saved here. The rates come with the price list.' : '' }}</p>
                    </div>
                    <div>
                        <label class="wx-label" for="est-title">Title</label>
                        <input id="est-title" wire:model="title" class="wx-input" placeholder="e.g. 24 workstations — head office, Gulberg" maxlength="120" />
                        @error('title')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="wx-label" for="est-client">Client</label>
                        <select id="est-client" wire:model="contactId" class="wx-input">
                            <option value="">— choose the client (needed to convert) —</option>
                            @foreach ($contacts as $contact)
                                <option value="{{ $contact->id }}">{{ $contact->name }}</option>
                            @endforeach
                        </select>
                        @error('contactId')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="wx-label" for="est-quantity">Size / quantity</label>
                        <input id="est-quantity" wire:model.live="quantity" class="wx-input" placeholder="e.g. 2400" inputmode="decimal" />
                        @error('quantity')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <span class="wx-label" id="est-finish-label">Finish level</span>
                        <div class="flex flex-wrap gap-3" role="radiogroup" aria-labelledby="est-finish-label">
                            @foreach (['essential' => 'Essential', 'standard' => 'Standard', 'premium' => 'Premium'] as $value => $label)
                                <label class="flex items-center gap-2 rounded-lg border px-3 py-2 text-theme-sm {{ $finish === $value ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30' : 'border-gray-200 dark:border-gray-800' }}">
                                    <input type="radio" wire:model.live="finish" value="{{ $value }}" />
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="wx-label" for="est-contingency">Contingency (basis points)</label>
                        <input id="est-contingency" wire:model.live="contingencyBp" class="wx-input" inputmode="numeric" placeholder="0 – 10000 (e.g. 500 = 5%)" />
                        @error('contingencyBp')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </x-admin.card>

            <x-admin.card title="Rate book items (optional)">
                <p class="mb-3 text-theme-sm text-gray-500 dark:text-gray-400">Known items priced from the rate book at today's rates — they become their own section on the quotation.</p>
                <div class="space-y-3">
                    @foreach ($catalogLines as $index => $line)
                        <div class="grid grid-cols-12 gap-2 items-end" wire:key="line-{{ $index }}">
                            <div class="col-span-12 min-w-0 sm:col-span-7">
                                <label class="wx-label" for="est-line-{{ $index }}-item">Item</label>
                                <select id="est-line-{{ $index }}-item" wire:model.live="catalogLines.{{ $index }}.catalog_item_id" class="wx-input">
                                    <option value="">— item —</option>
                                    @foreach ($catalogItems as $item)
                                        <option value="{{ $item->id }}">{{ $item->category }} — {{ Str::limit($item->description, 60) }} · {{ \App\Support\Money::ofMinor($item->rate_minor)->format() }}/{{ $item->unit }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-span-7 min-w-0 sm:col-span-3">
                                <label class="wx-label" for="est-line-{{ $index }}-qty">Qty</label>
                                <input id="est-line-{{ $index }}-qty" wire:model.live="catalogLines.{{ $index }}.qty" class="wx-input" inputmode="decimal" />
                            </div>
                            <div class="col-span-5 sm:col-span-2">
                                <x-admin.button variant="secondary" type="button" wire:click="removeLine({{ $index }})">Remove</x-admin.button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3">
                    <x-admin.button variant="secondary" icon="plus" type="button" wire:click="addLine">Add item</x-admin.button>
                </div>
                @error('catalogLines')<p class="mt-2 text-theme-sm text-red-600">{{ $message }}</p>@enderror
            </x-admin.card>

            <x-admin.card title="Assumptions & exclusions">
                <div class="grid gap-4">
                    <div>
                        <label class="wx-label" for="est-assumptions">Assumptions (internal + carried to the quote's notes)</label>
                        <textarea id="est-assumptions" wire:model="assumptions" class="wx-input" rows="3" maxlength="4000" placeholder="e.g. rates per piece, delivered in Lahore; site access during working hours; installation included"></textarea>
                    </div>
                    <div>
                        <label class="wx-label" for="est-exclusions">Exclusions</label>
                        <textarea id="est-exclusions" wire:model="exclusions" class="wx-input" rows="3" maxlength="4000" placeholder="e.g. electrical work, flooring and partitions by others"></textarea>
                    </div>
                </div>
            </x-admin.card>
        </fieldset>

        <div class="min-w-0 space-y-6">
            @if ($saved = $this->savedSummary())
                <x-admin.card title="Saved snapshot">
                    <p class="text-2xl font-semibold">{{ $saved['total'] }}</p>
                    <p class="text-theme-sm text-gray-500">Rate {{ $saved['rate_minor'] }} · {{ $saved['effective_on'] }} · {{ ucfirst($status) }}</p>
                    <p class="mt-2 text-theme-xs text-gray-500">Conversion uses this saved amount, not today's preview. Contingency remains an internal allowance unless deliberately added to the quotation.</p>
                </x-admin.card>
            @endif
            @if ($status !== 'converted')
            <x-admin.card title="Current-rate preview (unsaved)">
                @if ($summary === null)
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">Choose a service and enter valid quantities to see the range.</p>
                @else
                    <dl class="space-y-2 text-theme-sm">
                        <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Rate ({{ $finish }})</dt><dd class="font-medium">{{ $summary['rate_minor'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Headline</dt><dd class="font-medium">{{ $summary['headline'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Honest range</dt><dd class="font-medium">{{ $summary['range_low'] }} – {{ $summary['range_high'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Rate book items</dt><dd class="font-medium">{{ $summary['lines_minor'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Contingency</dt><dd class="font-medium">{{ $summary['contingency'] }}</dd></div>
                        <div class="flex justify-between border-t border-gray-100 dark:border-gray-800 pt-2"><dt class="font-medium">Total</dt><dd class="font-semibold">{{ $summary['total'] }}</dd></div>
                    </dl>
                    <p class="mt-3 text-xs text-gray-400">Rates as of {{ $summary['effective_on'] }} — the snapshot freezes them when you save. Empty add-on drafts are not included in this preview; complete or remove them before saving.</p>
                @endif
            </x-admin.card>

            @endif
            @can('estimates.manage')
                <x-admin.card title="Actions">
                    <div class="space-y-3">
                        @if ($status !== 'converted')
                        @if ($estimateId !== null)
                            <label class="flex items-start gap-2 text-theme-sm"><input type="checkbox" wire:model="confirmReprice" data-unsaved-skip class="mt-1">I compared saved/current amounts and confirm saving with today's rates.</label>
                            @error('confirmReprice')<p class="text-error-600">{{ $message }}</p>@enderror
                            <x-admin.button variant="ghost" wire:click="discard">Discard unsaved changes</x-admin.button>
                        @endif
                        <x-admin.button wire:click="save" class="w-full justify-center">{{ $estimateId !== null ? 'Save changes' : 'Save estimate' }}</x-admin.button>
                        @endif
                        @if ($estimateId !== null && $status !== 'converted')
                            <x-admin.button variant="secondary" wire:click="review" class="w-full justify-center">Mark reviewed</x-admin.button>
                            <x-admin.button variant="secondary" wire:click="convert" class="w-full justify-center" wire:confirm="Convert the saved snapshot to a draft? Contingency is disclosed internally, not automatically billed. Nothing is sent.">Convert to quotation</x-admin.button>
                        @endif
                        @if ($status === 'converted')
                            <p class="text-theme-sm text-gray-500 dark:text-gray-400">Converted — the quotation carries it from here.</p>
                        @endif
                    </div>
                </x-admin.card>
            @endcan
        </div>
    </div>
</div>
