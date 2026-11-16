<?php

declare(strict_types=1);

namespace App\Domain\Sales\Mail;

use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\ReceiptPdf;
use App\Settings\BusinessSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The client's copy of what happened to their payment (INV-006/007): the receipt when it is confirmed, and a plain
 * note with the reason when it is not.
 */
final class PaymentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly int $paymentId, public readonly string $kind) {}

    public function envelope(): Envelope
    {
        $payment = $this->payment();

        return new Envelope(subject: match ($this->kind) {
            'verified' => "Thank you — payment received ({$payment->receipt_number})",
            default => 'About the payment you reported',
        });
    }

    public function content(): Content
    {
        $payment = $this->payment();

        return new Content(view: 'mail.payment', with: [
            'payment' => $payment,
            'kind' => $this->kind,
            'received' => $payment->amount()->format(),
            'business' => app(BusinessSettings::class),
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $payment = $this->payment();

        return $this->kind === 'verified' && $payment->receipt_pdf_path !== null
            ? [Attachment::fromStorageDisk('local', $payment->receipt_pdf_path)->as(ReceiptPdf::filename($payment))->withMime('application/pdf')]
            : [];
    }

    private function payment(): Payment
    {
        return Payment::query()->with('contact')->findOrFail($this->paymentId);
    }
}
