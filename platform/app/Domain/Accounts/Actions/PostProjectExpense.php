<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Projects\Models\ProjectExpense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A cost recorded on a job reaches the books (slice I). Until this existed, `project_expenses` was a private
 * list: the project screen showed what the work cost and the ledger never heard about it, so job profit and
 * the P&L were both wrong. Dr the cost account, Cr Accounts payable — the supplier invoice follows.
 */
final class PostProjectExpense
{
    /** Category → chart code. The cost tree is the studio's own, so the mapping is explicit. */
    private const array CATEGORY_ACCOUNTS = [
        'material' => '5010',
        'labour' => '5020',
        'transport' => '5130',
        'other' => '5190',
    ];

    public function handle(ProjectExpense $expense, ?User $actor = null): JournalEntry
    {
        $existing = JournalEntry::query()
            ->where('source_type', 'project_expense')
            ->where('source_id', $expense->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $amount = (int) $expense->amount_minor;

        if ($amount <= 0) {
            throw new JournalPostingFailed('A project cost needs a positive amount before it can be posted.');
        }

        $code = self::CATEGORY_ACCOUNTS[$expense->category->value] ?? '5190';
        $account = Account::query()->where('code', $code)->where('is_active', true)->first();
        $payable = Account::query()->where('code', '2010')->where('is_active', true)->first();

        if ($account === null || $payable === null) {
            throw new JournalPostingFailed('The cost tree is incomplete — 2010 (Accounts payable) and the 5xxx cost accounts must exist before a project cost can be posted.');
        }

        $actor ??= $this->systemActor();
        $description = trim((string) $expense->description);
        $label = $description !== '' ? $description : $expense->category->label();

        return DB::transaction(function () use ($expense, $actor, $account, $payable, $amount, $label): JournalEntry {
            $entry = app(PostJournalEntry::class)->handle($actor, [
                ['account_id' => $account->id, 'debit_minor' => $amount, 'memo' => 'Project cost — '.$label],
                ['account_id' => $payable->id, 'credit_minor' => $amount, 'memo' => 'Project cost — '.$label],
            ], [
                'source_type' => 'project_expense',
                'source_id' => $expense->id,
                'business_date' => $expense->incurred_on->toDateString(),
                'reference' => 'Project #'.$expense->project_id,
                'project_id' => $expense->project_id,
                'note' => 'Project cost: '.$label.'.',
            ]);

            $expense->forceFill(['account_id' => $account->id, 'journal_entry_id' => $entry->id])->save();

            return $entry;
        });
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
