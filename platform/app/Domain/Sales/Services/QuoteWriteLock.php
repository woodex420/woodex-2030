<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Models\Quote;
use Illuminate\Support\Facades\DB;
use LogicException;

/** All quote mutations acquire the parent first; callers hold the transaction until completion. */
final class QuoteWriteLock
{
    public static function refresh(Quote $quote): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Quotation locking requires an active transaction.');
        }
        $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
        $quote->setRawAttributes($locked->getAttributes(), true);
        $quote->unsetRelations();
    }
}
