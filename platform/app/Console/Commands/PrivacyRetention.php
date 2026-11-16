<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use Illuminate\Console\Command;

/**
 * SYS-013: the retention half of the privacy promise — data that stopped
 * earning its keep leaves on a schedule, not on someone's memory:
 *
 *  - Lost enquiries with no quotation, untouched for 30 days, are deleted
 *    (their activities, tasks and visits go with them — the books don't: money
 *    rows only ever exist on quoted work).
 *  - Chat transcripts that never became a lead are removed 12 months after
 *    their last message. The contact row, if any, stays.
 */
final class PrivacyRetention extends Command
{
    protected $signature = 'woodex:privacy-retention {--dry-run : report what would go, delete nothing}';

    protected $description = 'Apply the privacy retention rules (SYS-013): stale lost leads and old non-lead transcripts';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $lostStageIds = PipelineStage::query()->where('type', StageType::Lost->value)->pluck('id');

        $staleLeads = Lead::query()
            ->whereIn('stage_id', $lostStageIds)
            ->where('updated_at', '<', now()->subDays(30))
            ->whereDoesntHave('quotes')
            ->get();

        $oldConversations = Conversation::query()
            ->whereNull('lead_id')
            ->where('last_message_at', '<', now()->subMonths(12))
            ->get();

        if ($dryRun) {
            $this->info('[dry-run] Would delete '.$staleLeads->count().' stale lost lead(s) and '.$oldConversations->count().' old non-lead transcript(s).');

            return self::SUCCESS;
        }

        foreach ($staleLeads as $lead) {
            $lead->forceDelete();
        }

        foreach ($oldConversations as $conversation) {
            $conversation->messages()->delete();
            $conversation->delete();
        }

        $this->info('Retention applied: '.$staleLeads->count().' stale lost lead(s) and '.$oldConversations->count().' old non-lead transcript(s) removed.');

        return self::SUCCESS;
    }
}
