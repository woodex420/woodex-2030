<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMilestone;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The milestones that fall due soon (the owner's document 05 §7: "Upcoming milestones"): how many active
 * projects have one within Project::MILESTONE_SOON_DAYS days, which opens those projects, and the next few by
 * date. A milestone is "coming" by ProjectMilestone::comingSoon(), written once for this list and for the
 * Projects list's `?milestone=soon`.
 */
final class Milestones extends Widget
{
    /** How many of the coming milestones are named; the figure counts all the projects that have one. */
    private const NAMED = 6;

    public function key(): string
    {
        return 'milestones';
    }

    public function title(): string
    {
        return 'Coming milestones';
    }

    public function description(): string
    {
        return 'Of active projects, due today or within '.Project::MILESTONE_SOON_DAYS.' days';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('projects.view');
    }

    public function width(): string
    {
        return 'wide';
    }

    public function data(DashboardContext $context): array
    {
        $today = CarbonImmutable::today();

        $next = ProjectMilestone::query()
            ->tap(ProjectMilestone::comingSoon(...))
            ->whereHas('project', fn (Builder $project) => $project->where('status', ProjectStatus::Active->value))
            ->with('project:id,name,code')
            ->orderBy('due_date')->orderBy('id')
            ->limit(self::NAMED)
            ->get()
            ->map(function (ProjectMilestone $milestone) use ($today): array {
                $days = (int) $today->diffInDays(CarbonImmutable::parse($milestone->due_date->format('Y-m-d')));

                return [
                    'id' => $milestone->id,
                    'name' => $milestone->name,
                    'project' => $milestone->project->name,
                    'code' => $milestone->project->code,
                    'href' => route('admin.projects.show', $milestone->project),
                    'due' => $milestone->due_date->format('j M'),
                    'in' => match ($days) {
                        0 => 'today',
                        1 => 'tomorrow',
                        default => 'in '.$days.' days',
                    },
                    'soon' => $days <= 2,
                ];
            })->all();

        return [
            'projects' => new Figure('milestones.soon', 'Projects with a milestone coming', Project::query()->milestoneSoon()->count(), route('admin.projects.index', ['milestone' => 'soon']), noun: ['project', 'projects']),
            'next' => $next,
            'named' => self::NAMED,
            'dates' => DashboardContext::days($today, $today->addDays(Project::MILESTONE_SOON_DAYS)),
        ];
    }

    public function isEmpty(array $data): bool
    {
        return $data['projects']->count === 0;
    }

    public function emptyWords(array $data = []): string
    {
        return 'No milestone of an active project falls due in the next '.Project::MILESTONE_SOON_DAYS.' days'.(isset($data['dates']) ? ' ('.$data['dates'].')' : '').'.';
    }
}
