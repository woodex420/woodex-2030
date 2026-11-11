<?php

declare(strict_types=1);

namespace App\Domain\Crm\Jobs;

use App\Domain\Crm\Actions\LogLeadActivity;
use App\Domain\Crm\Actions\ManageTask;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\TaskType;
use App\Domain\Crm\Mail\AskForNumberMail;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Task;
use App\Domain\System\Models\Automation;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * P8.5 ask-for-number: when a NEW enquiry arrives with an email but not a single phone number, the
 * studio replies once asking for a WhatsApp number (a service response to the client's own enquiry
 * — not marketing, nothing without consent) and opens a follow-up task so a silent inbox never
 * forgets the lead. The task is also the exactly-once anchor: if it exists, the ask already went.
 */
final class AskForPhoneNumber implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public const TASK_TITLE = "Get the client's phone number — the enquiry came without one";

    public function __construct(
        public readonly int $leadId,
        public readonly bool $isNewLead,
    ) {}

    public function handle(LogLeadActivity $logActivity, ManageTask $manageTask): void
    {
        if (! Automation::enabled('woodex:ask-for-number')) {
            return;
        }

        if (! $this->isNewLead) {
            return; // A returning enquiry joins a lead that already went through this.
        }

        $lead = Lead::query()->with(['contact', 'owner'])->find($this->leadId);

        if ($lead === null) {
            return;
        }

        $contact = $lead->contact;

        // Only when there is an email to reply to and no number anywhere to reach them by.
        if ($contact->email === null || $contact->phone !== null || $contact->whatsapp !== null || $contact->alt_phone !== null) {
            return;
        }

        if (Task::query()->where('lead_id', $lead->id)->where('title', self::TASK_TITLE)->exists()) {
            return; // The ask already went out — retries and replays must never send a second email.
        }

        $task = $manageTask->create(
            title: self::TASK_TITLE,
            dueAt: $lead->sla_due_at ?? now()->addDay(),
            assignee: $lead->owner,
            by: User::query()->role('owner')->first(),
            lead: $lead,
            type: TaskType::Todo,
            description: 'The enquiry arrived by email only. A reply asking for a WhatsApp number was sent automatically; chase it if there is no answer.',
        );

        Mail::to($contact->email)->queue(new AskForNumberMail($lead->id, $task->id));

        $logActivity->handle(
            $lead->refresh(),
            ActivityType::System,
            body: 'Asked '.$contact->email.' for a phone number (the enquiry arrived without one) and opened follow-up task #'.$task->id.'.',
        );
    }
}
