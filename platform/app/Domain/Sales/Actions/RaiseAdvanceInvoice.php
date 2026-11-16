<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Projects\Models\ProjectMilestone;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Quote;
use App\Models\User;

/**
 * The advance invoice that follows an accepted quotation (QUO-011, INV-001).
 *
 * The quotation's milestone with the `on_acceptance` trigger becomes the first invoice: 50% on an execution job,
 * 75% on a design job, whatever the owner set on a custom one. Called once per milestone: accepting a quotation
 * twice does not raise a second invoice.
 *
 * Slice E moved the raising itself into {@see RaiseMilestoneInvoice}, which the scheduler also uses for every
 * milestone the job reaches later; this class keeps the acceptance rules — an accepted quotation, the
 * `on_acceptance` milestone, nothing archived — and nothing else.
 */
final class RaiseAdvanceInvoice
{
    public function __construct(
        private readonly RaiseMilestoneInvoice $milestones,
    ) {}

    public function handle(Quote $quote, ?User $by = null): ?Invoice
    {
        if ($quote->status !== QuoteStatus::Accepted) {
            throw new QuoteActionDenied('Only an accepted quotation can raise an advance invoice.');
        }

        $quote = $quote->fresh();
        if (($quote->meta['furniture_billing'] ?? '') === 'full') {
            return null;
        }

        $milestone = $quote->milestones()->where('trigger', 'on_acceptance')->first();

        if ($milestone === null) {
            return null;
        }

        $project = $quote->project()->first();

        if ($project === null) {
            throw new QuoteActionDenied('The accepted quotation has no project to bill against.');
        }

        $projectMilestone = ProjectMilestone::query()
            ->where('project_id', $project->id)
            ->where('quote_milestone_id', $milestone->id)
            ->first();

        if ($projectMilestone === null) {
            throw new QuoteActionDenied('The accepted quotation has no project milestone to bill against.');
        }

        return $this->milestones->handle($quote, $projectMilestone, $by);
    }

    /** The PDF and the snapshot of an invoice the client will see (INV-003). */
    public function freeze(Invoice $invoice): Invoice
    {
        return $this->milestones->freeze($invoice);
    }
}
