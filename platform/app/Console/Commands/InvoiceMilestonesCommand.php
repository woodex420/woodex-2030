<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\ProjectMilestone;
use App\Domain\Sales\Actions\RaiseMilestoneInvoice;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Quote;
use Illuminate\Console\Command;

/**
 * Money follows the job (slice E): every milestone a live project has reached becomes its invoice, once.
 *
 * The advance invoice is still raised the moment the owner accepts the quotation; this command is what
 * bills the milestones that come after it. It is also the catch-up: a project whose advance was never
 * raised (accepted before the invoice automation existed, or the acceptance job failed) is picked up on
 * the next run, because the rule is simply "reached, accepted quotation, no invoice yet".
 */
final class InvoiceMilestonesCommand extends Command
{
    protected $signature = 'woodex:invoice-milestones {--dry-run}';

    protected $description = 'Raise the invoice for every project milestone an accepted quotation has already reached.';

    public function handle(RaiseMilestoneInvoice $raise): int
    {
        $dry = (bool) $this->option('dry-run');

        $milestones = ProjectMilestone::query()
            ->with(['project.quote'])
            ->whereNotNull('reached_at')
            ->whereHas('project', fn ($query) => $query->where('status', ProjectStatus::Active->value))
            ->whereNotIn('id', Invoice::withTrashed()
                ->whereNotNull('project_milestone_id')
                ->select('project_milestone_id'))
            ->orderBy('reached_at')
            ->get();

        $raised = 0;
        $skipped = 0;

        foreach ($milestones as $milestone) {
            $project = $milestone->project;
            $quote = $project?->quote;

            if ($project === null || ! $quote instanceof Quote || $quote->status !== QuoteStatus::Accepted || ($quote->meta['furniture_billing'] ?? '') === 'full' || Invoice::withTrashed()->where('source_quote_id', $quote->id)->exists()) {
                $skipped++;

                continue;
            }

            if ($dry) {
                $this->line(sprintf('  would bill %s · %s (%s)', $project->code, $milestone->name, $quote->number));
                $raised++;

                continue;
            }

            $invoice = $raise->handle($quote, $milestone);
            $raised++;

            $this->line(sprintf('  %s · %s → %s (%s)', $project->code, $milestone->name, $invoice->number, $invoice->status->label()));
        }

        $this->components->info(sprintf(
            '%s %d milestone(s); %d left for a person.',
            $dry ? 'Would raise' : 'Raised',
            $raised,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
