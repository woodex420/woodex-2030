<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Crm\Models\SiteVisit;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;

/**
 * /visits — site visits today (default) or tomorrow (TGM-004), the day's route in one message.
 */
final class VisitsCommand implements TelegramCommand
{
    public function name(): string
    {
        return '/visits';
    }

    public function usage(): string
    {
        return '/visits [today|tomorrow]';
    }

    public function description(): string
    {
        return 'site visits today or tomorrow';
    }

    public function permission(): string
    {
        return 'leads.view';
    }

    public function handle(User $user, string $argument): string
    {
        $day = match (strtolower(trim($argument))) {
            'tomorrow' => now()->addDay(),
            default => now(),
        };

        $visits = SiteVisit::query()
            ->whereBetween('scheduled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->with(['contact:id,name', 'lead:id,ref', 'assignee:id,name'])
            ->orderBy('scheduled_at')
            ->get();

        $title = $day->format('D d M').':';

        if ($visits->isEmpty()) {
            return 'No site visits '.($day->isToday() ? 'today' : 'tomorrow').'.';
        }

        $lines = [$title];

        foreach ($visits as $visit) {
            $who = $visit->contact->name;
            $lines[] = sprintf(
                '%s · %s · %s%s',
                $visit->scheduled_at->format('H:i'),
                $who,
                $visit->address !== null && $visit->address !== '' ? $visit->address : $visit->lead->ref,
                $visit->assignee !== null ? ' · '.$visit->assignee->name : '',
            );
        }

        return implode("\n", $lines);
    }
}
