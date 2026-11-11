<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P8.2: an issued invoice becomes a ledger entry — the receivable is booked, revenue per business
 * line, tax to taxes payable. Idempotent per invoice: the source guard means it can be retried
 * safely, and a credit note is deliberately left unposted until a flow raises one.
 */
final class PostInvoiceToLedger
{
    public function handle(Invoice $invoice, ?User $actor = null): ?JournalEntry
    {
        if ($invoice->kind === InvoiceKind::CreditNote) {
            return null; // No flow raises credit notes yet; posting one is a deliberate later act.
        }

        if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
            throw new JournalPostingFailed("Invoice {$invoice->number} is not issued, so there is nothing to post.");
        }

        $existing = JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $receivable = Account::query()->where('code', '1100')->where('is_active', true)->first();
        $taxPayable = Account::query()->where('code', '2100')->where('is_active', true)->first();

        if ($receivable === null) {
            throw new JournalPostingFailed('Account 1100 (Accounts receivable) is missing from the chart. Restore it before issuing invoices.');
        }

        $revenueCode = match ($invoice->business_line?->value) {
            'interior' => '4010',
            'furniture' => '4020',
            default => '4090',
        };
        $revenue = Account::query()->where('code', $revenueCode)->where('is_active', true)->first();

        if ($revenue === null) {
            throw new JournalPostingFailed("Account {$revenueCode} (revenue) is missing from the chart. Restore it before issuing invoices.");
        }

        $total = (int) $invoice->total_minor;
        $tax = min((int) $invoice->tax_minor, $total);
        $net = $total - $tax;

        if ($total <= 0) {
            throw new JournalPostingFailed("Invoice {$invoice->number} has no amount to post.");
        }

        $lines = [
            ['account_id' => $receivable->id, 'debit_minor' => $total, 'memo' => "Invoice {$invoice->number}"],
            ['account_id' => $revenue->id, 'credit_minor' => $net, 'memo' => "Invoice {$invoice->number} — revenue"],
        ];

        if ($tax > 0) {
            if ($taxPayable === null) {
                throw new JournalPostingFailed('Account 2100 (Taxes payable) is missing from the chart. Restore it before issuing taxed invoices.');
            }

            $lines[] = ['account_id' => $taxPayable->id, 'credit_minor' => $tax, 'memo' => "Invoice {$invoice->number} — sales tax"];
        }

        $actor ??= $this->systemActor();

        return DB::transaction(function () use ($invoice, $actor, $lines): JournalEntry {
            return app(PostJournalEntry::class)->handle($actor, $lines, [
                'source_type' => 'invoice',
                'source_id' => $invoice->id,
                'business_date' => $invoice->issue_date?->toDateString() ?? today()->toDateString(),
                'reference' => $invoice->number,
                'business_line' => $invoice->business_line?->value,
                'project_id' => $invoice->project_id,
                'note' => 'Issued invoice '.$invoice->number.' — receivable and revenue.',
            ]);
        });
    }

    /**
     * Who the entry is recorded against when no human actor is known: the owner, then an
     * admin, then any active staff member. The books never stop because a role is missing;
     * the owner reads created_by on the entry either way.
     */
    private function systemActor(): User
    {
        foreach (['owner', 'admin'] as $role) {
            $user = User::query()->role($role)->where('is_active', true)->orderBy('id')->first();

            if ($user !== null) {
                return $user;
            }
        }

        return User::query()->where('is_active', true)->orderBy('id')->first()
            ?? throw new JournalPostingFailed('No active staff account exists to post a system entry to the ledger.');
    }
}
