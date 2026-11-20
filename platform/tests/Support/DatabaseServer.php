<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which database server a test is talking to. A test that pins what a server does with a value asks this.
 *
 * The suite runs on SQLite in a sandbox and, in CI, on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4. The application
 * connects to MariaDB with the MySQL driver, as the installer writes it, so the driver's name does not tell the
 * two apart; the server's own version does.
 */
final class DatabaseServer
{
    public const string SQLITE = 'sqlite';

    public const string MYSQL = 'mysql';

    public const string MARIADB = 'mariadb';

    public static function kind(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return self::SQLITE;
        }

        return str_contains(strtolower(self::version()), 'mariadb') ? self::MARIADB : self::MYSQL;
    }

    public static function version(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'SQLite '.DB::selectOne('select sqlite_version() as version')->version
            : (string) DB::selectOne('select version() as version')->version;
    }

    /** MySQL and MariaDB, as the admin sets them up: strict, and comparing text without regard to case. */
    public static function isMySqlFamily(): bool
    {
        return self::kind() !== self::SQLITE;
    }
}
