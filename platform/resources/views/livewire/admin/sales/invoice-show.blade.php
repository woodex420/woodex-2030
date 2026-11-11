@use('App\Support\Money')
<div>
    <x-admin.page-header :title="$invoice->number"
                         :description="'Invoice for '.$invoice->contact?->name.($invoice->project ? ' · project '.$invoice->project->code : ($invoice->quote ? ' · quotation '.$invoice->quote->number : ''))"
                         :breadcrumbs="['Sales' => null, 'Invoices' => route('admin.invoices.index'), $invoice->number => null]">
        <x-slot:actions>
            @if ($invoice->status === \App\Domain\Sales\Enums\InvoiceStatus::Draft)
                <x-admin.button variant="secondary" icon="eye" wire:click="$toggle('showPreview')">{{ $showPreview ? 'Hide preview' : 'Live preview' }}</x-admin.button>
            @endif
            @if ($invoice->pdf_path)
                <a href="{{ route('admin.invoices.pdf', $invoice) }}" target="_blank" rel="noopener">
                    <x-admin.button variant="secondary" icon="document">PDF</x-admin.button>
                </a>
            @endif
            <a href="{{ $portal }}" target="_blank" rel="noopener">
                <x-admin.button variant="secondary" icon="link">Client link</x-admin.button>
            </a>
            @if ($whatsapp)
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener">
                    <x-admin.button variant="secondary" icon="chat">Send on WhatsApp</x-admin.button>
                </a>
            @endif
            @if ($canIssue)
                <x-admin.button icon="check" wire:click="issue" wire:confirm="Issue this invoice? It will be frozen and booked in the ledger.">Issue invoice</x-admin.button>
            @endif
            @if ($canVoid)
                <x-admin.button variant="danger" icon="trash" wire:click="openVoid">Void invoice</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-500">
            {{ session('status') }}
        </div>
    @endif

    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.stat label="Total" :value="Money::ofMinor((int) $invoice->total_minor)->format($lakh)" icon="invoice" />
        <x-admin.stat label="Paid" :value="Money::ofMinor((int) $invoice->paid_minor)->format($lakh)" icon="wallet"
                      :tone="(int) $invoice->paid_minor > 0 ? 'success' : 'navy'" />
        <x-admin.stat label="Balance" :value="Money::ofMinor((int) $invoice->balance_minor)->format($lakh)" icon="clock"
                      :tone="(int) $invoice->balance_minor > 0 ? 'warning' : 'success'"
                      :hint="$invoice->due_date ? 'Due '.$invoice->due_date->format('j M Y') : null" />
        <x-admin.stat label="Status" :value="$invoice->status->label()" icon="check" :tone="$invoice->status->color()"
                      :hint="$invoice->kind->label().' · issued '.($invoice->issue_date?->format('j M Y') ?? '—')" />
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-admin.card title="Lines" :description="$invoice->status === \App\Domain\Sales\Enums\InvoiceStatus::Draft ? 'Draft — nothing is frozen or booked yet. Issuing freezes this document and posts it to the ledger.' : 'Frozen when the invoice was issued — this is the document the client received.'">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-theme-sm">
                        <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Description</th>
                                <th class="py-2 pr-4 text-right font-medium">Qty</th>
                                <th class="py-2 pr-4 text-right font-medium">Rate</th>
                                <th class="py-2 pr-4 text-right font-medium">Tax</th>
                                <th class="py-2 text-right font-medium">Line total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($invoice->items as $item)
                                <tr>
                                    <td class="py-3 pr-4">
                                        @if ($item->product_name)<strong class="block">{{ $item->product_name }}</strong>@endif
                                        <span class="block text-gray-800 dark:text-white/90">{{ $item->description }}</span>
                                        @if ($item->unit)
                                            <span class="text-theme-xs text-gray-500">{{ $item->unit }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $item->qty, 2, '.', ''), '0'), '.') }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums">{{ Money::ofMinor((int) $item->rate_minor)->format($lakh) }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-gray-500">{{ number_format((int) $item->tax_bp / 100, 2) }}%</td>
                                    <td class="py-3 text-right font-medium tabular-nums">{{ Money::ofMinor((int) $item->line_total_minor)->format($lakh) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-gray-100 text-theme-sm dark:border-gray-800">
                            <tr>
                                <td colspan="4" class="py-2 pr-4 text-right text-gray-500">Subtotal</td>
                                <td class="py-2 text-right tabular-nums">{{ Money::ofMinor((int) $invoice->subtotal_minor)->format($lakh) }}</td>
                            </tr>
                            @if ((int) $invoice->discount_minor > 0)
                                <tr>
                                    <td colspan="4" class="py-2 pr-4 text-right text-gray-500">Discount</td>
                                    <td class="py-2 text-right tabular-nums">− {{ Money::ofMinor((int) $invoice->discount_minor)->format($lakh) }}</td>
                                </tr>
                            @endif
                            @if (($invoice->commercial['renderer'] ?? '') === 'furniture-v1')
                                @foreach (['Rent' => (int) $invoice->rent_minor, 'Shipping / transport' => (int) $invoice->shipping_minor, 'Rounding' => (int) ($invoice->commercial['rounding_minor'] ?? 0)] as $label => $minor)
                                <tr><td colspan="4" class="py-2 pr-4 text-right text-gray-500">{{ $label }}</td><td class="py-2 text-right tabular-nums">{{ Money::ofMinor($minor)->format($lakh) }}</td></tr>
                                @endforeach
                                <tr><td colspan="4" class="py-2 pr-4 text-right text-gray-500">Advance requested (not received)</td><td class="py-2 text-right tabular-nums">{{ Money::ofMinor((int) $invoice->total_minor)->percent((int) $invoice->advance_bp)->format($lakh) }}</td></tr>
                            @endif
                            @if ((int) $invoice->tax_minor > 0)
                                <tr>
                                    <td colspan="4" class="py-2 pr-4 text-right text-gray-500">Sales tax</td>
                                    <td class="py-2 text-right tabular-nums">{{ Money::ofMinor((int) $invoice->tax_minor)->format($lakh) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td colspan="4" class="py-2 pr-4 text-right font-medium text-gray-800 dark:text-white/90">Total</td>
                                <td class="py-2 text-right font-semibold tabular-nums text-gray-900 dark:text-white">{{ Money::ofMinor((int) $invoice->total_minor)->format($lakh) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if ($invoice->notes)
                    <p class="mt-4 border-t border-gray-100 pt-4 text-theme-sm text-gray-600 dark:border-gray-800 dark:text-gray-400">{{ $invoice->notes }}</p>
                @endif

                @if ($invoice->voided_at)
                    <p class="mt-4 rounded-lg border border-error-200 bg-error-50 px-3 py-2 text-theme-sm text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-500">
                        Void on {{ $invoice->voided_at->format('j M Y, H:i') }} — {{ $invoice->void_reason }}
                    </p>
                @endif
            </x-admin.card>

            <x-admin.card :padding="false" title="Payments" description="Every payment recorded against this invoice, oldest first.">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-theme-sm">
                        <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                            <tr>
                                <th class="px-5 py-3 font-medium">Received</th>
                                <th class="px-5 py-3 font-medium">Method</th>
                                <th class="px-5 py-3 font-medium">Reference</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($payments as $payment)
                                <tr>
                                    <td class="px-5 py-3 text-gray-700 dark:text-gray-300">{{ $payment->received_on?->format('j M Y') }}</td>
                                    <td class="px-5 py-3">{{ $payment->method->label() }}</td>
                                    <td class="px-5 py-3 text-gray-500">{{ $payment->reference ?? $payment->receipt_number ?? '—' }}</td>
                                    <td class="px-5 py-3"><x-admin.badge :color="$payment->status->color()">{{ $payment->status->label() }}</x-admin.badge></td>
                                    <td class="px-5 py-3 text-right tabular-nums">{{ $payment->amount()->format($lakh) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-6 text-center text-theme-sm text-gray-500">Nothing collected against this invoice yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-admin.card>

            @if ($journal)
                <x-admin.card title="Ledger"
                              description="What this invoice became in the books. A void reverses the entry — it never deletes it.">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3 text-theme-sm dark:border-gray-800">
                        <div>
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $journal->entry_no }}</span>
                            <span class="text-gray-500"> · {{ $journal->business_date?->format('j M Y') }} · {{ $journal->reference }}</span>
                        </div>
                        @if ($journal->isReversed())
                            <x-admin.badge color="error">Reversed</x-admin.badge>
                        @else
                            <x-admin.badge color="success">Posted</x-admin.badge>
                        @endif
                    </div>
                    <table class="mt-3 min-w-full text-left text-theme-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($journal->lines as $line)
                                <tr>
                                    <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">{{ $line->account?->name ?? 'Account #'.$line->account_id }}</td>
                                    <td class="py-2 pr-4 text-gray-500">{{ $line->memo }}</td>
                                    <td class="py-2 text-right tabular-nums {{ (int) $line->debit_minor > 0 ? '' : 'text-gray-400' }}">{{ (int) $line->debit_minor > 0 ? Money::ofMinor((int) $line->debit_minor)->format($lakh) : '—' }}</td>
                                    <td class="py-2 text-right tabular-nums {{ (int) $line->credit_minor > 0 ? '' : 'text-gray-400' }}">{{ (int) $line->credit_minor > 0 ? Money::ofMinor((int) $line->credit_minor)->format($lakh) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-admin.card>
            @endif
        </div>

        <div class="space-y-6">
            @if ($canCollect)
                <x-admin.card title="Collect a payment" description="Recorded, verified, receipted and posted to the ledger in one act.">
                    <form wire:submit="collect" class="space-y-4">
                        <div>
                            <label class="wx-label" for="collect-amount">Amount (Rs)</label>
                            <input id="collect-amount" type="text" inputmode="decimal" wire:model="amount" class="wx-input" />
                            @error('amount') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                            @error('invoice') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="wx-label" for="collect-method">Method</label>
                            <select id="collect-method" wire:model="method" class="wx-input">
                                @foreach ($methods as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                            @error('method') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="wx-label" for="collect-reference">Reference <span class="text-gray-400">(optional)</span></label>
                            <input id="collect-reference" type="text" wire:model="reference" class="wx-input" placeholder="Bank ref, cheque no." />
                            @error('reference') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        </div>
                        <x-admin.button type="submit" icon="wallet">Collect payment</x-admin.button>
                    </form>
                </x-admin.card>
            @endif

            @if ($showVoid)
                <x-admin.card title="Void this invoice" description="The invoice stays readable and marked void. Its ledger entry is reversed, not deleted.">
                    <form wire:submit="void" class="space-y-4">
                        <div>
                            <label class="wx-label" for="void-reason">Reason</label>
                            <textarea id="void-reason" rows="3" wire:model="voidReason" class="wx-input" placeholder="Why this invoice is being voided"></textarea>
                            @error('voidReason') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            <x-admin.button type="submit" variant="danger" icon="trash">Void invoice</x-admin.button>
                            <x-admin.button variant="ghost" wire:click="closeVoid">Cancel</x-admin.button>
                        </div>
                    </form>
                </x-admin.card>
            @endif

            <x-admin.card title="Client">
                <dl class="space-y-3 text-theme-sm">
                    <div>
                        <dt class="text-gray-500">Name</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">
                            @if ($invoice->contact)
                                <a href="{{ route('admin.contacts.show', $invoice->contact) }}" wire:navigate class="hover:underline">{{ $invoice->contact->name }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    @if ($invoice->contact?->phone)
                        <div>
                            <dt class="text-gray-500">Phone</dt>
                            <dd>{{ $invoice->contact->phone }}</dd>
                        </div>
                    @endif
                    @if ($invoice->contact?->email)
                        <div>
                            <dt class="text-gray-500">Email</dt>
                            <dd class="truncate">{{ $invoice->contact->email }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-gray-500">Client link</dt>
                        <dd><a href="{{ $portal }}" target="_blank" rel="noopener" class="break-all text-brand-600 hover:underline dark:text-brand-400">{{ $portal }}</a></dd>
                    </div>
                    @if ($invoice->quote)
                        <div>
                            <dt class="text-gray-500">Quotation</dt>
                            <dd><a href="{{ route('admin.quotes.show', $invoice->quote) }}" wire:navigate class="text-brand-600 hover:underline dark:text-brand-400">{{ $invoice->quote->number }}</a> · {{ $invoice->quote->title }}</dd>
                        </div>
                    @endif
                    @if ($invoice->project)
                        <div>
                            <dt class="text-gray-500">Project</dt>
                            <dd><a href="{{ route('admin.projects.show', $invoice->project) }}" wire:navigate class="text-brand-600 hover:underline dark:text-brand-400">{{ $invoice->project->code }}</a> · {{ $invoice->project->name }}</dd>
                        </div>
                    @endif
                </dl>
            </x-admin.card>

            <x-admin.card title="Payment details" description="Where the client sends the money — printed on the invoice.">
                @if ($invoice->bankAccount)
                    @php $bank = $invoice->bankAccount; @endphp
                    <dl class="space-y-2 text-theme-sm">
                        <div><dt class="text-gray-500">Account</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $bank->title }}</dd></div>
                        <div><dt class="text-gray-500">Bank</dt><dd>{{ $bank->bank_name }}</dd></div>
                        @if ($bank->account_number)<div><dt class="text-gray-500">Account no.</dt><dd class="tabular-nums">{{ $bank->account_number }}</dd></div>@endif
                        @if ($bank->iban)<div><dt class="text-gray-500">IBAN</dt><dd class="tabular-nums">{{ $bank->iban }}</dd></div>@endif
                        @if ($bank->wallet_provider)<div><dt class="text-gray-500">Wallet</dt><dd>{{ $bank->wallet_provider }}</dd></div>@endif
                    </dl>
                @else
                    <p class="text-theme-sm text-gray-500">
                        No bank account was attached to this invoice.
                        @can('invoices.manage')
                            <a href="{{ route('admin.invoices.index', ['tab' => 'bank']) }}" wire:navigate class="text-brand-600 hover:underline dark:text-brand-400">Add one under Invoices → Bank accounts</a>.
                        @endcan
                    </p>
                @endif
            </x-admin.card>
        </div>
    </div>

    @if ($showPreview && $invoice->status === \App\Domain\Sales\Enums\InvoiceStatus::Draft)
        <div class="fixed inset-y-0 right-0 z-40 flex w-full max-w-3xl flex-col bg-white shadow-2xl dark:bg-gray-900" role="dialog" aria-label="Live preview">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <p class="text-theme-sm font-medium text-gray-900 dark:text-white">Live preview <span class="font-normal text-gray-400">· the document as it will print</span></p>
                <x-admin.button size="sm" variant="ghost" wire:click="$set('showPreview', false)">Close</x-admin.button>
            </div>
            <iframe srcdoc="{{ $previewHtml }}" class="min-h-0 flex-1 bg-gray-100" title="Invoice live preview"></iframe>
        </div>
    @endif
</div>
