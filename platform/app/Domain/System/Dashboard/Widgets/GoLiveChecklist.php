<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Console\Commands\Heartbeat;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Widget;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Integrations\Turnstile\TurnstileVerifier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The go-live checklist, shown to the people who look after the system until everything is green
 * (docs/06 §9). It has no figure: each line is done or not, with where to do it.
 */
final class GoLiveChecklist extends Widget
{
    public function key(): string
    {
        return 'go_live_checklist';
    }

    public function title(): string
    {
        return 'Go-live checklist';
    }

    public function description(): string
    {
        return 'Turns green as setup completes';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('system.health');
    }

    public function width(): string
    {
        return 'narrow';
    }

    public function data(DashboardContext $context): array
    {
        $user = $context->user;
        $heartbeat = Cache::get(Heartbeat::CACHE_KEY);
        $cronOk = is_string($heartbeat) && CarbonImmutable::parse($heartbeat)->greaterThan(now()->subMinutes(3));

        return ['checklist' => [
            ['label' => 'Two-factor authentication on your account', 'done' => $user->hasTwoFactorEnabled(), 'hint' => 'Profile → Security'],
            ['label' => 'Telegram alerts linked to your account', 'done' => $user->routeNotificationForTelegram() !== null, 'hint' => 'Profile → Security → Link Telegram'],
            ['label' => 'Telegram bot configured', 'done' => app(TelegramGateway::class)->isConfigured(), 'hint' => 'TELEGRAM_BOT_TOKEN in .env'],
            ['label' => 'Spam protection (Cloudflare Turnstile)', 'done' => app(TurnstileVerifier::class)->enabled(), 'hint' => 'TURNSTILE_* keys in .env'],
            ['label' => 'Cron heartbeat in the last 3 minutes', 'done' => $cronOk, 'hint' => 'hPanel → Cron Jobs → schedule:run'],
            ['label' => 'Bank accounts added for invoices', 'done' => BankAccount::query()->where('is_active', true)->exists(), 'hint' => 'Invoices → Bank accounts'],
        ]];
    }
}
