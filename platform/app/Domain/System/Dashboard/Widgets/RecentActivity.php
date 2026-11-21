<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\Crm\Models\Activity;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The day's record: the last things that happened on the leads the person may see, with who did each and when.
 * A record, not a figure: nothing here is counted.
 */
final class RecentActivity extends Widget
{
    private const SHOWN = 8;

    public function key(): string
    {
        return 'recent_activity';
    }

    public function title(): string
    {
        return 'Recent activity';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('leads.view');
    }

    public function width(): string
    {
        return 'wide';
    }

    public function data(DashboardContext $context): array
    {
        $user = $context->user;

        return ['activities' => Activity::query()
            ->with(['contact', 'user'])
            ->when(! $user->can('leads.view_all'), fn (Builder $q) => $q->whereHas('lead', fn (Builder $lead) => $lead->where('owner_id', $user->id)))
            ->latest('occurred_at')
            ->limit(self::SHOWN)
            ->get()
            ->map(fn (Activity $activity): array => [
                'who' => $activity->contact?->name ?? 'System',
                'what' => Str::limit((string) $activity->body, 140),
                'when' => $activity->occurred_at?->diffForHumans(),
                'by' => $activity->user?->name,
            ])->all()];
    }

    public function isEmpty(array $data): bool
    {
        return $data['activities'] === [];
    }

    public function emptyWords(array $data = []): string
    {
        return 'No activity yet. New enquiries from the website, WhatsApp and the team will appear here.';
    }
}
