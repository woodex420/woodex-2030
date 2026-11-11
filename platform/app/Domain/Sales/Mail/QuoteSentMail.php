<?php

declare(strict_types=1);

namespace App\Domain\Sales\Mail;

use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteVersion;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\Sales\Services\QuotePdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The quotation by email (QUO-008, before the WhatsApp API in P4): link + the sent version's PDF.
 */
final class QuoteSentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly int $quoteId, public readonly int $versionId)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $version = $this->version();

        return new Envelope(subject: "Your quotation {$version->snapshot['label']} · {$version->snapshot['company']['brand']}");
    }

    public function content(): Content
    {
        $quote = Quote::query()->with('contact')->findOrFail($this->quoteId);

        return new Content(view: 'mail.quote-sent', with: [
            's' => $this->version()->snapshot,
            'link' => QuoteLinks::portal($quote),
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $version = $this->version();

        return $version->pdf_path === null ? [] : [
            Attachment::fromStorageDisk('local', $version->pdf_path)->as(QuotePdf::filename($version->snapshot))->withMime('application/pdf'),
        ];
    }

    private function version(): QuoteVersion
    {
        /** @var QuoteVersion $version */
        $version = QuoteVersion::query()->where('quote_id', $this->quoteId)->findOrFail($this->versionId);

        return $version;
    }
}
