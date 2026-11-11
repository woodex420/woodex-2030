<?php

declare(strict_types=1);

namespace App\Domain\Crm\Listeners;

use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Crm\Jobs\SendLeadAlert;

/**
 * New leads must reach the team within seconds (G6): the alert runs right after the HTTP response
 * instead of waiting for the cron-driven queue.
 */
final class SendNewLeadAlerts
{
    public function handle(LeadCaptured $event): void
    {
        SendLeadAlert::dispatchAfterResponse($event->lead->id, $event->isNewLead);
    }
}
