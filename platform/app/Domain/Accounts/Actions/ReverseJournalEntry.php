<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reverse a posted journal (docs/18 §2.5): the correction is itself a posted, immutable entry —
 * the original is never edited or deleted. A reversal swaps every line's side and links both ways,
 * and an entry can only be reversed once.
 */
final class ReverseJournalEntry
{
    public function __construct(private readonly PostJournalEntry $post) {}

    public function handle(JournalEntry $entry, User $actor, ?string $reason = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $actor, $reason): JournalEntry {
            $fresh = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($fresh->isReversed()) {
                throw new JournalPostingFailed("{$fresh->entry_no} is already reversed by ".$fresh->reversedBy->entry_no.'. A reversal is itself never undone — post a correcting entry instead.');
            }

            if ($fresh->isReversal()) {
                throw new JournalPostingFailed("{$fresh->entry_no} is already a reversal of {$fresh->reverses->entry_no}. Reversing a reversal hides history — post a new correcting entry instead.");
            }

            $reversal = $this->post->handle($actor, array_map(fn (array $line): array => [
                'account_id' => (int) $line['account_id'],
                'debit_minor' => (int) $line['credit_minor'],
                'credit_minor' => (int) $line['debit_minor'],
                'memo' => 'Reversal'.($line['memo'] !== null ? ' · '.$line['memo'] : ''),
            ], $fresh->lines()->orderBy('id')->get()->toArray()), [
                'business_date' => today()->toDateString(),
                'reference' => 'Reversal of '.$fresh->entry_no,
                'business_line' => $fresh->business_line?->value,
                'project_id' => $fresh->project_id,
                'reverses_entry_id' => $fresh->id,
                'note' => trim((string) $reason) !== '' ? trim((string) $reason) : 'Reversal requested from the accounts workspace.',
            ]);

            $fresh->forceFill(['reversed_by_entry_id' => $reversal->id])->save();

            return $reversal->load(['lines' => fn ($query) => $query->orderBy('id'), 'lines.account']);
        });
    }
}
