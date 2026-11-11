<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Projects\Services\ProjectCostAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A received invoice entered by mistake is voided, never deleted (slice I): the document stays, the reason is
 * recorded and the journal it created is reversed through the same action the rest of the books use. A bill
 * with money against it is refused — that is a credit note, not a void.
 */
final class VoidBill
{
    public function handle(Bill $bill, User $actor, string $reason): Bill
    {
        $actor = app(ProjectCostAccess::class)->actor($actor);
        $reason = trim($reason);

        if (mb_strlen($reason) < 4) {
            throw ValidationException::withMessages(['voidReason' => 'Say why the bill is being voided — at least four characters.']);
        }

        return DB::transaction(function () use ($bill, $actor, $reason): Bill {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);

            app(ProjectCostAccess::class)->project($bill->project_id, $actor, true);

            if ($bill->status === BillStatus::Void) {
                throw ValidationException::withMessages(['voidReason' => 'That bill is already void.']);
            }

            if ((int) $bill->paid_minor > 0) {
                throw ValidationException::withMessages([
                    'voidReason' => 'Money has already been paid against this bill. Raise a credit note with the vendor instead of voiding it.',
                ]);
            }

            $entry = JournalEntry::query()
                ->where('source_type', 'bill')
                ->where('source_id', $bill->id)
                ->first();

            if ($entry !== null && ! $entry->isReversed() && ! $entry->isReversal()) {
                app(ReverseJournalEntry::class)->handle($entry, $actor, 'Bill '.$bill->number.' voided: '.$reason);
            }

            $bill->update([
                'status' => BillStatus::Void,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $bill;
        });
    }
}
