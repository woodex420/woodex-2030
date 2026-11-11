<?php

declare(strict_types=1);

namespace App\Domain\System\Actions;

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Models\MonthlyReport;
use App\Domain\System\Notifications\MonthlyReportNotification;
use App\Models\User;
use App\Support\Reports\ReportRange;
use App\Support\Reports\RevenueCollectionsReport;
use App\Support\Reports\SalesFunnelReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The monthly CRM report (P8.5): on the 1st at 09:00 the owner gets the month that just ended in
 * one message — enquiries, quotations, wins, money in, money outstanding, website chats — with
 * the month before for comparison. The computation reuses the reports kit the screens already
 * use (RPT-002/RPT-005), so the digest and the dashboards can never disagree. One row per month
 * is kept under System → Automations; regenerating a month updates the row rather than adding
 * a second copy of the truth.
 */
final class GenerateMonthlyReport
{
    public function __construct(
        private readonly SalesFunnelReport $funnel,
        private readonly RevenueCollectionsReport $money,
    ) {}

    /**
     * @param  ?CarbonImmutable  $periodStart  first day of the month to report; null = last month
     */
    public function handle(?CarbonImmutable $periodStart = null, bool $send = true): MonthlyReport
    {
        $start = ($periodStart ?? CarbonImmutable::now()->startOfMonth()->subSecond()->startOfMonth())->startOfDay();

        if (! $start->isSameDay($start->copy()->startOfMonth())) {
            throw new \InvalidArgumentException('The report period must start on the first day of a month.');
        }

        $end = $start->endOfMonth();
        $range = new ReportRange('custom', $start, $end);

        $payload = [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'label' => $start->translatedFormat('F Y'),
            ],
            'funnel' => $this->funnel->headline($range),
            'funnel_previous' => $this->funnel->headline($range->previous()),
            'money' => $this->money->totals($range),
            'money_previous' => $this->money->totals($range->previous()),
            'top_sources' => array_slice($this->funnel->bySource($range), 0, 3),
            'overdue' => $this->overdue(),
            'web_chat' => $this->webChat($start, $end),
        ];

        /** @var MonthlyReport $report */
        $report = MonthlyReport::query()->updateOrCreate(
            // The Carbon object, not a date string: the column stores a datetime, and a bare
            // 'Y-m-d' binding would not match it (sqlite compares byte by byte).
            ['period_start' => $start],
            ['period_end' => $end, 'payload' => $payload],
        );

        if ($send) {
            $this->notify($report);
        }

        return $report;
    }

    /**
     * Invoices past their due date and still not settled — the number that decides this month's
     * chasing. Today's view, like the receivables screen.
     *
     * @return array{count: int, oldest_days: int}
     */
    private function overdue(): array
    {
        $today = CarbonImmutable::today();

        /** @var Builder<Invoice> $open */
        $open = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid])
            ->whereDate('due_date', '<', $today);

        $oldest = (clone $open)->orderBy('due_date')->value('due_date');

        return [
            'count' => (int) (clone $open)->count(),
            'oldest_days' => $oldest !== null ? (int) $today->diffInDays(CarbonImmutable::parse($oldest)) : 0,
        ];
    }

    /**
     * The studio's own chat widget (P8.5 part 3): how many visitors opened a thread and how much
     * they typed — the channel the owner just switched on deserves a line in its first reports.
     *
     * @return array{threads: int, messages: int}
     */
    private function webChat(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $threads = Conversation::query()
            ->where('channel', Channel::WebChat->value)
            ->whereBetween('created_at', [$start, $end->endOfDay()])
            ->count();

        $messages = Message::query()
            ->whereHas('conversation', fn (Builder $query): Builder => $query->where('channel', Channel::WebChat->value))
            ->whereBetween('created_at', [$start, $end->endOfDay()])
            ->count();

        return ['threads' => (int) $threads, 'messages' => (int) $messages];
    }

    /** The report is for the owner's eyes: every active owner, same as the alert flows. */
    private function notify(MonthlyReport $report): void
    {
        $owners = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', 'owner'))
            ->get();

        if ($owners->isEmpty()) {
            return;
        }

        app(AlertRouter::class)->send('system.monthly_report', $owners, new MonthlyReportNotification($report));

        $report->forceFill(['notified_at' => now()])->save();
    }
}
