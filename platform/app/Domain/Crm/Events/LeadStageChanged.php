<?php

declare(strict_types=1);

namespace App\Domain\Crm\Events;

use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class LeadStageChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public PipelineStage $from,
        public PipelineStage $to,
        public ?int $userId,
    ) {}
}
