<?php

declare(strict_types=1);

namespace App\Domain\System\Control;

use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Models\Invoice;
use App\Domain\System\Models\Approval;
use App\Domain\System\Models\ScheduledTaskRun;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Modules;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    public const STALE_HOURS = 25;

    /**
     * What this panel has already asked the database. The dashboard shows a number twice (a tile, and its line
     * under "Needs attention"), and both must be one reading. A panel is made anew for every drawing
     * (app(ControlPanel::class)), so nothing here outlives the page it was asked for.
     *
     * @var array<string, mixed>
     */
    private array $asked = [];

    public function __construct(
        private readonly SalesSettings $sales,
    ) {}

    /**
     * Which of the six tiles a person may see: each one's right is the right to open the screen behind it.
     * Asks no record, so a widget can decide whether it is this person's without a query.
     *
     * @return array{leads: bool, approvals: bool, money: bool, projects: bool, automations: bool}
     */
    public function sees(User $user): array
    {
        return [
            'leads' => $user->can('leads.view'),
            'approvals' => $user->can('quotes.approve'),
            'money' => $user->can('invoices.view'),
            'projects' => $user->can('projects.view'),
            'automations' => $this->automationHref($user) !== null,
        ];
    }

    /**
     * Enquiries captured today, from midnight to midnight on the studio's clock: every channel, scoped to the
     * leads the person may see. Lead::createdBetween() is the definition, and the Leads list's `?created=today`
     * asks it with the same two moments, as does the period "Today" above the dashboard.
     */
    public function enquiriesToday(User $user): int
    {
        $now = CarbonImmutable::now();

        return $this->asked['today:'.$user->id] ??= Lead::query()
            ->visibleTo($user)
            ->createdBetween($now->startOfDay(), $now->endOfDay())
            ->count();
    }

    /**
     * Quotations waiting for a discount or margin decision (QUO-007): Approval::quotesWaiting(), which is what
     * the Approvals screen lists. A request whose quotation was deleted is not counted: it was counted here and
     * not listed there, so the tile said 1 over an empty list (found and cured with T-044).
     */
    public function awaitingApproval(): int
    {
        return $this->asked['approvals'] ??= Approval::query()->quotesWaiting()->count();
    }

    /**
     * Follow-ups that should already have happened: a next action in the past, or a first reply
     * past its SLA. `Lead::followUpDue()` is the single definition, and the Leads list's
     * `?due=overdue` filter calls the same scope.
     */
    public function overdueFollowUps(User $user): int
    {
        return $this->asked['followups:'.$user->id] ??= Lead::query()->visibleTo($user)->followUpDue()->count();
    }

    /**
     * What clients still owe: every issued or partly paid invoice with a balance, split into the
     * part that is already late (the existing Invoice::overdue() scope) and the rest.
     *
     * @return array{total_minor: int, overdue_minor: int, invoices: int, overdue_invoices: int}
     */
    public function moneyDue(): array
    {
        if (isset($this->asked['money'])) {
            return $this->asked['money'];
        }

        // Invoice::owing() and ::overdue(): the Invoices list's ?age=open and ?age=overdue are the same scopes.
        // The count and the sum of each are one reading, so an invoice issued in between cannot be in one of them only.
        $read = fn (Builder $invoices): object => $invoices->toBase()->selectRaw('count(*) as invoices, coalesce(sum(balance_minor), 0) as owed')->first() ?? (object) ['invoices' => 0, 'owed' => 0];
        $open = $read(Invoice::query()->owing());
        $overdue = $read(Invoice::query()->overdue());

        return $this->asked['money'] = [
            'total_minor' => (int) $open->owed,
            'overdue_minor' => (int) $overdue->owed,
            'invoices' => (int) $open->invoices,
            'overdue_invoices' => (int) $overdue->invoices,
        ];
    }

    /** Jobs the studio is running right now. */
    public function jobsRunning(): int
    {
        return $this->asked['jobs'] ??= Project::query()->where('status', ProjectStatus::Active->value)->count();
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
        if (isset($this->asked['automations'])) {
            return $this->asked['automations'];
        }

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

        return $this->asked['automations'] = [
            'failed_runs' => count($tasks),
            'failed_jobs' => $failedJobs,
            'tasks' => $tasks,
            'stale' => $stale,
            'total' => count($tasks) + $failedJobs,
        ];
    }

    /**
     * The rows behind "Automations failing": each scheduled task whose latest run ended in error, and the newest
     * of the jobs that failed in the queue. System health draws them under "Failing now", so the tile's link
     * opens what it counted (master list T-044); before, it opened a list of every task with no word on which.
     *
     * `stale` is the second list of that screen: the tasks that have not reported for STALE_HOURS, which the
     * tile does not count and "Needs attention" names on a line of its own.
     *
     * @return array{total: int, tasks: list<array{task: string, ran_at: CarbonInterface|null}>, jobs: list<array{queue: string, failed_at: string, exception: string}>, more_jobs: int, stale: list<array{task: string, ran_at: CarbonInterface|null}>}
     */
    public function failures(int $jobs = 50): array
    {
        $failing = $this->automationsFailing();
        $runs = ScheduledTaskRun::latestPerTask();
        $withRun = fn (string $task): array => ['task' => $task, 'ran_at' => $runs[$task]->ran_at ?? null];

        $shown = DB::table('failed_jobs')->orderByDesc('id')->limit($jobs)->get(['queue', 'failed_at', 'exception'])
            ->map(fn (object $job): array => [
                'queue' => (string) $job->queue,
                'failed_at' => (string) $job->failed_at,
                // The first line of the trace is the error itself; the rest is where it happened.
                'exception' => Str::limit(trim((string) strtok((string) $job->exception, "\n")), 160),
            ])->all();

        return [
            'total' => $failing['total'],
            'tasks' => array_map($withRun, $failing['tasks']),
            'jobs' => $shown,
            'more_jobs' => max(0, $failing['failed_jobs'] - count($shown)),
            'stale' => array_map($withRun, $failing['stale']),
        ];
    }

    /**
     * The six tiles, in the order the day happens: enquiries, decisions, follow-ups, money, work, system.
     * Cards the person cannot open are left out rather than shown as a dead end.
     *
     * `count` is the number of records behind the tile and `href` the list of exactly those records: the list
     * says the same number in its data-list-rows (master list T-044; tests/Feature/Overview/DashboardWidgetsTest).
     * `rows` is false for the one tile whose link is not such a list: "Automations failing" for a person who may
     * open the Automation screen but not System health. That screen lists every task, failed or not.
     *
     * @return list<array{key: string, label: string, display: string, hint: string, href: string, icon: string, tone: string, count: int, rows: bool}>
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
                'rows' => true,
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
                'rows' => true,
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
                'rows' => true,
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
                // The invoices that are owed on, one row an invoice. It opened the receivables by client, where
                // three invoices of one client are one row: a figure of 3 over a list of 1.
                'href' => route('admin.invoices.index', ['age' => 'open']),
                'icon' => 'banknote',
                'tone' => $due['total_minor'] > 0 ? 'wood' : 'success',
                'count' => $due['invoices'],
                'rows' => true,
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
                'rows' => true,
            ];
        }

        // Shown to the people who can open the screen that explains it (see automationHref).
        $automationHref = $this->automationHref($user);
        if ($automationHref !== null) {
            $failing = $this->automationsFailing();

            $cards[] = [
                'key' => 'automations_failing',
                'label' => 'Automations failing',
                'display' => (string) $failing['total'],
                'hint' => $failing['total'] === 0
                    ? 'last run of every task was clean'
                    : $failing['failed_runs'].' scheduled · '.$failing['failed_jobs'].' queued job'.($failing['failed_jobs'] === 1 ? '' : 's'),
                'href' => $automationHref,
                'icon' => 'bot',
                'tone' => $failing['total'] > 0 ? 'error' : 'success',
                'count' => $failing['total'],
                'rows' => $user->can('system.health'),
            ];
        }

        return $cards;
    }

    /**
     * What needs a person right now, with the one click that starts the work. Only non-zero lines
     * are returned — an empty list is the honest "nothing is waiting".
     *
     * `href` is where the work is done, and `list` is the list of exactly the `count` records the line speaks
     * of (master list T-044). They are the same address but for late money: it is chased from the receivables
     * book, client by client, and counted in invoices. `list` is null where the person may not open such a list.
     *
     * @return list<array{key: string, title: string, detail: string, count: int, href: string, list: string|null, action: string, icon: string, tone: string}>
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
                    'list' => route('admin.leads.index', ['due' => 'overdue']),
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
                    'list' => route('admin.approvals'),
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
                    'list' => route('admin.invoices.index', ['age' => 'overdue']),
                    'action' => 'Chase it',
                    'icon' => 'banknote',
                    'tone' => 'error',
                ];
            }
        }

        // Shown to the people who can open the screen that explains it: Health, or the Automation screen while
        // that module is on. Anyone else would get an alert with a link that does not open.
        $automationHref = $this->automationHref($user);
        if ($automationHref !== null) {
            $failing = $this->automationsFailing();

            if ($failing['total'] > 0) {
                $items[] = [
                    'key' => 'automations_failing',
                    'title' => $failing['total'] === 1 ? 'One automation failed' : $failing['total'].' automations failed',
                    'detail' => $failing['failed_runs'].' scheduled run'.($failing['failed_runs'] === 1 ? '' : 's').' ended in error · '.$failing['failed_jobs'].' job'.($failing['failed_jobs'] === 1 ? '' : 's').' in the failed queue.',
                    'count' => $failing['total'],
                    'href' => $automationHref,
                    'list' => $user->can('system.health') ? $automationHref : null,
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
                    'href' => $this->automationHref($user, 'stale'),
                    'list' => $user->can('system.health') ? $this->automationHref($user, 'stale') : null,
                    'action' => 'Check the schedule',
                    'icon' => 'refresh',
                    'tone' => 'warning',
                ];
            }
        }

        return $items;
    }

    /**
     * Health is the screen that shows why; without it, the Automation page is the next best door. Null when
     * this person can open neither (no permission, or the Automation module is switched off and answers 404).
     *
     * On Health the address ends in the list it speaks of: `#failing` for what the tile counts, `#stale` for the
     * tasks that stopped reporting. Each is a list of that screen with its own count (data-list-rows).
     */
    private function automationHref(User $user, string $list = 'failing'): ?string
    {
        if ($user->can('system.health')) {
            return route('admin.system.platform', ['tab' => 'health']).'#'.$list;
        }

        return $user->can('automation.manage') ? Modules::url('admin.automation.index') : null;
    }
}
