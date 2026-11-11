<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Services\EstimateAccess;
use App\Domain\Sales\Services\EstimatorLeadDetails;
use App\Models\User;

/**
 * Writes the draft estimate for a website cost-estimator enquiry (deep QA slice C).
 *
 * A visitor who used the estimator has already picked a service, a size and a finish; the rate book
 * already knows what that costs. Until this action existed those three facts only lived in the lead's
 * meta, so the Estimations screen stayed empty while estimator enquiries were arriving — the sales
 * team could start a quotation, but the estimate that quotation is supposed to come from had nowhere
 * to live until they did.
 *
 * The row is a **draft**, attributed to the lead owner (or the first active person who may manage
 * estimates when the lead has no owner yet). It never reaches a client; it is the record of what the
 * rate book said on the day the enquiry arrived. Repeat enquiries that join the same lead reuse the
 * open draft instead of stacking duplicates.
 */
final class DraftEstimateFromEstimatorLead
{
    public function __construct(
        private readonly EstimatorLeadDetails $details,
        private readonly SaveEstimate $saveEstimate,
    ) {}

    /** The open draft this lead already has, when its inputs still match what the estimator sent. */
    public function existingFor(Lead $lead): ?Estimate
    {
        $read = $this->details->read($lead);
        $rate = $read['rate'];

        if ($rate === null || $read['area'] <= 0) {
            return null;
        }

        return Estimate::query()
            ->where('lead_id', $lead->id)
            ->where('service_key', $rate->key)
            ->whereIn('status', [EstimateStatus::Draft->value, EstimateStatus::Reviewed->value])
            ->orderByDesc('id')
            ->get()
            ->first(fn (Estimate $estimate): bool => (string) ($estimate->inputs['quantity'] ?? '') === $this->quantity($read['area'])
                && ($estimate->inputs['finish'] ?? '') === $this->finish($read['finish']));
    }

    /** Create the draft for this lead. Returns null when the enquiry cannot be priced honestly. */
    public function handle(Lead $lead, ?User $by = null): ?Estimate
    {
        if ($by !== null) {
            EstimateAccess::lead($lead, $by);
        }
        $read = $this->details->read($lead);
        $rate = $read['rate'];

        if ($rate === null || $read['area'] <= 0) {
            return null;
        }

        $existing = $this->existingFor($lead);

        if ($existing !== null) {
            return $existing;
        }

        $by = $by ?? $this->attribution($lead);

        if ($by === null) {
            // No active person may manage estimates — leave the enquiry priceable by hand rather than
            // inventing an author for a document the team cannot open.
            return null;
        }

        return $this->saveEstimate->handle([
            // A direct lookup, not a relation read: models run with lazy-loading prevention on.
            'title' => $rate->label.' — '.($lead->contact()->value('name') ?? $lead->ref),
            'service_key' => $rate->key,
            'contact_id' => $lead->contact_id,
            'lead_id' => $lead->id,
            'quantity' => $this->quantity($read['area']),
            'finish' => $this->finish($read['finish']),
            'contingency_bp' => 0,
            'assumptions' => $read['estimate'] !== '' ? 'The website estimator showed the client: '.$read['estimate'].'.' : null,
            'exclusions' => null,
            'catalog_lines' => [],
        ], $by);
    }

    private function attribution(Lead $lead): ?User
    {
        $owner = $lead->owner_id !== null ? User::query()->find($lead->owner_id) : null;

        if ($owner !== null && $owner->is_active && $owner->can('estimates.manage')) {
            return $owner;
        }

        return User::query()
            ->where('is_active', true)
            ->permission('estimates.manage')
            ->orderBy('id')
            ->first();
    }

    private function finish(string $finish): string
    {
        return in_array($finish, ['essential', 'standard', 'premium'], true) ? $finish : 'standard';
    }

    private function quantity(float $area): string
    {
        return rtrim(rtrim(number_format($area, 2, '.', ''), '0'), '.');
    }
}
