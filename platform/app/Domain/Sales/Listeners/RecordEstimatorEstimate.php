<?php

declare(strict_types=1);

namespace App\Domain\Sales\Listeners;

use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Sales\Actions\DraftEstimateFromEstimatorLead;

/**
 * Keeps the estimate and the enquiry in step: every estimator lead gets its draft estimate at the
 * moment it arrives, not only when somebody remembers to click "start quotation".
 */
final class RecordEstimatorEstimate
{
    public function __construct(
        private readonly DraftEstimateFromEstimatorLead $draft,
    ) {}

    public function handle(LeadCaptured $event): void
    {
        if ($event->lead->source !== LeadSource::Estimator) {
            return;
        }

        $this->draft->handle($event->lead);
    }
}
