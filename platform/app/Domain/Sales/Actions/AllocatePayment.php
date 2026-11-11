<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Events\InvoicePaid;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Allocations are validated and locked here, not trusted from a form or future automation tool. */
final class AllocatePayment
{
    /**
     * Explicit maps allocate only the chosen amounts; their remainder stays credit. Without a map, pay the
     * payment's own invoice first, then eligible oldest debts (D-85). The current payment ledger is PKR-only.
     *
     * @param  array<int, int>  $allocations  invoice id => positive integer paisa
     */
    public function handle(Payment $payment, array $allocations = []): Payment
    {
        return DB::transaction(function () use ($payment, $allocations): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== PaymentStatus::Verified || $payment->amount_minor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Only confirmed positive payments can be allocated.']);
            }

            if ($payment->receipt_number !== null) {
                throw ValidationException::withMessages(['amount' => 'This payment already has a receipt. Use an approved credit adjustment instead.']);
            }

            $bank = $payment->bank_account_id === null ? null : BankAccount::query()->find($payment->bank_account_id);
            if ($payment->bank_account_id !== null && ($bank === null || ! $bank->is_active)) {
                throw ValidationException::withMessages(['bankAccountId' => 'Choose an active receiving account.']);
            }

            if ($payment->project_id !== null && ! Project::query()->whereKey($payment->project_id)->where('contact_id', $payment->contact_id)->exists()) {
                throw ValidationException::withMessages(['invoiceId' => 'The project must belong to this client.']);
            }

            $remaining = $payment->amount_minor - (int) $payment->allocations()->sum('amount_minor');
            if ($remaining < 0) {
                throw ValidationException::withMessages(['amount' => 'Existing allocations exceed this payment. Ask accounts to reconcile it.']);
            }

            $requested = 0;
            foreach ($allocations as $id => $amount) {
                if (! is_int($id) || $id <= 0 || ! is_int($amount) || $amount <= 0 || $amount > $remaining - $requested) {
                    throw ValidationException::withMessages(['invoiceId' => 'Allocation amounts must be positive integer paisa within the unallocated payment.']);
                }
                $requested += $amount;
            }

            $targetIds = array_keys($allocations);
            if ($payment->invoice_id !== null) {
                $targetIds[] = $payment->invoice_id;
            }

            // Lock eligible candidates and explicit targets in a deterministic order. The payment lock also
            // serializes competing allocations against the same credit; invoice locks protect other payments.
            $invoices = Invoice::query()
                ->where(fn (Builder $query) => $query->whereIn('id', $targetIds)->orWhere(fn (Builder $eligible) => $eligible
                    ->where('contact_id', $payment->contact_id)
                    ->where('currency', 'PKR')
                    ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
                    ->where('kind', '!=', InvoiceKind::CreditNote->value)
                    ->where('balance_minor', '>', 0)
                    ->when($payment->project_id !== null, fn (Builder $q) => $q->where('project_id', $payment->project_id))
                    ->when($bank?->business_line !== null, fn (Builder $q) => $q->where('business_line', $bank->business_line->value))))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Validate ALL explicit targets before touching ANY balance; a bad later row rolls back everything.
            foreach (array_unique($targetIds) as $id) {
                $invoice = $invoices->get($id);
                if ($invoice === null || $invoice->contact_id !== $payment->contact_id
                    || $invoice->currency !== 'PKR' || $invoice->kind === InvoiceKind::CreditNote
                    || ! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)
                    || ($payment->project_id !== null && $invoice->project_id !== $payment->project_id)
                    || ($bank?->business_line !== null && $invoice->business_line !== $bank->business_line)) {
                    throw ValidationException::withMessages(['invoiceId' => 'Choose a payable PKR invoice for this client, project and receiving account.']);
                }
            }

            if ($allocations !== []) {
                $plan = [];
                foreach ($allocations as $id => $amount) {
                    $invoice = $invoices->get($id);
                    if ($invoice === null || $amount > $invoice->balance_minor || $payment->allocations()->where('invoice_id', $id)->exists()) {
                        throw ValidationException::withMessages(['invoiceId' => 'An allocation exceeds the invoice balance or duplicates an existing allocation.']);
                    }
                    $plan[] = ['invoice' => $invoice, 'amount' => $amount];
                }
                foreach ($plan as $item) {
                    $this->apply($payment, $item['invoice'], $item['amount']);
                }
            } else {
                $ordered = $invoices->sortBy(fn (Invoice $invoice): string => ($invoice->id === $payment->invoice_id ? '0' : '1').($invoice->due_date?->format('Y-m-d') ?? '9999-12-31').sprintf('%020d', $invoice->id));
                foreach ($ordered as $invoice) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $amount = min($remaining, $invoice->balance_minor);
                    if ($amount > 0 && ! $payment->allocations()->where('invoice_id', $invoice->id)->exists()) {
                        $this->apply($payment, $invoice, $amount);
                        $remaining -= $amount;
                    }
                }
            }

            return $payment->refresh();
        });
    }

    private function apply(Payment $payment, Invoice $invoice, int $amount): void
    {
        PaymentAllocation::query()->create([
            'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_minor' => $amount,
        ]);
        $invoice->applyPayment($amount);

        if ($invoice->status === InvoiceStatus::Paid) {
            InvoicePaid::dispatch($invoice, $payment);
        }
    }
}
