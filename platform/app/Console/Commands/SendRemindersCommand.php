<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Crm\Actions\SendReminders;
use Illuminate\Console\Command;

class SendRemindersCommand extends Command
{
    protected $signature = 'woodex:reminders';

    protected $description = 'Send due task reminders and site visit reminders to staff (in-app + Telegram).';

    public function handle(SendReminders $reminders): int
    {
        $sent = $reminders->handle();

        if ($sent['tasks'] + $sent['visits'] > 0) {
            $this->components->info("Reminders sent: {$sent['tasks']} task(s), {$sent['visits']} visit(s).");
        }

        return self::SUCCESS;
    }
}
