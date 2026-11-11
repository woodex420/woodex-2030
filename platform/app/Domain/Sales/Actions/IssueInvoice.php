<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Accounts\Actions\PostInvoiceToLedger;
use App\Domain\Automation\Actions\EnrollLead;
use App\Domain\Automation\Enums\SequenceTrigger;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Services\InvoiceFreezer;
use App\Models\User;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Issue a draft invoice (Slice P): the one act that turns a draft into a document — status, dates, the frozen
 * snapshot/PDF, the ledger posting and the reminder-sequence trigger, exactly as RaiseMilestoneInvoice does
 * for invoices it issues at birth. Drafts raised in "draft" advance mode, which had no door out, use this too.
 */
final class IssueInvoice
{
    public function __construct(
        private readonly SalesSettings $sales,
        private readonly InvoiceFreezer $freezer,
        private readonly PostInvoiceToLedger $postToLedger,
    ) {}

    public function handle(Invoice $invoice, User $by): Invoice
    {
        $by = User::query()->findOrFail($by->id);
        abort_unless($by->is_active, 403);
        Gate::forUser($by)->authorize('issue', $invoice);

        return DB::transaction(function () use ($invoice, $by): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new QuoteActionDenied('Only a draft invoice can be issued.');
            }

            if ((int) $invoice->total_minor <= 0) {
                throw new QuoteActionDenied('An invoice with no amount cannot be issued.');
            }

            $issueDate = $invoice->issue_date ?? today();

            $invoice->forceFill([
                'status' => InvoiceStatus::Issued,
                'issue_date' => $issueDate,
                'due_date' => $invoice->due_date ?? $issueDate->copy()->addDays((int) $this->sales->invoice_due_days),
            ])->save();

            $this->freezer->freeze($invoice);
            $this->postToLedger->handle($invoice->refresh(), $by);

            $leadId = $invoice->quote?->lead_id;

            if ($leadId !== null && ($lead = Lead::query()->find($leadId)) !== null) {
                app(EnrollLead::class)->forTrigger(SequenceTrigger::InvoiceIssued, $lead);
            }

            return $invoice->refresh();
        });
    }
}
