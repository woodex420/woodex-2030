<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Crm\Models\Lead;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * /find — search leads by name, phone in any format, or reference (TGM-004), within the caller's visibility.
 */
final class FindCommand implements TelegramCommand
{
    private const LIMIT = 8;

    public function name(): string
    {
        return '/find';
    }

    public function usage(): string
    {
        return '/find <text>';
    }

    public function description(): string
    {
        return 'search leads by name, phone or reference';
    }

    public function permission(): string
    {
        return 'leads.view';
    }

    public function handle(User $user, string $argument): string
    {
        $term = trim($argument);

        if (mb_strlen($term) < 2) {
            return 'Give at least two letters to search, e.g. /find Ali or /find 0321.';
        }

        $leads = Lead::query()->visibleTo($user)
            ->with(['contact:id,name', 'owner:id,name'])
            ->where(fn (Builder $query) => $query
                ->where('ref', 'like', "%{$term}%")
                ->orWhereHas('contact', fn (Builder $contact) => $contact->search($term)))
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        if ($leads->isEmpty()) {
            return "Nothing matches {$term}.";
        }

        $lines = ['Found '.$leads->count().' lead(s):'];

        foreach ($leads as $lead) {
            $lines[] = sprintf('%s · %s · %s', $lead->ref, $lead->contact->name, $lead->owner === null ? 'unassigned' : $lead->owner->name);
        }

        $lines[] = '/lead <reference> for the full picture.';

        return implode("\n", $lines);
    }
}
