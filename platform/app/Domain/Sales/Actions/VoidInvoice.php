<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Accounts\Actions\ReverseJournalEntry;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Voiding an issued invoice (INV-003). An invoice is frozen once issued, so a mistake is not edited away: it is
 * voided with a reason, and if it reached the ledger its journal is reversed — the books keep both entries.
 *
 * Refused when money has been recorded against it: that is a refund or a credit note, not a void. Nothing here
 * deletes anything; the invoice, its snapshot and its PDF stay readable with VOID on them.
 */
final class VoidInvoice
{
    public function __construct(private readonly ReverseJournalEntry $reverse) {}

    public function handle(Invoice $invoice, User $actor, string $reason): Invoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['voidReason' => 'Say why this invoice is being voided — the reason is kept with it.']);
        }

        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            $fresh = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            Gate::forUser($actor)->authorize('void', $fresh);

            if ($fresh->status === InvoiceStatus::Void) {
                throw ValidationException::withMessages(['voidReason' => "Invoice {$fresh->number} is already void."]);
            }

            if ((int) $fresh->paid_minor > 0) {
                throw ValidationException::withMessages([
                    'voidReason' => "Invoice {$fresh->number} has payments against it. Refund or credit it — voiding would lose the record of that money.",
                ]);
            }

            $fresh->update([
                'status' => InvoiceStatus::Void,
                'voided_at' => now(),
                'void_reason' => $reason,
                'balance_minor' => 0,
            ]);

            $journal = JournalEntry::query()
                ->where('source_type', 'invoice')
                ->where('source_id', $fresh->id)
                ->first();

            if ($journal !== null && ! $journal->isReversed()) {
                $this->reverse->handle($journal, $actor, "Invoice {$fresh->number} was voided: {$reason}");
            }

            activity('billing')
                ->causedBy($actor)
                ->performedOn($fresh)
                ->withProperties([
                    'reason' => $reason,
                    'total_minor' => (int) $fresh->total_minor,
                    'journal_reversed' => $journal !== null,
                ])
                ->log("Voided invoice {$fresh->number}: {$reason}");

            return $fresh->refresh();
        });
    }
}
