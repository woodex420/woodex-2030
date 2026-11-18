<?php

declare(strict_types=1);

namespace App\Domain\Projects\Mail;

use App\Settings\BusinessSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The six digits that open the client portal (PRJ-006). It is the only thing we ever email to a client who has
 * lost their link, and it stops working the moment it is used.
 */
final class PortalCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $name, public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Woodex portal code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.portal-code', with: [
            'name' => $this->name,
            'code' => $this->code,
            'business' => app(BusinessSettings::class),
        ]);
    }
}
