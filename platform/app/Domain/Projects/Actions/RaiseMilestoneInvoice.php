<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMilestone;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\InvoiceItem;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\InvoiceNumbering;
use App\Models\User;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The invoice a milestone unlocks (INV-002, PRJ-003): "civil + wood work complete" asks for the 40%.
 *
 * It always lands as a **draft**. A milestone reached on site is the team's word that the money is due, and the
 * owner still checks the number and sends it — nothing reaches a client without a person saying so (D-81).
 * One invoice per milestone, however often the button is pressed.
 */
final class RaiseMilestoneInvoice
{
    public function __construct(
        private readonly SalesSettings $sales,
    ) {}

    public function handle(ProjectMilestone $milestone, User $by, bool $issue = false): Invoice
    {
        if ($issue) {
            throw ValidationException::withMessages(['invoice' => 'Milestone invoices must be raised as drafts, then reviewed and issued from Sales.']);
        }

        return DB::transaction(function () use ($milestone, $by): Invoice {
            $context = $milestone->fresh()->project;
            if ($context->quote_id !== null) {
                Quote::query()->whereKey($context->quote_id)->lockForUpdate()->firstOrFail();
            }

            $project = Project::query()->whereKey($context->id)->lockForUpdate()->firstOrFail();
            if ($project->quote_id !== $context->quote_id) {
                throw ValidationException::withMessages(['invoice' => 'The project changed. Reload it before raising an invoice.']);
            }

            $milestone = ProjectMilestone::query()->whereKey($milestone->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            $by = User::query()->findOrFail($by->id);
            abort_unless($by->is_active, 403);
            Gate::forUser($by)->authorize('update', $project);

            $existing = Invoice::withTrashed()->where('project_milestone_id', $milestone->id)->first();
            if ($existing !== null) {
                if ($existing->trashed()) {
                    throw ValidationException::withMessages(['invoice' => 'This milestone already has an archived invoice. Ask Accounts to review it.']);
                }

                return $existing;
            }

            $quote = $project->quote;
            if ($quote !== null && (($quote->meta['furniture_billing'] ?? '') === 'full' || Invoice::withTrashed()->where('source_quote_id', $quote->id)->exists())) {
                throw ValidationException::withMessages(['invoice' => 'This quotation uses a full Furniture invoice, not milestone billing.']);
            }
            $number = InvoiceNumbering::next($project);
            $percent = (int) $milestone->percent_bp;
            $amount = (int) $milestone->amount_minor;
            $taxBp = $quote === null ? 0 : (int) $quote->tax_bp;

            $taxMinor = $taxBp > 0 ? (int) round($amount * $taxBp / (10000 + $taxBp)) : 0;
            $subtotal = $amount - $taxMinor;

            $invoice = Invoice::query()->create([
                'number' => $number,
                'kind' => InvoiceKind::Milestone,
                'status' => InvoiceStatus::Draft,
                'contact_id' => $project->contact_id,
                'company_id' => $project->company_id,
                'quote_id' => $project->quote_id,
                'project_id' => $project->id,
                'project_milestone_id' => $milestone->id,
                'business_line' => $project->business_line,
                'currency' => $quote === null ? 'PKR' : $quote->currency,
                'bank_account_id' => BankAccount::query()->forLine($project->business_line)->first()?->id,
                'subtotal_minor' => $subtotal,
                'discount_minor' => 0,
                'tax_minor' => $taxMinor,
                'total_minor' => $amount,
                'paid_minor' => 0,
                'balance_minor' => $amount,
                'issue_date' => null,
                'due_date' => null,
                'terms' => $this->sales->invoice_terms,
                'notes' => sprintf('%s of %s (%s).', $milestone->name, $project->code, $project->name),
                'created_by' => $by->id,
            ]);

            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'description' => sprintf(
                    '%s — %s of %s',
                    $milestone->name,
                    rtrim(rtrim(number_format($percent / 100, 2), '0'), '.').'%',
                    $project->code,
                ),
                'unit' => 'job',
                'qty' => 1,
                'rate_minor' => $subtotal,
                'discount_minor' => 0,
                'tax_bp' => $taxBp,
                'line_total_minor' => $amount,
                'position' => 0,
            ]);

            return $invoice;
        });
    }
}
