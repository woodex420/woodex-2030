<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\System\Models\NumberSequence;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * The enquiry's turn: one enquiry at a time between "do we know this person?" and "written" (master list T-063).
 *
 * Every new enquiry takes the next number of the series "lead", and the row that holds that number stays locked
 * until the enquiry's transaction ends. So enquiries were already written one at a time; only the waiting came
 * too late: after the question "is there a contact with this phone or this address?". App\Domain\Crm\Actions\
 * CaptureLead takes this row FIRST and asks afterwards.
 *
 * Why it matters. MySQL and MariaDB answer "is there a row like this?", asked with a lock, by holding the empty
 * place where such a row would go. Two enquiries of two people who are both new each held the empty place of the
 * other's address, each then waited for the other to let go before writing its contact, and the database ended one
 * of the two with a deadlock: one visitor of the shop was told the enquiry had failed. Inside the turn only one
 * enquiry can be holding such a place. It is the same cure as App\Domain\Accounts\Services\LedgerTurn, which says
 * more about it.
 *
 * An enquiry takes about a tenth of a second, and a shop of this size receives a few in an hour: nobody waits.
 *
 * The order is always: the key of the request first (App\Support\Api\Idempotency, where the request has one), the
 * turn next, a contact's own row after it. Nothing that holds a contact's row may ask for the turn.
 *
 * SQLite lets one writer in at a time and shows none of this: tools/ci/two-writers.php tries it on MySQL and
 * MariaDB with two processes (the steps "two-enquiries" and "two-baskets"), and tests/Feature/Crm/
 * EnquiryTurnTest.php keeps the order from being undone.
 */
final class EnquiryTurn
{
    public const string SERIES = 'lead';

    /** Wait for the turn and keep it until the transaction that is open now ends. Taking it twice is harmless. */
    public function take(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The enquiry\'s turn lasts as long as a transaction: take it inside one.');
        }

        // The same question, in the same words, as Numbering asks when the enquiry takes its number: one lock on
        // one row, asked for once and found held the second time.
        if (NumberSequence::query()->where('key', self::SERIES)->lockForUpdate()->first() === null) {
            throw new RuntimeException('Number sequence ['.self::SERIES.'] is not configured. Run the NumberSequenceSeeder.');
        }
    }
}
