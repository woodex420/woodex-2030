<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\PaymentAllocation;
use App\Domain\Sales\Models\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Revenue and collections (RPT-005): quoted, accepted, invoiced and collected per month; what each business
 * line contributes; where the money arrives (method and bank). The cash-flow question, answerable per month
 * — the same numbers under the screen, the CSV and the comparison row.
 */
final class RevenueCollectionsReport
{
    /**
     * @return array<string, mixed>
     */
    public function handle(ReportRange $range, ?BusinessLine $line = null): array
    {
        return [
            'months' => $this->months($range, $line),
            'totals' => $this->totals($range, $line),
            'previous' => $this->totals($range->previous(), $line),
            'by_line' => $this->byLine($range, $line),
            'by_method' => $this->byMethod($range, $line),
        ];
    }

    /**
     * @return list<array{month: string, quoted_minor: int, accepted_minor: int, invoiced_minor: int, collected_minor: int}>
     */
    public function months(ReportRange $range, ?BusinessLine $line = null): array
    {
        // Plain table reads: these are aggregates, not models.
        $quoted = $this->byDay(
            DB::table('quotes')->when($line !== null, fn ($q) => $q->where('business_line', $line?->value))->whereNot('status', QuoteStatus::Void->value)->whereBetween('sent_at', [$range->from, $range->to]),
            'sent_at',
            'total_minor',
        );
        $accepted = $this->byDay(
            DB::table('quotes')->when($line !== null, fn ($q) => $q->where('business_line', $line?->value))->where('status', QuoteStatus::Accepted->value)->whereBetween('accepted_at', [$range->from, $range->to]),
            'accepted_at',
            'total_minor',
        );
        $invoiced = $this->byDay(
            DB::table('invoices')->when($line !== null, fn ($q) => $q->where('business_line', $line?->value))->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value])->where('issue_date', '>=', $range->from->toDateString())->where('issue_date', '<', $range->to->addDay()->toDateString()),
            'issue_date',
            'total_minor',
        );
        $collected = $this->byDay(
            $this->collections($range, $line),
            'payments.received_on',
            $line === null ? 'payments.amount_minor' : 'payment_allocations.amount_minor',
        );

        $month = fn (string $day): string => substr($day, 0, 7);

        $toMonths = function (Collection $byDay) use ($month): Collection {
            $months = collect();

            foreach ($byDay as $day => $value) {
                $key = $month((string) $day);
                $months[$key] = ($months[$key] ?? 0) + (int) $value;
            }

            return $months;
        };

        $quotedMonths = $toMonths($quoted);
        $acceptedMonths = $toMonths($accepted);
        $invoicedMonths = $toMonths($invoiced);
        $collectedMonths = $toMonths($collected);

        $keys = $quotedMonths->keys()
            ->merge($acceptedMonths->keys())
            ->merge($invoicedMonths->keys())
            ->merge($collectedMonths->keys())
            ->unique()
            ->sort()
            ->values();

        $rows = [];

        foreach ($keys as $key) {
            $rows[] = [
                'month' => CarbonImmutable::parse($key.'-01')->format('M Y'),
                'quoted_minor' => (int) ($quotedMonths[$key] ?? 0),
                'accepted_minor' => (int) ($acceptedMonths[$key] ?? 0),
                'invoiced_minor' => (int) ($invoicedMonths[$key] ?? 0),
                'collected_minor' => (int) ($collectedMonths[$key] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @return array{quoted_minor: int, accepted_minor: int, invoiced_minor: int, collected_minor: int, outstanding_minor: int}
     */
    public function totals(ReportRange $range, ?BusinessLine $line = null): array
    {
        $quoted = (int) Quote::query()->when($line !== null, fn ($q) => $q->where('business_line', $line?->value))
            ->whereNot('status', QuoteStatus::Void->value)
            ->whereBetween('sent_at', [$range->from, $range->to])->sum('total_minor');
        $accepted = (int) Quote::query()->when($line !== null, fn ($q) => $q->where('business_line', $line?->value))
            ->where('status', QuoteStatus::Accepted->value)
            ->whereBetween('accepted_at', [$range->from, $range->to])->sum('total_minor');
        $invoiced = (int) Invoice::query()->when($line !== null, fn ($q) => $q->where('business_line', $line?->value))
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value])
            ->where('issue_date', '>=', $range->from->toDateString())->where('issue_date', '<', $range->to->addDay()->toDateString())->sum('total_minor');
        $collected = (int) $this->collections($range, $line)->sum($line === null ? 'payments.amount_minor' : 'payment_allocations.amount_minor');

        return [
            'quoted_minor' => $quoted,
            'accepted_minor' => $accepted,
            'invoiced_minor' => $invoiced,
            'collected_minor' => $collected,
            'outstanding_minor' => $this->outstandingMinor($line),
        ];
    }

    /**
     * What is owed on issued invoices today, whatever the period — the number the report ends on.
     */
    public function outstandingMinor(?BusinessLine $line = null): int
    {
        return (int) Invoice::query()->when($line !== null, fn ($q) => $q->where('business_line', $line?->value))
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->selectRaw('sum(total_minor - paid_minor) as outstanding')
            ->value('outstanding');
    }

    /**
     * Accepted, invoiced and collected per business line (RPT-005: which line earns its keep). Collected
     * follows the allocations: a payment counts towards the line of the invoices it actually settled.
     *
     * @return list<array{label: string, accepted_minor: int, invoiced_minor: int, collected_minor: int}>
     */
    public function byLine(ReportRange $range, ?BusinessLine $line = null): array
    {
        $accepted = Quote::query()->when($line !== null, fn ($q) => $q->where('business_line', $line?->value))
            ->where('status', QuoteStatus::Accepted->value)
            ->whereBetween('accepted_at', [$range->from, $range->to])
            ->selectRaw('business_line, sum(total_minor) as value')
            ->groupBy('business_line')
            ->pluck('value', 'business_line');

        $invoiced = Invoice::query()->when($line !== null, fn ($q) => $q->where('business_line', $line?->value))
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value])
            ->where('issue_date', '>=', $range->from->toDateString())->where('issue_date', '<', $range->to->addDay()->toDateString())
            ->selectRaw('business_line, sum(total_minor) as value')
            ->groupBy('business_line')
            ->pluck('value', 'business_line');

        $collected = PaymentAllocation::query()
            ->join('payments', 'payment_allocations.payment_id', '=', 'payments.id')
            ->join('invoices', 'payment_allocations.invoice_id', '=', 'invoices.id')
            ->where('payments.status', PaymentStatus::Verified->value)
            ->when($line !== null, fn ($q) => $q->where('invoices.business_line', $line?->value))
            ->where('payments.received_on', '>=', $range->from->toDateString())->where('payments.received_on', '<', $range->to->addDay()->toDateString())
            ->selectRaw('invoices.business_line, sum(payment_allocations.amount_minor) as value')
            ->groupBy('invoices.business_line')
            ->pluck('value', 'business_line');

        $keys = $accepted->keys()->merge($invoiced->keys())->merge($collected->keys())->unique()->values();

        return $keys
            ->map(fn (string $line): array => [
                'label' => BusinessLine::tryFrom($line)?->label() ?? $line,
                'accepted_minor' => (int) ($accepted[$line] ?? 0),
                'invoiced_minor' => (int) ($invoiced[$line] ?? 0),
                'collected_minor' => (int) ($collected[$line] ?? 0),
            ])
            ->sortByDesc('invoiced_minor')
            ->values()
            ->all();
    }

    /**
     * Where the money arrived: method and bank (RPT-005: reconciling the bank statement starts here).
     *
     * @return list<array{label: string, count: int, amount_minor: int}>
     */
    public function byMethod(ReportRange $range, ?BusinessLine $line = null): array
    {
        // A plain table read: aggregate aliases like `count` do not belong on a model.
        $sum = $line === null ? 'payments.amount_minor' : 'payment_allocations.amount_minor';
        $rows = $this->collections($range, $line)
            ->selectRaw('payments.method, payments.bank_account_id, count(distinct payments.id) as payment_count, sum('.$sum.') as value')
            ->groupBy('payments.method', 'payments.bank_account_id')
            ->orderByDesc('value')->get();

        $banks = BankAccount::query()->pluck('bank_name', 'id');

        return $rows
            ->map(function ($row) use ($banks): array {
                $method = PaymentMethod::tryFrom((string) $row->method)?->label() ?? (string) $row->method;
                $bank = $row->bank_account_id !== null ? (string) ($banks[(int) $row->bank_account_id] ?? '') : '';

                return [
                    'label' => $bank !== '' ? $method.' · '.$bank : $method,
                    'count' => (int) $row->payment_count,
                    'amount_minor' => (int) $row->value,
                ];
            })
            ->all();
    }

    /** Verified cash globally; allocations only when attributing it to one business line. */
    private function collections(ReportRange $range, ?BusinessLine $line): Builder
    {
        $query = DB::table('payments')->where('payments.status', PaymentStatus::Verified->value)
            ->where('payments.received_on', '>=', $range->from->toDateString())->where('payments.received_on', '<', $range->to->addDay()->toDateString());
        if ($line !== null) {
            $query->join('payment_allocations', 'payment_allocations.payment_id', '=', 'payments.id')
                ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
                ->where('invoices.business_line', $line->value);
        }

        return $query;
    }

    /**
     * Day-level sums for one metric, grouped in SQL on the raw date (portable: no strftime/DATE_FORMAT),
     * bucketed to months by the caller.
     *
     * @param  Builder  $query
     * @return Collection<string, int> "Y-m-d" => sum
     */
    private function byDay($query, string $dateColumn, string $sumColumn): Collection
    {
        return $query
            ->selectRaw($dateColumn.' as day, sum('.$sumColumn.') as value')
            ->groupBy($dateColumn)
            ->pluck('value', 'day');
    }
}
