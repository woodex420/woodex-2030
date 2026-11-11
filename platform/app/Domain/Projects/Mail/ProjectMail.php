<?php

declare(strict_types=1);

namespace App\Domain\Projects\Mail;

use App\Domain\Projects\Models\Project;
use App\Settings\BusinessSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The client's copy of what is happening on their project (PRJ-002, PRJ-004): one message shape, whether it is a
 * stage change or an update from the site. The photographs and the portal link join it in the next part of this
 * phase; until then the email is the message.
 */
final class ProjectMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  string|null  $url  where the client can read more (the portal, from P4 part 2)
     * @param  string|null  $buttonLabel  label of that link; no button when either is null
     */
    public function __construct(
        public readonly int $projectId,
        public readonly string $heading,
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly ?string $buttonLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        $project = Project::query()->with('contact')->findOrFail($this->projectId);

        return new Content(view: 'mail.project', with: [
            'project' => $project,
            'heading' => $this->heading,
            'body' => $this->body,
            'url' => $this->url,
            'buttonLabel' => $this->buttonLabel,
            'business' => app(BusinessSettings::class),
        ]);
    }
}
