<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Projects\Services\ProjectCostAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A received invoice becomes a ledger entry (slice I): Dr the expense/asset account on every line, Dr input
 * tax when the vendor charged tax, Cr Accounts payable for the total. Idempotent per bill — a retried post
 * can never book the same supplier invoice twice.
 */
final class PostBillToLedger
{
    public function handle(Bill $bill, ?User $actor = null): JournalEntry
    {
        $actor = app(ProjectCostAccess::class)->actor($actor ?? $this->systemActor());

        return DB::transaction(function () use ($bill, $actor): JournalEntry {
            // Re-read under a source-row lock before checking idempotence or the bill's state.
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $existing = JournalEntry::query()
                ->where('source_type', 'bill')
                ->where('source_id', $bill->id)
                ->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($bill->status === BillStatus::Draft) {
                throw new JournalPostingFailed("Bill {$bill->number} is still a draft — mark it received before it reaches the books.");
            }

            if ($bill->status === BillStatus::Void) {
                throw new JournalPostingFailed("Bill {$bill->number} is void and cannot be posted.");
            }

            $payable = $this->account('2010', 'Accounts payable');
            $total = (int) $bill->total_minor;

            if ($total <= 0) {
                throw new JournalPostingFailed("Bill {$bill->number} has no amount to post.");
            }

            $items = $bill->items()->with('account')->get();

            if ($items->isEmpty()) {
                throw new JournalPostingFailed("Bill {$bill->number} has no lines to post.");
            }

            $lines = [];

            foreach ($items as $item) {
                if ((int) $item->amount_minor <= 0) {
                    continue;
                }

                $account = $item->account;

                if ($account === null || ! $account->acceptsPostings()) {
                    throw new JournalPostingFailed('A bill line points at an account that cannot receive postings. Fix the line and post again.');
                }

                $lines[] = [
                    'account_id' => $account->id,
                    'debit_minor' => (int) $item->amount_minor,
                    'memo' => "Bill {$bill->number} — {$item->description}",
                ];
            }

            $itemTotal = array_sum(array_map(static fn (array $line): int => (int) $line['debit_minor'], $lines));
            $tax = (int) $bill->tax_minor;

            if ($tax > 0) {
                $lines[] = [
                    'account_id' => $this->account('1210', 'Input tax (recoverable)')->id,
                    'debit_minor' => $tax,
                    'memo' => "Bill {$bill->number} — input tax",
                ];
            }

            if ($itemTotal + $tax !== $total) {
                throw new JournalPostingFailed(sprintf(
                    'Bill %s totals do not add up: lines %s + tax %s ≠ total %s. Re-save the bill.',
                    $bill->number,
                    number_format($itemTotal / 100, 2),
                    number_format($tax / 100, 2),
                    number_format($total / 100, 2),
                ));
            }

            $lines[] = ['account_id' => $payable->id, 'credit_minor' => $total, 'memo' => "Bill {$bill->number} — payable"];

            $project = app(ProjectCostAccess::class)->project($bill->project_id, $actor);
            $vendorName = Vendor::query()->whereKey($bill->vendor_id)->value('name') ?? 'vendor';

            return app(PostJournalEntry::class)->handle($actor, $lines, [
                'project_id' => $project?->id,
                'business_line' => $project?->business_line->value,
                'source_type' => 'bill',
                'source_id' => $bill->id,
                'business_date' => $bill->bill_date->toDateString(),
                'reference' => $bill->number,
                'note' => "Received invoice {$bill->number} from {$vendorName}.",
            ]);
        });
    }

    private function account(string $code, string $label): Account
    {
        $account = Account::query()->where('code', $code)->where('is_active', true)->first();

        if ($account === null) {
            throw new JournalPostingFailed("Account {$code} ({$label}) is missing from the chart. Restore it before posting received invoices.");
        }

        return $account;
    }

    private function systemActor(): User
    {
        foreach (['owner', 'admin'] as $role) {
            $user = User::query()->role($role)->where('is_active', true)->orderBy('id')->first();

            if ($user !== null) {
                return $user;
            }
        }

        return User::query()->where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
