<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;
use App\Settings\SalesSettings;

/**
 * /due — invoices with money outstanding: overdue first, then due within a week (TGM-004).
 */
final class DueCommand implements TelegramCommand
{
    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    public function name(): string
    {
        return '/due';
    }

    public function usage(): string
    {
        return '/due';
    }

    public function description(): string
    {
        return 'invoices overdue or due this week, with balances';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function handle(User $user, string $argument): string
    {
        $lakh = $this->settings->lakh_grouping;

        $overdue = Invoice::query()->overdue()->with('contact:id,name')->orderBy('due_date')->limit(8)->get();
        $soon = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->with('contact:id,name')
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        if ($overdue->isEmpty() && $soon->isEmpty()) {
            return 'Nothing outstanding: no invoice is overdue or due this week.';
        }

        $lines = [];

        if ($overdue->isNotEmpty()) {
            $lines[] = 'Overdue:';

            foreach ($overdue as $invoice) {
                $days = max(1, now()->diffInDays($invoice->due_date));
                $lines[] = sprintf('%s · %s · %s · %d day(s) late', $invoice->number, $invoice->contact->name, $invoice->balance()->format($lakh), $days);
            }
        }

        if ($soon->isNotEmpty()) {
            $lines[] = 'Due this week:';

            foreach ($soon as $invoice) {
                $lines[] = sprintf('%s · %s · %s · due %s', $invoice->number, $invoice->contact->name, $invoice->balance()->format($lakh), $invoice->due_date->format('d M'));
            }
        }

        return implode("\n", $lines);
    }
}
