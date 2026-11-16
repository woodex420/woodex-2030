<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\DraftEstimateFromEstimatorLead;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Services\EstimatorLeadDetails;
use Illuminate\Console\Command;

/**
 * One-time catch-up for estimator enquiries captured before slice C: they carried the service and
 * the size but no estimate row, so the Estimations screen could not show them. The command writes
 * the same draft the listener now writes at capture time, and is safe to re-run — an enquiry that
 * already has an open draft is left alone.
 */
class BackfillEstimates extends Command
{
    protected $signature = 'estimates:backfill {--dry-run : Show what would be written without writing it}';

    protected $description = 'Write the missing draft estimate for estimator enquiries captured before slice C.';

    public function handle(DraftEstimateFromEstimatorLead $draft, EstimatorLeadDetails $details): int
    {
        $dry = (bool) $this->option('dry-run');

        $leads = Lead::query()
            ->where('source', LeadSource::Estimator)
            ->whereNotIn('id', Estimate::query()->whereNotNull('lead_id')->select('lead_id'))
            ->orderBy('id')
            ->get();

        if ($leads->isEmpty()) {
            $this->components->info('Every estimator enquiry already has an estimate.');

            return self::SUCCESS;
        }

        $written = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            $read = $details->read($lead);

            if ($draft->existingFor($lead) !== null) {
                $skipped++;

                continue;
            }

            if (! $details->available($lead)) {
                $skipped++;
                $this->components->warn("{$lead->ref}: no active rate for '{$read['service']}' or no size — left for a person.");

                continue;
            }

            if ($dry) {
                $written++;
                $this->components->twoColumnDetail($lead->ref, 'would draft: '.$read['rate']->label.' · '.$read['area'].' '.(string) $read['rate']->unit);

                continue;
            }

            $estimate = $draft->handle($lead);

            if ($estimate === null) {
                $skipped++;

                continue;
            }

            $written++;
            $this->components->twoColumnDetail($lead->ref, $estimate->number.' · '.$estimate->title);
        }

        $this->components->info(($dry ? 'would write' : 'wrote')." {$written} draft estimate(s); {$skipped} enquiry(ies) left unpriced.");

        return self::SUCCESS;
    }
}
