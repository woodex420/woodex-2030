<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Automation\Actions\EnrollLead;
use App\Domain\Automation\Enums\SequenceTrigger;
use App\Domain\Crm\Enums\LeadPriority;
use App\Domain\Crm\Enums\TaskRecurrence;
use App\Domain\Crm\Enums\TaskStatus;
use App\Domain\Crm\Enums\TaskType;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Create, complete and reopen follow-up tasks (CRM-007). The reminder defaults to 30 minutes before the
 * due time; completing a repeating task creates its next occurrence.
 */
final class ManageTask
{
    public function create(
        string $title,
        ?CarbonInterface $dueAt,
        ?User $assignee,
        ?User $by,
        ?Lead $lead = null,
        TaskType $type = TaskType::Todo,
        LeadPriority $priority = LeadPriority::Normal,
        ?string $description = null,
        ?TaskRecurrence $recurrence = null,
    ): Task {
        return Task::create([
            'title' => trim($title),
            'description' => $description,
            'type' => $type,
            'due_at' => $dueAt,
            'remind_at' => $dueAt?->copy()->subMinutes(30),
            'assignee_id' => $assignee->id ?? $lead->owner_id ?? $by?->id,
            'created_by' => $by?->id,
            'lead_id' => $lead?->id,
            'contact_id' => $lead?->contact_id,
            'priority' => $priority,
            'status' => TaskStatus::Open,
            'recurrence' => $recurrence,
        ]);
    }

    public function complete(Task $task): Task
    {
        $task->forceFill(['status' => TaskStatus::Done, 'completed_at' => now()])->save();

        if ($task->recurrence !== null) {
            $this->scheduleNext($task);
        }

        if ($task->lead_id !== null) {
            $task->lead()->update(['last_activity_at' => now()]);

            // AUT-001: a finished site visit starts the post-visit sequences.
            if ($task->type === TaskType::Visit) {
                $lead = Lead::query()->find($task->lead_id);

                if ($lead !== null) {
                    app(EnrollLead::class)->forTrigger(SequenceTrigger::VisitCompleted, $lead);
                }
            }
        }

        return $task;
    }

    public function reopen(Task $task): Task
    {
        $task->forceFill(['status' => TaskStatus::Open, 'completed_at' => null])->save();

        return $task;
    }

    /**
     * Next occurrence of a repeating task (skipped if it already exists, e.g. completed → reopened → completed).
     */
    private function scheduleNext(Task $task): ?Task
    {
        /** @var TaskRecurrence $recurrence */
        $recurrence = $task->recurrence;
        $due = $recurrence->next($task->due_at ?? now());

        // A task completed long after it was due continues from today, not from the past.
        while ($due->isPast()) {
            $due = $recurrence->next($due);
        }

        $exists = Task::query()
            ->where('status', TaskStatus::Open)
            ->where('title', $task->title)
            ->where('assignee_id', $task->assignee_id)
            ->where('lead_id', $task->lead_id)
            ->where('due_at', '>=', $due->copy()->startOfDay())
            ->exists();

        if ($exists) {
            return null;
        }

        return Task::create([
            'title' => $task->title,
            'description' => $task->description,
            'type' => $task->type,
            'due_at' => $due,
            'remind_at' => $due->copy()->subMinutes(30),
            'assignee_id' => $task->assignee_id,
            'created_by' => $task->created_by,
            'lead_id' => $task->lead_id,
            'contact_id' => $task->contact_id,
            'priority' => $task->priority,
            'status' => TaskStatus::Open,
            'recurrence' => $recurrence,
        ]);
    }
}
