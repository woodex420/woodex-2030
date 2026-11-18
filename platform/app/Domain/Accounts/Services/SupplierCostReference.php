<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Services;

use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Accounts\Models\Vendor;
use Illuminate\Validation\ValidationException;

/**
 * Serialize bill/expense entry for the same vendor; a supplier document has one booking source.
 *
 * Order of the locks (master list T-093): the supplier's row first, then the ledger's turn, then the question.
 */
final class SupplierCostReference
{
    public function assertAvailable(int $vendorId, ?string $reference, ?int $exceptBillId = null, string $field = 'reference'): void
    {
        // Call inside the writer's transaction. All new bill/outgoing-expense paths take this same lock.
        $vendor = Vendor::query()->lockForUpdate()->findOrFail($vendorId);
        if (! $vendor->is_active) {
            throw ValidationException::withMessages([$field => 'Choose an active vendor.']);
        }
        $reference = trim((string) $reference);
        if ($reference === '') {
            return;
        }

        // The two questions below hold the empty place where such a bill or expense would go. Two people booking
        // documents of two different suppliers each held the other's place and the database stopped one of them
        // (T-093): the questions are asked inside the ledger's turn, where only one writer can be.
        app(LedgerTurn::class)->take();

        $bill = Bill::query()->where('vendor_id', $vendorId)->where('number', $reference)
            ->when($exceptBillId !== null, fn ($query) => $query->whereKeyNot($exceptBillId))->lockForUpdate()->exists();
        $expense = Expense::query()->where('direction', 'out')->where('vendor_id', $vendorId)->where('reference', $reference)->lockForUpdate()->exists();
        if ($bill || $expense) {
            throw ValidationException::withMessages([$field => 'This supplier reference is already recorded as a bill or expense. Pay the existing bill instead of booking the cost again.']);
        }
    }
}
