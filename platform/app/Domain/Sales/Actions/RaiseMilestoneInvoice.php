<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Accounts\Actions\PostInvoiceToLedger;
use App\Domain\Automation\Actions\EnrollLead;
use App\Domain\Automation\Enums\SequenceTrigger;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\ProjectMilestone;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\InvoiceItem;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\InvoiceFreezer;
use App\Domain\Sales\Services\InvoiceNumbering;
use App\Models\User;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\DB;

/**
 * A milestone of an accepted quotation becomes an invoice (QUO-011, INV-001) — slice E.
 *
 * The advance invoice (`on_acceptance`) is raised the moment the quotation is accepted; every other
 * milestone is raised when the work reaches it, so the money follows the job instead of somebody
 * remembering to bill. Both paths run this one method: same number series, same tax slice, same snapshot,
 * same ledger posting, same sequence trigger. Raising twice for one milestone returns the existing invoice —
 * a milestone is billed once.
 */
final class RaiseMilestoneInvoice
{
    public function __construct(
        private readonly SalesSettings $sales,
        private readonly InvoiceFreezer $freezer,
        private readonly PostInvoiceToLedger $postToLedger,
    ) {}

    /**
     * @param  bool|null  $issued  null = follow the owner's advance-invoice setting; true/false forces the state.
     */
    public function handle(Quote $quote, ProjectMilestone $milestone, ?User $by = null, ?bool $issued = null): Invoice
    {
        return DB::transaction(function () use ($quote, $milestone, $by, $issued): Invoice {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if (($quote->meta['furniture_billing'] ?? '') === 'full' || Invoice::withTrashed()->where('source_quote_id', $quote->id)->exists()) {
                throw new QuoteActionDenied('This quotation uses a full Furniture invoice, not milestone billing.');
            }
            $project = $quote->project()->lockForUpdate()->firstOrFail();

            $milestone = ProjectMilestone::query()
                ->whereKey($milestone->id)
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = Invoice::withTrashed()->where('project_milestone_id', $milestone->id)->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    throw new QuoteActionDenied('This milestone already has an archived invoice. Ask Accounts to review it.');
                }

                return $existing;
            }

            $quoteMilestone = $milestone->quoteMilestone()->first();
            $percent = (int) ($milestone->percent_bp ?: ($quoteMilestone === null ? 0 : $quoteMilestone->percent_bp));
            $amount = (int) ($milestone->amount_minor ?: ($quoteMilestone === null ? 0 : $quoteMilestone->amount_minor));
            $name = $quoteMilestone === null ? ($milestone->name ?: 'Milestone') : $quoteMilestone->name;

            $note = $percent > 0
                ? rtrim(rtrim(number_format($percent / 100, 2), '0'), '.').'% '.$name
                : $name;

            // Tax keeps the quotation's rate: a milestone is a slice of the same job, not a different supply.
            $taxMinor = (int) round($amount * (int) $quote->tax_bp / (10000 + (int) $quote->tax_bp));
            $subtotal = $amount - $taxMinor;

            $issued ??= $this->sales->advance_invoice_mode !== 'draft';

            $invoice = Invoice::query()->create([
                'number' => InvoiceNumbering::next($project),
                'kind' => InvoiceKind::Milestone,
                'status' => $issued ? InvoiceStatus::Issued : InvoiceStatus::Draft,
                'contact_id' => $quote->contact_id,
                'company_id' => $quote->company_id,
                'quote_id' => $quote->id,
                'project_id' => $project->id,
                'project_milestone_id' => $milestone->id,
                'business_line' => $quote->business_line,
                'bank_account_id' => $this->bankAccountFor($quote->business_line)?->id,
                'currency' => $quote->currency,
                'subtotal_minor' => $subtotal,
                'discount_minor' => 0,
                'tax_minor' => $taxMinor,
                'total_minor' => $amount,
                'paid_minor' => 0,
                'balance_minor' => $amount,
                'issue_date' => $issued ? today() : null,
                'due_date' => $issued ? today()->addDays((int) $this->sales->invoice_due_days) : null,
                'terms' => $this->sales->invoice_terms,
                'notes' => sprintf('%s of quotation %s (%s).', $note, $quote->number, $quote->title),
                'created_by' => $by?->id,
            ]);

            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'description' => sprintf('%s — %s of quotation %s', $name, $percent > 0 ? rtrim(rtrim(number_format($percent / 100, 2), '0'), '.').'%' : 'balance', $quote->number),
                'unit' => 'job',
                'qty' => 1,
                'rate_minor' => $subtotal,
                'discount_minor' => 0,
                'tax_bp' => (int) $quote->tax_bp,
                'line_total_minor' => $amount,
                'position' => 0,
            ]);

            $milestone->forceFill(['due_date' => $invoice->due_date])->save();

            if ($issued) {
                $this->freeze($invoice);

                // Booked: receivable + revenue land in the ledger in the same act (P8.2).
                $this->postToLedger->handle($invoice->refresh(), $by);

                // AUT-001: an invoice that exists starts the reminder sequences.
                if ($quote->lead_id !== null) {
                    $lead = Lead::query()->find($quote->lead_id);

                    if ($lead !== null) {
                        app(EnrollLead::class)->forTrigger(SequenceTrigger::InvoiceIssued, $lead);
                    }
                }
            }

            return $invoice;
        });
    }

    /** The PDF and the snapshot of an invoice the client will see (INV-003) — one implementation, shared. */
    public function freeze(Invoice $invoice): Invoice
    {
        return $this->freezer->freeze($invoice);
    }

    /** Interior → "WOODEX INTERIOR", Furniture → "WOODEX FURNITURE" (INV-004). */
    private function bankAccountFor(BusinessLine $line): ?BankAccount
    {
        return BankAccount::query()->forLine($line)->first();
    }
}
