<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Automation\Models\AutomationSequence;
use App\Domain\Automation\Models\AutomationStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The built-in sequences (AUT-002), seeded as editable rows, not code. Wording lives in Meta's
 * approved templates — the steps only say WHICH moment ("follow_up", "payment_reminder"), so
 * changing a sentence is a Meta edit, never a deploy. The review-request flow already exists as
 * its own command (RequestClientReviewsCommand) and is deliberately not a sequence.
 */
class AutomationSequenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->sequences() as $definition) {
            $steps = $definition['steps'];
            unset($definition['steps']);

            $sequence = AutomationSequence::query()->firstOrNew(['slug' => $definition['slug']]);
            $sequence->fill($definition + ['is_builtin' => true]);
            $sequence->save();

            // Steps are seeded once; afterwards they are the studio's to edit (AUT-002).
            if ($sequence->steps()->doesntExist()) {
                $sequence->steps()->delete();

                foreach ($steps as $position => $step) {
                    AutomationStep::create(['sequence_id' => $sequence->id, 'position' => $position + 1] + $step);
                }
            }
        }
    }

    /** Owner-requested repair only: never updates existing definitions, steps or enrolments. */
    public function restoreMissingPaused(): int
    {
        return DB::transaction(function (): int {
            $created = 0;
            foreach ($this->sequences() as $definition) {
                $steps = $definition['steps'];
                unset($definition['steps']);
                $sequence = AutomationSequence::query()->firstOrCreate(
                    ['slug' => $definition['slug']],
                    $definition + ['is_builtin' => true, 'is_active' => false],
                );
                if (! $sequence->wasRecentlyCreated) {
                    continue;
                }
                foreach ($steps as $position => $step) {
                    AutomationStep::create(['sequence_id' => $sequence->id, 'position' => $position + 1] + $step);
                }
                $created++;
            }

            return $created;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sequences(): array
    {
        return [
            [
                'slug' => 'lead-not-reached',
                'name' => 'Lead not reached',
                'description' => 'A new enquiry nobody has spoken to yet: a WhatsApp nudge, then calls before the lead goes cold.',
                'trigger' => 'lead_created',
                'exits_on_reply' => true,
                'steps' => [
                    ['kind' => 'wait', 'wait_minutes' => 1440],
                    ['kind' => 'send_template', 'template_event' => 'follow_up'],
                    ['kind' => 'wait', 'wait_minutes' => 2880],
                    ['kind' => 'create_task', 'task_title' => 'Call {first_name} — second attempt', 'task_due_minutes' => 240],
                    ['kind' => 'wait', 'wait_minutes' => 4320],
                    ['kind' => 'create_task', 'task_title' => 'Final call to {first_name}, or mark the lead lost', 'task_due_minutes' => 240],
                ],
            ],
            [
                'slug' => 'quote-not-viewed',
                'name' => 'Quotation not opened',
                'description' => 'The quotation went out and the client has not even opened it: a nudge, then a call.',
                'trigger' => 'quotation_sent',
                'exits_on_reply' => true,
                'steps' => [
                    ['kind' => 'wait', 'wait_minutes' => 4320],
                    ['kind' => 'send_template', 'template_event' => 'follow_up'],
                    ['kind' => 'wait', 'wait_minutes' => 2880],
                    ['kind' => 'create_task', 'task_title' => 'Call {first_name} about the unopened quotation', 'task_due_minutes' => 240],
                ],
            ],
            [
                'slug' => 'quote-viewed-not-accepted',
                'name' => 'Quotation seen but silent',
                'description' => 'They read it and did not answer: the classic follow-up window, one nudge then a call.',
                'trigger' => 'quotation_viewed',
                'exits_on_reply' => true,
                'steps' => [
                    ['kind' => 'wait', 'wait_minutes' => 2880],
                    ['kind' => 'send_template', 'template_event' => 'follow_up'],
                    ['kind' => 'wait', 'wait_minutes' => 4320],
                    ['kind' => 'create_task', 'task_title' => 'Follow up with {first_name} — quotation seen, no answer', 'task_due_minutes' => 240],
                ],
            ],
            [
                'slug' => 'invoice-reminders',
                'name' => 'Invoice reminders',
                'description' => 'A week after issue, and a week after that: polite payment reminders by template, then a task for the team.',
                'trigger' => 'invoice_issued',
                'exits_on_reply' => true,
                'steps' => [
                    ['kind' => 'wait', 'wait_minutes' => 10080],
                    ['kind' => 'send_template', 'template_event' => 'payment_reminder'],
                    ['kind' => 'wait', 'wait_minutes' => 10080],
                    ['kind' => 'send_template', 'template_event' => 'payment_reminder'],
                    ['kind' => 'wait', 'wait_minutes' => 1440],
                    ['kind' => 'create_task', 'task_title' => 'Call {first_name} about the overdue invoice', 'task_due_minutes' => 240],
                ],
            ],
            [
                'slug' => 'post-visit',
                'name' => 'After a site visit',
                'description' => 'A thank-you the same day, and a task to bring the visit into a concept or quotation.',
                'trigger' => 'visit_completed',
                'exits_on_reply' => true,
                'steps' => [
                    ['kind' => 'wait', 'wait_minutes' => 120],
                    ['kind' => 'send_template', 'template_event' => 'follow_up'],
                    ['kind' => 'wait', 'wait_minutes' => 4320],
                    ['kind' => 'create_task', 'task_title' => 'Turn {first_name}’s visit into a quotation', 'task_due_minutes' => 480],
                ],
            ],
            [
                'slug' => 'lost-lead-nurture',
                'name' => 'Lost lead check-in',
                'description' => 'A lost lead stays warm: one check-in after a week, one task after a month, then it rests.',
                'trigger' => 'lead_lost',
                'exits_on_reply' => true,
                'steps' => [
                    ['kind' => 'wait', 'wait_minutes' => 10080],
                    ['kind' => 'send_template', 'template_event' => 'follow_up'],
                    ['kind' => 'wait', 'wait_minutes' => 30240],
                    ['kind' => 'create_task', 'task_title' => 'Check in on {first_name} — lost in October, anything changed?', 'task_due_minutes' => 240],
                ],
            ],
        ];
    }
}
