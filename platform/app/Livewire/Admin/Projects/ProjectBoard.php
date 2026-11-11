<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Projects;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Projects\Actions\ChangeProjectStage;
use App\Domain\Projects\Enums\ProjectStage;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The jobs arranged by stage (PRJ-005). Drag a card to move the work on: planning → design → procurement →
 * execution → finishing → handover → completed. Dropping a card on Completed closes the job.
 */
#[Layout('layouts::admin')]
#[Title('Project board')]
class ProjectBoard extends Component
{
    use InteractsWithAdmin;

    #[Url(except: '')]
    public string $line = '';

    #[Url(except: '')]
    public string $owner = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    public function moveProject(int $projectId, string $stage): void
    {
        $project = Project::query()->findOrFail($projectId);
        $this->authorize('update', $project);

        $target = ProjectStage::tryFrom($stage);

        if ($target === null || $project->stage === $target) {
            return;
        }

        app(ChangeProjectStage::class)->handle($project, $target, $this->user());

        $this->toast($project->code.' moved to '.$target->label().'.');
    }

    public function render(): View
    {
        $projects = Project::query()
            ->with(['contact:id,name', 'manager:id,name', 'milestones'])
            ->when($this->line !== '', fn (Builder $q) => $q->where('business_line', $this->line))
            ->when($this->owner === 'me', fn (Builder $q) => $q->where('pm_id', $this->user()->id))
            ->where('status', '!=', ProjectStatus::Cancelled->value)
            // Finished jobs leave the board a month after they were completed.
            ->where(fn (Builder $q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', now()->subDays(30)))
            ->orderBy('target_date')
            ->limit(300)
            ->get()
            ->groupBy(fn (Project $project): string => $project->stage->value);

        return view('livewire.admin.projects.project-board', [
            'stages' => ProjectStage::cases(),
            'projectsByStage' => $projects,
            'lines' => BusinessLine::options(),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}
