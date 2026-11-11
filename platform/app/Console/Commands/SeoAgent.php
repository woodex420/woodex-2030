<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Notifications\AgentReportNotification;
use App\Domain\Website\Actions\CheckSeoHealth;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * P8.5 dormant SEO agent: a weekly, read-only look at the website's search basics, sent to the
 * owner as a short list. Gated in routes/console.php by the woodex:seo-agent switch, which ships
 * OFF (A0) — nothing runs until the owner switches it on from the Automations screen.
 */
class SeoAgent extends Command
{
    protected $signature = 'woodex:seo-agent';

    protected $description = 'Weekly read-only SEO health check (missing descriptions, long titles, broken redirects) for the owner.';

    public function handle(CheckSeoHealth $check): int
    {
        $lines = $check->lines($check->handle());

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
            kind: 'agent.seo',
            title: 'SEO health check',
            lines: $lines,
            url: route('admin.website.pages'),
        ));
    }
}
