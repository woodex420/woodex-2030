<?php

declare(strict_types=1);

namespace App\Domain\Projects\Services;

use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Projects\Models\Project;

/** Read-only source totals, never another ledger or a guessed historical reconciliation. */
final class ProjectCostSummary
{
    /** @return array<string, int> */
    public function forProject(Project $project): array
    {
        $bills = Bill::query()->where('project_id', $project->id)
            ->whereIn('status', ['received', 'partially_paid', 'paid'])
            ->whereHas('journalEntry', fn ($query) => $query->whereNull('reversed_by_entry_id')->whereNull('reverses_entry_id'));
        $paid = Expense::query()->where('project_id', $project->id)->where('direction', 'out')
            ->whereIn('paid_from', ['cash', 'bank', 'wallet'])
            ->whereHas('journalEntry', fn ($query) => $query->whereNull('reversed_by_entry_id')->whereNull('reverses_entry_id'));
        $legacy = $project->expenses();
        $legacyPosted = (clone $legacy)->whereIn('journal_entry_id', JournalEntry::query()
            ->where('source_type', 'project_expense')->whereNull('reversed_by_entry_id')->whereNull('reverses_entry_id')->select('id'));
        $customer = $project->invoices()->whereIn('status', ['issued', 'partially_paid', 'paid']);
        $invoices = (clone $customer)->where('kind', '!=', 'credit_note');

        $billSubtotal = (int) (clone $bills)->sum('subtotal_minor');
        $billTotal = (int) (clone $bills)->sum('total_minor');
        $billPaid = (int) (clone $bills)->sum('paid_minor');
        $directPaid = (int) (clone $paid)->sum('amount_minor');

        return [
            'linked_cost_minor' => $billSubtotal + $directPaid,
            'bill_subtotal_minor' => $billSubtotal,
            'bill_tax_minor' => (int) (clone $bills)->sum('tax_minor'),
            'supplier_billed_minor' => $billTotal,
            'supplier_paid_minor' => $billPaid,
            'supplier_outstanding_minor' => $billTotal - $billPaid,
            'direct_paid_minor' => $directPaid,
            'draft_bills_minor' => (int) Bill::query()->where('project_id', $project->id)->where('status', 'draft')->sum('total_minor'),
            'legacy_recorded_minor' => (int) (clone $legacy)->sum('amount_minor'),
            'legacy_posted_minor' => (int) (clone $legacyPosted)->sum('amount_minor'),
            'legacy_count' => (clone $legacy)->count(),
            'legacy_posted_count' => (clone $legacyPosted)->count(),
            'customer_billed_minor' => (int) (clone $invoices)->sum('total_minor'),
            'customer_received_minor' => (int) (clone $invoices)->sum('paid_minor'),
            'customer_outstanding_minor' => (int) (clone $invoices)->sum('balance_minor'),
            'customer_credit_notes_minor' => (int) (clone $customer)->where('kind', 'credit_note')->sum('total_minor'),
        ];
    }
}
