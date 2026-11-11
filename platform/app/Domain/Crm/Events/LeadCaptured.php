<?php

declare(strict_types=1);

namespace App\Domain\Crm\Events;

use App\Domain\Crm\Models\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a new lead is created or a returning enquiry joins an open lead.
 */
final class LeadCaptured implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public bool $isNewLead,
    ) {}
}
