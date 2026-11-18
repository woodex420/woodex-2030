<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Receivables aging (RPT-005 aging half, INV-005b): who owes what, and how long it has been owed — per
 * client, per bucket (not due yet, 1–30, 31–60, 61–90, 90+ days past due). The follow-up list for the
 * coming week, in the order that hurts.
 */
final class ReceivablesReport
{
    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function handle(): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        // One row per open invoice; buckets and per-client sums are folded in PHP so the aging is exact.
        $invoices = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->with('contact:id,name')
            ->get();

        $byContact = [];

        foreach ($invoices as $invoice) {
            $balance = (int) $invoice->total_minor - (int) $invoice->paid_minor;

            if ($balance <= 0) {
                continue;
            }

            $due = $invoice->due_date !== null ? CarbonImmutable::parse($invoice->due_date->format('Y-m-d')) : $today;
            $daysUntilDue = (int) $today->diffInDays($due); // negative once the due date has passed

            $bucket = match (true) {
                $daysUntilDue >= 0 => 'current',
                $daysUntilDue >= -30 => 'd1_30',
                $daysUntilDue >= -60 => 'd31_60',
                $daysUntilDue >= -90 => 'd61_90',
                default => 'd90_plus',
            };

            $contactId = (int) $invoice->contact_id;
            $entry = $byContact[$contactId] ?? [
                'contact_id' => $contactId,
                'contact' => $invoice->contact->name,
                'invoices' => 0,
                'invoiced_minor' => 0,
                'paid_minor' => 0,
                'balance_minor' => 0,
                'oldest_due' => null,
                'current' => 0,
                'd1_30' => 0,
                'd31_60' => 0,
                'd61_90' => 0,
                'd90_plus' => 0,
            ];

            $entry['invoices']++;
            $entry['invoiced_minor'] += (int) $invoice->total_minor;
            $entry['paid_minor'] += (int) $invoice->paid_minor;
            $entry['balance_minor'] += $balance;
            $entry[$bucket] += $balance;

            if ($due->lessThan($today) && ($entry['oldest_due'] === null || $due->lessThan($entry['oldest_due']))) {
                $entry['oldest_due'] = $due;
            }

            $byContact[$contactId] = $entry;
        }

        $rows = collect($byContact)
            ->sortByDesc(fn (array $entry): array => [$entry['d90_plus'], $entry['d61_90'], $entry['d31_60'], $entry['d1_30'], $entry['current']])
            ->values()
            ->all();

        $totals = [
            'balance_minor' => 0,
            'current' => 0,
            'd1_30' => 0,
            'd31_60' => 0,
            'd61_90' => 0,
            'd90_plus' => 0,
        ];

        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * The open invoices of one client, oldest overdue first — the detail behind a receivables row.
     *
     * @return Collection<int, Invoice>
     */
    public function openInvoicesFor(int $contactId): Collection
    {
        return Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('contact_id', $contactId)
            ->whereColumn('paid_minor', '<', 'total_minor')
            ->orderBy('due_date')
            ->get();
    }
}
