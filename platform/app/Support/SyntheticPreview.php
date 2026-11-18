<?php

declare(strict_types=1);

namespace App\Support;

final class SyntheticPreview
{
    public static function enabled(): bool
    {
        return config('preview.synthetic') === true
            && app()->environment('local', 'testing')
            && config('database.default') === 'sqlite'
            && config('database.connections.sqlite.driver') === 'sqlite'
            && blank(config('database.connections.sqlite.url'))
            && blank(config('database.connections.sqlite.read'))
            && blank(config('database.connections.sqlite.write'));
    }

    /** @return array{email: string, password: string}|null */
    public static function credentials(): ?array
    {
        if (! self::enabled() || config('preview.show_credentials') !== true || ! self::isolatedDatabase()) {
            return null;
        }
        $email = config('preview.login_email');
        $password = config('preview.login_password');
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! str_ends_with(strtolower($email), '@woodex.test')
            || ! is_string($password) || strlen($password) < 12 || strlen($password) > 128 || preg_match('/[\r\n\x00]/', $password)) {
            return null;
        }

        return ['email' => $email, 'password' => $password];
    }

    public static function isolatedDatabase(): bool
    {
        $database = config('database.connections.sqlite.database');
        if (! is_string($database) || $database === '') {
            return false;
        }
        if ($database === ':memory:') {
            return app()->environment('testing');
        }
        // Resolve symlinks: a conveniently named link to an installed DB must not expose credentials.
        $path = realpath($database);

        return $path !== false && str_starts_with(basename($path), 'woodex-synthetic-');
    }
}
