<?php

declare(strict_types=1);

namespace App\Domain\System\Import\OldSystem;

use BackedEnum;
use Carbon\CarbonImmutable;
use JsonException;
use Normalizer;

/**
 * A value as the old system holds it, made into a value as the admin holds it. Or refused, by name.
 *
 * The old system runs on PostgreSQL and was written for JavaScript; the admin runs on MySQL or MariaDB and is
 * written in PHP. The two disagree about a value in more ways than a test on SQLite can show, so every importer
 * (master list T-178) goes through this class and nothing else converts a value of the old system:
 *
 *   time()          a text to a moment. The old API writes `2026-11-08T20:54:28.341Z` into a text column.
 *   day()           a text to a calendar day, for a column that holds a day and no time.
 *   paisa()         rupees written as a decimal number to whole paisa.
 *   paisaOfFloat()  rupees held as a floating number to whole paisa.
 *   json()          a text of JSON to a value.
 *   flag()          0 and 1 to no and yes.
 *   word()          a word of the old system to its place in an enumeration of the admin.
 *   text()          a text of no fixed length to a column that has one.
 *   asCompared()    a text as the admin's database compares it, to see before the database does that two meet.
 *   settle()        the rule of a unique column, applied to the old values on their way into it.
 *
 * Nothing here rounds, cuts or guesses. What does not fit is thrown as {@see Refused}, which says what the value
 * was meant to be, the value and the reason; an importer's dry run lists these (T-179).
 *
 * tests/Feature/OldSystem/ConversionsTest.php writes each kind to a table of the admin and reads it back, on
 * SQLite and, in CI, on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4. The rules are written out for the owner in
 * docs/woodex-os/36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md §8.
 */
final class Convert
{
    /**
     * What a time column of the admin can hold. Every one is a TIMESTAMP (tests/Feature/TimeStorageTest.php), and
     * MySQL and MariaDB keep a TIMESTAMP as seconds since 1970, from the first second to 19 January 2038, 03:14:07 UTC.
     * PostgreSQL holds any year; so does the text column the old API writes its times into.
     */
    public const int FIRST_SECOND = 1;

    public const int LAST_SECOND = 2147483647;

    /** A floating amount at or above this many rupees is refused: a double no longer holds its paisa for certain. */
    public const float LARGEST_FLOATING_RUPEES = 1.0e11;

    /**
     * A text of the old system to a moment.
     *
     * The answer is the moment itself, written as the wall clock of the workspace (Asia/Karachi). That is on
     * purpose: Eloquent and the query builder write a time as the wall clock of the object they are given, and the
     * database session speaks the workspace's time (config/database.php). A time handed over in UTC would be kept
     * five hours early, on every server and without an error. Ask `->utc()` of the answer for the UTC writing.
     *
     *   - nothing, or an empty text                    nothing
     *   - `2026-11-08T20:54:28.341Z`, `…+05:00`, `2026-11-08 20:54:28.341+00`   that moment; the part of a second is
     *     dropped, not rounded, because the admin's columns keep whole seconds and MySQL would round where
     *     MariaDB cuts
     *   - `2026-11-08`                                 the start of that day in $zone, or in the workspace's zone
     *   - `2026-11-08 20:54:28` (a clock, no zone)     refused, unless $zone says where that clock stood
     *   - anything else, a day that does not exist, a moment a TIMESTAMP cannot hold   refused
     */
    public static function time(?string $text, ?string $zone = null): ?CarbonImmutable
    {
        $moment = self::moment($text, $zone, 'time');

        if ($moment !== null && ($moment->getTimestamp() < self::FIRST_SECOND || $moment->getTimestamp() > self::LAST_SECOND)) {
            throw Refused::of('time', $text, 'a time column of the admin holds 1970 to 19 January 2038, and this moment lies outside');
        }

        return $moment;
    }

    /**
     * A text of the old system to a calendar day, as `2026-11-08`, for a column that holds a day and no time.
     *
     * A day has no time zone, and it must not be given one on the way: `2026-11-08` made into a moment and read
     * back in another zone is 7 November. So a bare day stays the text it is. A moment (a time with its zone) is
     * the day it falls on in Pakistan: `2026-11-08T20:54:28.341Z` is 9 November there.
     * A day column of MySQL holds the years 1000 to 9999; another year is refused.
     */
    public static function day(?string $text, ?string $zone = null): ?string
    {
        $moment = self::moment($text, $zone, 'day');

        if ($moment === null) {
            return null;
        }

        // A bare day is returned as it came: it was only made a moment to see that such a day exists.
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/D', trim((string) $text)) === 1 ? trim((string) $text) : $moment->format('Y-m-d');

        if ((int) substr($day, 0, 4) < 1000) {
            throw Refused::of('day', $text, 'a day column of the admin holds the years 1000 to 9999');
        }

        return $day;
    }

    /**
     * Rupees written as a decimal number, as PostgreSQL prints a NUMERIC column (`1234.50`), to whole paisa.
     *
     * A decimal number is exact, so the rule is exact: multiplied by 100 it is a whole number, or it is refused.
     * A floating number is refused here, so that nobody passes one as if it were exact: {@see paisaOfFloat()}.
     */
    public static function paisa(int|float|string|null $rupees): ?int
    {
        if ($rupees === null) {
            return null;
        }

        if (is_float($rupees)) {
            throw Refused::of('money', $rupees, 'a floating number; say so with paisaOfFloat(), which tells its noise from a part of a paisa');
        }

        $text = is_int($rupees) ? (string) $rupees : $rupees;

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $text, $parts) !== 1) {
            throw Refused::of('money', $rupees, 'not a decimal number as the old database writes one');
        }

        $decimals = rtrim($parts[3] ?? '', '0');

        if (strlen($decimals) > 2) {
            throw Refused::of('money', $rupees, 'does not come out as whole paisa');
        }

        $digits = ltrim($parts[2].str_pad($decimals, 2, '0'), '0');

        // PHP_INT_MAX has 19 digits; 18 digits of paisa always fit, and no amount of rupees comes near.
        if (strlen($digits) > 18) {
            throw Refused::of('money', $rupees, 'too large to be an amount');
        }

        $paisa = (int) $digits;

        return $parts[1] === '-' ? -$paisa : $paisa;
    }

    /**
     * Rupees held as a floating number (a DOUBLE PRECISION column of the old system, a JavaScript number) to whole
     * paisa.
     *
     * A double cannot hold 0.1, and the old system adds such numbers: 0.1 + 0.2 is 0.30000000000000004 there.
     * That is noise, and it arrives as 30 paisa. 1234.565 is not noise: it is half a paisa that somebody's
     * arithmetic left, and it is refused. The line between the two: the amount times 100 lies within a millionth
     * of a paisa of a whole number, or within one part in 10^14 of it, which is what a double cannot tell apart
     * at that size. Never rounded: what is further away is refused.
     *
     * Add the paisa of the lines, never the floating rupees: a sum of a thousand floats has drifted.
     *
     * A text is taken as PostgreSQL or JSON prints a double (`0.30000000000000004`, `1e+15`).
     */
    public static function paisaOfFloat(int|float|string|null $rupees): ?int
    {
        if ($rupees === null) {
            return null;
        }

        if (is_int($rupees)) {
            return self::paisa($rupees);
        }

        if (is_string($rupees)) {
            if (preg_match('/^-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $rupees) !== 1) {
                throw Refused::of('money', $rupees, 'not a number as the old database writes a floating one');
            }

            $float = (float) $rupees;
        } else {
            $float = $rupees;
        }

        if (! is_finite($float)) {
            throw Refused::of('money', $rupees, 'not a finite number');
        }

        if (abs($float) >= self::LARGEST_FLOATING_RUPEES) {
            throw Refused::of('money', $rupees, 'too large for a floating number to hold its paisa for certain');
        }

        $paisa = $float * 100.0;
        $whole = round($paisa);

        if (abs($paisa - $whole) > max(1.0e-6, abs($paisa) * 1.0e-14)) {
            throw Refused::of('money', $rupees, 'does not come out as whole paisa');
        }

        return (int) $whole;
    }

    /**
     * A text of JSON to a value: a list, a map (as a PHP array), a text, a number, yes or no.
     *
     * Nothing and an empty text are nothing. What is not JSON is refused with the parser's reason. A whole number
     * too large for PHP arrives as its digits in a text, never as a floating number that has lost them.
     *
     * Two things the admin's database does to JSON that PostgreSQL's text column did not, pinned by the test:
     * MySQL keeps a JSON column as a document and returns its keys in its own order, so what must stay in order
     * goes in a list, never in the keys of a map; and nothing is to be compared, or checksummed, as text after it
     * was read back.
     */
    public static function json(?string $text): mixed
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw Refused::of('JSON', $text, 'not JSON ('.$exception->getMessage().')');
        }
    }

    /**
     * 0 and 1 to no and yes. The old system writes them into a whole-number column (13 are held to that by a
     * constraint) and a true yes-or-no into three columns, which PostgreSQL prints as `t` and `f`.
     * Nothing stays nothing: whether an empty cell means no is the importer's to say. Any other value is refused.
     */
    public static function flag(int|bool|string|null $value): ?bool
    {
        return match (true) {
            $value === null => null,
            $value === true, $value === 1, $value === '1', $value === 't', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'f', $value === 'false' => false,
            default => throw Refused::of('yes or no', $value, 'neither 0 nor 1'),
        };
    }

    /**
     * A word of the old system to its place in an enumeration of the admin.
     *
     * The old system lists the words a column may hold (in a CHECK constraint, or in the API's code); the place of
     * each is written in resources/data/old-system-rules.json. A word is compared exactly, as PostgreSQL does:
     * `Paid` is a status of an old invoice, `paid` is not. Refused, each with its reason:
     *   - a word the old system does not allow in that column;
     *   - a word that has no place in the admin (the file says why);
     *   - a word of a table whose rows are not brought over today (the map says which line it waits for).
     * Nothing stays nothing.
     */
    public static function word(string $table, string $column, ?string $word): ?BackedEnum
    {
        $allowed = Shape::words($table, $column);

        if ($allowed === null) {
            throw Refused::of('word', "{$table}.{$column}", 'the old system lists no words for this column, so there is nothing to place');
        }

        if ($word === null) {
            return null;
        }

        if (! in_array($word, $allowed, true)) {
            throw Refused::of('word', $word, "not a word the old system allows in `{$table}.{$column}`");
        }

        if (($why = Shape::whyNot($table)) !== null) {
            throw Refused::of('word', $word, $why);
        }

        $vocabulary = Rules::vocabulary($table, $column);
        $place = $vocabulary['places'][$word] ?? null;

        if ($vocabulary === null || $place === null || $vocabulary['enum'] === null) {
            throw Refused::of('word', $word, "no place in the admin for this word of `{$table}.{$column}`: ".($vocabulary['without'][$word] ?? 'none is written yet'));
        }

        return $vocabulary['enum']::from($place);
    }

    /**
     * A text of the old system to a column of the admin that holds at most $characters.
     *
     * PostgreSQL's TEXT has no length. A column of the admin has one, and the servers disagree about a text that
     * is longer: MySQL and MariaDB refuse it (the admin runs them strict), SQLite keeps all of it, and a MySQL that
     * is not strict would cut it without a word. So it is refused here, by name, on every server alike. Characters
     * are counted, not bytes, as MySQL counts them: an Urdu letter and an emoji are one each.
     * Nothing stays nothing, and an empty text stays an empty text.
     */
    public static function text(?string $text, int $characters): ?string
    {
        if ($text === null) {
            return null;
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw Refused::of('text', bin2hex(substr($text, 0, 20)), 'not UTF-8');
        }

        if (($length = mb_strlen($text, 'UTF-8')) > $characters) {
            throw Refused::of('text', $text, "{$length} characters, and the column holds {$characters}");
        }

        return $text;
    }

    /**
     * A text as the admin's database compares it in a unique column: upper and lower case are one, a letter with
     * and without its accent are one, and spaces at the end do not count. PostgreSQL holds all of these apart.
     *
     * Two old values with the same answer here meet on a unique column of the admin, and the rule of that column
     * is applied before anything is written ({@see settle()}). This is what a person can type; it is not the
     * whole of the database's comparison (`ß` is `ss` there). For the rest the unique index itself stands behind
     * it: the database refuses the second value, and the import stops.
     */
    public static function asCompared(string $text): string
    {
        $apart = Normalizer::normalize($text, Normalizer::FORM_D);

        if ($apart === false) {
            throw Refused::of('text', bin2hex(substr($text, 0, 20)), 'not UTF-8');
        }

        return rtrim(mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', $apart), 'UTF-8'), ' ');
    }

    /**
     * The rule of a unique column, applied to the old values that are on their way into it.
     *
     * $values is the old number of each record and the value it brings, in the order of the old numbers. For an
     * index of several columns the value is the list of them, in the index's order. The answer is the old numbers
     * whose value is written. A value that is nothing, or has nothing in one of its columns, meets nobody: that
     * is how a unique index treats it.
     *   - rule "stop": two values that meet stop the import; the refusal names both old numbers.
     *   - rule "first": the one that comes first is kept; the others are returned under `left`, for the report.
     *
     * @param  list<string>  $columns  the columns of the unique index, in its order
     * @param  array<int|string, string|int|list<string|int|null>|null>  $values  old number => value
     * @return array{kept: list<int|string>, left: array<int|string, int|string>} `left`: old number => the old number it met
     */
    public static function settle(string $table, array $columns, array $values): array
    {
        $rule = Rules::uniqueColumn($table, $columns);
        $name = $table.'.'.implode('+', $columns);

        if ($rule === null) {
            throw Refused::of('name', $name, 'no unique index of this name has a written rule');
        }

        if ($rule['value'] !== 'carried') {
            throw Refused::of('name', $name, 'the old system\'s value is not written into this column: '.$rule['why']);
        }

        $seen = [];
        $kept = [];
        $left = [];

        foreach ($values as $old => $value) {
            $parts = is_array($value) ? $value : [$value];

            if (count($parts) !== count($columns)) {
                throw Refused::of('name', $name, 'the index has '.count($columns).' column(s), and the old record '.$old.' brings '.count($parts).' value(s)');
            }

            if (in_array(null, $parts, true)) {
                $kept[] = $old;

                continue;
            }

            $compared = implode("\x1f", array_map(static fn (string|int $part): string => self::asCompared((string) $part), $parts));

            if (! array_key_exists($compared, $seen)) {
                $seen[$compared] = $old;
                $kept[] = $old;

                continue;
            }

            if ($rule['rule'] !== 'first') {
                throw Refused::of('unique column', implode(' + ', $parts), "the old records {$seen[$compared]} and {$old} meet on `{$name}`, which holds each value once; the import stops");
            }

            $left[$old] = $seen[$compared];
        }

        return ['kept' => $kept, 'left' => $left];
    }

    /**
     * The moment a text names, in the workspace's zone; null for nothing. No limit of a column is applied here.
     */
    private static function moment(?string $text, ?string $zone, string $what): ?CarbonImmutable
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $pattern = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:[.,]\d{1,9})?)?(Z|[+-]\d{2}(?::?\d{2})?)?)?$/D';

        if (preg_match($pattern, trim($text), $parts) !== 1) {
            throw Refused::of($what, $text, 'not a time as the old system writes one (2026-11-08T20:54:28.341Z, or a day as 2026-11-08)');
        }

        [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        $hasClock = ($parts[4] ?? '') !== '';
        [$hour, $minute, $second] = [(int) ($parts[4] ?? 0), (int) ($parts[5] ?? 0), (int) ($parts[6] ?? 0)];
        $offset = $parts[7] ?? '';

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            throw Refused::of($what, $text, 'no such day or time of day');
        }

        $workspace = (string) config('app.timezone', 'Asia/Karachi');

        if ($offset !== '') {
            $in = $offset === 'Z' ? 'UTC' : substr($offset, 0, 3).':'.(strlen($offset) > 3 ? substr($offset, -2) : '00');

            if ((int) substr($in, 1, 2) > 15 || (int) substr($in, 4, 2) > 59) {
                throw Refused::of($what, $text, 'no such time zone');
            }
        } elseif ($hasClock && $zone === null) {
            throw Refused::of($what, $text, 'a time of day without a time zone; say in which zone that clock stood');
        } else {
            $in = $zone ?? $workspace;
        }

        $moment = CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, $in);

        if (! $moment instanceof CarbonImmutable) {
            throw Refused::of($what, $text, 'no such day or time of day');
        }

        return $moment->setTimezone($workspace);
    }
}
