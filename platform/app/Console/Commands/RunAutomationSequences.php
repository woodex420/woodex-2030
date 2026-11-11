<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Automation\Actions\RunSequences;
use Illuminate\Console\Command;

/**
 * The sequences heartbeat (AUT-001): every due step of every active enrolment. Runs each minute
 * next to the SLA check; a step held by quiet hours or the daily cap simply waits for its next
 * slot — nothing is dropped, everything is logged.
 */
class RunAutomationSequences extends Command
{
    protected $signature = 'woodex:automation-run';

    protected $description = 'Run the due steps of every active follow-up sequence enrolment.';

    public function handle(RunSequences $runner): int
    {
        $result = $runner->handle();

        if ($result['ran'] > 0) {
            $this->info(sprintf(
                '%d step(s): %d sent, %d held, %d skipped, %d failed, %d enrolment(s) exited.',
                $result['ran'],
                $result['sent'],
                $result['held'],
                $result['skipped'],
                $result['failed'],
                $result['exited'],
            ));
        }

        return self::SUCCESS;
    }
}
