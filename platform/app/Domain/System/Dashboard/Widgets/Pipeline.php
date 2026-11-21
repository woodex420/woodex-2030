<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\Crm\Models\PipelineStage;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The leads the person may see, by the stage each stands in now. Each stage opens the Leads list at that stage.
 */
final class Pipeline extends Widget
{
    public function key(): string
    {
        return 'pipeline';
    }

    public function title(): string
    {
        return 'Pipeline';
    }

    public function description(): string
    {
        return 'Leads per stage';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('leads.view');
    }

    public function width(): string
    {
        return 'narrow';
    }

    public function data(DashboardContext $context): array
    {
        $user = $context->user;
        $ownOnly = ! $user->can('leads.view_all');

        $stages = PipelineStage::query()->ordered()
            ->withCount(['leads' => fn (Builder $q) => $q->when($ownOnly, fn (Builder $inner) => $inner->where('owner_id', $user->id))])
            ->get()
            ->map(fn (PipelineStage $stage): array => [
                'type' => $stage->type->value,
                'figure' => new Figure('pipeline.'.$stage->key, $stage->name, (int) $stage->leads_count, route('admin.leads.index', ['stage' => $stage->key]), noun: ['lead', 'leads']),
            ])->all();

        return [
            'stages' => $stages,
            'most' => max(1, ...array_map(fn (array $stage): int => $stage['figure']->count, $stages), ...[0]),
            'own' => $ownOnly,
        ];
    }

    public function isEmpty(array $data): bool
    {
        return array_sum(array_map(fn (array $stage): int => $stage['figure']->count, $data['stages'])) === 0;
    }

    public function emptyWords(array $data = []): string
    {
        return 'No lead yet. Each enquiry stands in one stage of the pipeline from the moment it arrives.';
    }
}
