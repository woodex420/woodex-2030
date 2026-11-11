<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Projects;

use App\Domain\Projects\Models\Project;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Handovers, in the open (PRJ-007). A job enters this list when it reaches finishing or handover;
 * the checklist and the client's signature still live on the project, but the studio can see at a
 * glance which jobs are waiting on a walkthrough, a signature or a last tick.
 *
 * The sidebar used to show "Handovers P4b" while the flow was already built — this is the screen
 * that badge was hiding.
 */
#[Layout('layouts::admin')]
#[Title('Handovers')]
class HandoversIndex extends Component
{
    use InteractsWithAdmin;

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    public function render(): View
    {
        $projects = Project::query()
            ->with(['contact:id,name', 'handoverItems'])
            ->where(fn ($query) => $query
                ->whereNotNull('handover_started_at')
                ->orWhereIn('stage', ['finishing', 'handover']))
            ->where('status', '!=', 'cancelled')
            ->orderByRaw('handover_started_at IS NULL')
            ->orderByDesc('handover_started_at')
            ->orderByDesc('id')
            ->get();

        return view('livewire.admin.projects.handovers-index', [
            'projects' => $projects,
        ]);
    }
}
