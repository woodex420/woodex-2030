<?php

declare(strict_types=1);

namespace App\Domain\Crm\Mail;

use App\Domain\Crm\Models\Lead;
use App\Settings\BusinessSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The ask-for-number reply (P8.5): a single service response to the client's own enquiry when it
 * arrived with an email but no phone number. Plain, honest, no marketing.
 */
final class AskForNumberMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $leadId,
        public readonly int $taskId,
    ) {}

    public function envelope(): Envelope
    {
        $lead = $this->lead();

        return new Envelope(subject: 'Your enquiry '.$lead->ref.' — one quick thing');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.ask-for-number', with: [
            'lead' => $this->lead(),
            'business' => app(BusinessSettings::class),
        ]);
    }

    private function lead(): Lead
    {
        return Lead::query()->with('contact')->findOrFail($this->leadId);
    }
}
