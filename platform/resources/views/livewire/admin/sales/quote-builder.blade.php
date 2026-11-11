@use('App\Support\Money')
@use('App\Support\Phone')
@use('App\Support\NumberInput')
@php
    $contact = $quote->contact;
    $money = fn (int $minor) => Money::ofMinor($minor)->format($lakh);
    $ro = ! $editable;
    $planKey = $quote->milestonePlan()->value;
@endphp
<div x-data="quoteWorkbench({ url: '{{ route('admin.quotes.preview', $quote, false) }}', csrf: '{{ csrf_token() }}' })" x-on:input="queuePreview($event)" x-on:change="queuePreview($event)" x-on:preview-refresh.window="queuePreview()">
    <x-admin.page-header :title="$quote->label()" :breadcrumbs="['Sales' => null, 'Quotations' => route('admin.quotes.index'), $quote->label() => null]">
        <x-slot:actions>
            @if ($quote->business_line->value === 'furniture' && in_array($quote->status->value, ['sent', 'viewed', 'accepted'], true))
                @can('create', \App\Domain\Sales\Models\Invoice::class)
                    <x-admin.button variant="secondary" wire:click="createFullInvoice" wire:loading.attr="disabled" wire:confirm="Create or open the full Furniture invoice? It starts as a draft; no payment is assumed.">Create full invoice</x-admin.button>
                @endcan
            @endif
            @can('duplicate', $quote)
                <x-admin.button variant="secondary" icon="copy" wire:click="duplicate" wire:loading.attr="disabled">Duplicate</x-admin.button>
            @endcan
            @can('manageCatalog', \App\Domain\Sales\Models\Quote::class)
                <x-admin.button variant="secondary" icon="templates" wire:click="openSaveTemplate">Save as template</x-admin.button>
            @endcan
            <x-admin.button variant="secondary" icon="file" :href="route('admin.quotes.pdf', $quote)" x-on:click.prevent="$dispatch('document-preview', { url: $el.href })">{{ $sendable ? 'Preview PDF' : 'PDF' }}</x-admin.button>
            @if ($editable)<x-admin.button variant="ghost" wire:click="applyCurrentDesign" wire:confirm="Copy the latest saved brand design into this draft? Check the PDF before sending.">Apply current design</x-admin.button>@endif
            @if ($canRevise)
                <x-admin.button variant="secondary" icon="pencil" wire:click="revise"
                                wire:confirm="Start revision R{{ $quote->revision + 1 }}? The client keeps seeing {{ $quote->label() }} until you send the new version.">Revise</x-admin.button>
            @endif
            @if ($canSend && $sendable && $quote->status !== \App\Domain\Sales\Enums\QuoteStatus::PendingApproval)
                <x-admin.button icon="send" wire:click="openSend">Send</x-admin.button>
            @elseif ($portalUrl && $canSend)
                <x-admin.button icon="link" wire:click="$set('showSend', true)">Share link</x-admin.button>
            @endif
            @if ($editable && $quote->sent_at === null)
            @can('delete', $quote)
                <x-admin.button variant="secondary" icon="trash" wire:click="deleteQuote"
                                wire:confirm="Move draft {{ $quote->label() }} to the recycle bin? It can be restored." aria-label="Delete draft" />
            @endcan
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="-mt-3 mb-5 flex flex-wrap items-center gap-2 text-theme-sm text-gray-500">
        <x-admin.badge :color="$quote->status->color()">{{ $quote->status->label() }}</x-admin.badge>
        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $quote->title }}</span>
        <span class="text-gray-400">·</span>
        <span>{{ $contact->name }}</span>
        <span class="text-gray-400">·</span>
        <span>{{ $quote->business_line->label() }}</span>
        @if ($quote->valid_until)
            <span class="text-gray-400">·</span>
            <span>Valid until {{ $quote->valid_until->format('j M Y') }}</span>
        @endif
        <span class="text-gray-400">·</span>
        <span>Saved {{ $quote->updated_at?->diffForHumans() }}</span>
        <span wire:loading.delay class="inline-flex items-center gap-1 text-navy-700 dark:text-white"><x-icon name="refresh" class="size-3.5 animate-spin" /> Saving…</span>
    </div>

    @php $status = $quote->status; @endphp
    @if ($quote->acceptance_hint_at && in_array($status, [\App\Domain\Sales\Enums\QuoteStatus::Sent, \App\Domain\Sales\Enums\QuoteStatus::Viewed], true))
        <div class="mb-5 flex flex-col gap-3 rounded-xl border border-warning-500/30 bg-warning-50 px-4 py-3 text-theme-sm text-warning-800 md:flex-row md:items-center md:justify-between dark:bg-warning-500/10 dark:text-warning-300" role="status">
            <div>
                <p class="font-medium">{{ $contact->name }} may have accepted on WhatsApp · {{ $quote->acceptance_hint_at->diffForHumans() }}</p>
                <p>They replied: “{{ $quote->acceptanceHintMessage?->body }}”. {{ ($quote->meta['furniture_billing'] ?? '') === 'full' ? 'Accepting opens the project. Use Create full invoice to bill this sale.' : 'Accepting opens the project and raises the advance invoice.' }}</p>
            </div>
            <div class="flex shrink-0 gap-2">
                <x-admin.button variant="secondary" size="sm" wire:click="dismissAcceptanceHint">Not a yes</x-admin.button>
                <x-admin.button size="sm" icon="check-circle" wire:click="acceptFromHint"
                                wire:confirm="Accept {{ $quote->label() }}? {{ ($quote->meta['furniture_billing'] ?? '') === 'full' ? 'This opens the project; billing remains a separate Accounts action.' : 'This opens the project and raises the advance invoice.' }}">Accept quotation</x-admin.button>
            </div>
        </div>
        @error('acceptHint')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
    @endif
    @if ($status === \App\Domain\Sales\Enums\QuoteStatus::PendingApproval)
        <div class="mb-5 flex flex-col gap-3 rounded-xl border border-warning-500/30 bg-warning-50 px-4 py-3 text-theme-sm text-warning-800 md:flex-row md:items-center md:justify-between dark:bg-warning-500/10 dark:text-warning-300" role="status">
            <div>
                <p class="font-medium">Waiting for approval{{ $pendingApproval?->requester ? ' · requested by '.$pendingApproval->requester->name : '' }} {{ $pendingApproval?->created_at?->diffForHumans() }}</p>
                @foreach ((array) ($pendingApproval?->payload['reasons'] ?? []) as $reason)<p>{{ $reason }}</p>@endforeach
                @if ($pendingApproval?->reason)<p class="italic">“{{ $pendingApproval->reason }}”</p>@endif
            </div>
            <div class="flex shrink-0 gap-2">
                @can('revise', $quote)
                    <x-admin.button variant="secondary" size="sm" wire:click="reopen">Withdraw</x-admin.button>
                @endcan
                @if ($canApprove)
                    <x-admin.button variant="secondary" size="sm" wire:click="openReject">Decline</x-admin.button>
                    <x-admin.button size="sm" icon="check" wire:click="approve" wire:loading.attr="disabled">Approve</x-admin.button>
                @endif
            </div>
        </div>
    @elseif ($status === \App\Domain\Sales\Enums\QuoteStatus::Approved)
        <div class="mb-5 flex flex-col gap-3 rounded-xl border border-success-500/30 bg-success-50 px-4 py-3 text-theme-sm text-success-800 md:flex-row md:items-center md:justify-between dark:bg-success-500/10 dark:text-success-300" role="status">
            <p>Approved {{ $quote->approved_at?->diffForHumans() }}: ready to send. Changing it needs a new approval.</p>
            @can('revise', $quote)
                <x-admin.button variant="secondary" size="sm" icon="pencil" wire:click="reopen">Edit again</x-admin.button>
            @endcan
        </div>
    @elseif ($status === \App\Domain\Sales\Enums\QuoteStatus::Accepted)
        <div class="mb-5 rounded-xl border border-success-500/30 bg-success-50 px-4 py-3 text-theme-sm text-success-800 dark:bg-success-500/10 dark:text-success-300" role="status">
            Accepted online by <strong>{{ $quote->accepted_name }}</strong> on {{ $quote->accepted_at?->timezone('Asia/Karachi')->format('j M Y, g:i a') }}. {{ ($quote->meta['furniture_billing'] ?? '') === 'full' ? 'Use Create full invoice to bill this Furniture sale.' : 'The advance invoice was raised with the project.' }}
        </div>
    @elseif ($status === \App\Domain\Sales\Enums\QuoteStatus::ChangesRequested && $clientComment)
        <div class="mb-5 rounded-xl border border-warning-500/30 bg-warning-50 px-4 py-3 text-theme-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300" role="status">
            <p class="font-medium">The client asked for changes:</p>
            <p class="italic">“{{ $clientComment }}”</p>
            <p class="mt-1">Edit the lines below, then Send: the client gets the next revision on the same link.</p>
        </div>
    @elseif ($ro)
        <div class="mb-5 rounded-xl border border-info-500/30 bg-info-50 px-4 py-3 text-theme-sm text-info-700 dark:bg-info-500/10">
            {{ $quote->isEditable() ? 'You can view this quotation but not change it.' : "This quotation is {$quote->status->label()} and can no longer be edited.".($canRevise ? ' Use “Revise” to prepare a changed version.' : '') }}
        </div>
    @endif

    <div class="quotation-workbench">
    <div class="quotation-editor grid grid-cols-1 gap-6">
        {{-- Main column: scope, sections and lines, totals --}}
        <div class="min-w-0 space-y-5">
            <x-admin.card title="Scope of work" description="Printed before the price list. Blank line = new paragraph; start lines with “-” for bullets.">
                <textarea id="details-scope" wire:model.live.blur="details.scope" rows="3" @disabled($ro)
                          x-data x-init="$nextTick(() => { $el.style.height = Math.max($el.scrollHeight, 76) + 'px' })"
                          x-on:input="$el.style.height = 'auto'; $el.style.height = Math.max($el.scrollHeight, 76) + 'px'"
                          class="wx-input h-auto min-h-[76px] py-2.5" placeholder="- Site measurement and drawings&#10;- Supply and installation as per BOQ"></textarea>
                @error('details.scope')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                <div class="mt-4 max-w-sm border-t border-gray-100 pt-4 dark:border-gray-800">
                    @if (($quote->meta['document_design']['furniture_revision'] ?? 1) < 2)
                    <x-admin.field label="Signed by" for="details-signed_by" hint="Printed on the quotation PDF. The company signatories are set in Sales settings.">
                        <select id="details-signed_by" wire:model.live="details.signed_by" @disabled($ro) class="wx-input">
                            <option value="">Company signatories (from Settings)</option>
                            @foreach ($signatoryOptions as $index => $signatory)
                                <option value="{{ $index }}">{{ $signatory['name'] }}@if ($signatory['title'] !== '') — {{ $signatory['title'] }}@endif</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    @endif
                    @error('details.signed_by')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                </div>
            </x-admin.card>

            <div class="space-y-5" data-quote-sections>
                @foreach ($quote->sections as $section)
                    <section wire:key="section-{{ $section->id }}" data-section-id="{{ $section->id }}"
                             class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                        <header class="flex items-start gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            @unless ($ro)
                                <button type="button" data-section-handle class="mt-2 hidden cursor-grab text-gray-300 hover:text-gray-500 sm:block" aria-label="Drag to reorder section" title="Drag to reorder">
                                    <x-icon name="grip" class="size-4" />
                                </button>
                            @endunless
                            <div class="min-w-0 flex-1">
                                <label for="section-{{ $section->id }}-name" class="sr-only">Section name</label>
                                <input id="section-{{ $section->id }}-name" type="text" wire:model.live.blur="sections.{{ $section->id }}.name" @disabled($ro)
                                       class="w-full rounded-md border border-transparent bg-transparent px-1.5 py-1 text-theme-lg font-medium text-gray-900 hover:border-gray-200 focus:border-navy-300 focus:outline-hidden dark:text-white dark:hover:border-gray-700">
                                <label for="section-{{ $section->id }}-note" class="sr-only">Section note</label>
                                <input id="section-{{ $section->id }}-note" type="text" wire:model.live.blur="sections.{{ $section->id }}.note" @disabled($ro)
                                       placeholder="{{ $ro ? '' : 'Note for this section (optional)' }}"
                                       class="w-full rounded-md border border-transparent bg-transparent px-1.5 py-0.5 text-theme-xs text-gray-500 hover:border-gray-200 focus:border-navy-300 focus:outline-hidden dark:hover:border-gray-700">
                                @error("sections.{$section->id}.name")<p class="px-1.5 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="shrink-0 pt-1.5 text-right">
                                <p class="text-theme-xs text-gray-400">Subtotal</p>
                                <p class="font-medium tabular-nums text-gray-900 dark:text-white">{{ $money($section->subtotal_minor) }}</p>
                            </div>
                            @unless ($ro)
                                <div class="relative shrink-0 pt-1" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                                    <button type="button" x-on:click="open = ! open" class="flex size-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5" aria-label="Section actions" x-bind:aria-expanded="open">
                                        <x-icon name="more" class="size-4" />
                                    </button>
                                    <div x-show="open" x-cloak x-transition.opacity class="absolute right-0 z-30 mt-1 w-48 rounded-xl border border-gray-200 bg-white p-1.5 text-theme-sm shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                                        <button type="button" wire:click="shiftSection({{ $section->id }}, -1)" x-on:click="open = false" @disabled($loop->first) class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left hover:bg-gray-50 disabled:opacity-40 dark:hover:bg-white/5"><x-icon name="chevron-left" class="size-4 rotate-90" /> Move up</button>
                                        <button type="button" wire:click="shiftSection({{ $section->id }}, 1)" x-on:click="open = false" @disabled($loop->last) class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left hover:bg-gray-50 disabled:opacity-40 dark:hover:bg-white/5"><x-icon name="chevron-down" class="size-4" /> Move down</button>
                                        <button type="button" wire:click="deleteSection({{ $section->id }})" wire:confirm="Delete the section “{{ $section->name }}” and its {{ $section->items->count() }} lines?" x-on:click="open = false" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-error-600 hover:bg-error-50 dark:hover:bg-error-500/10"><x-icon name="trash" class="size-4" /> Delete section</button>
                                    </div>
                                </div>
                            @endunless
                        </header>

                        <div data-quote-lines data-section-id="{{ $section->id }}" class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($section->items as $item)
                                @php
                                    $line = $lines[$item->id] ?? null;
                                    $pending = ! $item->is_optional && ((float) $item->qty <= 0 || $item->rate_minor <= 0);
                                @endphp
                                @if ($line !== null)
                                    <div wire:key="line-{{ $item->id }}" data-item-id="{{ $item->id }}" @class(['flex gap-2 px-4 py-3', 'bg-gray-50/70 dark:bg-white/[0.02]' => $item->is_optional])>
                                        @unless ($ro)
                                            <button type="button" data-line-handle class="mt-2.5 hidden shrink-0 cursor-grab text-gray-300 hover:text-gray-500 sm:block" aria-label="Drag to reorder line" title="Drag to reorder">
                                                <x-icon name="grip" class="size-4" />
                                            </button>
                                        @endunless
                                        @if ($item->image_path)
                                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item->image_path) }}" alt="" loading="lazy" class="mt-0.5 size-11 shrink-0 rounded-md object-cover">
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start gap-2">
                                                <div class="min-w-0 flex-1">
                                                    @if ($quote->business_line->value === 'furniture')
                                                        <label for="line-{{ $item->id }}-product" class="sr-only">Product name</label>
                                                        <input id="line-{{ $item->id }}-product" wire:model.live.blur="lines.{{ $item->id }}.product_name" @disabled($ro) maxlength="160" placeholder="Product name · workstation, chair, sofa" class="wx-input mb-2" aria-label="Product name">
                                                        @error('lines.'.$item->id.'.product_name')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror
                                                        <p class="text-theme-xs text-gray-500">Description / material specification</p>
                                                    @endif
                                                    <label for="line-{{ $item->id }}-description" class="sr-only">Description</label>
                                                    <textarea id="line-{{ $item->id }}-description" wire:model.live.blur="lines.{{ $item->id }}.description" rows="1" @disabled($ro)
                                                              x-data x-init="$nextTick(() => { $el.style.height = $el.scrollHeight + 'px' })"
                                                              x-on:input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                                                              placeholder="Describe the work or item"
                                                              class="block w-full resize-none overflow-hidden rounded-lg border border-transparent bg-transparent px-2 py-1.5 text-theme-sm text-gray-800 hover:border-gray-200 focus:border-navy-300 focus:bg-white focus:outline-hidden dark:text-white/90 dark:hover:border-gray-700 dark:focus:bg-gray-900"></textarea>
                                                </div>
                                                <div class="shrink-0 pt-1.5 text-right">
                                                    <p @class(['font-medium tabular-nums', 'text-gray-900 dark:text-white' => ! $item->is_optional, 'text-gray-400 line-through decoration-gray-300' => $item->is_optional])>{{ $money($item->line_total_minor) }}</p>
                                                    @if ($item->discount_minor > 0)
                                                        <p class="text-theme-xs text-success-700">−{{ $money($item->discount_minor) }}</p>
                                                    @endif
                                                </div>
                                                @unless ($ro)
                                                    <div class="relative shrink-0" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                                                        <button type="button" x-on:click="open = ! open" class="mt-0.5 flex size-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5" aria-label="Line actions" x-bind:aria-expanded="open">
                                                            <x-icon name="more" class="size-4" />
                                                        </button>
                                                        <div x-show="open" x-cloak x-transition.opacity class="absolute right-0 z-30 mt-1 w-64 rounded-xl border border-gray-200 bg-white p-3 text-theme-sm shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                                                            <label class="flex items-center gap-2">
                                                                <input type="checkbox" wire:model.live="lines.{{ $item->id }}.is_optional" class="size-4 rounded border-gray-300 text-navy-900">
                                                                <span>Optional line <span class="block text-theme-xs text-gray-500">Shown to the client, not added to the total</span></span>
                                                            </label>
                                                            <label for="line-{{ $item->id }}-cost" class="mt-3 block text-theme-xs font-medium text-gray-600 dark:text-gray-300">Internal cost per unit (staff only)</label>
                                                            <div class="mt-1 flex items-center rounded-lg border border-gray-300 focus-within:border-navy-300 dark:border-gray-700">
                                                                <span class="pl-2.5 text-theme-xs text-gray-400">Rs</span>
                                                                <input id="line-{{ $item->id }}-cost" type="text" inputmode="decimal" wire:model.live.blur="lines.{{ $item->id }}.internal_cost" placeholder="—"
                                                                       class="h-9 w-full rounded-lg border-0 bg-transparent px-2 text-theme-sm tabular-nums focus:outline-hidden">
                                                            </div>
                                                            @error("lines.{$item->id}.internal_cost")<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                                                            <div class="mt-3 grid grid-cols-2 gap-1.5 border-t border-gray-100 pt-3 dark:border-gray-800">
                                                                <button type="button" wire:click="shiftLine({{ $item->id }}, -1)" x-on:click="open = false" @disabled($loop->first) class="rounded-lg px-2 py-1.5 text-left hover:bg-gray-50 disabled:opacity-40 dark:hover:bg-white/5">Move up</button>
                                                                <button type="button" wire:click="shiftLine({{ $item->id }}, 1)" x-on:click="open = false" @disabled($loop->last) class="rounded-lg px-2 py-1.5 text-left hover:bg-gray-50 disabled:opacity-40 dark:hover:bg-white/5">Move down</button>
                                                                <button type="button" wire:click="deleteLine({{ $item->id }})" x-on:click="open = false" class="col-span-2 flex items-center gap-2 rounded-lg px-2 py-1.5 text-left text-error-600 hover:bg-error-50 dark:hover:bg-error-500/10"><x-icon name="trash" class="size-4" /> Delete line</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endunless
                                            </div>

                                            <div class="mt-1.5 flex flex-wrap items-center gap-2 pl-0.5">
                                                <label class="flex h-9 items-center rounded-lg border border-gray-300 bg-white focus-within:border-navy-300 dark:border-gray-700 dark:bg-gray-900">
                                                    <span class="pl-2.5 text-theme-xs text-gray-400">Qty</span>
                                                    <input type="text" inputmode="decimal" wire:model.live.blur="lines.{{ $item->id }}.qty" @disabled($ro) aria-label="Quantity"
                                                           x-on:keydown.enter.prevent="$el.blur(); $nextTick(() => $wire.addLine({{ $section->id }}, {{ $item->id }}))"
                                                           class="h-full w-20 rounded-lg border-0 bg-transparent px-2 text-right text-theme-sm tabular-nums focus:outline-hidden">
                                                </label>
                                                <select wire:model.live="lines.{{ $item->id }}.unit" @disabled($ro) aria-label="Unit"
                                                        class="h-9 rounded-lg border border-gray-300 bg-white px-2 text-theme-sm text-gray-700 focus:border-navy-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                                    @foreach ($units as $value => $label)
                                                        <option value="{{ $value }}">{{ $value }}</option>
                                                    @endforeach
                                                </select>
                                                <label class="flex h-9 items-center rounded-lg border border-gray-300 bg-white focus-within:border-navy-300 dark:border-gray-700 dark:bg-gray-900">
                                                    <span class="pl-2.5 text-theme-xs text-gray-400">Rs</span>
                                                    <input type="text" inputmode="decimal" wire:model.live.blur="lines.{{ $item->id }}.rate" @disabled($ro) aria-label="Rate in rupees"
                                                           x-on:keydown.enter.prevent="$el.blur(); $nextTick(() => $wire.addLine({{ $section->id }}, {{ $item->id }}))"
                                                           class="h-full w-24 rounded-lg border-0 bg-transparent px-2 text-right text-theme-sm tabular-nums focus:outline-hidden">
                                                    <span class="pr-2.5 text-theme-xs text-gray-400">/{{ $item->unit }}</span>
                                                </label>
                                                <div class="flex h-9 items-center rounded-lg border border-gray-300 bg-white focus-within:border-navy-300 dark:border-gray-700 dark:bg-gray-900">
                                                    <select wire:model.live="lines.{{ $item->id }}.discount_type" @disabled($ro) aria-label="Line discount type"
                                                            class="h-full rounded-l-lg border-0 bg-transparent pr-1 pl-2.5 text-theme-xs text-gray-500 focus:outline-hidden">
                                                        <option value="">No discount</option>
                                                        <option value="percent">Discount %</option>
                                                        <option value="amount">Discount Rs</option>
                                                    </select>
                                                    @if ($line['discount_type'] !== '')
                                                        <input type="text" inputmode="decimal" wire:model.live.blur="lines.{{ $item->id }}.discount_value" @disabled($ro) aria-label="Line discount"
                                                               x-on:keydown.enter.prevent="$el.blur(); $nextTick(() => $wire.addLine({{ $section->id }}, {{ $item->id }}))"
                                                               class="h-full w-20 rounded-r-lg border-0 border-l border-gray-200 bg-transparent px-2 text-right text-theme-sm tabular-nums focus:outline-hidden dark:border-gray-700">
                                                    @endif
                                                </div>
                                                @if ($item->is_optional)
                                                    <x-admin.badge color="gray" :dot="false">Optional</x-admin.badge>
                                                @endif
                                                @if ($pending)
                                                    <span class="inline-flex items-center gap-1 text-theme-xs text-warning-700"><span class="size-1.5 rounded-full bg-warning-500"></span>Needs {{ (float) $item->qty <= 0 ? 'quantity' : 'rate' }}</span>
                                                @endif
                                            </div>
                                            @foreach (['qty', 'rate', 'unit', 'discount_value', 'description'] as $field)
                                                @error("lines.{$item->id}.{$field}")<p class="mt-1 pl-0.5 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        @if ($section->items->isEmpty())
                            <p class="px-5 py-4 text-theme-sm text-gray-500">No lines in this section yet.</p>
                        @endif

                        @unless ($ro)
                            <footer class="flex flex-wrap gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                                <x-admin.button size="sm" variant="ghost" icon="plus" wire:click="addLine({{ $section->id }})">Add line</x-admin.button>
                                <x-admin.button size="sm" variant="ghost" icon="book" wire:click="openCatalog({{ $section->id }})">From rate book</x-admin.button>
                                <x-admin.button size="sm" variant="ghost" icon="templates" wire:click="openDescriptions({{ $section->id }})">Description library</x-admin.button>
                            </footer>
                        @endunless
                    </section>
                @endforeach
            </div>

            @if ($quote->sections->isEmpty())
                <x-admin.card>
                    <x-admin.empty icon="quote" title="No sections yet" description="Add a section such as Civil work, Wood work or Furniture, then add its lines." />
                </x-admin.card>
            @endif

            @unless ($ro)
                <button type="button" wire:click="addSection" class="flex w-full items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-300 px-4 py-4 text-theme-sm font-medium text-gray-600 hover:border-navy-300 hover:bg-navy-50/40 hover:text-navy-800 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/5">
                    <x-icon name="plus" class="size-4" /> Add section
                </button>
            @endunless

            {{-- Totals bar (QUO-004): always the server's numbers --}}
            <div class="sticky bottom-3 z-20 rounded-2xl border border-gray-200 bg-white/95 px-4 py-3 shadow-theme-lg backdrop-blur sm:p-4 dark:border-gray-800 dark:bg-gray-900/95" data-quote-totals>
                <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                    {{-- Phones show the total only; the breakdown starts at the sm breakpoint. --}}
                    <dl class="hidden flex-wrap gap-x-6 gap-y-2 text-theme-sm sm:flex">
                        <div><dt class="text-theme-xs text-gray-500">Subtotal</dt><dd class="font-medium tabular-nums">{{ $money($quote->subtotal_minor) }}</dd></div>
                        @if ($quote->discount_minor > 0)
                            <div><dt class="text-theme-xs text-gray-500">Discount</dt><dd class="font-medium tabular-nums text-success-700">−{{ $money($quote->discount_minor) }}</dd></div>
                        @endif
                        @if ($quote->other_charges_minor > 0)
                            <div><dt class="text-theme-xs text-gray-500">{{ $quote->other_charges_label ?: 'Other charges' }}</dt><dd class="font-medium tabular-nums">{{ $money($quote->other_charges_minor) }}</dd></div>
                        @endif
                        <div>
                            <dt class="text-theme-xs text-gray-500">Tax</dt>
                            <dd class="font-medium tabular-nums">{{ $quote->tax_bp > 0 ? $money($quote->tax_minor).' ('.NumberInput::fromBp($quote->tax_bp).'%)' : 'Exclusive of taxes' }}</dd>
                        </div>
                        @if ($quote->margin_bp !== null)
                            <div title="From internal costs. Never shown to the client.">
                                <dt class="text-theme-xs text-gray-500">Margin (staff)</dt>
                                <dd @class(['font-medium tabular-nums', 'text-error-600' => $quote->margin_bp < 0])>{{ NumberInput::fromBp($quote->margin_bp) }}%</dd>
                            </div>
                        @endif
                    </dl>
                    <div class="flex w-full items-baseline justify-between gap-3 sm:block sm:w-auto sm:text-right">
                        <p class="text-theme-xs text-gray-500">Total{{ $quote->rounding_minor !== 0 ? ' (rounded)' : '' }}{{ $quote->tax_bp > 0 ? '' : ' · excl. taxes' }}</p>
                        <p class="font-heading text-2xl leading-none text-gray-900 tabular-nums sm:text-title-sm dark:text-white" data-quote-total>{{ $money($quote->total_minor) }}</p>
                    </div>
                </div>
                @if ($needsPricing > 0 || $milestoneTotal !== 10000)
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 border-t border-gray-100 pt-2 text-theme-xs text-warning-700 sm:mt-3 sm:pt-3 dark:border-gray-800">
                        @if ($needsPricing > 0)
                            <span>● {{ $needsPricing }} of {{ $lineCount }} lines still need a quantity or rate</span>
                        @endif
                        @if ($milestoneTotal !== 10000)
                            <span>● Payment milestones add up to {{ NumberInput::fromBp($milestoneTotal) }}%, not 100%</span>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Side panel --}}
        <div class="space-y-5">
            @if ($portalUrl)
                <x-admin.card title="Client link">
                    <x-admin.quote-share-links :portal-url="$portalUrl" :pdf-url="$sharedPdfUrl" :whatsapp-share="$whatsappShare" />
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-theme-sm">
                        <div><dt class="text-theme-xs text-gray-500">Views</dt><dd class="font-medium">{{ $quote->view_count }}</dd></div>
                        <div><dt class="text-theme-xs text-gray-500">Last opened</dt><dd>{{ $quote->last_viewed_at?->diffForHumans() ?? 'not yet' }}</dd></div>
                        <div><dt class="text-theme-xs text-gray-500">First opened</dt><dd>{{ $quote->first_viewed_at?->timezone('Asia/Karachi')->format('j M, g:i a') ?? '—' }}</dd></div>
                        <div><dt class="text-theme-xs text-gray-500">Sent</dt><dd>{{ $quote->sent_at?->timezone('Asia/Karachi')->format('j M, g:i a') ?? '—' }}</dd></div>
                    </dl>
                </x-admin.card>

                <x-admin.card title="Sent versions" :padding="false">
                    @foreach ($versions as $version)
                        <div wire:key="version-{{ $version->id }}" class="border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium text-gray-800 dark:text-white/90">{{ $version->snapshot['label'] ?? $quote->number }}</span>
                                <a href="{{ route('admin.quotes.pdf', ['quote' => $quote, 'revision' => $version->revision]) }}" x-on:click.prevent="$dispatch('document-preview', { url: $el.href })" class="inline-flex items-center gap-1 text-theme-xs text-navy-700 hover:underline dark:text-gray-300"><x-icon name="file" class="size-3.5" /> PDF</a>
                            </div>
                            <p class="text-theme-xs text-gray-500">{{ Money::ofMinor($version->total_minor)->format($lakh) }} · {{ $version->sent_at?->timezone('Asia/Karachi')->format('j M Y, g:i a') }}{{ $version->creator ? ' · '.$version->creator->name : '' }}</p>
                            @if (isset($diffs[$version->id]))
                                <ul class="mt-1.5 list-disc space-y-0.5 pl-4 text-theme-xs text-gray-600 dark:text-gray-400">
                                    @foreach ($diffs[$version->id] as $change)<li>{{ $change }}</li>@endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </x-admin.card>
            @endif

            <x-admin.card title="Client">
                <a href="{{ route('admin.contacts.show', $contact) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $contact->name }}</a>
                @if ($quote->company)
                    <p class="text-theme-sm text-gray-500">{{ $quote->company->name }}</p>
                @endif
                @if ($contact->phone)
                    <p class="mt-2 flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-400">
                        {{ Phone::display($contact->phone) }}
                        <a href="{{ Phone::telUrl($contact->phone) }}" class="text-gray-400 hover:text-navy-800" aria-label="Call"><x-icon name="phone" class="size-4" /></a>
                        @if ($wa = Phone::whatsappUrl($contact->whatsappNumber(), "Assalam o Alaikum {$contact->firstName()}, this is regarding your quotation {$quote->label()} from Woodex Interior."))
                            <a href="{{ $wa }}" target="_blank" rel="noopener" class="text-success-600 hover:text-success-700" aria-label="WhatsApp"><x-icon name="whatsapp" class="size-4" /></a>
                        @endif
                    </p>
                @endif
                @if ($quote->lead)
                    <a href="{{ route('admin.leads.show', $quote->lead) }}" wire:navigate class="mt-3 flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-theme-sm hover:bg-gray-100 dark:bg-white/5 dark:hover:bg-white/10">
                        <span>Enquiry {{ $quote->lead->ref }}</span>
                        <x-admin.badge :color="$quote->lead->stage->badgeColor()">{{ $quote->lead->stage->name }}</x-admin.badge>
                    </a>
                @endif
                <div class="mt-4 space-y-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <p class="text-theme-xs font-medium text-gray-500">As printed on this quotation <span class="font-normal">(blank a field to use the contact's own details)</span></p>
                    <x-admin.field label="Client name" for="client.name">
                        <input id="client.name" type="text" wire:model.live.blur="client.name" @disabled($ro) class="wx-input">
                    </x-admin.field>
                    <x-admin.field label="Company" for="client.company">
                        <input id="client.company" type="text" wire:model.live.blur="client.company" @disabled($ro) class="wx-input">
                    </x-admin.field>
                    <x-admin.field label="Phone" for="client.phone">
                        <input id="client.phone" type="text" wire:model.live.blur="client.phone" @disabled($ro) class="wx-input">
                    </x-admin.field>
                    <x-admin.field label="Email" for="client.email">
                        <input id="client.email" type="text" wire:model.live.blur="client.email" @disabled($ro) class="wx-input">
                    </x-admin.field>
                    <x-admin.field label="Client address" for="client.address">
                        <input id="client.address" type="text" wire:model.live.blur="client.address" @disabled($ro) class="wx-input">
                    </x-admin.field>
                </div>
            </x-admin.card>

            <x-admin.card title="Details">
                <div class="space-y-4">
                    <x-admin.field label="Title" for="details.title">
                        <input id="details.title" type="text" wire:model.live.blur="details.title" @disabled($ro) class="wx-input">
                    </x-admin.field>
                    <x-admin.field label="Valid until" for="details.valid_until" hint="Default {{ app(\App\Settings\SalesSettings::class)->quote_validity_days }} days from today.">
                        <input id="details.valid_until" type="date" wire:model.live.blur="details.valid_until" @disabled($ro) class="wx-input">
                    </x-admin.field>
                    <x-admin.field label="Type" for="details.type" hint="Printed in the project details box.">
                        <input id="details.type" type="text" list="quote-types" wire:model.live.blur="details.type" @disabled($ro) placeholder="e.g. Renovation" class="wx-input">
                        <datalist id="quote-types">
                            @foreach (['Renovation', 'Civil Work', 'Interior Designing', 'Interior', 'Fit-out', 'Architecture'] as $typeOption)
                                <option value="{{ $typeOption }}"></option>
                            @endforeach
                        </datalist>
                    </x-admin.field>
                    <x-admin.field label="Site" for="details.site">
                        <input id="details.site" type="text" wire:model.live.blur="details.site" @disabled($ro) placeholder="e.g. DHA Phase 6, Lahore" class="wx-input">
                    </x-admin.field>
                    <x-admin.field label="Intro line on the summary page" for="details.intro_line" hint="Printed above the summary of cost. Optional.">
                        <textarea id="details.intro_line" rows="2" wire:model.live.blur="details.intro_line" @disabled($ro) placeholder="Thank you for the opportunity. Please find below our quotation for the renovation work." class="wx-input"></textarea>
                    </x-admin.field>
                    <dl class="grid grid-cols-2 gap-3 text-theme-sm">
                        <div><dt class="text-theme-xs text-gray-500">Number</dt><dd>{{ $quote->label() }}</dd></div>
                        <div><dt class="text-theme-xs text-gray-500">Line</dt><dd>{{ $quote->business_line->label() }}</dd></div>
                        <div><dt class="text-theme-xs text-gray-500">Template</dt><dd>{{ $quote->template->name ?? 'Blank' }}</dd></div>
                        <div><dt class="text-theme-xs text-gray-500">Owner</dt><dd>{{ $quote->owner->name ?? '—' }}</dd></div>
                    </dl>
                </div>
            </x-admin.card>

            <x-admin.card title="PDF design" description="For this quotation only. Brand defaults live in Document design.">
                <div class="space-y-4">
                    <p class="text-theme-xs text-gray-500">Choose the v2.1 layout and colour design above the live preview. Below, customise the blocks for this quotation only.</p>
                    <div>
                        <div class="flex items-center justify-between">
                            <span class="wx-label">PDF blocks</span>
                            <button type="button" wire:click="resetQuoteBlocks" @disabled($ro) class="text-theme-xs font-medium text-gray-500 hover:text-gray-900 disabled:opacity-40 dark:hover:text-white">Reset order</button>
                        </div>
                        <p class="mt-1 text-theme-xs text-gray-500">Click Show / Hide. Hidden content is retained. Furniture amounts and closing sections stay together above the final footer; arrows reorder blocks within the main / closing sections.</p>
                        @if (!$flexibleBlocks && !$ro)<p class="mt-2 text-theme-xs text-amber-700">Legacy draft: click Apply current design to enable every block switch without altering old sent versions.</p>@endif
                        <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
                            @foreach ($quoteBlocks as $i => $block)
                                @php $locked = in_array($block['key'], $lockedBlocks, true); @endphp
                                <li class="flex items-center gap-2 px-2.5 py-1.5" wire:key="quote-block-{{ $block['key'] }}">
                                    <span class="flex flex-col leading-none">
                                        <button type="button" wire:click="moveQuoteBlock({{ $i }}, -1)" @disabled($ro || $loop->first) class="px-1 text-gray-400 hover:text-gray-900 disabled:opacity-30 dark:hover:text-white" aria-label="Move {{ $blockLabels[$block['key']] }} up">↑</button>
                                        <button type="button" wire:click="moveQuoteBlock({{ $i }}, 1)" @disabled($ro || $loop->last) class="px-1 text-gray-400 hover:text-gray-900 disabled:opacity-30 dark:hover:text-white" aria-label="Move {{ $blockLabels[$block['key']] }} down">↓</button>
                                    </span>
                                    <span class="flex-1 text-theme-sm {{ $block['visible'] ? 'text-gray-900 dark:text-white' : 'text-gray-400 line-through' }}">{{ $blockLabels[$block['key']] }}</span>
                                    <button type="button" wire:click="toggleQuoteBlock({{ $i }})" @disabled($ro || $locked) aria-label="Toggle {{ $blockLabels[$block['key']] }}" aria-pressed="{{ $block['visible'] ? 'true' : 'false' }}" class="rounded-md border border-gray-200 px-3 py-1 text-theme-xs font-medium disabled:opacity-40 dark:border-gray-700">{{ $block['visible'] ? 'Show' : 'Hide' }}</button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </x-admin.card>

            @if ($quote->business_line->value === 'furniture')
            <x-admin.card title="Furniture quotation details" description="Advance is a payment request, never a payment received. Full invoices are created explicitly from this quotation.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach (['document_title' => 'Printed document heading', 'delivery_text' => 'Delivery time', 'po_reference' => 'PO / client reference', 'transport_text' => 'Transportation note', 'tax_note' => 'Tax note'] as $field => $label)
                        <x-admin.field :label="$label" :for="'details.'.$field" :error="'details.'.$field"><input id="details.{{ $field }}" wire:model.live.blur="details.{{ $field }}" @disabled($ro) class="wx-input" maxlength="{{ $field === 'document_title' ? 60 : 300 }}"></x-admin.field>
                    @endforeach
                    @if ((int) $quote->shipping_minor !== 0)
                    <p class="text-theme-xs text-gray-500">Previous transport charge is preserved and included in the combined Rent / Transport total. Set it to zero only when intentionally replacing it.</p>
                    <x-admin.field label="Previous transport charge (Rs)" for="details.shipping" error="details.shipping"><input id="details.shipping" wire:model.live.blur="details.shipping" @disabled($ro) class="wx-input" inputmode="decimal"></x-admin.field>
                    @endif
                    <x-admin.field label="Advance requested (%)" for="details.advance" error="details.advance"><input id="details.advance" wire:model.live.blur="details.advance" @disabled($ro) class="wx-input" inputmode="decimal"></x-admin.field>
                </div>
                <p class="mt-3 text-theme-xs text-gray-500">Rent / Transport is entered below. The advance sentence in Terms and conditions is generated from this percentage. Tax applies after discounts and charges. Changing the heading does not create an accounting invoice.</p>
            </x-admin.card>
            @endif
            <x-admin.card title="Discount, charges and tax">
                <div class="space-y-4">
                    <x-admin.field label="Discount on the subtotal" error="details.discount_value">
                        <div class="flex gap-2">
                            <select wire:model.live="details.discount_type" @disabled($ro) class="wx-input w-36 shrink-0" aria-label="Discount type">
                                <option value="">None</option>
                                <option value="percent">Percent</option>
                                <option value="amount">Amount (Rs)</option>
                            </select>
                            @if (($details['discount_type'] ?? '') !== '')
                                <input type="text" inputmode="decimal" wire:model.live.blur="details.discount_value" @disabled($ro) aria-label="Discount value"
                                       placeholder="{{ $details['discount_type'] === 'percent' ? 'e.g. 5' : 'e.g. 25000' }}" class="wx-input text-right tabular-nums">
                            @endif
                        </div>
                    </x-admin.field>
                    <x-admin.field :label="$quote->business_line->value === 'furniture' ? 'Rent / Transport (Rs)' : 'Other charges'" error="details.other_charges" hint="Rent, transport or scaffolding; added after the discount, before tax.">
                        <div class="flex gap-2">
                            <input type="text" wire:model.live.blur="details.other_charges_label" @disabled($ro) placeholder="{{ $quote->business_line->value === 'furniture' ? 'Rent / Transport' : 'Label, e.g. Transport' }}" aria-label="Other charges label" class="wx-input">
                            <input type="text" inputmode="decimal" wire:model.live.blur="details.other_charges" @disabled($ro) placeholder="Rs" aria-label="Other charges amount" class="wx-input w-32 shrink-0 text-right tabular-nums">
                        </div>
                    </x-admin.field>
                    <x-admin.field label="Tax %" for="details.tax" hint="Leave empty for “exclusive of taxes”.">
                        <input id="details.tax" type="text" inputmode="decimal" wire:model.live.blur="details.tax" @disabled($ro) placeholder="0" class="wx-input text-right tabular-nums">
                    </x-admin.field>
                </div>
            </x-admin.card>

            @if (($quote->meta['furniture_billing'] ?? '') !== 'full')
            <x-admin.card title="Payment milestones">
                <x-slot:actions>
                    <select wire:model.live="plan" @disabled($ro) class="h-8 rounded-lg border border-gray-300 bg-white px-2 text-theme-xs dark:border-gray-700 dark:bg-gray-900" aria-label="Milestone plan">
                        @foreach ($plans as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-slot:actions>
                <ol class="space-y-2.5">
                    @foreach ($quote->milestones as $milestone)
                        <li wire:key="milestone-{{ $milestone->id }}" class="rounded-xl border border-gray-100 p-2.5 dark:border-gray-800">
                            <div class="flex items-center gap-2">
                                <input type="text" wire:model.live.blur="milestones.{{ $milestone->id }}.name" @disabled($ro) aria-label="Milestone name"
                                       class="min-w-0 flex-1 rounded-md border border-transparent bg-transparent px-1.5 py-1 text-theme-sm hover:border-gray-200 focus:border-navy-300 focus:outline-hidden dark:hover:border-gray-700">
                                <label class="flex h-8 items-center rounded-lg border border-gray-300 dark:border-gray-700">
                                    <input type="text" inputmode="decimal" wire:model.live.blur="milestones.{{ $milestone->id }}.percent" @disabled($ro) aria-label="Milestone percent"
                                           class="h-full w-12 rounded-l-lg border-0 bg-transparent px-1.5 text-right text-theme-sm tabular-nums focus:outline-hidden">
                                    <span class="pr-2 text-theme-xs text-gray-400">%</span>
                                </label>
                                @if (! $ro && $quote->milestones->count() > 1)
                                    <button type="button" wire:click="deleteMilestone({{ $milestone->id }})" class="text-gray-300 hover:text-error-600" aria-label="Remove milestone"><x-icon name="x" class="size-4" /></button>
                                @endif
                            </div>
                            <p class="mt-1 px-1.5 text-right text-theme-xs text-gray-500 tabular-nums">{{ $money($milestone->amount_minor) }}</p>
                            @error("milestones.{$milestone->id}.percent")<p class="px-1.5 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                            @error("milestones.{$milestone->id}.name")<p class="px-1.5 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                        </li>
                    @endforeach
                </ol>
                <div class="mt-3 flex items-center justify-between text-theme-xs">
                    <span @class(['text-gray-500' => $milestoneTotal === 10000, 'font-medium text-warning-700' => $milestoneTotal !== 10000])>Total {{ NumberInput::fromBp($milestoneTotal) }}%</span>
                    @unless ($ro)
                        <button type="button" wire:click="addMilestone" class="font-medium text-navy-800 hover:underline dark:text-white">+ Add milestone</button>
                    @endunless
                </div>
            </x-admin.card>
            @endif

            {{-- What acceptance produced: the project that carries the work and the invoices that ask for payment --}}
            @if ($project || $invoices->isNotEmpty())
                <x-admin.card title="Project and invoices" :padding="false">
                    @if ($project)
                        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('admin.projects.show', $project) }}" wire:navigate class="text-theme-sm font-medium text-gray-800 hover:underline dark:text-white/90">{{ $project->name }}</a>
                                    <p class="text-theme-xs text-gray-500">{{ $project->code }} · {{ $project->stage->label() }} · {{ $project->status->label() }}</p>
                                </div>
                                <span class="text-theme-sm tabular-nums text-gray-600 dark:text-gray-300">{{ $money((int) $project->value_minor) }}</span>
                            </div>
                            <ol class="mt-2 space-y-1">
                                @foreach ($project->milestones as $milestone)
                                    <li class="flex items-center justify-between gap-3 text-theme-xs text-gray-500">
                                        <span class="truncate">{{ $milestone->name }}</span>
                                        <span class="tabular-nums">{{ $money($milestone->amount_minor) }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endif
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($invoices as $invoice)
                            <li class="flex items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $invoice->number }}</p>
                                    <p class="text-theme-xs text-gray-500">{{ $invoice->projectMilestone?->name ?? 'Invoice' }} · due {{ $invoice->due_date?->format('j M Y') ?? '—' }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-theme-sm tabular-nums text-gray-700 dark:text-gray-200">{{ $money((int) $invoice->total_minor) }}</span>
                                    <x-admin.badge :color="$invoice->status->color()">{{ $invoice->status->label() }}</x-admin.badge>
                                    @can('view', $invoice)
                                        <a href="{{ route('admin.invoices.pdf', $invoice) }}" x-on:click.prevent="$dispatch('document-preview', { url: $el.href })" class="text-theme-xs text-brand-600 hover:underline dark:text-brand-400">PDF</a>
                                        @if ($invoice->status !== \App\Domain\Sales\Enums\InvoiceStatus::Draft)
                                            <a href="{{ \App\Domain\Sales\Services\InvoiceLinks::portal($invoice) }}" target="_blank" rel="noopener" class="text-theme-xs text-brand-600 hover:underline dark:text-brand-400">Client link</a>
                                            @if ($share = \App\Domain\Sales\Services\InvoiceLinks::whatsappShare($invoice))
                                                <a href="{{ $share }}" target="_blank" rel="noopener" class="text-theme-xs text-brand-600 hover:underline dark:text-brand-400">WhatsApp</a>
                                            @endif
                                        @endif
                                    @endcan
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($invoices->isNotEmpty())
                        <div class="px-4 py-3">
                            <a href="{{ route('admin.invoices.index') }}" wire:navigate class="text-theme-xs font-medium text-navy-800 hover:underline dark:text-white">All invoices →</a>
                        </div>
                    @endif
                </x-admin.card>
            @endif

            <x-admin.card title="Terms and notes">
                <div class="space-y-4">
                    @if ($quote->business_line->value === 'furniture')
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-theme-sm dark:border-gray-700 dark:bg-gray-800" aria-label="Automatic advance payment term">{{ \App\Domain\Sales\Services\FurnitureDocument::terms('', (int) $quote->advance_bp)[0] }}<p class="mt-1 text-theme-xs text-gray-500">Generated from Advance requested (%). Edit that percentage to keep the sentence and calculated amount in sync.</p></div>
                    @endif
                    <x-admin.field :label="$quote->business_line->value === 'furniture' ? 'Terms and conditions — additional terms' : 'Terms and conditions'" for="details.terms">
                        <textarea id="details.terms" wire:model.live.blur="details.terms" rows="5" @disabled($ro) class="wx-input h-auto py-2.5"></textarea>
                    </x-admin.field>
                    <x-admin.field label="Notes for the client" for="details.notes">
                        <textarea id="details.notes" wire:model.live.blur="details.notes" rows="3" @disabled($ro) class="wx-input h-auto py-2.5"></textarea>
                    </x-admin.field>
                    <x-admin.field label="Internal notes" for="details.internal_notes" hint="Only staff see these.">
                        <textarea id="details.internal_notes" wire:model.live.blur="details.internal_notes" rows="3" @disabled($ro) class="wx-input h-auto py-2.5"></textarea>
                    </x-admin.field>
                </div>
            </x-admin.card>
            @if ($quote->business_line->value === 'furniture')
                <x-admin.card title="Furniture print options" description="Bottom sections and detail rows — click Show / Hide. Terms, notes and signatures are controlled once in PDF blocks above. Applied discounts, charges and tax remain disclosed when nonzero; these row switches include or omit zero rows only.">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach (\App\Domain\Sales\Services\FurnitureDocument::OPTIONS as $field => $label)
                        @if (!$flexibleBlocks || !in_array($field, ['show_terms', 'show_notes', 'show_signatories'], true))
                        <div class="flex items-center justify-between gap-2"><span class="text-theme-sm">{{ $label }}</span><button type="button" id="details.{{ $field }}" wire:click="toggleFurnitureOption('{{ $field }}')" @disabled($ro) aria-label="Toggle {{ $label }}" aria-pressed="{{ ($details[$field] ?? 'yes') === 'yes' ? 'true' : 'false' }}" class="rounded-md border border-gray-200 px-3 py-1.5 text-theme-xs font-medium disabled:opacity-40 dark:border-gray-700">{{ ($details[$field] ?? 'yes') === 'yes' ? 'Show' : 'Hide' }}</button></div>
                        @endif
                    @endforeach
                    </div>
                    <x-admin.field label="Signatories — add or remove a person" for="details.signatories_text" error="details.signatories_text" hint="One person per line: Name | Title. Delete a line to remove that person. Leave empty for no names. Printed centred; maximum six people.">
                        <textarea id="details.signatories_text" wire:model.live.blur="details.signatories_text" @disabled($ro) rows="4" maxlength="2000" class="wx-input h-auto"></textarea>
                    </x-admin.field>
                    <p class="mt-2 text-theme-xs text-gray-500">Existing drafts: Apply current design above to opt into the new A4 layout. Stored advance percentages and charges are not reset.</p>
                </x-admin.card>
            @endif


        </div>
    </div>

        <aside class="quotation-preview" aria-label="Quotation preview panel">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                <div><p class="font-medium">Live preview · {{ $quoteLayout === 'furniture' ? 'Furniture' : 'v2.1' }}</p><p class="text-theme-xs text-gray-500">{{ $layouts[$quoteLayout] ?? 'Classic BOQ' }} · {{ $quoteLayout === 'furniture' ? 'Brand green / grey' : ($themes[$pdfTheme] ?? 'Classic') }}</p></div>
                <x-admin.button size="sm" variant="secondary" icon="file" :href="route('admin.quotes.pdf', $quote)" x-on:click.prevent="$dispatch('document-preview', { url: $el.href })">Preview saved PDF</x-admin.button>
            </div>
            <div class="grid grid-cols-2 gap-3 border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                <label class="text-theme-xs text-gray-500" for="quoteLayout">Document layout
                    <select id="quoteLayout" wire:model.live="quoteLayout" @disabled($ro) class="wx-input mt-1" title="Single: one continuous table. Project: summary and a separate page per trade. Classic: summary and detailed BOQ.">
                        @foreach ($layouts as $layoutKey => $layoutLabel)<option value="{{ $layoutKey }}">{{ $layoutLabel }}</option>@endforeach
                    </select>
                </label>
                @if ($quoteLayout !== 'furniture')
                <label class="text-theme-xs text-gray-500" for="pdfTheme">Colour design
                    <select id="pdfTheme" wire:model.live="pdfTheme" @disabled($ro) class="wx-input mt-1">
                        @foreach ($themes as $themeKey => $themeLabel)<option value="{{ $themeKey }}">{{ $themeLabel }}</option>@endforeach
                    </select>
                </label>
                @else
                    <p class="text-theme-xs text-gray-500">Colours, logo and headings: Sales → Document design → Furniture. Use Apply current design to update this draft.</p>
                @endif
                @if ($quoteLayout === 'single')<p class="col-span-2 text-theme-xs text-gray-500">One continuous table; long quotations can continue onto more pages.</p>@endif
            </div>
            <p class="border-b border-gray-200 bg-white px-4 py-2 text-theme-xs text-gray-500 dark:bg-gray-900" role="status" x-text="previewStatus">Preview ready · saves when you leave a field</p>
            <iframe wire:ignore x-ref="sheet" x-init="fitSheet()" x-on:load="fitSheet()" srcdoc="{{ $previewHtml }}" class="min-h-0 w-full flex-1 border-0" title="Quotation live preview"></iframe>
        </aside>
    </div>

    {{-- Rate book picker (QUO-001) --}}
    @if ($showCatalog)
        <x-admin.modal name="showCatalog" title="Add from the rate book" description="Rates are copied into the quotation; later rate changes never alter it." width="max-w-3xl">
            <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="relative sm:col-span-2">
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
                    <input type="search" wire:model.live.debounce.300ms="catalogSearch" placeholder="Search items" class="wx-input pl-10" aria-label="Search the rate book" autofocus>
                </div>
                <select wire:model.live="catalogCategory" class="wx-input" aria-label="Category">
                    <option value="">All categories</option>
                    @foreach ($catalogCategories as $category)
                        <option value="{{ $category }}">{{ $category }}</option>
                    @endforeach
                </select>
            </div>
            <div class="max-h-[50vh] divide-y divide-gray-100 overflow-y-auto rounded-xl border border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                @forelse ($catalogItems as $catalogItem)
                    <div wire:key="catalog-{{ $catalogItem->id }}" class="flex items-center gap-3 px-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="text-theme-sm text-gray-800 dark:text-white/90">{{ $catalogItem->description }}</p>
                            <p class="text-theme-xs text-gray-500">{{ $catalogItem->category }} · per {{ $catalogItem->unit }}</p>
                        </div>
                        <p class="shrink-0 text-right text-theme-sm tabular-nums {{ $catalogItem->rate_minor > 0 ? 'text-gray-800 dark:text-white/90' : 'text-gray-400' }}">
                            {{ $catalogItem->rate_minor > 0 ? $money($catalogItem->rate_minor) : 'Rate not set' }}
                        </p>
                        <x-admin.button size="sm" variant="secondary" icon="plus" wire:click="addFromCatalog({{ $catalogItem->id }})">Add</x-admin.button>
                    </div>
                @empty
                    <x-admin.empty icon="book" title="Nothing found" description="Try another word or category, or add the item to the rate book." />
                @endforelse
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showCatalog', false)">Done</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Save as template (QUO-002) --}}
    @if ($showTemplate)
        <x-admin.modal name="showTemplate" title="Save as template" description="Sections, lines, terms, scope and the milestone plan. Client details and discounts are not saved." submit="saveTemplate">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-2">
                    <label @class(['cursor-pointer rounded-xl border p-3 text-theme-sm', 'border-navy-900 bg-navy-50 dark:border-white dark:bg-white/10' => $templateMode === 'new', 'border-gray-200 dark:border-gray-800' => $templateMode !== 'new'])>
                        <input type="radio" wire:model.live="templateMode" value="new" class="sr-only"> New template
                    </label>
                    <label @class(['cursor-pointer rounded-xl border p-3 text-theme-sm', 'border-navy-900 bg-navy-50 dark:border-white dark:bg-white/10' => $templateMode === 'replace', 'border-gray-200 dark:border-gray-800' => $templateMode !== 'replace'])>
                        <input type="radio" wire:model.live="templateMode" value="replace" class="sr-only"> Update a template
                    </label>
                </div>
                @if ($templateMode === 'replace')
                    <x-admin.field label="Template to update" for="templateId" hint="Its sections and lines are replaced with this quotation's.">
                        <select id="templateId" wire:model="templateId" class="wx-input">
                            <option value="">Choose…</option>
                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                @endif
                <x-admin.field label="Template name" for="templateName">
                    <input id="templateName" type="text" wire:model="templateName" class="wx-input">
                </x-admin.field>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showTemplate', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" icon="check">Save template</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($showSend)
        <x-admin.modal name="showSend" :title="$sendable ? 'Send '.$quote->label() : 'Share '.$quote->label()" width="max-w-xl"
                       :description="$sendable ? 'A frozen copy (PDF + client page) is made now; later changes become the next revision.' : 'The link always shows the latest sent version.'">
            @if ($sendable)
                @if ($sendProblems !== [])
                    <div class="rounded-xl border border-error-500/30 bg-error-50 px-4 py-3 text-theme-sm text-error-700" role="alert">
                        <p class="font-medium">Fix these first:</p>
                        <ul class="mt-1 list-disc pl-5">@foreach ($sendProblems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                    </div>
                @elseif ($assessment && $assessment->needed && ! $approvedAsIs)
                    <div class="rounded-xl border border-warning-500/30 bg-warning-50 px-4 py-3 text-theme-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                        <p class="font-medium">Approval needed before sending</p>
                        @foreach ($assessment->reasons as $reason)<p>{{ $reason }}</p>@endforeach
                    </div>
                    <label for="approval-note" class="wx-label mt-4">Note for the approver (optional)</label>
                    <textarea id="approval-note" wire:model="approvalNote" rows="2" class="wx-input h-auto py-2" placeholder="Why this discount? For example: repeat client, large order."></textarea>
                    @error('approvalNote')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                @else
                    <dl class="grid grid-cols-2 gap-3 rounded-xl bg-gray-50 p-4 text-theme-sm dark:bg-white/5">
                        <div><dt class="text-theme-xs text-gray-500">Client</dt><dd class="font-medium">{{ $contact->name }}</dd></div>
                        <div><dt class="text-theme-xs text-gray-500">Total</dt><dd class="font-medium tabular-nums">{{ $money($quote->total_minor) }}</dd></div>
                        <div><dt class="text-theme-xs text-gray-500">Valid until</dt><dd>{{ $quote->valid_until?->format('j M Y') }}</dd></div>
                        <div><dt class="text-theme-xs text-gray-500">Will be sent as</dt><dd>{{ $quote->number }}{{ $versions->contains('revision', $quote->revision) ? ' R'.($quote->revision + 1) : ($quote->revision > 0 ? ' R'.$quote->revision : '') }}</dd></div>
                    </dl>
                    <label class="mt-4 flex items-start gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="sendEmail" @disabled(blank($contact->email)) class="mt-0.5 size-4 rounded border-gray-300">
                        <span>Also email the PDF and link{{ $contact->email ? ' to '.$contact->email : ' (the client has no email address)' }}</span>
                    </label>
                    <label class="mt-2 flex items-start gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="sendWhatsapp" @disabled(! $this->whatsappReady()) class="mt-0.5 size-4 rounded border-gray-300">
                        <span>Send the link on WhatsApp{{ $this->whatsappReady() ? ' to '.$contact->whatsappNumber() : ' (no WhatsApp number, or the API is not connected)' }}</span>
                    </label>
                    <p class="mt-2 text-theme-xs text-gray-500">The link always opens the latest version, so the client can accept, ask for a change or ask a question from the same page. Inside 24 hours of the client's last message it is sent as a normal message; after that WhatsApp only allows an approved template.</p>
                @endif
                @error('send')
                    <div class="mt-4 rounded-xl border border-error-500/30 bg-error-50 px-4 py-3 text-theme-sm text-error-700" role="alert">
                        @foreach ($errors->get('send') as $message)<p>{{ $message }}</p>@endforeach
                    </div>
                @enderror
            @elseif ($portalUrl)
                <x-admin.quote-share-links :portal-url="$portalUrl" :pdf-url="$sharedPdfUrl" :whatsapp-share="$whatsappShare" />
            @endif
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showSend', false)">{{ $sendable ? 'Cancel' : 'Close' }}</x-admin.button>
                @if ($sendable && $sendProblems === [])
                    @if ($assessment && $assessment->needed && ! $approvedAsIs)
                        <x-admin.button icon="check-circle" wire:click="requestApproval" wire:loading.attr="disabled">Request approval</x-admin.button>
                    @else
                        <x-admin.button icon="send" wire:click="send" wire:loading.attr="disabled">Send now</x-admin.button>
                    @endif
                @endif
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($showReject)
        <x-admin.modal name="showReject" title="Decline approval" description="The sales person sees your reason and can change the quotation." submit="reject">
            <label for="builder-reject-reason" class="wx-label">Reason</label>
            <textarea id="builder-reject-reason" wire:model="rejectReason" rows="3" class="wx-input h-auto py-2"></textarea>
            @error('rejectReason')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
            <x-slot:footer>
                <x-admin.button variant="secondary" type="button" wire:click="$set('showReject', false)">Cancel</x-admin.button>
                <x-admin.button type="submit">Decline</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
    @if ($showDescriptions)
        <x-admin.modal name="showDescriptions" title="Copy a description" description="Adds a new unpriced line to this section. Existing text, quantities and rates are unchanged." width="max-w-3xl">
            <input type="search" wire:model.live.debounce.300ms="descriptionSearch" class="wx-input mb-4" placeholder="Search section or description" aria-label="Search description library">
            <div class="space-y-3">
                @forelse ($descriptionTemplates as $description)
                    <div wire:key="pick-description-{{ $description->id }}" class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <p class="font-medium text-gray-900 dark:text-white">{{ $description->section }} · {{ $description->title }}</p>
                        <p class="my-2 whitespace-pre-line break-words text-theme-sm text-gray-500">{{ $description->body }}</p>
                        <x-admin.button size="sm" variant="secondary" wire:click="addDescription({{ $description->id }})" wire:loading.attr="disabled">Add description line</x-admin.button>
                    </div>
                @empty
                    <p class="text-theme-sm text-gray-500">No active descriptions match this brand and search.</p>
                @endforelse
            </div>
        </x-admin.modal>
    @endif

</div>
