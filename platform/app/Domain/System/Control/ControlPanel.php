<?php

declare(strict_types=1);

namespace App\Domain\System\Control;

use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Domain\System\Models\ScheduledTaskRun;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The six numbers the studio runs on (deep QA slice D).
 *
 * One query each, and every screen that shows a number asks this class: the dashboard tiles, the
 * "needs attention" list and the Reports control strip all read the same methods, so two screens
 * cannot quietly disagree. Each card carries the link to the screen that filters to exactly the
 * same rows — a number that cannot be opened is a number nobody can act on.
 *
 * Roles see what they may act on: a card is only returned when the person can open its screen.
 */
final class ControlPanel
{
    /** A scheduled task that has not reported in this long has stopped, whether or not it errored. */
    private const STALE_HOURS = 25;

    public function __construct(
        private readonly SalesSettings $sales,
    ) {}

    /**
     * Enquiries captured since midnight — every channel, scoped to the leads the person may see.
     */
    public function enquiriesToday(User $user): int
    {
        return Lead::query()
            ->visibleTo($user)
            ->where('created_at', '>=', CarbonImmutable::now()->startOfDay())
            ->count();
    }

    /** Quotations waiting for a discount or margin decision (QUO-007). */
    public function awaitingApproval(): int
    {
        return Approval::query()
            ->where('approvable_type', 'quote')
            ->where('status', ApprovalStatus::Pending->value)
            ->count();
    }

    /**
     * Follow-ups that should already have happened: a next action in the past, or a first reply
     * past its SLA. `Lead::followUpDue()` is the single definition, and the Leads list's
     * `?due=overdue` filter calls the same scope.
     */
    public function overdueFollowUps(User $user): int
    {
        return Lead::query()->visibleTo($user)->followUpDue()->count();
    }

    /**
     * What clients still owe: every issued or partly paid invoice with a balance, split into the
     * part that is already late (the existing Invoice::overdue() scope) and the rest.
     *
     * @return array{total_minor: int, overdue_minor: int, invoices: int, overdue_invoices: int}
     */
    public function moneyDue(): array
    {
        $open = fn () => Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('balance_minor', '>', 0);

        $overdue = fn () => Invoice::query()->overdue();

        return [
            'total_minor' => (int) $open()->sum('balance_minor'),
            'overdue_minor' => (int) $overdue()->sum('balance_minor'),
            'invoices' => $open()->count(),
            'overdue_invoices' => $overdue()->count(),
        ];
    }

    /** Jobs the studio is running right now. */
    public function jobsRunning(): int
    {
        return Project::query()->where('status', ProjectStatus::Active->value)->count();
    }

    /**
     * Automations that failed and stayed failed: the most recent recorded run of a scheduled task
     * ended in error, plus anything sitting in the failed-jobs queue. A task whose last retry
     * succeeded is not failing, which is why only the latest run per task is read.
     *
     * A task that has not run at all in 25 hours is just as broken as one that threw, so `stale` lists
     * those separately — the tile counts failures, the attention line names both.
     *
     * @return array{failed_runs: int, failed_jobs: int, tasks: list<string>, stale: list<string>, total: int}
     */
    public function automationsFailing(): array
    {
        $tasks = [];
        $stale = [];
        $staleBefore = CarbonImmutable::now()->subHours(self::STALE_HOURS);

        foreach (ScheduledTaskRun::latestPerTask() as $task => $run) {
            if ($run->ok === false) {
                $tasks[] = $task;
            } elseif ($run->ran_at !== null && $run->ran_at->lessThan($staleBefore)) {
                $stale[] = $task;
            }
        }

        $failedJobs = DB::table('failed_jobs')->count();

        return [
            'failed_runs' => count($tasks),
            'failed_jobs' => $failedJobs,
            'tasks' => $tasks,
            'stale' => $stale,
            'total' => count($tasks) + $failedJobs,
        ];
    }

    /**
     * The six tiles, in the order the day happens: enquiries, decisions, follow-ups, money, work, system.
     * Cards the person cannot open are left out rather than shown as a dead end.
     *
     * @return list<array{key: string, label: string, display: string, hint: string, href: string, icon: string, tone: string, count: int}>
     */
    public function cards(User $user): array
    {
        $cards = [];
        $lakh = $this->sales->lakh_grouping;

        if ($user->can('leads.view')) {
            $today = $this->enquiriesToday($user);
            $yesterday = Lead::query()
                ->visibleTo($user)
                ->whereBetween('created_at', [CarbonImmutable::now()->subDay()->startOfDay(), CarbonImmutable::now()->startOfDay()])
                ->count();

            $cards[] = [
                'key' => 'enquiries_today',
                'label' => 'Enquiries today',
                'display' => (string) $today,
                'hint' => $yesterday === 0 ? 'none yesterday' : $yesterday.' yesterday',
                'href' => route('admin.leads.index', ['created' => 'today']),
                'icon' => 'user-plus',
                'tone' => 'navy',
                'count' => $today,
            ];

            $overdue = $this->overdueFollowUps($user);

            $cards[] = [
                'key' => 'overdue_followups',
                'label' => 'Follow-ups overdue',
                'display' => (string) $overdue,
                'hint' => $overdue === 0 ? 'every enquiry is on schedule' : 'past their next action or SLA',
                'href' => route('admin.leads.index', ['due' => 'overdue']),
                'icon' => 'clock',
                'tone' => $overdue > 0 ? 'error' : 'success',
                'count' => $overdue,
            ];
        }

        if ($user->can('quotes.approve')) {
            $pending = $this->awaitingApproval();

            $cards[] = [
                'key' => 'awaiting_approval',
                'label' => 'Awaiting approval',
                'display' => (string) $pending,
                'hint' => $pending === 0 ? 'nothing to decide' : 'quotations held for a decision',
                'href' => route('admin.approvals'),
                'icon' => 'quote',
                'tone' => $pending > 0 ? 'warning' : 'success',
                'count' => $pending,
            ];
        }

        if ($user->can('invoices.view')) {
            $due = $this->moneyDue();

            $cards[] = [
                'key' => 'money_due',
                'label' => 'Money due',
                'display' => Money::ofMinor($due['total_minor'])->format($lakh),
                'hint' => $due['total_minor'] === 0
                    ? 'nothing outstanding'
                    : Money::ofMinor($due['overdue_minor'])->format($lakh).' of it late · '.$due['invoices'].' invoice'.($due['invoices'] === 1 ? '' : 's'),
                'href' => route('admin.payments.index', ['tab' => 'receivables']),
                'icon' => 'banknote',
                'tone' => $due['total_minor'] > 0 ? 'wood' : 'success',
                'count' => $due['invoices'],
            ];
        }

        if ($user->can('projects.view')) {
            $running = $this->jobsRunning();

            $cards[] = [
                'key' => 'jobs_running',
                'label' => 'Jobs running',
                'display' => (string) $running,
                'hint' => $running === 0 ? 'no active project yet' : 'active projects on the board',
                'href' => route('admin.projects.index', ['status' => 'active']),
                'icon' => 'hammer',
                'tone' => 'navy',
                'count' => $running,
            ];
        }

        if ($user->can('system.health') || $user->can('automation.manage')) {
            $failing = $this->automationsFailing();

            $cards[] = [
                'key' => 'automations_failing',
                'label' => 'Automations failing',
                'display' => (string) $failing['total'],
                'hint' => $failing['total'] === 0
                    ? 'last run of every task was clean'
                    : $failing['failed_runs'].' scheduled · '.$failing['failed_jobs'].' queued job'.($failing['failed_jobs'] === 1 ? '' : 's'),
                'href' => $this->automationHref($user),
                'icon' => 'bot',
                'tone' => $failing['total'] > 0 ? 'error' : 'success',
                'count' => $failing['total'],
            ];
        }

        return $cards;
    }

    /**
     * What needs a person right now, with the one click that starts the work. Only non-zero lines
     * are returned — an empty list is the honest "nothing is waiting".
     *
     * @return list<array{key: string, title: string, detail: string, count: int, href: string, action: string, icon: string, tone: string}>
     */
    public function attention(User $user): array
    {
        $items = [];
        $lakh = $this->sales->lakh_grouping;

        if ($user->can('leads.view')) {
            $overdue = $this->overdueFollowUps($user);

            if ($overdue > 0) {
                $items[] = [
                    'key' => 'overdue_followups',
                    'title' => $overdue === 1 ? 'One enquiry is past its follow-up' : $overdue.' enquiries are past their follow-up',
                    'detail' => 'A next action fell due, or the first reply went past its SLA.',
                    'count' => $overdue,
                    'href' => route('admin.leads.index', ['due' => 'overdue']),
                    'action' => 'Open the list',
                    'icon' => 'clock',
                    'tone' => 'error',
                ];
            }
        }

        if ($user->can('quotes.approve')) {
            $pending = $this->awaitingApproval();

            if ($pending > 0) {
                $items[] = [
                    'key' => 'awaiting_approval',
                    'title' => $pending === 1 ? 'One quotation is waiting for you' : $pending.' quotations are waiting for you',
                    'detail' => 'A discount or a margin needs an owner-level decision before it can go out.',
                    'count' => $pending,
                    'href' => route('admin.approvals'),
                    'action' => 'Decide now',
                    'icon' => 'quote',
                    'tone' => 'warning',
                ];
            }
        }

        if ($user->can('invoices.view')) {
            $due = $this->moneyDue();

            if ($due['overdue_minor'] > 0) {
                $items[] = [
                    'key' => 'money_due',
                    'title' => Money::ofMinor($due['overdue_minor'])->format($lakh).' is late',
                    'detail' => $due['overdue_invoices'].' invoice'.($due['overdue_invoices'] === 1 ? '' : 's').' past the due date — the receivables book has the WhatsApp nudge.',
                    'count' => $due['overdue_invoices'],
                    'href' => route('admin.payments.index', ['tab' => 'receivables']),
                    'action' => 'Chase it',
                    'icon' => 'banknote',
                    'tone' => 'error',
                ];
            }
        }

        if ($user->can('system.health') || $user->can('automation.manage')) {
            $failing = $this->automationsFailing();

            if ($failing['total'] > 0) {
                $items[] = [
                    'key' => 'automations_failing',
                    'title' => $failing['total'] === 1 ? 'One automation failed' : $failing['total'].' automations failed',
                    'detail' => $failing['failed_runs'].' scheduled run'.($failing['failed_runs'] === 1 ? '' : 's').' ended in error · '.$failing['failed_jobs'].' job'.($failing['failed_jobs'] === 1 ? '' : 's').' in the failed queue.',
                    'count' => $failing['total'],
                    'href' => $this->automationHref($user),
                    'action' => 'See what failed',
                    'icon' => 'bot',
                    'tone' => 'error',
                ];
            }

            if ($failing['stale'] !== []) {
                $stopped = count($failing['stale']);

                $items[] = [
                    'key' => 'automations_stale',
                    'title' => $stopped === 1 ? 'One scheduled task has stopped reporting' : $stopped.' scheduled tasks have stopped reporting',
                    'detail' => 'No run in the last '.self::STALE_HOURS.' hours: '.implode(', ', array_slice($failing['stale'], 0, 3)).($stopped > 3 ? '…' : '').'. Check the cron entry and the queue worker.',
                    'count' => $stopped,
                    'href' => $this->automationHref($user),
                    'action' => 'Check the schedule',
                    'icon' => 'refresh',
                    'tone' => 'warning',
                ];
            }
        }

        return $items;
    }

    /** Health is the screen that shows why; without it, the Automation page is the next best door. */
    private function automationHref(User $user): string
    {
        return $user->can('system.health')
            ? route('admin.system.platform', ['tab' => 'health'])
            : route('admin.automation.index');
    }
}
