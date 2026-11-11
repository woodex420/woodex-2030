<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Services\SupplierCostReference;
use App\Domain\Projects\Services\ProjectCostAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReceiveBill
{
    public function handle(Bill $bill, User $actor): Bill
    {
        $actor = app(ProjectCostAccess::class)->actor($actor);

        return DB::transaction(function () use ($bill, $actor): Bill {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            if ($bill->status !== BillStatus::Draft) {
                throw ValidationException::withMessages(['bill' => 'That bill is already posted or void.']);
            }
            if ($bill->journal_entry_id !== null) {
                throw ValidationException::withMessages(['bill' => 'A posted bill cannot be reset or edited.']);
            }
            app(ProjectCostAccess::class)->project($bill->project_id, $actor);
            app(SupplierCostReference::class)->assertAvailable($bill->vendor_id, $bill->number, $bill->id, 'number');
            $bill->update(['status' => BillStatus::Received]);
            $entry = app(PostBillToLedger::class)->handle($bill, $actor);
            $bill->update(['journal_entry_id' => $entry->id]);

            return $bill->refresh();
        });
    }
}
