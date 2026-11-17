<?php

declare(strict_types=1);

namespace App\Domain\Sales\Policies;

use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Models\Payment;
use App\Models\User;

/**
 * Payments (INV-005/006). Whoever sees invoices sees the money; recording and confirming it are separate
 * permissions, so the person who receives the cash is not always the person who confirms it.
 */
final class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payments.record');
    }

    /** Confirming, or sending back, a payment somebody reported (INV-006). */
    public function verify(User $user, Payment $payment): bool
    {
        return $user->can('payments.verify') && $payment->status === PaymentStatus::PendingVerification;
    }
}
