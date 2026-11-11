<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Actions\ManageTask;
use App\Domain\Crm\Enums\LeadPriority;
use App\Domain\Crm\Enums\TaskRecurrence;
use App\Domain\Crm\Enums\TaskStatus;
use App\Domain\Crm\Enums\TaskType;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Task;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Follow-up tasks (CRM-007): overdue, today, upcoming; mine or the whole team.
 */
#[Layout('layouts::admin')]
#[Title('Tasks')]
class TasksIndex extends Component
{
    use InteractsWithAdmin;

    #[Url(except: 'mine')]
    public string $scope = 'mine';

    #[Url(except: 'open')]
    public string $show = 'open';

    public bool $showCreate = false;

    public string $title = '';

    public string $type = 'todo';

    public string $priority = 'normal';

    public string $due = '';

    public string $assignee = '';

    public string $leadRef = '';

    public string $recurrence = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);

        if (! $this->user()->can('leads.view_all')) {
            $this->scope = 'mine';
        }
    }

    public function openCreate(): void
    {
        $this->reset('title', 'due', 'leadRef', 'recurrence');
        $this->type = 'todo';
        $this->priority = 'normal';
        $this->assignee = (string) $this->user()->id;
        $this->due = now()->addDay()->setTime(11, 0)->format('Y-m-d\TH:i');
        $this->resetValidation();
        $this->showCreate = true;
    }

    public function create(): void
    {
        $user = $this->user();

        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::enum(TaskType::class)],
            'priority' => ['required', Rule::enum(LeadPriority::class)],
            'due' => ['nullable', 'date'],
            'assignee' => ['nullable', Rule::exists('users', 'id')->where('is_active', true)],
            'leadRef' => ['nullable', 'string', 'max:20', Rule::exists('leads', 'ref')->whereNull('deleted_at')],
            'recurrence' => ['nullable', Rule::enum(TaskRecurrence::class)],
        ], attributes: ['leadRef' => 'lead reference']);

        if ($this->recurrence !== '' && $this->due === '') {
            $this->addError('due', 'A repeating task needs a first due date.');

            return;
        }

        $lead = $this->leadRef !== '' ? Lead::query()->where('ref', trim($this->leadRef))->first() : null;

        if ($lead !== null && ! $user->can('view', $lead)) {
            $this->addError('leadRef', 'You do not have access to this lead.');

            return;
        }

        $assignee = $this->assignee !== '' ? User::query()->find((int) $this->assignee) : $user;

        if ($assignee !== null && $assignee->id !== $user->id && ! $user->can('leads.assign')) {
            $assignee = $user;
        }

        app(ManageTask::class)->create(
            $this->title,
            $this->due !== '' ? Carbon::parse($this->due) : null,
            $assignee,
            $user,
            $lead,
            TaskType::from($this->type),
            LeadPriority::from($this->priority),
            recurrence: TaskRecurrence::tryFrom($this->recurrence),
        );

        $this->showCreate = false;
        $this->toast('Task added.');
    }

    public function toggle(int $taskId): void
    {
        $task = $this->findTask($taskId);
        $manage = app(ManageTask::class);

        $task->status === TaskStatus::Done ? $manage->reopen($task) : $manage->complete($task);
    }

    public function delete(int $taskId): void
    {
        $task = $this->findTask($taskId);
        $user = $this->user();

        abort_unless($task->created_by === $user->id || $user->can('leads.view_all'), 403);

        $task->delete();
        $this->toast('Task deleted.');
    }

    public function render(): View
    {
        $user = $this->user();
        $query = $this->visibleTasks()->with(['lead.contact:id,name', 'assignee:id,name']);

        if ($this->show === 'done') {
            $done = (clone $query)->where('status', TaskStatus::Done->value)->latest('completed_at')->limit(50)->get();
            $groups = ['Completed' => $done];
        } else {
            $open = (clone $query)->where('status', TaskStatus::Open->value)->orderByRaw('due_at is null')->orderBy('due_at')->limit(300)->get();
            $endOfToday = now()->endOfDay();

            $groups = [
                'Overdue' => $open->filter(fn (Task $t) => $t->due_at !== null && $t->due_at->isPast()),
                'Today' => $open->filter(fn (Task $t) => $t->due_at !== null && ! $t->due_at->isPast() && $t->due_at->lte($endOfToday)),
                'Upcoming' => $open->filter(fn (Task $t) => $t->due_at !== null && $t->due_at->gt($endOfToday)),
                'No due date' => $open->filter(fn (Task $t) => $t->due_at === null),
            ];
        }

        return view('livewire.admin.crm.tasks-index', [
            'groups' => array_filter($groups, fn ($items) => $items->isNotEmpty()),
            'canSeeTeam' => $user->can('leads.view_all'),
            'staff' => $this->staff(),
            'types' => TaskType::options(),
            'recurrences' => TaskRecurrence::options(),
            'priorities' => LeadPriority::options(),
            'counts' => [
                'open' => $this->visibleTasks()->where('status', TaskStatus::Open->value)->count(),
                'overdue' => $this->visibleTasks()->where('status', TaskStatus::Open->value)->where('due_at', '<', now())->count(),
            ],
        ]);
    }

    /**
     * @return Builder<Task>
     */
    private function visibleTasks(): Builder
    {
        $user = $this->user();

        return Task::query()->when(
            $this->scope !== 'team' || ! $user->can('leads.view_all'),
            fn (Builder $q) => $q->where(fn (Builder $mine) => $mine->where('assignee_id', $user->id)->orWhere(fn (Builder $c) => $c->whereNull('assignee_id')->where('created_by', $user->id))),
        );
    }

    private function findTask(int $taskId): Task
    {
        $user = $this->user();
        $task = Task::query()->findOrFail($taskId);

        abort_unless(
            $task->assignee_id === $user->id || $task->created_by === $user->id || $user->can('leads.view_all'),
            403,
        );

        return $task;
    }
}
