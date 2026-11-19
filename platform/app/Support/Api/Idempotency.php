<?php

declare(strict_types=1);

namespace App\Support\Api;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A create that is sent twice writes once and answers the same (master list T-063).
 *
 * A visitor presses "Send", the answer is lost on the way, the shop sends again. Without a key the second request
 * is a second enquiry. With one, the client makes a UUID for the thing it creates and sends it as the header
 * Idempotency-Key; the first request writes the record and a row for the key in ONE transaction, and every later
 * request with that key is answered from the row and writes nothing.
 *
 *   - The same key with other content is refused (409, IDEMPOTENCY_KEY_REUSED): a key names one request, for good.
 *   - A request that was refused or failed leaves no row: the same key may be sent again, corrected.
 *   - Two requests with one key in the same moment: the key's own row is the FIRST thing the transaction writes
 *     (as with the ledger, the order of locks is the design: AGENTS.md §6). The second request waits at that row
 *     for the unique key of the table, holding nothing else, and when the first is done it is told "duplicate"
 *     and answers from the row.
 *   - Neither the key nor what was sent is kept: only a hash of each. What the answer is made from is kept, and
 *     nothing secret may be put into it.
 */
final class Idempotency
{
    private const string TABLE = 'api_idempotency_keys';

    /**
     * What the first request with this key was answered, when there was one. Asked of the database each time: the
     * answer to the same question changes while another request with the key is at work.
     *
     * @phpstan-impure
     */
    public function seen(string $scope, string $key, string $fingerprint): ?IdempotentOutcome
    {
        $row = DB::table(self::TABLE)->where('scope', $scope)->where('key_hash', $this->hash($scope, $key))->first();

        if ($row === null || $row->status === null) {
            return null;
        }

        if (! hash_equals((string) $row->request_hash, $fingerprint)) {
            throw new Problem(409, 'IDEMPOTENCY_KEY_REUSED', ApiErrors::CODES['IDEMPOTENCY_KEY_REUSED']);
        }

        /** @var array<string, mixed> $kept */
        $kept = json_decode((string) $row->answer, true, flags: JSON_THROW_ON_ERROR);

        return new IdempotentOutcome((int) $row->status, $kept, replayed: true);
    }

    /**
     * Do the work once for this key. $work answers [the HTTP status, what the answer is made from]; whatever it
     * throws takes the key's row away with it.
     *
     * @param  Closure(): array{0: int, 1: array<string, mixed>}  $work
     */
    public function once(string $scope, string $key, string $fingerprint, Closure $work): IdempotentOutcome
    {
        if (($seen = $this->seen($scope, $key, $fingerprint)) !== null) {
            return $seen;
        }

        try {
            return DB::transaction(function () use ($scope, $key, $fingerprint, $work): IdempotentOutcome {
                $row = ['scope' => $scope, 'key_hash' => $this->hash($scope, $key)];

                DB::table(self::TABLE)->insert([...$row, 'request_hash' => $fingerprint, 'created_at' => now()]);

                [$status, $kept] = $work();

                DB::table(self::TABLE)->where($row)->update(['status' => $status, 'answer' => json_encode($kept, JSON_THROW_ON_ERROR)]);

                return new IdempotentOutcome($status, $kept, replayed: false);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Another request with this key got there first, in this same moment. What it wrote is the answer.
            // (A unique key of another table that $work ran into finds no row here and is thrown on.)
            return $this->seen($scope, $key, $fingerprint) ?? throw $exception;
        }
    }

    /**
     * What a request is, as a hash: the fields in one order, without those that are empty, so that the same form
     * sent twice is the same request however the client wrote it down.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fingerprint(array $input): string
    {
        return hash('sha256', json_encode(self::inOneOrder($input), JSON_THROW_ON_ERROR));
    }

    private function hash(string $scope, string $key): string
    {
        return hash('sha256', $scope."\n".Str::lower(trim($key)));
    }

    /** Every value as text; the fields of a record in one order, the empty ones left out; a list as it was sent. */
    private static function inOneOrder(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_bool($value) ? ($value ? '1' : '') : (string) $value;
        }

        if (array_is_list($value)) {
            return array_map(self::inOneOrder(...), $value);
        }

        $value = array_filter(array_map(self::inOneOrder(...), $value), static fn (mixed $inner): bool => $inner !== '' && $inner !== []);
        ksort($value, SORT_STRING);

        return $value;
    }
}
