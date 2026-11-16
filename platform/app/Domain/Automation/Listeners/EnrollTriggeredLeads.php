<?php

declare(strict_types=1);

namespace App\Domain\Automation\Listeners;

use App\Domain\Automation\Actions\EnrollLead;
use App\Domain\Automation\Enums\SequenceTrigger;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Crm\Events\LeadStageChanged;

/**
 * The triggers that come from events (AUT-001): a fresh enquiry starts the "not reached"
 * sequences; a lost lead starts the nurture ones. Everything else (quotation sent/viewed,
 * invoice, visit) is hooked where it happens, in the action itself.
 */
final class EnrollTriggeredLeads
{
    public function __construct(private readonly EnrollLead $enroll) {}

    public function handleLeadCaptured(LeadCaptured $event): void
    {
        if (! $event->isNewLead) {
            return; // an existing client writing again is a conversation, not a fresh enrolment
        }

        $this->enroll->forTrigger(SequenceTrigger::LeadCreated, $event->lead);
    }

    public function handleStageChanged(LeadStageChanged $event): void
    {
        if ($event->to->type === StageType::Lost) {
            $this->enroll->forTrigger(SequenceTrigger::LeadLost, $event->lead);
        }
    }
}
