<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Actions\CollectSocialPostIdeas;
use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Notifications\AgentReportNotification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * P8.5 dormant social agent: a weekly digest of what is worth posting about, for the owner alone.
 * It never posts, never writes client-facing copy and never names a client — the owner decides.
 * Gated in routes/console.php by the woodex:social-agent switch, which ships OFF (A0).
 */
class SocialAgent extends Command
{
    protected $signature = 'woodex:social-agent';

    protected $description = 'Weekly social post ideas (handovers signed, pages published, projects won) for the owner.';

    public function handle(CollectSocialPostIdeas $ideas): int
    {
        $lines = $ideas->lines($ideas->handle());

        $this->notifyOwner($lines);

        foreach ($lines as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /** @param list<string> $lines */
    private function notifyOwner(array $lines): void
    {
        $owners = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', 'owner'))
            ->get();

        if ($owners->isEmpty()) {
            return;
        }

        app(AlertRouter::class)->send('system.agent_report', $owners, new AgentReportNotification(
            kind: 'agent.social',
            title: 'Social post ideas',
            lines: $lines,
            url: route('admin.automation.index'),
        ));
    }
}
