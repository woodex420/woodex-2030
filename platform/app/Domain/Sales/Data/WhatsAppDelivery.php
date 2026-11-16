<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

/**
 * The outcome of putting a quotation in front of a client on WhatsApp (QUO-008, D-111).
 *
 * A failed delivery never fails the quotation: the snapshot, the PDF and the link already exist, and the team is
 * told exactly why the message did not go — so they can copy the link instead of believing the client has it.
 */
final readonly class WhatsAppDelivery
{
    private function __construct(
        public bool $ok,
        public ?string $messageId,
        public ?string $error,
        public bool $byTemplate,
    ) {}

    public static function sent(string $messageId, bool $byTemplate = false): self
    {
        return new self(true, $messageId, null, $byTemplate);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error, false);
    }

    /** One line for the timeline and the toast: what happened, in the studio's words. */
    public function summary(string $quoteLabel): string
    {
        if (! $this->ok) {
            return "{$quoteLabel} could not be sent on WhatsApp: {$this->error}";
        }

        return $this->byTemplate
            ? "{$quoteLabel} sent on WhatsApp (template, the 24-hour window was closed)."
            : "{$quoteLabel} sent on WhatsApp.";
    }
}
