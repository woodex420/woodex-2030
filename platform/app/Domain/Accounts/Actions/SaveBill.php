<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Services\SupplierCostReference;
use App\Domain\Projects\Services\ProjectCostAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** One draft/received-bill writer for the account screen and project workspace. Posted bills stay immutable. */
final class SaveBill
{
    /**
     * @param  array{vendor_id: int, number: string, bill_date: string, due_date?: ?string, project_id?: ?int, tax_minor?: int, notes?: ?string}  $attributes
     * @param  list<array{account_id: int, description: string, amount_minor: int, position?: int}>  $items
     */
    public function handle(array $attributes, array $items, User $actor, BillStatus $status = BillStatus::Draft, ?Bill $existing = null): Bill
    {
        $actor = app(ProjectCostAccess::class)->actor($actor);
        if (! in_array($status, [BillStatus::Draft, BillStatus::Received], true)) {
            throw ValidationException::withMessages(['status' => 'Save a draft or received bill.']);
        }
        Validator::make($attributes, [
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'number' => ['required', 'string', 'max:60'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'tax_minor' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();
        Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1'],
            'items.*.account_id' => ['required', 'integer', Rule::exists('chart_of_accounts', 'id')->whereIn('type', ['expense', 'asset'])],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount_minor' => ['required', 'integer', 'gt:0'],
        ])->validate();

        return DB::transaction(function () use ($attributes, $items, $actor, $status, $existing): Bill {
            $bill = $existing !== null ? Bill::query()->lockForUpdate()->findOrFail($existing->id) : new Bill;
            if ($bill->exists && ($bill->status !== BillStatus::Draft || $bill->journal_entry_id !== null)) {
                throw ValidationException::withMessages(['bill' => 'A posted bill cannot be edited or reset to draft. Void it with a reason instead.']);
            }
            $projectId = array_key_exists('project_id', $attributes) ? $attributes['project_id'] : $bill->project_id;
            app(ProjectCostAccess::class)->project($projectId, $actor);
            app(SupplierCostReference::class)->assertAvailable($attributes['vendor_id'], $attributes['number'], $bill->exists ? $bill->id : null, 'number');
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += $item['amount_minor'];
                if (! is_int($subtotal)) {
                    throw ValidationException::withMessages(['items' => 'Bill total exceeds the supported integer money range.']);
                }
            }
            $tax = $attributes['tax_minor'] ?? 0;
            if (! is_int($subtotal + $tax)) {
                throw ValidationException::withMessages(['tax_minor' => 'Bill total exceeds the supported integer money range.']);
            }
            $bill->fill([
                'vendor_id' => $attributes['vendor_id'], 'number' => trim($attributes['number']),
                'project_id' => $projectId,
                'bill_date' => $attributes['bill_date'], 'due_date' => $attributes['due_date'] ?? null,
                'status' => $status, 'subtotal_minor' => $subtotal, 'tax_minor' => $tax,
                'total_minor' => $subtotal + $tax, 'notes' => $attributes['notes'] ?? null,
                'created_by' => $bill->created_by ?? $actor->id,
            ])->save();
            $bill->items()->delete();
            foreach ($items as $position => $item) {
                $bill->items()->create([...$item, 'position' => $position]);
            }
            if ($status === BillStatus::Received) {
                $entry = app(PostBillToLedger::class)->handle($bill->refresh(), $actor);
                $bill->update(['journal_entry_id' => $entry->id]);
            }

            return $bill->refresh();
        });
    }
}
