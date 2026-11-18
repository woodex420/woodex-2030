<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Accounts\Actions\PostInvoiceToLedger;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\InvoiceItem;
use App\Domain\Sales\Models\RecurringInvoice;
use App\Domain\Sales\Services\InvoiceFreezer;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Numbering;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The standing charge becomes an invoice (slice E, part 2).
 *
 * The schedule decides the lines, the tax rate and the due days; this action turns them into an issued invoice
 * with its own number series (`RI-2026-0001`), the frozen PDF and the ledger posting every issued invoice gets,
 * then moves the schedule to its next date. A schedule still inside its own period is left alone, so running the
 * command twice in one day bills once — and a missed day does not make a client pay twice.
 */
final class IssueRecurringInvoice
{
    public function __construct(
        private readonly SalesSettings $sales,
        private readonly Numbering $numbering,
        private readonly InvoiceFreezer $freezer,
        private readonly PostInvoiceToLedger $postToLedger,
    ) {}

    public function handle(RecurringInvoice $schedule, ?User $by = null): ?Invoice
    {
        return DB::transaction(function () use ($schedule, $by): ?Invoice {
            $schedule = RecurringInvoice::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();

            if (! $schedule->is_active || $schedule->next_issue_on->isAfter(today())) {
                return null;
            }

            if ($schedule->ends_on !== null && $schedule->ends_on->isBefore(today())) {
                $schedule->forceFill(['is_active' => false])->save();

                return null;
            }

            $lines = $this->lines($schedule);
            $subtotal = array_sum(array_column($lines, 'pre_tax'));
            $tax = array_sum(array_column($lines, 'tax'));
            $total = $subtotal + $tax;

            $issuedOn = today();
            $dueDays = $schedule->due_days ?? (int) $this->sales->invoice_due_days;

            $invoice = Invoice::query()->create([
                'number' => $this->numbering->next('recurring.invoice'),
                'kind' => InvoiceKind::Recurring,
                'status' => InvoiceStatus::Issued,
                'contact_id' => $schedule->contact_id,
                'company_id' => $schedule->company_id,
                'business_line' => $schedule->business_line,
                'bank_account_id' => $this->bankAccountFor($schedule->business_line)?->id,
                'currency' => 'PKR',
                'subtotal_minor' => $subtotal,
                'discount_minor' => 0,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'paid_minor' => 0,
                'balance_minor' => $total,
                'issue_date' => $issuedOn,
                'due_date' => $issuedOn->copy()->addDays($dueDays),
                'terms' => $this->sales->invoice_terms,
                'notes' => $schedule->notes ?? ('Recurring charge — '.$schedule->title.'.'),
                'created_by' => $by?->id,
            ]);

            foreach ($lines as $index => $line) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'description' => $line['description'],
                    'unit' => $line['unit'],
                    'qty' => $line['qty'],
                    'rate_minor' => $line['rate_minor'],
                    'discount_minor' => $line['discount_minor'],
                    'tax_bp' => $schedule->tax_bp,
                    'line_total_minor' => $line['pre_tax'] + $line['tax'],
                    'position' => $index,
                ]);
            }

            $this->freezer->freeze($invoice);
            $this->postToLedger->handle($invoice->refresh(), $by);

            $next = $this->nextDate($schedule);

            $schedule->forceFill([
                'next_issue_on' => $next,
                'invoice_count' => $schedule->invoice_count + 1,
                'last_issued_at' => now(),
                'is_active' => $schedule->ends_on === null || ! $next->isAfter($schedule->ends_on->toImmutable()),
            ])->save();

            return $invoice;
        });
    }

    /**
     * The first date after today, walking the rhythm forward. A schedule that missed its date — the server was
     * down, the client asked to skip a month — produces one invoice now, not a backlog of invoices later.
     */
    private function nextDate(RecurringInvoice $schedule): CarbonImmutable
    {
        $today = today()->toImmutable();
        $next = $schedule->next_issue_on->toImmutable();

        for ($step = 0; $step < 1000 && $next->lessThanOrEqualTo($today); $step++) {
            $next = $schedule->frequency->advance($next);
        }

        if ($next->lessThanOrEqualTo($today)) {
            throw new RuntimeException('The recurring schedule is too far behind to advance safely.');
        }

        return $next;
    }

    /**
     * The standing lines, priced once: description, quantity, unit price, discount, and the tax slice of each.
     *
     * @return list<array{description: string, unit: string, qty: int, rate_minor: int, discount_minor: int, pre_tax: int, tax: int}>
     */
    private function lines(RecurringInvoice $schedule): array
    {
        $lines = [];

        foreach ($schedule->lines as $line) {
            $description = trim((string) ($line['description'] ?? ''));

            if ($description === '') {
                continue;
            }

            $qty = max(1, (int) ($line['qty'] ?? 1));
            $rate = max(0, (int) ($line['rate_minor'] ?? 0));
            $discount = max(0, (int) ($line['discount_minor'] ?? 0));
            $preTax = max(0, $qty * $rate - $discount);

            $lines[] = [
                'description' => $description,
                'unit' => (string) ($line['unit'] ?? 'service'),
                'qty' => $qty,
                'rate_minor' => $rate,
                'discount_minor' => $discount,
                'pre_tax' => $preTax,
                'tax' => (int) round($preTax * $schedule->tax_bp / 10000),
            ];
        }

        if ($lines === []) {
            throw new RuntimeException('A recurring schedule needs at least one priced line.');
        }

        return $lines;
    }

    /** Interior → "WOODEX INTERIOR", Furniture → "WOODEX FURNITURE" (INV-004). */
    private function bankAccountFor(BusinessLine $line): ?BankAccount
    {
        return BankAccount::query()->forLine($line)->first();
    }
}
