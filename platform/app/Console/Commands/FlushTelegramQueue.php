<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Actions\FlushPendingTelegramMessages;
use Illuminate\Console\Command;

/**
 * S8 / TGM-008: deliver Telegram messages that waited out a chat's quiet hours.
 *
 * Runs every minute. Without it the queue would only ever grow, so the switch on
 * this one is plumbing (not pauseable) — pause the sending automations instead.
 */
class FlushTelegramQueue extends Command
{
    protected $signature = 'woodex:telegram-flush';

    protected $description = 'Deliver Telegram messages queued while a chat was muted.';

    public function handle(FlushPendingTelegramMessages $flush): int
    {
        $result = $flush->handle();

        if ($result['sent'] > 0 || $result['pending'] > 0) {
            $this->line(sprintf('delivered %d · still waiting %d', $result['sent'], $result['pending']));
        }

        return self::SUCCESS;
    }
}
