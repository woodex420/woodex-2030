<?php

declare(strict_types=1);

namespace App\Domain\System\Import\OldSystem;

use App\Domain\System\Models\LegacyRef;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The one place that writes `legacy_refs` for the old system (the React shop and dashboard with their Node API).
 *
 * `legacy_refs` keeps the old number of a record beside its new one, so that a second run of an importer finds
 * what the first brought over. An entry holds the name and the key below, the admin's table (`model_type`) and
 * the number of the record there (`model_id`), the checksum and the time. Every importer of this old system (master list T-178) asks this class whether a
 * row was brought over and whether it changed since, and this class writes the answer. Nothing else does:
 * tests/Feature/OldSystem/RefsTest.php reads every file that names the table and fails on one that is not listed.
 *
 * The rule (written out for the owner in docs/woodex-os/36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md §9):
 *
 *   The name      `node.` + the old table + `>` + the admin's table, as `node.leads>contacts`. Derived from the
 *                 table's name and from the map of the tables, which says into which tables its rows go. `node.`
 *                 is this old system; the importers that came with the admin use other names, so the old lead 17
 *                 of this system and the lead 17 of another never meet.
 *   The key       the row's primary key, read from the row itself. A number is its digits. A text is kept letter
 *                 for letter in `a` to `z`, `0` to `9`, `-`, `_` and `.`; every other byte is written as `%` and
 *                 two hexadecimal digits. The parts of a key of two columns are joined by `:`. So two different
 *                 keys never give the same text, also for a database that holds `A` and `a` to be one letter,
 *                 and a `:` inside a value is `%3a`. More than 64 characters is refused: the column holds 64.
 *   Two records   one old row that becomes two records of the admin (an enquiry and its contact) is entered once
 *                 for each, under the same key, with the admin's table in the name. Where a row makes several
 *                 records in one table (the lines of a quotation), those are not entered: the entry of their
 *                 parent answers for them, and they are written and replaced with it.
 *   The checksum  SHA-256 of the row as the importer read it: the columns in the order of their names, each
 *                 value as a text, nothing as nothing. An importer reads a table the same way on every run.
 */
final class Refs
{
    /** The beginning of every name this class writes. No other writer of `legacy_refs` uses it. */
    public const string SYSTEM = 'node.';

    /** What `legacy_refs.legacy_table` and `legacy_refs.legacy_id` hold (2026_10_15_000100_create_system_tables). */
    public const int NAME_LENGTH = 60;

    public const int KEY_LENGTH = 64;

    /**
     * The name under which the rows of an old table are entered for one table of the admin.
     */
    public static function name(string $table, string $into): string
    {
        $shape = Shape::table($table);

        if (($why = Shape::whyNot($table)) !== null) {
            throw Refused::of('name', $table, $why);
        }

        if (! in_array($into, $shape['to'], true)) {
            throw Refused::of('name', "{$table}>{$into}", "the map of the tables sends the rows of `{$table}` into ".implode(', ', $shape['to']).', not into this table');
        }

        if (($why = Rules::whyNoEntry($table, $into)) !== null) {
            throw Refused::of('name', "{$table}>{$into}", 'these records get no entry of their own: '.$why);
        }

        $name = self::SYSTEM.$table.'>'.$into;

        if (strlen($name) > self::NAME_LENGTH) {
            throw Refused::of('name', $name, strlen($name).' characters, and the column holds '.self::NAME_LENGTH);
        }

        return $name;
    }

    /**
     * Every name this class can write today: one for each table of the old system that moves and each table of
     * the admin its rows go into, without the tables that only take the lines of a row.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (Shape::tables() as $table => $shape) {
            if ($shape['fate'] === 'moves') {
                foreach ($shape['to'] as $into) {
                    if (Rules::whyNoEntry($table, $into) === null) {
                        $names[] = self::name($table, $into);
                    }
                }
            }
        }

        sort($names);

        return $names;
    }

    /**
     * The key of a row as it is entered, read from the row by the columns of the table's primary key.
     *
     * @param  array<string, mixed>  $row
     */
    public static function key(string $table, array $row): string
    {
        $values = [];

        foreach (Shape::table($table)['key'] as [$column]) {
            if (! array_key_exists($column, $row)) {
                throw Refused::of('key', "{$table}.{$column}", 'the row was read without this column of its key');
            }

            $values[] = $row[$column];
        }

        return self::keyOf($table, $values);
    }

    /**
     * The key as it is entered, from the values of the key's columns in their order. A key of one column may be
     * given as the value itself.
     *
     * @param  int|string|list<mixed>  $values
     */
    public static function keyOf(string $table, int|string|array $values): string
    {
        $columns = Shape::table($table)['key'];
        $values = is_array($values) ? array_values($values) : [$values];

        if (count($values) !== count($columns)) {
            throw Refused::of('key', $table, 'its key has '.count($columns).' column(s), and '.count($values).' value(s) were given');
        }

        $parts = [];

        foreach ($columns as $index => [$column, $kind]) {
            $parts[] = self::written($values[$index], $kind, "{$table}.{$column}");
        }

        $key = implode(':', $parts);

        if (strlen($key) > self::KEY_LENGTH) {
            throw Refused::of('key', $key, "the key of `{$table}` is ".strlen($key).' characters as it is written, and `legacy_refs.legacy_id` holds '.self::KEY_LENGTH);
        }

        return $key;
    }

    /**
     * One value of a key as it is written into the key's text.
     */
    public static function written(mixed $value, string $kind, string $where = 'key'): string
    {
        if ($kind === 'number') {
            if (is_int($value) || (is_string($value) && preg_match('/^(?:0|-?[1-9]\d*)$/D', $value) === 1)) {
                return (string) $value;
            }

            throw Refused::of('key', $value, "`{$where}` is a number, and this is not a whole number");
        }

        if (! is_string($value) || $value === '') {
            throw Refused::of('key', $value, "`{$where}` is a text, and this is ".($value === '' ? 'an empty one' : 'not a text'));
        }

        return (string) preg_replace_callback('/[^a-z0-9._-]/', static fn (array $byte): string => '%'.bin2hex($byte[0]), $value);
    }

    /**
     * What the checksum is made of: the row as the importer read it. The columns in the order of their names;
     * a whole number as its digits; a floating number in the shortest writing that reads back as the same number;
     * yes and no as `true` and `false`; nothing as nothing, which no text is equal to.
     *
     * @param  array<string, mixed>  $row
     */
    public static function checksum(array $row): string
    {
        ksort($row, SORT_STRING);
        $written = [];

        foreach ($row as $column => $value) {
            $written[(string) $column] = match (true) {
                $value === null => null,
                is_string($value) => $value,
                is_int($value) => (string) $value,
                is_bool($value) => $value ? 'true' : 'false',
                is_float($value) && is_finite($value) => (string) json_encode($value, JSON_THROW_ON_ERROR),
                default => throw Refused::of('row', $value, "the column `{$column}` is neither a text, a number, yes or no, nor nothing; hand a list over as the text the old database holds"),
            };
        }

        return hash('sha256', (string) json_encode((object) $written, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Was this row brought over, and did it change since? Reads only: an importer's dry run asks this.
     *
     * @param  array<string, mixed>  $row
     */
    public function ask(string $table, array $row, string $into): Answer
    {
        $name = self::name($table, $into);
        $key = self::key($table, $row);
        $entry = $this->entry($name, $key);

        if ($entry === null) {
            return new Answer(State::New, null, $name, $key);
        }

        $same = hash_equals((string) $entry->checksum, self::checksum($row));

        return new Answer($same ? State::Same : State::Changed, (int) $entry->model_id, $name, $key);
    }

    /**
     * Bring one row over, once.
     *
     *   - A row that was never brought over: $create makes the record and returns it (or its number), and the
     *     entry is written. Both in one transaction: a record is never left without its entry, which would make
     *     a second record on the next run.
     *   - A row that was brought over and is as it was: nothing is called and nothing is written.
     *   - A row that was brought over and has changed: $update is called with the record's number, and the entry
     *     takes the new checksum. Without $update nothing is written, so the row is seen as changed on every run
     *     until somebody has dealt with it.
     *
     * The answer says what was found, before anything was done.
     *
     * @param  array<string, mixed>  $row
     * @param  Closure(): (Model|int)  $create
     * @param  (Closure(int): mixed)|null  $update
     */
    public function bring(string $table, array $row, string $into, Closure $create, ?Closure $update = null): Answer
    {
        $found = $this->ask($table, $row, $into);

        if ($found->state === State::Same || ($found->state === State::Changed && $update === null)) {
            return $found;
        }

        $checksum = self::checksum($row);

        try {
            return DB::transaction(function () use ($found, $into, $create, $update, $checksum): Answer {
                if ($found->state === State::Changed && $update !== null) {
                    $update((int) $found->record);

                    LegacyRef::query()
                        ->where('legacy_table', $found->name)
                        ->where('legacy_id', $found->key)
                        ->update(['checksum' => $checksum, 'imported_at' => now()]);

                    return $found;
                }

                $made = $create();

                if ($made instanceof Model && $made->getTable() !== $into) {
                    throw Refused::of('name', $found->name, "the record that was made is in `{$made->getTable()}`, not in `{$into}`");
                }

                $record = $made instanceof Model ? $made->getKey() : $made;

                if (! is_int($record) || $record < 1) {
                    throw Refused::of('name', $found->name, 'the record that was made has no number');
                }

                LegacyRef::query()->create([
                    'legacy_table' => $found->name,
                    'legacy_id' => $found->key,
                    'model_type' => $into,
                    'model_id' => $record,
                    'checksum' => $checksum,
                    'imported_at' => now(),
                ]);

                return new Answer(State::New, $record, $found->name, $found->key);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'legacy_refs')) {
                throw $exception;
            }

            throw Refused::of('key', $found->key, "`{$found->name}` already holds this key: another run brought the row over at the same moment, and what this run made was taken back");
        }
    }

    /**
     * The number of the admin's record an old row became, or nothing when it was not brought over. An importer
     * asks this to follow a reference: which enquiry of the admin is the old enquiry 17?
     *
     * @param  int|string|list<mixed>  $key
     */
    public function recordOf(string $table, int|string|array $key, string $into): ?int
    {
        $entry = $this->entry(self::name($table, $into), self::keyOf($table, $key));

        return $entry === null ? null : (int) $entry->model_id;
    }

    private function entry(string $name, string $key): ?LegacyRef
    {
        return LegacyRef::query()->where('legacy_table', $name)->where('legacy_id', $key)->first();
    }
}
