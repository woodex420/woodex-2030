<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Conversations\Actions\CheckConversationSla;
use App\Domain\Crm\Actions\CheckLeadSla;
use Illuminate\Console\Command;

class SlaCheck extends Command
{
    protected $signature = 'woodex:sla-check';

    protected $description = 'Flag new leads that missed the first-response target and alert owners and managers (CRM-010).';

    public function handle(CheckLeadSla $check, CheckConversationSla $conversationCheck): int
    {
        $flagged = $check->handle();
        $escalated = $check->escalate();

        $threads = $conversationCheck->handle();
        $threadsEscalated = $conversationCheck->escalate();

        if ($flagged > 0) {
            $this->components->warn("{$flagged} lead(s) missed the response target; alerts sent.");
        }

        if ($escalated > 0) {
            $this->components->warn("{$escalated} lead(s) still not contacted; escalated.");
        }

        if ($threads > 0) {
            $this->components->warn("{$threads} WhatsApp conversation(s) missed the reply target; alerts sent.");
        }

        if ($threadsEscalated > 0) {
            $this->components->warn("{$threadsEscalated} WhatsApp conversation(s) still unanswered; escalated.");
        }

        return self::SUCCESS;
    }
}
