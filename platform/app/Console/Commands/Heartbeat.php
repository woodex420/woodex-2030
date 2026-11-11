<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Written every minute by the scheduler. The health screen (and Telegram in P5) alert when it stops,
 * which means the hPanel cron job is missing or broken.
 */
final class Heartbeat extends Command
{
    protected $signature = 'woodex:heartbeat';

    protected $description = 'Record the cron heartbeat (shown on System → Health).';

    public const CACHE_KEY = 'woodex:heartbeat';

    public function handle(): int
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}
