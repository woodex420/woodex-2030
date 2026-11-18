<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

/**
 * One order for everybody who needs more than one invoice at a time (master list T-093).
 *
 * Money received for a client may reach every open invoice of that client, so an allocation locks all of them.
 * Two requests that lock the same rows in different orders each end up holding what the other waits for, and the
 * database stops one of them with a deadlock. Here the rows are taken by their own number, lowest first, each with
 * a lock on that one row and nothing around it. Asking the database for "the open invoices of this client, with a
 * lock" instead made it lock whatever it read on its way, which on a small table is every invoice there is.
 *
 * Which invoices are open is asked without a lock; what is done with them is decided on the rows as they are when
 * the lock is held. Two writers at once are tried by tools/ci/two-writers.php on MySQL and MariaDB.
 */
final class InvoiceLocks
{
    /**
     * The invoices of a client that money may still be allocated to, as they stand right now. Read without a
     * lock: a list of numbers to lock, never a statement about what they hold.
     *
     * @return list<int>
     */
    public function openOf(int $contactId): array
    {
        return Invoice::query()
            ->where('contact_id', $contactId)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Everything money of this client may reach, locked: the client's open invoices and the ones named besides.
     * This comes BEFORE the first row is written for that money. A payment row points at its invoice, and writing
     * it makes the database hold that invoice for reading; two people recording two payments each held it that
     * way, each then asked for it to write, and the database stopped one of them.
     *
     * @param  array<int, mixed>  $named  numbers of invoices from a form; what is not a number of an invoice is ignored
     * @return Collection<int, Invoice>
     */
    public function forMoneyOf(int $contactId, array $named = []): Collection
    {
        return $this->take([...$this->openOf($contactId), ...array_filter($named, fn (mixed $id): bool => is_int($id) && $id > 0)]);
    }

    /**
     * Lock these invoices, lowest number first, and return them as they are now, by number. One that does not
     * exist is left out (asking for a row that is not there, with a lock, holds the empty place where it would
     * be). Call inside a transaction; the locks last until it ends.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Invoice>
     */
    public function take(array $ids): Collection
    {
        $ids = Invoice::query()->whereIn('id', array_unique(array_map(intval(...), $ids)))->orderBy('id')->pluck('id')->all();

        $held = new Collection;

        foreach ($ids as $id) {
            $invoice = Invoice::query()->whereKey($id)->lockForUpdate()->first();

            if ($invoice !== null) {
                $held->put((int) $id, $invoice);
            }
        }

        return $held;
    }
}
