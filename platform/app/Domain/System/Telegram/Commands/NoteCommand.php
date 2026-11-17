<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Crm\Actions\LogLeadActivity;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Lead;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;

/**
 * /note — leave a note on a lead from the phone (TGM-005/S3): `/note L-2026-000123 client prefers a call after 6`.
 * The note appears on the lead's timeline exactly as if written in Woodex OS, under the writer's name.
 */
final class NoteCommand implements TelegramCommand
{
    public function __construct(
        private readonly LogLeadActivity $logActivity,
    ) {}

    public function name(): string
    {
        return '/note';
    }

    public function usage(): string
    {
        return '/note <lead> <text>';
    }

    public function description(): string
    {
        return 'leave a note on a lead';
    }

    public function permission(): string
    {
        return 'leads.update';
    }

    public function handle(User $user, string $argument): string
    {
        $parts = preg_split('/\s+/', trim($argument), 2) ?: [];

        if (count($parts) < 2 || $parts[0] === '' || trim($parts[1]) === '') {
            return 'Give a lead and the note, e.g. /note L-2026-000123 client prefers a call after 6.';
        }

        [$term, $text] = [$parts[0], trim($parts[1])];

        /** @var Lead|null $lead */
        $lead = Lead::query()->visibleTo($user)->where('ref', strtoupper($term))->first();

        if ($lead === null && ctype_digit($term)) {
            /** @var Lead|null $byId */
            $byId = Lead::query()->visibleTo($user)->find((int) $term);
            $lead = $byId;
        }

        if ($lead === null) {
            return "No lead {$term} within your leads. Try the reference (L-2026-…).";
        }

        $this->logActivity->handle($lead, ActivityType::Note, $user, $text.' (via Telegram)');

        return sprintf('Noted on %s: %s', $lead->ref, $text);
    }
}
