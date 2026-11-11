<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Crm\Actions\AssignLead;
use App\Domain\Crm\Models\Lead;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;

/**
 * /assign — give a lead to a colleague from the phone (TGM-005/S3): `/assign L-2026-000123 Ayesha`.
 * The name is matched against active staff; an ambiguous name is answered with the options, not a guess.
 */
final class AssignCommand implements TelegramCommand
{
    public function __construct(
        private readonly AssignLead $assignLead,
    ) {}

    public function name(): string
    {
        return '/assign';
    }

    public function usage(): string
    {
        return '/assign <lead> <person>';
    }

    public function description(): string
    {
        return 'give a lead to a colleague';
    }

    public function permission(): string
    {
        return 'leads.assign';
    }

    public function handle(User $user, string $argument): string
    {
        $parts = preg_split('/\s+/', trim($argument), 2) ?: [];

        if (count($parts) < 2 || $parts[0] === '' || trim($parts[1]) === '') {
            return 'Give a lead and a person, e.g. /assign L-2026-000123 Ayesha.';
        }

        [$term, $name] = [$parts[0], trim($parts[1])];

        /** @var Lead|null $lead */
        $lead = Lead::query()->visibleTo($user)->with('contact:id,name')->where('ref', strtoupper($term))->first();

        if ($lead === null && ctype_digit($term)) {
            /** @var Lead|null $byId */
            $byId = Lead::query()->visibleTo($user)->with('contact:id,name')->find((int) $term);
            $lead = $byId;
        }

        if ($lead === null) {
            return "No lead {$term} within your leads. Try the reference (L-2026-…).";
        }

        $candidates = User::query()
            ->where('is_active', true)
            ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($name).'%'])
            ->orderBy('name')
            ->get();

        if ($candidates->isEmpty()) {
            return "No staff member matches \"{$name}\". Use their name as it appears in Woodex OS.";
        }

        if ($candidates->count() > 1) {
            return sprintf(
                'Which one? %s. Repeat the command with the full name.',
                $candidates->map(fn (User $candidate): string => $candidate->name)->implode(' / '),
            );
        }

        $to = $candidates->first();
        $this->assignLead->handle($lead, $to, $user, "Assigned to {$to->name} from Telegram by {$user->name}");

        return sprintf('%s (%s) now belongs to %s.', $lead->ref, $lead->contact->name, $to->name);
    }
}
