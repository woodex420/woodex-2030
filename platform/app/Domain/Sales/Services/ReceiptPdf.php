<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentAllocation;
use App\Settings\BusinessSettings;
use App\Support\AmountInWords;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A4 receipt on the Studio letterhead (INV-007): what was received, when, how, and against which invoices.
 * Plain and short — a receipt is proof, not a sales document.
 */
final class ReceiptPdf
{
    public function __construct(private readonly BusinessSettings $business) {}

    public function render(Payment $payment): string
    {
        $payment->loadMissing(['contact', 'allocations.invoice', 'bankAccount']);

        $pdf = Pdf::loadView('pdf.receipt', [
            'payment' => $payment,
            'business' => [
                'name' => $this->business->brand_name,
                'legal_name' => $this->business->legal_name,
                'tagline' => $this->business->tagline,
                'address' => $this->business->address,
                'city' => $this->business->city,
                'phone' => $this->business->phone,
                'email' => $this->business->email,
                'website' => $this->business->website,
                'ntn' => $this->business->ntn,
            ],
            'received' => $payment->amount()->format(),
            'received_in_words' => AmountInWords::rupees($payment->amount()),
            'allocations' => $payment->allocations->map(fn (PaymentAllocation $allocation): array => [
                'invoice' => $allocation->invoice?->number,
                'amount' => Money::ofMinor((int) $allocation->amount_minor, 'PKR')->format(),
            ])->all(),
            'unallocated' => $payment->unallocated()->format(),
        ])
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isFontSubsettingEnabled', true);

        return $pdf->output();
    }

    public static function filename(Payment $payment): string
    {
        return 'receipt-'.str_replace(['/', '\\'], '-', (string) $payment->receipt_number).'.pdf';
    }
}
