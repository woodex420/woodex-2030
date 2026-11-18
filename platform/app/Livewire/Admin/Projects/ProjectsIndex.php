<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Projects;

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
use Livewire\WithPagination;

/**
 * Every job in the studio (PRJ-005): what it is, where it stands, what is invoiced and what has been collected.
 * The board (admin.projects.board) is the same data arranged by stage.
 */
#[Layout('layouts::admin')]
#[Title('Projects')]
class ProjectsIndex extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: 'active')]
    public string $status = 'active';

    #[Url(except: '')]
    public string $owner = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'stage', 'status', 'owner'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $projects = $this->query()->paginate(25);

        return view('livewire.admin.projects.projects-index', [
            'projects' => $projects,
            'counts' => $this->counts(),
            'total' => (int) Project::query()->count(),
            'stages' => ProjectStage::cases(),
            'statuses' => ProjectStatus::cases(),
            'staff' => $this->staff(),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }

    /**
     * @return Builder<Project>
     */
    private function query(): Builder
    {
        return Project::query()
            ->with(['contact:id,name,phone', 'manager:id,name', 'milestones', 'invoices'])
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('code', 'like', '%'.$this->search.'%')
                ->orWhere('name', 'like', '%'.$this->search.'%')
                ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', '%'.$this->search.'%'))
                ->orWhereHas('quote', fn (Builder $c) => $c->where('number', 'like', '%'.$this->search.'%'))))
            ->when($this->stage !== '', fn (Builder $q) => $q->where('stage', $this->stage))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->owner === 'me', fn (Builder $q) => $q->where('pm_id', $this->user()->id))
            ->when($this->owner !== '' && $this->owner !== 'me', fn (Builder $q) => $q->where('pm_id', (int) $this->owner))
            // The job that is late comes first, then the one whose target date is nearest.
            ->orderByRaw('case when target_date is null then 1 else 0 end')
            ->orderBy('target_date')
            ->latest('id');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return Project::query()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
    }
}
