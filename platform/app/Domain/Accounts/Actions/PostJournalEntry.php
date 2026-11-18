<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\AccountingPeriod;
use App\Domain\Accounts\Models\JournalEntry;
use App\Models\User;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;

/**
 * Post a balanced journal entry (docs/18 §2, batch C). Atomic and idempotent per business source:
 * a retried verification or invoice action can never create a second entry. PKR integer paisa only —
 * foreign currency is rejected by design until an FX policy exists.
 */
final class PostJournalEntry
{
    /**
     * @param  list<array{account_id: int, debit_minor?: int, credit_minor?: int, memo?: string|null}>  $lines
     * @param  array{source_type?: string|null, source_id?: int|null, business_date: string, reference?: string|null, business_line?: string|null, project_id?: int|null, note?: string|null, reverses_entry_id?: int|null}  $context
     */
    public function handle(User $actor, array $lines, array $context): JournalEntry
    {
        $this->assertLinesAreWellFormed($lines);
        $this->assertBalanced($lines);

        $businessDate = (string) $context['business_date'];

        return DB::transaction(function () use ($actor, $lines, $context, $businessDate): JournalEntry {
            $this->assertSourceIsNew((string) ($context['source_type'] ?? ''), $context['source_id'] ?? null);
            $this->assertOpenPeriod($businessDate);
            $accounts = $this->loadAndCheckAccounts($lines);

            $entry = JournalEntry::query()->create([
                'entry_no' => app(Numbering::class)->next('journal'),
                'source_type' => $context['source_type'] ?? null,
                'source_id' => $context['source_id'] ?? null,
                'business_date' => $businessDate,
                'reference' => $context['reference'] ?? null,
                'created_by' => $actor->id,
                'business_line' => $context['business_line'] ?? null,
                'project_id' => $context['project_id'] ?? null,
                'note' => $context['note'] ?? null,
                'reverses_entry_id' => $context['reverses_entry_id'] ?? null,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit_minor' => (int) ($line['debit_minor'] ?? 0),
                    'credit_minor' => (int) ($line['credit_minor'] ?? 0),
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            return $entry->load(['lines' => fn ($query) => $query->orderBy('id'), 'lines.account']);
        });
    }

    /**
     * @param  list<array{account_id: int, debit_minor?: int, credit_minor?: int}>  $lines
     */
    private function assertLinesAreWellFormed(array $lines): void
    {
        if (count($lines) < 2) {
            throw new JournalPostingFailed('A journal entry needs at least two lines — one debit and one credit.');
        }

        foreach ($lines as $index => $line) {
            $debit = (int) ($line['debit_minor'] ?? 0);
            $credit = (int) ($line['credit_minor'] ?? 0);

            if (($debit > 0) === ($credit > 0) || $debit < 0 || $credit < 0) {
                throw JournalPostingFailed::oneSidedLine($index + 1);
            }
        }
    }

    /**
     * @param  list<array{account_id: int, debit_minor?: int, credit_minor?: int}>  $lines
     */
    private function assertBalanced(array $lines): void
    {
        $debit = array_sum(array_map(fn (array $line): int => (int) ($line['debit_minor'] ?? 0), $lines));
        $credit = array_sum(array_map(fn (array $line): int => (int) ($line['credit_minor'] ?? 0), $lines));

        if ($debit === 0 || $debit !== $credit) {
            throw JournalPostingFailed::unbalanced($debit, $credit);
        }
    }

    private function assertSourceIsNew(string $sourceType, ?int $sourceId): void
    {
        if ($sourceType === '' || $sourceId === null) {
            return; // manual entries carry no source; duplicates are the editor's responsibility.
        }

        $exists = JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw JournalPostingFailed::duplicateSource($sourceType.' #'.$sourceId);
        }
    }

    private function assertOpenPeriod(string $businessDate): void
    {
        $period = AccountingPeriod::query()->covering($businessDate)->lockForUpdate()->first();

        if ($period === null) {
            throw JournalPostingFailed::noOpenPeriod($businessDate);
        }

        if (! $period->isOpen()) {
            throw JournalPostingFailed::periodClosed($period->name);
        }
    }

    /**
     * @param  list<array{account_id: int, debit_minor?: int, credit_minor?: int}>  $lines
     * @return array<int, Account>
     */
    private function loadAndCheckAccounts(array $lines): array
    {
        $accounts = Account::query()->whereIn('id', array_column($lines, 'account_id'))->get()->keyBy('id');

        foreach ($lines as $line) {
            $account = $accounts[$line['account_id']] ?? null;

            if ($account === null || ! $account->acceptsPostings()) {
                $label = $account?->label() ?? ('Account #'.$line['account_id']);

                throw JournalPostingFailed::notPostable($label);
            }
        }

        return $accounts->all();
    }
}
