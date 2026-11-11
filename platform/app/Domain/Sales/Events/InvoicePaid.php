<?php

declare(strict_types=1);

namespace App\Domain\Sales\Events;

use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once, when an allocation brings an invoice's balance to zero. AllocatePayment is the single
 * choke point every verified-payment path passes through, so this never fires twice for one invoice.
 */
final class InvoicePaid implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public Payment $payment,
    ) {}
}
