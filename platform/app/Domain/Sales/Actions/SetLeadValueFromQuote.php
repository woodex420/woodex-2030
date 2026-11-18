<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteVersion;

/**
 * A lead's expected value follows its quotation (master list T-092, defect D-024).
 *
 * The value used to be a figure somebody typed on the lead. A sale won for 496,800 counted as nothing on the
 * pipeline board unless that figure had been typed. Now:
 *
 *   - a quotation that is sent gives the lead its total when the lead has no value yet, or still carries the total
 *     of an earlier sending (a revision moves it). A figure typed by hand is kept: it is somebody's own estimate;
 *   - a quotation that is accepted sets the value, whatever was there: the sale is now a fact. Should a lead have
 *     more than one accepted quotation, the value is their sum.
 *
 * The lead keeps its own list of changes, so each change made here is recorded with the person who caused it.
 */
final class SetLeadValueFromQuote
{
    /** Called inside the sending, after the version of the quotation has been frozen. */
    public function sent(Quote $quote, ?QuoteVersion $version = null): void
    {
        $lead = $quote->lead()->first();
        $total = (int) $quote->total_minor;

        // Nothing to follow, or the lead is already closed: a later offer does not rewrite a sale that was made.
        if ($lead === null || $total <= 0 || $lead->won_at !== null || $lead->lost_at !== null) {
            return;
        }

        $current = (int) ($lead->expected_value_minor ?? 0);

        if ($current > 0 && ! $this->cameFromAnEarlierSending($lead, $current, $version)) {
            return; // typed by hand
        }

        $this->set($lead, $total);
    }

    /** Called inside the acceptance, after the quotation has been marked accepted. */
    public function accepted(Quote $quote): void
    {
        $lead = $quote->lead()->first();

        if ($lead === null) {
            return;
        }

        $accepted = (int) Quote::query()->where('lead_id', $lead->id)->where('status', QuoteStatus::Accepted)->sum('total_minor');

        $this->set($lead, max($accepted, (int) $quote->total_minor));
    }

    private function cameFromAnEarlierSending(Lead $lead, int $value, ?QuoteVersion $except): bool
    {
        return QuoteVersion::query()
            ->whereIn('quote_id', Quote::query()->where('lead_id', $lead->id)->select('id'))
            ->when($except !== null, fn ($versions) => $versions->whereKeyNot($except->getKey()))
            ->where('total_minor', $value)
            ->exists();
    }

    private function set(Lead $lead, int $value): void
    {
        if ((int) ($lead->expected_value_minor ?? 0) !== $value) {
            $lead->update(['expected_value_minor' => $value]);
        }
    }
}
