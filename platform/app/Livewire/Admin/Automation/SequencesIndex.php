<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automation;

use App\Domain\Automation\Enums\EnrolmentStatus;
use App\Domain\Automation\Models\AutomationEnrolment;
use App\Domain\Automation\Models\AutomationSequence;
use App\Livewire\Concerns\InteractsWithAdmin;
use Database\Seeders\AutomationSequenceSeeder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The sequences screen (AUT-001/AUT-002/AUT-005): what the studio follows up, who is enrolled,
 * and exactly what happened — every wait, send, skip and hold. Sequences are editable data, so
 * this is a reader's view of them (steps as the timeline the engine will run), with the on/off
 * and the enrolment controls that matter day to day.
 */
#[Layout('layouts::admin')]
#[Title('Sequences')]
class SequencesIndex extends Component
{
    use InteractsWithAdmin;

    public ?int $selectedId = null;

    public function mount(): void
    {
        $this->authorize('automation.manage');
    }

    public function restoreMissingDefaults(AutomationSequenceSeeder $seeder): void
    {
        $this->authorize('automation.manage');
        abort_unless($this->user()->hasRole('owner'), 403);
        $count = $seeder->restoreMissingPaused();
        activity('system')->causedBy($this->user())->withProperties(['created' => $count])
            ->log('Restored missing sequence defaults, paused. Existing definitions and enrolments unchanged.');
        $this->toast($count === 0 ? 'All defaults already exist — nothing changed.' : $count.' missing defaults added, paused. No clients enrolled or messages sent.');
    }

    public function select(?int $id): void
    {
        $this->authorize('automation.manage');
        $this->selectedId = $id;
    }

    public function toggleSequence(int $id): void
    {
        $this->authorize('automation.manage');
        $sequence = AutomationSequence::query()->findOrFail($id);
        $sequence->forceFill(['is_active' => ! $sequence->is_active])->save();

        activity('system')
            ->causedBy($this->user())
            ->withProperties(['sequence' => $sequence->slug, 'active' => $sequence->is_active])
            ->log(($sequence->is_active ? 'Resumed sequence ' : 'Paused sequence ').$sequence->name.'.');

        $this->toast($sequence->is_active ? 'Sequence on — new leads will be enrolled.' : 'Sequence off — current enrolments still run.');
    }

    public function pauseEnrolment(int $id): void
    {
        $this->authorize('automation.manage');
        $enrolment = AutomationEnrolment::query()->findOrFail($id);
        $enrolment->pause();
        $this->toast('Enrolment paused — it holds its place.');
    }

    public function resumeEnrolment(int $id): void
    {
        $this->authorize('automation.manage');
        $enrolment = AutomationEnrolment::query()->findOrFail($id);
        $enrolment->resume();
        $this->toast('Enrolment resumed.');
    }

    public function cancelEnrolment(int $id): void
    {
        $this->authorize('automation.manage');
        $enrolment = AutomationEnrolment::query()->findOrFail($id);
        $enrolment->end(EnrolmentStatus::Cancelled, 'cancelled from the sequences screen');
        $this->toast('Enrolment cancelled — nothing more will be sent.');
    }

    public function render(): View
    {
        $this->authorize('automation.manage');
        $sequences = AutomationSequence::query()
            ->with('steps')
            ->withCount([
                'enrolments as active_count' => fn ($query) => $query->whereIn('status', [EnrolmentStatus::Active->value, EnrolmentStatus::Paused->value]),
                'enrolments as finished_count' => fn ($query) => $query->whereIn('status', [EnrolmentStatus::Completed->value, EnrolmentStatus::Exited->value, EnrolmentStatus::Cancelled->value]),
            ])
            ->orderBy('name')
            ->get();

        $selected = $this->selectedId === null ? null : AutomationSequence::query()->with('steps')->find($this->selectedId);

        $enrolments = $selected === null ? collect() : AutomationEnrolment::query()
            ->where('sequence_id', $selected->id)
            ->with(['lead.contact', 'runs.step'])
            ->latest('enrolled_at')
            ->limit(20)
            ->get();

        return view('livewire.admin.automation.sequences-index', [
            'sequences' => $sequences,
            'selected' => $selected,
            'enrolments' => $enrolments,
        ]);
    }
}
