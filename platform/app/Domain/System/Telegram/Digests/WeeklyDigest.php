<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Digests;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * S4: the Monday digest — the week that just ended, in one message per linked staff
 * member. Sources, quotations, money in, receivables; each line only for roles who
 * may see it. Like the daily digest it waits out a mute rather than being skipped.
 */
final class WeeklyDigest
{
    /** Build the message for one staff member, or null when the role sees none of it. */
    public function build(User $user): ?string
    {
        $start = CarbonImmutable::now()->startOfWeek()->subWeek(); // last Monday 00:00
        $end = $start->addWeek(); // this Monday 00:00 — the digest runs at 09:00

        $lines = ['📊 The week '.$start->format('d M').' – '.$end->subDay()->format('d M')];

        if ($user->can('leads.view')) {
            $leads = Lead::query()->visibleTo($user)->whereBetween('created_at', [$start, $end]);

            $total = (clone $leads)->count();

            $sources = (clone $leads)
                ->whereNotNull('source')
                ->groupBy('source')
                ->orderByRaw('count(*) desc')
                ->limit(3)
                ->selectRaw('source, count(*) as c')
                ->pluck('c', 'source');

            $lines[] = '';
            $lines[] = sprintf('Leads: %d.', $total);

            if ($sources->isNotEmpty()) {
                $lines[] = 'Top sources: '.implode(', ', $sources->map(fn ($count, $source): string => "{$source} {$count}")->all());
            }

            $won = (clone $leads)->whereBetween('won_at', [$start, $end])->count();
            $lost = (clone $leads)->whereBetween('lost_at', [$start, $end])->count();
            $lines[] = sprintf('Won: %d · lost: %d.', $won, $lost);
        }

        if ($user->can('quotes.view')) {
            $sent = Quote::query()->visibleTo($user)->whereBetween('sent_at', [$start, $end])->count();
            $accepted = Quote::query()->visibleTo($user)->whereBetween('accepted_at', [$start, $end])->count();
            $lines[] = sprintf('Quotations: %d sent, %d accepted.', $sent, $accepted);
        }

        if ($user->can('payments.view')) {
            $money = Payment::query()
                ->whereBetween('verified_at', [$start, $end])
                ->selectRaw('count(*) as c, coalesce(sum(amount_minor), 0) as total')
                ->first();

            $lines[] = sprintf(
                'Collected: %s across %d payment%s.',
                Money::ofMinor((int) ($money->total ?? 0))->format(),
                (int) ($money->c ?? 0),
                (int) ($money->c ?? 0) === 1 ? '' : 's',
            );
        }

        if ($user->can('invoices.view')) {
            $receivable = Invoice::query()
                ->whereNull('voided_at')
                ->where('balance_minor', '>', 0)
                ->selectRaw('count(*) as c, coalesce(sum(balance_minor), 0) as total')
                ->first();

            $overdue = Invoice::query()
                ->whereNull('voided_at')
                ->where('balance_minor', '>', 0)
                ->whereDate('due_date', '<', now()->toDateString())
                ->count();

            $lines[] = sprintf(
                'Still to collect: %s on %d invoice%s (%d overdue).',
                Money::ofMinor((int) ($receivable->total ?? 0))->format(),
                (int) ($receivable->c ?? 0),
                (int) ($receivable->c ?? 0) === 1 ? '' : 's',
                $overdue,
            );
        }

        if (count($lines) === 1) {
            return null; // This role sees none of the weekly numbers.
        }

        return implode("\n", $lines);
    }
}
