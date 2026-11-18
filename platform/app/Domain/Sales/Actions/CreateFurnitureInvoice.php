<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\FurnitureDocument;
use App\Domain\Sales\Services\QuoteWriteLock;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** One full sale per quotation. Uses the sent document, not subsequently edited commercial data. */
final class CreateFurnitureInvoice
{
    public function handle(Quote $quote, User $by): Invoice
    {
        $by = User::query()->findOrFail($by->id);
        abort_unless($by->is_active, 403);
        Gate::forUser($by)->authorize('view', $quote);
        Gate::forUser($by)->authorize('create', Invoice::class);

        return DB::transaction(function () use ($quote, $by): Invoice {
            QuoteWriteLock::refresh($quote);
            if ($quote->business_line !== BusinessLine::Furniture || ! in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Accepted], true)) {
                throw new QuoteActionDenied('Send or accept a Furniture quotation before creating its full invoice.');
            }
            $existing = Invoice::withTrashed()->where('source_quote_id', $quote->id)->first();
            if ($existing !== null) {
                if ($existing->trashed()) {
                    throw new QuoteActionDenied('The full invoice is archived. Restore or review it in Accounts; do not bill twice.');
                }

                return $existing;
            }
            if (Invoice::withTrashed()->where('quote_id', $quote->id)->exists()) {
                throw new QuoteActionDenied('This quotation already has invoices, including archived ones. Accounts must review them before changing its billing method.');
            }
            $version = $quote->versions()->reorder('revision', 'desc')->first();
            if ($version === null) {
                throw new QuoteActionDenied('The sent quotation snapshot is missing.');
            }
            $s = $version->snapshot;
            $t = $s['totals'];
            if ((int) $t['total_minor'] <= 0) {
                throw new QuoteActionDenied('The quotation has no billable total.');
            }
            $invoice = Invoice::query()->create([
                'number' => app(Numbering::class)->next('furniture.invoice'),
                'kind' => InvoiceKind::FurnitureSale, 'status' => InvoiceStatus::Draft,
                'contact_id' => $quote->contact_id, 'company_id' => $quote->company_id,
                'business_line' => BusinessLine::Furniture, 'quote_id' => $quote->id, 'source_quote_id' => $quote->id,
                'project_id' => $quote->project()->first()?->id,
                'bank_account_id' => BankAccount::query()->forLine(BusinessLine::Furniture)->first()?->id,
                'currency' => $quote->currency, 'subtotal_minor' => (int) $t['subtotal_minor'],
                'discount_minor' => (int) $t['discount_minor'], 'rent_minor' => (int) ($t['other_charges_minor'] ?? 0),
                'shipping_minor' => (int) ($t['shipping_minor'] ?? 0), 'tax_bp' => (int) $t['tax_bp'],
                'tax_minor' => (int) $t['tax_minor'], 'advance_bp' => (int) ($t['advance_bp'] ?? 0),
                'total_minor' => (int) $t['total_minor'], 'paid_minor' => 0, 'balance_minor' => (int) $t['total_minor'],
                'issue_date' => today(), 'due_date' => today()->addDays((int) app(SalesSettings::class)->invoice_due_days),
                'notes' => (string) ($s['notes'] ?? ''), 'terms' => implode("\n", $s['terms'] ?? []), 'created_by' => $by->id,
                'commercial' => ['renderer' => 'furniture-v1', 'document_design' => $s['document_design'] ?? null,
                    'scope_html' => $s['scope_html'] ?? null, 'signatories' => $s['signatories'] ?? [],
                    'client' => $s['client'], 'source_number' => $s['label'] ?? $s['number'],
                    'rounding_minor' => (int) ($t['rounding_minor'] ?? 0),
                    'furniture' => [...FurnitureDocument::text($s['furniture'] ?? null), 'document_title' => '']],
            ]);
            $position = 0;
            foreach ($s['sections'] as $section) {
                foreach ($section['items'] as $item) {
                    if ($item['is_optional'] ?? false) {
                        continue;
                    }
                    $invoice->items()->create([
                        'position' => $position++, 'product_name' => (string) ($item['product_name'] ?? ''),
                        'description' => (string) $item['description'], 'unit' => $item['unit'], 'qty' => $item['qty'],
                        'rate_minor' => (int) $item['rate_minor'], 'discount_minor' => (int) ($item['discount_minor'] ?? 0),
                        'tax_bp' => (int) $t['tax_bp'], 'line_total_minor' => (int) $item['line_total_minor'],
                    ]);
                }
            }
            $meta = $quote->meta ?? [];
            $quote->update(['meta' => [...$meta, 'furniture_billing' => 'full']]);
            activity('billing')->causedBy($by)->performedOn($invoice)->withProperties(['quote_id' => $quote->id, 'quote_revision' => $version->revision])->log('Full furniture invoice drafted from sent quotation');

            return $invoice->refresh();
        });
    }
}
