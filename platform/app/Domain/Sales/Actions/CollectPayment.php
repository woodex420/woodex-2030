<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\InvoiceLocks;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P8.2: money collected by a trusted staff member in one act — the payment is recorded AND verified
 * (the collector is the verifier: owner or accounts), allocated to its invoice, receipted and posted
 * to the ledger. Used by Telegram /collect; a dashboard button can reuse it later.
 */
final class CollectPayment
{
    public function __construct(
        private readonly VerifyPayment $verify,
        private readonly InvoiceLocks $locks,
    ) {}

    public function handle(Invoice $invoice, int $amountMinor, PaymentMethod $method, User $by, ?string $reference = null, ?string $receivedOn = null): Payment
    {
        $by = User::query()->findOrFail($by->id);
        if (! $by->is_active || ! $by->can('payments.record') || ! $by->can('payments.verify')) {
            throw new AuthorizationException;
        }

        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The collected amount must be positive.']);
        }

        return DB::transaction(function () use ($invoice, $amountMinor, $method, $by, $reference, $receivedOn): Payment {
            // The allocation further down locks every open invoice of this client, lowest number first. The same
            // rows are taken here in the same order before this invoice is looked at: with this one locked first
            // and the others after it, two people collecting two invoices of one client each held what the other
            // was waiting for, and the database stopped one of them (T-093, InvoiceLocks).
            $invoice = $this->locks->forMoneyOf((int) $invoice->contact_id, [(int) $invoice->id])->get((int) $invoice->id)
                ?? throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoice->id]);

            if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)) {
                throw ValidationException::withMessages(['invoice' => "Invoice {$invoice->number} is {$invoice->status->value} and cannot collect a payment."]);
            }

            if ($amountMinor > (int) $invoice->balance_minor) {
                throw ValidationException::withMessages(['amount' => sprintf('%s has only %s outstanding — collect that amount or record the surplus separately.', $invoice->number, Money::ofMinor((int) $invoice->balance_minor)->format())]);
            }

            $payment = Payment::query()->create([
                'contact_id' => $invoice->contact_id,
                'invoice_id' => $invoice->id,
                'project_id' => $invoice->project_id,
                'amount_minor' => $amountMinor,
                'received_on' => $receivedOn ?? today()->toDateString(),
                'method' => $method,
                'status' => PaymentStatus::PendingVerification,
                'reference' => $reference !== null && trim($reference) !== '' ? trim($reference) : null,
                'created_by' => $by->id,
            ]);

            // VerifyPayment allocates, receipts and posts to the ledger (P8.2 wiring).
            return $this->verify->handle($payment, $by);
        });
    }
}
