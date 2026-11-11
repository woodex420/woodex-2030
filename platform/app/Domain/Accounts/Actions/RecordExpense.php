<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\ExpenseDirection;
use App\Domain\Accounts\Enums\PaidFrom;
use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Accounts\Services\SupplierCostReference;
use App\Domain\Projects\Services\ProjectCostAccess;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One expense or one piece of other income, written once and posted in the same transaction (slice I).
 *
 * Money out: Dr the account the cost belongs to, Cr cash / bank / wallet — or Cr Accounts payable when the
 * bill is on credit. Money in: Dr cash / bank, Cr the income account. The caller supplies integer paisa; the
 * screen converts rupees with {@see Money} and never rounds here.
 */
final class RecordExpense
{
    /**
     * @param  array{direction: ExpenseDirection|string, account_id: int, amount_minor: int, spent_on: string, paid_from: PaidFrom|string, vendor_id?: int|null, reference?: string|null, notes?: string|null, recurring_expense_id?: int|null, project_id?: int|null, description: string}  $attributes
     */
    public function handle(array $attributes, User $actor): Expense
    {
        $actor = app(ProjectCostAccess::class)->actor($actor);
        $project = app(ProjectCostAccess::class)->project($attributes['project_id'] ?? null, $actor);
        $direction = $attributes['direction'] instanceof ExpenseDirection
            ? $attributes['direction']
            : ExpenseDirection::from((string) $attributes['direction']);
        $paidFrom = $attributes['paid_from'] instanceof PaidFrom
            ? $attributes['paid_from']
            : PaidFrom::from((string) $attributes['paid_from']);

        if ($project !== null && ($direction !== ExpenseDirection::Out || $paidFrom === PaidFrom::Payable)) {
            throw ValidationException::withMessages(['project_id' => 'Linked project expenses must be paid costs. Use a supplier bill for an unpaid cost.']);
        }

        $amount = (int) $attributes['amount_minor'];

        if ($amount <= 0) {
            throw new JournalPostingFailed('An amount must be positive before it can be posted.');
        }

        $counterAccount = $this->account($attributes['account_id']);
        $moneyAccount = $this->accountByCode($paidFrom->accountCode());

        $screenAccount = $direction->accountType();

        if ($counterAccount->type !== $screenAccount) {
            throw new JournalPostingFailed(sprintf(
                'Account %s %s is %s, but this entry is %s. Pick an %s account.',
                $counterAccount->code,
                $counterAccount->name,
                $counterAccount->type->label(),
                mb_strtolower($direction->label()),
                mb_strtolower($screenAccount->label()),
            ));
        }

        $lines = $direction === ExpenseDirection::Out
            ? [
                ['account_id' => $counterAccount->id, 'debit_minor' => $amount, 'memo' => $attributes['description']],
                ['account_id' => $moneyAccount->id, 'credit_minor' => $amount, 'memo' => $attributes['description']],
            ]
            : [
                ['account_id' => $moneyAccount->id, 'debit_minor' => $amount, 'memo' => $attributes['description']],
                ['account_id' => $counterAccount->id, 'credit_minor' => $amount, 'memo' => $attributes['description']],
            ];

        return DB::transaction(function () use ($attributes, $actor, $direction, $paidFrom, $amount, $lines, $moneyAccount, $project): Expense {
            if ($direction === ExpenseDirection::Out && ! empty($attributes['vendor_id'])) {
                app(SupplierCostReference::class)->assertAvailable((int) $attributes['vendor_id'], $attributes['reference'] ?? null);
            }
            $expense = Expense::query()->create([
                'project_id' => $project?->id,
                'direction' => $direction,
                'vendor_id' => $attributes['vendor_id'] ?? null,
                'account_id' => $attributes['account_id'],
                'description' => $attributes['description'],
                'amount_minor' => $amount,
                'spent_on' => $attributes['spent_on'],
                'paid_from' => $paidFrom,
                'payment_account_id' => $moneyAccount->id,
                'reference' => $this->blankToNull($attributes['reference'] ?? null),
                'notes' => $this->blankToNull($attributes['notes'] ?? null),
                'recurring_expense_id' => $attributes['recurring_expense_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            $entry = app(PostJournalEntry::class)->handle($actor, $lines, [
                'project_id' => $project?->id,
                'business_line' => $project?->business_line->value,
                'source_type' => 'expense',
                'source_id' => $expense->id,
                'business_date' => (string) $attributes['spent_on'],
                'reference' => $expense->public_id,
                'note' => $direction->label().': '.$attributes['description'],
            ]);

            $expense->update(['journal_entry_id' => $entry->id]);

            return $expense->refresh();
        });
    }

    private function account(int $id): Account
    {
        $account = Account::query()->whereKey($id)->where('is_active', true)->first();

        if ($account === null || ! $account->acceptsPostings()) {
            throw new JournalPostingFailed('The chosen account is missing, inactive or a group — pick a postable account.');
        }

        return $account;
    }

    private function accountByCode(string $code): Account
    {
        $account = Account::query()->where('code', $code)->where('is_active', true)->first();

        if ($account === null) {
            throw new JournalPostingFailed("Account {$code} is missing from the chart. Restore it before recording this entry.");
        }

        return $account;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' || $value === null ? null : $value;
    }
}
