<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Reports;

use App\Domain\Sales\Enums\PaymentStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only completeness check, not a backfill or proof that every posted amount is correct. */
final class PaymentJournalReconciliation
{
    /** @return array{currency: string, verified_count: int, missing_journal_count: int, missing_amount_minor: int, manual_review_required: bool} */
    public function summary(): array
    {
        return DB::transaction(function (): array {
            $verified = DB::table('payments')->where('status', PaymentStatus::Verified->value);
            $missing = (clone $verified)->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('journal_entries')
                    ->where('source_type', 'payment')->whereColumn('source_id', 'payments.id');
            })->selectRaw('count(*) as missing_count, coalesce(sum(amount_minor), 0) as missing_amount')->first();
            $count = (int) ($missing->missing_count ?? 0);

            return [
                'currency' => 'PKR',
                'verified_count' => $verified->count(),
                'missing_journal_count' => $count,
                'missing_amount_minor' => (int) ($missing->missing_amount ?? 0),
                'manual_review_required' => $count > 0,
            ];
        });
    }
}
