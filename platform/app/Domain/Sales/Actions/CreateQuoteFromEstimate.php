<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\EstimateAccess;
use App\Domain\Sales\Services\EstimatorLeadDetails;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Compatibility entry point: CRM and the native hub now use one snapshot-safe conversion. */
final class CreateQuoteFromEstimate
{
    public function __construct(
        private readonly EstimatorLeadDetails $details,
        private readonly DraftEstimateFromEstimatorLead $drafts,
        private readonly ConvertEstimateToQuote $convert,
    ) {}

    /** @return array{service: string, area: float, finish: string, estimate: string, rate: ?EstimatorRate} */
    public function read(Lead $lead): array
    {
        return $this->details->read($lead);
    }

    public function available(Lead $lead): bool
    {
        return $this->details->available($lead);
    }

    public function handle(Lead $lead, User $by): Quote
    {
        return DB::transaction(function () use ($lead, $by): Quote {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            EstimateAccess::lead($lead, $by);
            // A retry is not a request for another quotation, even if the public service was retired.
            $linked = Estimate::query()->where('lead_id', $lead->id)->whereNotNull('quote_id')->latest('id')->first();
            if ($linked !== null) {
                return $this->convert->handle($linked, $by);
            }
            $estimate = $this->drafts->existingFor($lead) ?? $this->drafts->handle($lead, $by);
            if ($estimate === null) {
                throw new \InvalidArgumentException('This enquiry has no estimate to start a quotation from.');
            }

            return $this->convert->handle($estimate, $by);
        });
    }
}
