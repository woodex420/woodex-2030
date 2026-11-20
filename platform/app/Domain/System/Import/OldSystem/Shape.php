<?php

declare(strict_types=1);

namespace App\Domain\System\Import\OldSystem;

use RuntimeException;

/**
 * What the admin is told about the tables of the old system (the React shop and dashboard with their Node API,
 * on PostgreSQL): the columns of each table's key, the words a column may hold, and where the map of the tables
 * sends its rows.
 *
 * It reads resources/data/old-system-shape.json. Nobody writes that file by hand: `node scripts/verify-old-shape.mjs
 * --write` (repository root) builds the old schema on a test database from its schema files, asks the database's
 * own catalogue, and reads docs/woodex-os/acceptance/tables.json; CI fails when the file says anything else.
 *
 * @phpstan-type OldTable array{key: list<array{0: string, 1: string}>, fate: string, to: list<string>, line?: string, wave?: int, words: array<string, list<string>>, words_in_code: array<string, array{file: string, constant: string, words: list<string>}>, flags: list<string>, booleans: list<string>}
 */
final class Shape
{
    public const string FILE = 'resources/data/old-system-shape.json';

    /** @var array<string, OldTable>|null */
    private static ?array $tables = null;

    /**
     * @return array<string, OldTable>
     */
    public static function tables(): array
    {
        if (self::$tables === null) {
            $path = dirname(__DIR__, 5).'/'.self::FILE;
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            if (! is_array($decoded) || ! is_array($decoded['tables'] ?? null)) {
                throw new RuntimeException('The shape of the old system cannot be read: '.self::FILE);
            }

            self::$tables = $decoded['tables'];
        }

        return self::$tables;
    }

    /**
     * @return OldTable
     */
    public static function table(string $table): array
    {
        return self::tables()[$table] ?? throw Refused::of('name', $table, 'the old system has no table of this name');
    }

    /**
     * The words a column may hold: by a CHECK constraint of the old schema, or, where there is none, by the list
     * the old API's code checks against. Null when neither lists any: the column takes any text.
     *
     * @return list<string>|null
     */
    public static function words(string $table, string $column): ?array
    {
        $shape = self::table($table);

        return $shape['words'][$column] ?? $shape['words_in_code'][$column]['words'] ?? null;
    }

    /**
     * Every column of the old system whose words are listed, as "table.column".
     *
     * @return list<string>
     */
    public static function vocabularies(): array
    {
        $found = [];

        foreach (self::tables() as $table => $shape) {
            foreach (array_keys($shape['words'] + $shape['words_in_code']) as $column) {
                $found[] = $table.'.'.$column;
            }
        }

        sort($found);

        return $found;
    }

    /** Why the rows of a table are not brought over today, in words; null when they are (the map says "moves"). */
    public static function whyNot(string $table): ?string
    {
        $shape = self::table($table);

        return match ($shape['fate']) {
            'moves' => null,
            'waits' => "the admin has no table for the rows of `{$table}` yet: it waits for ".($shape['line'] ?? 'a line of the master list'),
            default => "by the map of the tables the rows of `{$table}` stay in the old system",
        };
    }
}
