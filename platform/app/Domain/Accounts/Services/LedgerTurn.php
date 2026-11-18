<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Services;

use App\Domain\System\Models\NumberSequence;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * The ledger's turn: one writer at a time between "is this already in the books?" and "written" (master list T-093).
 *
 * Every journal entry takes the next number of the series "journal", and the row that holds that number stays
 * locked until the entry's transaction ends. So the ledger was already written by one person at a time; only the
 * waiting came too late. Whoever asks the books a question that must still be true when the answer is acted on
 * takes this row FIRST and asks afterwards: is this document posted already, is this supplier's reference booked
 * already. The second writer then waits here, holding nothing, and is shown what the first one wrote.
 *
 * Why it matters. MySQL and MariaDB answer "is there a row like this?", asked with a lock, by holding the empty
 * place where such a row would go. Two writers may hold the same empty place; each then waits for the other to let
 * go before writing into it, and the database ends one of the two with a deadlock: an error page for a person who
 * did nothing wrong. Inside the turn only one writer can be holding such a place.
 *
 * The order is always: the document's own row (invoice, payment, bill, supplier) first, the turn last. Nothing
 * that holds the turn may ask for a document's row it does not hold yet.
 *
 * SQLite lets one writer in at a time and shows none of this: tools/ci/two-writers.php tries it on MySQL and
 * MariaDB with two processes, and tests/Feature/Accounts/TwoWritersOrderTest.php keeps the order from being undone.
 */
final class LedgerTurn
{
    public const string SERIES = 'journal';

    /** Wait for the turn and keep it until the transaction that is open now ends. Taking it twice is harmless. */
    public function take(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The ledger\'s turn lasts as long as a transaction: take it inside one.');
        }

        // The same question, in the same words, as Numbering asks when the entry takes its number: one lock on one
        // row, asked for once and found held the second time.
        if (NumberSequence::query()->where('key', self::SERIES)->lockForUpdate()->first() === null) {
            throw new RuntimeException('Number sequence ['.self::SERIES.'] is not configured. Run the NumberSequenceSeeder.');
        }
    }
}
