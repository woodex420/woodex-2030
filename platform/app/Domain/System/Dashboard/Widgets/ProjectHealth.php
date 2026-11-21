<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\Projects\Models\Project;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;

/**
 * How the active projects stand against their dates today (the owner's document 05 §7: "Project health").
 *
 * Every active project is in exactly one of four states (Project::HEALTH), counted by Project::health(), the
 * scope the Projects list asks for `?health=…`. The four add up to the active projects.
 *
 * The owner's document also names "budget variance" and "handover status". A project carries no budget to vary
 * from yet, and handover is a stage that the Projects list already filters by; neither is a figure here (DL-044).
 */
final class ProjectHealth extends Widget
{
    private const TONES = ['late' => 'error', 'milestone' => 'warning', 'on_time' => 'success', 'undated' => 'navy'];

    public function key(): string
    {
        return 'project_health';
    }

    public function title(): string
    {
        return 'Project health';
    }

    public function description(): string
    {
        return 'Active projects against their dates, as of today';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('projects.view');
    }

    public function width(): string
    {
        return 'narrow';
    }

    public function data(DashboardContext $context): array
    {
        $states = [];
        $active = 0;

        foreach (Project::HEALTH as $health => $label) {
            $count = Project::query()->health($health)->count();
            $active += $count;
            $states[] = new Figure('project_health.'.$health, $label, $count, route('admin.projects.index', ['health' => $health]), noun: ['project', 'projects'], tone: self::TONES[$health]);
        }

        return [
            'states' => $states,
            'active' => new Figure('project_health.active', 'Active projects', $active, route('admin.projects.index', ['status' => 'active']), noun: ['project', 'projects']),
            'most' => max(1, ...array_map(fn (Figure $state): int => $state->count, $states)),
            'as_of' => $context->now->format('j M Y'),
        ];
    }

    public function isEmpty(array $data): bool
    {
        return $data['active']->count === 0;
    }

    public function emptyWords(array $data = []): string
    {
        return 'No project is active yet. A project starts when a quotation is accepted and turned into one.';
    }
}
