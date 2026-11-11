<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Accounts\Actions\PostPaymentToLedger;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The accountant confirms the money is in the account (INV-005/006/007): the payment is allocated to the invoices it
 * settles, a receipt is numbered and written, and the client gets their copy by email.
 */
final class VerifyPayment
{
    public function __construct(
        private readonly AllocatePayment $allocate,
        private readonly RecordPayment $record,
        private readonly PostPaymentToLedger $postToLedger,
    ) {}

    public function handle(Payment $payment, User $by): Payment
    {
        $by = User::query()->findOrFail($by->id);
        if (! $by->is_active || ! $by->can('payments.verify')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($payment, $by): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::PendingVerification) {
                return $payment;
            }
            $payment->forceFill([
                'status' => PaymentStatus::Verified,
                'verified_by' => $by->id,
                'verified_at' => now(),
            ])->save();

            $this->allocate->handle($payment);

            // The receipt is numbered and written, and the client gets their copy (INV-007)…
            $receipted = $this->record->receipt($payment, $by);

            // …and the money is booked: cash/bank in, receivables (or client credit) out (P8.2).
            $this->postToLedger->handle($receipted->refresh(), $by);

            return $receipted->refresh();
        });
    }
}
