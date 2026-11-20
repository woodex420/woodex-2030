<?php

declare(strict_types=1);

namespace App\Domain\System\Import\OldSystem;

use BackedEnum;
use RuntimeException;

/**
 * The decisions an importer of the old system follows, as they are written in resources/data/old-system-rules.json:
 * the place of each word of the old system in an enumeration of the admin, the rule for every unique column, and
 * which records get no entry of their own in the table of the old numbers.
 *
 * The file is written by hand, and every line of it is a proposal until the mapping of its module is approved
 * (master list T-181). It is held to the old schema by `node scripts/verify-old-shape.mjs` (a word of a table that
 * moves has a place or a reason; document 36 shows the file) and to the admin by tests/Feature/OldSystem.
 *
 * @phpstan-type Vocabulary array{enum: class-string<BackedEnum>|null, places: array<string, string>, without: array<string, string>, note?: string}
 * @phpstan-type UniqueRule array{table: string, columns: list<string>, value: string, from?: string, rule?: string, why: string}
 * @phpstan-type LinesRule array{from: string, into: string, why: string}
 */
final class Rules
{
    public const string FILE = 'resources/data/old-system-rules.json';

    /** @var array{words: array<string, Vocabulary>, unique: list<UniqueRule>, lines: list<LinesRule>}|null */
    private static ?array $rules = null;

    /**
     * @return array{words: array<string, Vocabulary>, unique: list<UniqueRule>, lines: list<LinesRule>}
     */
    private static function all(): array
    {
        if (self::$rules === null) {
            $path = dirname(__DIR__, 5).'/'.self::FILE;
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            if (! is_array($decoded) || ! is_array($decoded['words'] ?? null) || ! is_array($decoded['unique'] ?? null) || ! is_array($decoded['lines'] ?? null)) {
                throw new RuntimeException('The rules for the old system cannot be read: '.self::FILE);
            }

            self::$rules = ['words' => $decoded['words'], 'unique' => $decoded['unique'], 'lines' => $decoded['lines']];
        }

        return self::$rules;
    }

    /**
     * @return array<string, Vocabulary> by "table.column"
     */
    public static function vocabularies(): array
    {
        return self::all()['words'];
    }

    /**
     * @return Vocabulary|null
     */
    public static function vocabulary(string $table, string $column): ?array
    {
        return self::all()['words'][$table.'.'.$column] ?? null;
    }

    /**
     * @return list<UniqueRule>
     */
    public static function uniqueColumns(): array
    {
        return self::all()['unique'];
    }

    /**
     * Where one old row makes several records in one table of the admin (the lines of a quotation): those records
     * get no entry in the table of the old numbers, and the entry of their parent answers for them.
     *
     * @return list<LinesRule>
     */
    public static function lines(): array
    {
        return self::all()['lines'];
    }

    /** Why the records an old table makes in this table of the admin get no entry; null when they get one. */
    public static function whyNoEntry(string $table, string $into): ?string
    {
        foreach (self::all()['lines'] as $rule) {
            if ($rule['from'] === $table && $rule['into'] === $into) {
                return $rule['why'];
            }
        }

        return null;
    }

    /**
     * The rule of one unique index, named by its table and its columns in the index's order.
     *
     * @param  list<string>  $columns
     * @return UniqueRule|null
     */
    public static function uniqueColumn(string $table, array $columns): ?array
    {
        foreach (self::all()['unique'] as $rule) {
            if ($rule['table'] === $table && $rule['columns'] === $columns) {
                return $rule;
            }
        }

        return null;
    }
}
