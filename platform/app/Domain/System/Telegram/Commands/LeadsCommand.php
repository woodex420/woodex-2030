<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Crm\Models\Lead;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;

/**
 * /leads — the latest leads, or a slice of them (TGM-004).
 *
 * A staff member sees only the leads their role may see: sales their own, managers and the owner everything.
 */
final class LeadsCommand implements TelegramCommand
{
    private const LIMIT = 8;

    public function name(): string
    {
        return '/leads';
    }

    public function usage(): string
    {
        return '/leads [new|mine|unassigned]';
    }

    public function description(): string
    {
        return 'latest leads, or new / mine / unassigned';
    }

    public function permission(): string
    {
        return 'leads.view';
    }

    public function handle(User $user, string $argument): string
    {
        $query = Lead::query()->visibleTo($user)->with(['contact:id,name', 'owner:id,name', 'stage:id,name']);
        $filter = strtolower(trim($argument));
        $title = 'Latest leads';

        if ($filter === 'new') {
            $title = 'Leads from the last 24 hours';
            $query->where('created_at', '>=', now()->subDay());
        } elseif ($filter === 'mine') {
            $title = 'Your leads';
            $query->where('owner_id', $user->id);
        } elseif ($filter === 'unassigned') {
            $title = 'Unassigned leads';
            $query->whereNull('owner_id')->whereNull('lost_at')->whereNull('won_at');
        }

        $leads = (clone $query)->latest('id')->limit(self::LIMIT + 1)->get();

        if ($leads->isEmpty()) {
            return $title.":\n— none";
        }

        $more = $leads->count() > self::LIMIT;
        $lines = [$title.':'];

        foreach ($leads->take(self::LIMIT) as $lead) {
            $lines[] = sprintf(
                '%s · %s · %s · score %d · %s',
                $lead->ref,
                $lead->contact->name,
                $lead->service !== null && $lead->service !== '' ? $lead->service : $lead->business_line->label(),
                $lead->score ?? 0,
                $lead->owner === null ? 'unassigned' : $lead->owner->name,
            );
        }

        if ($more) {
            $lines[] = '(more in Woodex OS — /lead <reference> for one)';
        }

        return implode("\n", $lines);
    }
}
