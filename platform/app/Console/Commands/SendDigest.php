<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Notifications\DigestNotification;
use App\Domain\System\Telegram\Digests\DailyDigest;
use App\Domain\System\Telegram\Digests\WeeklyDigest;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * S4 digests: every linked staff member's morning (and the week that just ended).
 *
 * Runs daily at 09:00 and Mondays at 09:00 behind the woodex:daily-digest /
 * woodex:weekly-digest switches (routes/console.php). Muted chats do not miss out:
 * the Telegram channel queues their copy until the mute ends (TGM-008).
 */
class SendDigest extends Command
{
    protected $signature = 'woodex:digest
        {--period=daily : daily or weekly}';

    protected $description = 'Send the daily (or weekly) digest to every linked staff chat.';

    public function handle(DailyDigest $daily, WeeklyDigest $weekly): int
    {
        $period = strtolower(trim((string) $this->option('period')));

        if (! in_array($period, ['daily', 'weekly'], true)) {
            $this->error('Period must be daily or weekly.');

            return self::INVALID;
        }

        $users = User::query()
            ->where('is_active', true)
            ->whereHas('telegramLink', fn ($query) => $query
                ->where('is_active', true)
                ->whereNotNull('chat_id')
                ->where(fn ($q) => $q->whereNull('chat_type')->orWhere('chat_type', 'private')))
            ->with('telegramLink')
            ->orderBy('id')
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $text = $period === 'daily' ? $daily->build($user) : $weekly->build($user);

            if ($text === null) {
                continue; // This role sees none of it.
            }

            $user->notify(new DigestNotification(
                "digest.{$period}",
                ($period === 'daily' ? 'Daily digest' : 'Weekly digest').' · '.now()->format('d M'),
                $text,
            ));

            $sent++;
        }

        $this->info(sprintf('%s digest: %d of %d linked chat%s reached.', ucfirst($period), $sent, $users->count(), $users->count() === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
