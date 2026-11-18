<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Enums\EnrolmentStatus;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Enums\SequenceTrigger;
use App\Domain\Automation\Enums\StepKind;
use App\Domain\Automation\Models\AutomationEnrolment;
use App\Domain\Automation\Models\AutomationStepRun;
use App\Domain\Conversations\Actions\SendConversationMessage;
use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Exceptions\ConsentRequired;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Actions\ManageTask;
use App\Domain\Crm\Actions\MoveLeadToStage;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\System\Models\WhatsAppTemplate;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The sequences heartbeat (AUT-001): every due step of every active enrolment, once a minute.
 *
 * Order of business per run: is the enrolment still worth continuing (lead won or lost, client
 * replied, opt-out)? Then the step itself. Sends pass the AUT-006 guard first — quiet hours and
 * the daily cap hold the message for its next legal moment rather than dropping or forcing it.
 */
final class RunSequences
{
    public function __construct(
        private readonly EnrollLead $enroll,
        private readonly SendConversationMessage $send,
        private readonly ManageTask $tasks,
        private readonly MoveLeadToStage $move,
        private readonly AutomatedSendGuard $guard,
    ) {}

    /**
     * @return array{ran: int, sent: int, held: int, skipped: int, failed: int, exited: int}
     */
    public function handle(): array
    {
        $counters = ['ran' => 0, 'sent' => 0, 'held' => 0, 'skipped' => 0, 'failed' => 0, 'exited' => 0];

        // One invocation walks a whole chain awake: a wait that ends now schedules its step for
        // now, and that step belongs to this same minute, not the next cron tick. Bounded so a
        // pathological loop (a sequence that somehow always reschedules to "now") still ends.
        for ($pass = 0; $pass < 10; $pass++) {
            $claimedThisPass = 0;

            $due = AutomationStepRun::query()
                ->where('status', RunStatus::Pending->value)
                ->where('scheduled_at', '<=', now())
                ->whereHas('enrolment', fn ($query) => $query->where('status', EnrolmentStatus::Active->value))
                ->orderBy('scheduled_at')
                ->with(['enrolment.lead.contact', 'enrolment.sequence', 'step'])
                ->limit(200)
                ->get();

            foreach ($due as $run) {
                // Atomic claim: only one process may execute a run, however many are watching.
                $claimed = AutomationStepRun::query()
                    ->whereKey($run->id)
                    ->where('status', RunStatus::Pending->value)
                    ->update(['status' => RunStatus::Running->value, 'ran_at' => now()]);

                if ($claimed === 0) {
                    continue;
                }

                // The claim went through the builder, so this model still thinks it is pending —
                // make the claimed state its baseline, or a later forceFill back to Pending (a
                // held send) would compare clean against the hydrated original and be dropped
                // from the UPDATE.
                $run->forceFill(['status' => RunStatus::Running, 'ran_at' => now()]);
                $run->syncOriginal();

                $claimedThisPass++;
                $counters['ran']++;
                $this->execute($run, $counters);
            }

            if ($claimedThisPass === 0) {
                break;
            }
        }

        return $counters;
    }

    /**
     * @param  array{ran: int, sent: int, held: int, skipped: int, failed: int, exited: int}  $counters
     */
    private function execute(AutomationStepRun $run, array &$counters): void
    {
        $enrolment = $run->enrolment;
        $lead = $enrolment->lead;

        if ($lead === null) {
            $enrolment->end(EnrolmentStatus::Exited, 'The lead was deleted.');
            $run->skipped('lead gone');

            return;
        }

        // Exit conditions first: no step runs for a lead that has moved on.
        if ($exit = $this->exitReason($enrolment, $lead)) {
            $enrolment->end(EnrolmentStatus::Exited, $exit);
            $run->skipped($exit);
            $counters['exited']++;

            return;
        }

        try {
            $outcome = $this->runStep($run, $enrolment, $lead, $counters);
        } catch (Throwable $exception) {
            report($exception);
            $run->failed($exception->getMessage());
            $counters['failed']++;
            $outcome = 'continue';
        }

        if ($outcome === 'held') {
            $counters['held']++;

            return; // the run stays pending at its new slot; the sequence continues then
        }

        $this->enroll->scheduleNext($enrolment, $run->step->position, now());
    }

    /**
     * @param  array{ran: int, sent: int, held: int, skipped: int, failed: int, exited: int}  $counters
     * @return 'done'|'held'|'continue'
     */
    private function runStep(AutomationStepRun $run, AutomationEnrolment $enrolment, Lead $lead, array &$counters): string
    {
        $step = $run->step;

        return match ($step->kind) {
            StepKind::Wait => $this->doWait($run),
            StepKind::SendTemplate => $this->doSendTemplate($run, $enrolment, $lead, $counters),
            StepKind::CreateTask => $this->doCreateTask($run, $lead),
            StepKind::ChangeStage => $this->doChangeStage($run, $lead),
        };
    }

    private function doWait(AutomationStepRun $run): string
    {
        $run->done('waited');

        return 'done';
    }

    /**
     * @param  array{ran: int, sent: int, held: int, skipped: int, failed: int, exited: int}  $counters
     */
    private function doSendTemplate(AutomationStepRun $run, AutomationEnrolment $enrolment, Lead $lead, array &$counters): string
    {
        $contact = $lead->contact;

        if ($contact === null) {
            $run->skipped('the lead has no contact');

            return 'continue';
        }

        if ($contact->opted_out_at !== null) {
            $enrolment->end(EnrolmentStatus::Exited, 'the client opted out');
            $run->skipped('opted out');
            $counters['exited']++;

            return 'continue';
        }

        /** @var Conversation|null $conversation */
        $conversation = Conversation::query()
            ->where('contact_id', $contact->id)
            ->where('channel', Channel::Whatsapp->value)
            ->first();

        if ($conversation === null) {
            $run->skipped('no WhatsApp conversation yet');

            return 'continue';
        }

        // AUT-006: quiet hours (unless the client wrote first) and the daily cap.
        $earliest = $this->guard->earliestAllowed($conversation);

        if ($earliest->isFuture()) {
            $run->holdUntil($earliest, 'held for quiet hours until '.$earliest->format('H:i'));

            return 'held';
        }

        if ($this->guard->dailyCapReached($conversation)) {
            $run->holdUntil($this->guard->nextDailySlot(), 'held: daily message cap reached');

            return 'held';
        }

        $event = (string) $run->step->template_event;

        /** @var WhatsAppTemplate|null $template */
        $template = WhatsAppTemplate::forEvent($event);

        if ($template === null) {
            $run->skipped('no approved template mapped for "'.$event.'" (Automation → WhatsApp Templates)');

            return 'continue';
        }

        $firstName = strtok((string) $contact->name, ' ') ?: (string) $contact->name;

        try {
            $message = $this->send->template(
                $conversation,
                $template->name,
                [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $firstName]]]],
                $template->language,
                $this->actorFor($lead),
            );
        } catch (ConsentRequired) {
            $run->skipped('no WhatsApp consent for templates');

            return 'continue';
        }

        if ($message->status === MessageStatus::Sent) {
            $run->done('sent "'.$template->name.'"');
            $counters['sent']++;

            return 'done';
        }

        $run->failed((string) ($message->error_message ?? 'Meta refused the message.'));
        $counters['failed']++;

        return 'continue';
    }

    private function doCreateTask(AutomationStepRun $run, Lead $lead): string
    {
        $name = (string) ($lead->contact->name ?? 'the client');
        $title = str_replace('{first_name}', strtok($name, ' ') ?: $name, (string) $run->step->task_title);

        $task = $this->tasks->create(
            $title,
            $run->step->task_due_minutes !== null && $run->step->task_due_minutes > 0
                ? now()->addMinutes((int) $run->step->task_due_minutes)
                : null,
            null,
            null,
            $lead,
        );

        $run->done('task #'.$task->id.': '.$title);

        return 'done';
    }

    private function doChangeStage(AutomationStepRun $run, Lead $lead): string
    {
        try {
            $stage = PipelineStage::byKey((string) $run->step->stage_key);
        } catch (Throwable) {
            $run->skipped('no pipeline stage "'.$run->step->stage_key.'"');

            return 'continue';
        }

        try {
            $this->move->handle($lead, $stage, null);
        } catch (ValidationException $exception) {
            $run->skipped(implode(' ', $exception->errors()['stage'] ?? ['the move was not allowed']));

            return 'continue';
        }

        $run->done('stage → '.$stage->name);

        return 'done';
    }

    /** Why this enrolment should stop before its next step, or null to carry on. */
    private function exitReason(AutomationEnrolment $enrolment, Lead $lead): ?string
    {
        $stage = $lead->stage;

        if ($stage !== null && $stage->type === StageType::Won) {
            return 'the lead was won';
        }

        if ($stage !== null && $stage->type === StageType::Lost && $enrolment->sequence->trigger !== SequenceTrigger::LeadLost) {
            return 'the lead was lost';
        }

        if (! $enrolment->sequence->exits_on_reply) {
            return null;
        }

        $contact = $lead->contact;

        if ($contact === null) {
            return null;
        }

        $replied = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.contact_id', $contact->id)
            ->where('messages.direction', MessageDirection::In->value)
            ->where('messages.created_at', '>', $enrolment->enrolled_at)
            ->exists();

        return $replied ? 'the client replied' : null;
    }

    /** Automated sends need a human name on them: the lead's owner, else an owner-role user. */
    private function actorFor(Lead $lead): ?User
    {
        if ($lead->owner_id !== null) {
            $owner = User::query()->find($lead->owner_id);

            if ($owner !== null && $owner->is_active) {
                return $owner;
            }
        }

        return User::query()->role('owner')->where('is_active', true)->first();
    }
}
