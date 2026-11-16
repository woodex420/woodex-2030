<?php

declare(strict_types=1);

namespace App\Domain\Crm\Listeners;

use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Crm\Jobs\AskForPhoneNumber;

/**
 * An enquiry that arrives with an email but no phone number cannot be called or messaged — the
 * ask-for-number flow (P8.5) goes out right after the HTTP response, alongside the staff alert.
 */
final class AskForMissingNumber
{
    public function handle(LeadCaptured $event): void
    {
        AskForPhoneNumber::dispatchAfterResponse($event->lead->id, $event->isNewLead);
    }
}
