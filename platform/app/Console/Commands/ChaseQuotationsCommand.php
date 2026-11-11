<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sales\Actions\ChaseQuotations;
use Illuminate\Console\Command;

class ChaseQuotationsCommand extends Command
{
    protected $signature = 'woodex:quote-follow-ups';

    protected $description = 'Chase quotations nobody answered, warn before they lapse, and lapse the stale ones (AUT).';

    public function handle(ChaseQuotations $chase): int
    {
        $done = $chase->handle();

        foreach (['chased' => 'chased (no answer)', 'expiring' => 'expiring', 'expired' => 'lapsed'] as $key => $label) {
            if ($done[$key] > 0) {
                $this->components->warn("{$done[$key]} quotation(s) {$label}; the team has been told.");
            }
        }

        return self::SUCCESS;
    }
}
