<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\Unit;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\InvoiceItem;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\FurnitureDocument;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Numbering;
use App\Support\NumberInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A manual invoice (Slice P): work billed without a quotation — a repair visit, a small supply, a design fee.
 * It is born a draft in its own number series (MI-2026-0001) and, when issued, freezes and books exactly like
 * a milestone invoice (IssueInvoice → InvoiceFreezer + PostInvoiceToLedger), so there is one money path.
 *
 * Line amounts are tax-exclusive: subtotal = Σ qty × rate; tax is charged on (subtotal − discount).
 */
final class CreateManualInvoice
{
    public function __construct(
        private readonly SalesSettings $sales,
        private readonly Numbering $numbering,
    ) {}

    /**
     * @param  array<string, mixed>  $input  contact_id, business_line, issue_date, due_date, bank_account_id,
     *                                       discount_minor, tax_bp, notes, terms, issue,
     *                                       items: list<{description, unit, qty, rate_minor}>
     */
    public function handle(User $by, array $input): Invoice
    {
        $by = User::query()->findOrFail($by->id);
        abort_unless($by->is_active, 403);
        Gate::forUser($by)->authorize('create', Invoice::class);

        $data = Validator::make($input, [
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'business_line' => ['required', Rule::enum(BusinessLine::class)],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'discount_minor' => ['sometimes', 'integer', 'min:0'],
            'rent_minor' => ['sometimes', 'integer', 'min:0', 'max:999999999900'],
            'shipping_minor' => ['sometimes', 'integer', 'min:0', 'max:999999999900'],
            'advance_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'furniture' => ['sometimes', 'array'],
            'items.*.product_name' => ['nullable', 'string', 'max:160'],
            'tax_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'issue' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:2000'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.qty' => ['required'],
            'items.*.rate_minor' => ['required', 'integer', 'min:0', 'max:999999999900'],
        ])->validate();

        $line = BusinessLine::from($data['business_line']);
        $taxBp = (int) ($data['tax_bp'] ?? 0);
        $discount = (int) ($data['discount_minor'] ?? 0);

        $items = [];
        $subtotal = 0;

        foreach ((array) $data['items'] as $i => $item) {
            try {
                $qty = NumberInput::quantity((string) $item['qty']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["items.{$i}.qty" => 'Enter a quantity like 2 or 2.5.']);
            }

            if ((float) $qty <= 0) {
                throw ValidationException::withMessages(["items.{$i}.qty" => 'Quantity must be more than zero.']);
            }

            $rate = (int) $item['rate_minor'];
            $lineTotal = (int) round((float) $qty * $rate);
            $subtotal += $lineTotal;

            $items[] = [
                'product_name' => trim((string) ($item['product_name'] ?? '')),
                'description' => trim((string) $item['description']),
                'unit' => Unit::normalize(isset($item['unit']) ? (string) $item['unit'] : null)->value,
                'qty' => $qty,
                'rate_minor' => $rate,
                'discount_minor' => 0,
                'tax_bp' => $taxBp,
                'line_total_minor' => $lineTotal,
            ];
        }

        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount_minor' => 'The discount cannot be more than the subtotal.']);
        }

        $rent = $line === BusinessLine::Furniture ? (int) ($data['rent_minor'] ?? 0) : 0;
        $shipping = $line === BusinessLine::Furniture ? (int) ($data['shipping_minor'] ?? 0) : 0;
        $furniture = FurnitureDocument::validatedText(['signatories_text' => FurnitureDocument::signatureText($this->sales->signatories), ...($data['furniture'] ?? [])]);
        $tax = (int) round(($subtotal - $discount + $rent + $shipping) * $taxBp / 10000);
        $total = $subtotal - $discount + $rent + $shipping + $tax;

        if ($total <= 0) {
            throw ValidationException::withMessages(['items' => 'The invoice total must be more than zero — give at least one line a rate.']);
        }

        $contact = Contact::query()->findOrFail($data['contact_id']);

        $bankId = $data['bank_account_id'] ?? null;

        if ($bankId !== null) {
            if (! BankAccount::query()->forLine($line)->whereKey($bankId)->exists()) {
                throw ValidationException::withMessages(['bank_account_id' => 'That bank account is not available for this brand.']);
            }
        } else {
            $bankId = BankAccount::query()->forLine($line)->first()?->id;
        }

        $terms = array_key_exists('terms', $data)
            ? (trim((string) ($data['terms'] ?? '')) ?: null)
            : $this->sales->invoice_terms;

        return DB::transaction(function () use ($by, $data, $line, $contact, $items, $subtotal, $discount, $tax, $total, $bankId, $terms, $rent, $shipping, $taxBp, $furniture): Invoice {
            $invoice = Invoice::query()->create([
                'number' => $this->numbering->next($line === BusinessLine::Furniture ? 'furniture.invoice' : 'manual.invoice'),
                'kind' => InvoiceKind::Manual,
                'status' => InvoiceStatus::Draft,
                'contact_id' => $contact->id,
                'company_id' => $contact->company_id,
                'business_line' => $line,
                'bank_account_id' => $bankId,
                'currency' => 'PKR',
                'subtotal_minor' => $subtotal,
                'discount_minor' => $discount,
                'rent_minor' => $rent, 'shipping_minor' => $shipping, 'tax_bp' => $taxBp,
                'advance_bp' => $line === BusinessLine::Furniture ? (int) ($data['advance_bp'] ?? 5000) : 0,
                'commercial' => $line === BusinessLine::Furniture ? ['renderer' => 'furniture-v1', 'furniture' => $furniture, 'document_design' => app(DocumentStyles::class)->copy($line)] : null,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'paid_minor' => 0,
                'balance_minor' => $total,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'terms' => $terms,
                'notes' => isset($data['notes']) ? (trim((string) $data['notes']) ?: null) : null,
                'created_by' => $by->id,
            ]);

            foreach ($items as $position => $item) {
                InvoiceItem::query()->create([...$item, 'invoice_id' => $invoice->id, 'position' => $position]);
            }

            if ((bool) ($data['issue'] ?? false)) {
                app(IssueInvoice::class)->handle($invoice, $by);
            }

            return $invoice->refresh();
        });
    }
}
