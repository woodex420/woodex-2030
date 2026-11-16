<?php

declare(strict_types=1);

namespace App\Support\Install;

use PDOException;

/**
 * Opens the MySQL database the owner entered in the installer. An interface so that tests (and the AI sandbox, whose
 * php-wasm has no network sockets) can stand in for a real server.
 */
interface ProbesDatabase
{
    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $db
     * @return array{version: string, tables: list<string>, user: string} user = CURRENT_USER(), the account MySQL
     *                                                                    authenticated ("name@host")
     *
     * @throws PDOException
     */
    public function probe(array $db): array;
}
