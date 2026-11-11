<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Money;

/**
 * /report — the day's, week's or month's numbers (TGM-004): leads in, quotes sent and accepted, invoiced,
 * collected. One period at a time, straight from the tables, so the number on the phone is the number in
 * Woodex OS.
 */
final class ReportCommand implements TelegramCommand
{
    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    public function name(): string
    {
        return '/report';
    }

    public function usage(): string
    {
        return '/report [day|week|month]';
    }

    public function description(): string
    {
        return 'leads, quotes, invoiced and collected for a period';
    }

    public function permission(): string
    {
        return 'reports.view';
    }

    public function handle(User $user, string $argument): string
    {
        [$from, $title] = match (strtolower(trim($argument))) {
            'week' => [now()->startOfWeek(), 'This week'],
            'month' => [now()->startOfMonth(), 'This month'],
            default => [now()->startOfDay(), 'Today'],
        };

        $to = now();
        $lakh = $this->settings->lakh_grouping;

        $leads = Lead::query()->whereBetween('created_at', [$from, $to])->count();

        $quotesSent = Quote::query()->whereBetween('sent_at', [$from, $to])->count();
        $accepted = Quote::query()->where('status', QuoteStatus::Accepted->value)->whereBetween('accepted_at', [$from, $to])->count();
        $acceptedValue = Money::ofMinor((int) Quote::query()->where('status', QuoteStatus::Accepted->value)->whereBetween('accepted_at', [$from, $to])->sum('total_minor'));

        $invoiced = Money::ofMinor((int) Invoice::query()->whereIn('status', ['issued', 'partially_paid', 'paid'])->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->sum('total_minor'));
        $collected = Money::ofMinor((int) Payment::query()->where('status', PaymentStatus::Verified->value)->whereBetween('verified_at', [$from, $to])->sum('amount_minor'));

        return implode("\n", [
            $title.':',
            sprintf('Leads: %d', $leads),
            sprintf('Quotes sent: %d · accepted: %d (%s)', $quotesSent, $accepted, $acceptedValue->format($lakh)),
            sprintf('Invoiced: %s', $invoiced->format($lakh)),
            sprintf('Collected: %s', $collected->format($lakh)),
        ]);
    }
}
