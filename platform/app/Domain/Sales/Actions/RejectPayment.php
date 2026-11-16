<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Mail\PaymentMail;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Notifications\PaymentNotification;
use App\Domain\System\Alerts\AlertRouter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The accountant did not see the money (INV-006): the payment is set aside with a reason, nothing is allocated, and
 * the client is told what is missing so they can send it again.
 */
final class RejectPayment
{
    public function handle(Payment $payment, string $reason, User $by): Payment
    {
        $by = User::query()->findOrFail($by->id);
        if (! $by->is_active || ! $by->can('payments.verify')) {
            throw new AuthorizationException;
        }
        if (mb_strlen(trim($reason)) < 5 || mb_strlen(trim($reason)) > 500) {
            throw ValidationException::withMessages(['rejectReason' => 'Give a reason between 5 and 500 characters.']);
        }

        return DB::transaction(function () use ($payment, $reason, $by): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::PendingVerification) {
                return $payment;
            }
            $payment->forceFill([
                'status' => PaymentStatus::Rejected,
                'rejected_reason' => trim($reason),
                'verified_by' => $by->id,
                'verified_at' => now(),
            ])->save();

            $payment->loadMissing('contact');

            if (filled($payment->contact?->email)) {
                Mail::to((string) $payment->contact->email)->queue((new PaymentMail($payment->id, 'rejected'))->afterCommit());
            }

            app(AlertRouter::class)->send('payment.rejected',
                User::query()->with('telegramLink')->permission('invoices.view')->where('is_active', true)->get(),
                new PaymentNotification($payment, 'rejected'),
            );

            return $payment->refresh();
        });
    }
}
