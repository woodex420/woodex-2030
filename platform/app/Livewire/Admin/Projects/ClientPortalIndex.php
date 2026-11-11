<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Projects;

use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\PortalLinks;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One place for every client door (PRJ-006). The link already lives on each project; the sidebar
 * used to advertise a "Client Portal" screen with a P4b badge while the feature was live — this is
 * that screen: the list of jobs with a working signed portal link, ready to send.
 *
 * Links are signed on demand and valid for 120 days, so a client who lost the message can be sent
 * a fresh one from here without touching the project.
 */
#[Layout('layouts::admin')]
#[Title('Client portal')]
class ClientPortalIndex extends Component
{
    use InteractsWithAdmin;

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    public function render(): View
    {
        $projects = Project::query()
            ->with(['contact:id,name'])
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        return view('livewire.admin.projects.client-portal-index', [
            'projects' => $projects,
            'links' => $projects->mapWithKeys(fn (Project $project): array => [$project->id => PortalLinks::absolute($project)]),
            'shareable' => $projects->mapWithKeys(fn (Project $project): array => [$project->id => PortalLinks::whatsappShare($project)]),
        ]);
    }
}
