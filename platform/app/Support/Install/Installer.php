<?php

declare(strict_types=1);

namespace App\Support\Install;

use App\Domain\System\Actions\CreateOwnerAccount;
use App\Domain\Website\Actions\ImportBundledWebsite;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The web installer for Hostinger Git deploys without SSH (docs/06 §2, D-58).
 *
 * Step 1 (site not configured): the owner proves access to the hosting account with the setup code that the installer
 * wrote into the data folder (readable in hPanel's File Manager, never on the web), enters the database and the owner
 * account, and the installer writes .env. Step 2 runs in a fresh request that boots with that .env: migrations,
 * reference data, the owner account. The setup code is then deleted, which closes the installer for good.
 */
final class Installer
{
    public const string CODE_FILE = 'setup-code.txt';

    /**
     * A copy of the setup code in the site folder (public_html), the folder hPanel's File Manager opens first: the
     * private folder in the home folder only shows under "Access all files of hosting", which owners did not find.
     * Not reachable from the web: the root .htaccess sends every request to platform/public (and denies this name).
     */
    public const string SITE_CODE_FILE = 'WOODEX-SETUP-CODE.txt';

    public const string PENDING_FILE = 'install-pending.json';

    /** No 0/O, 1/I/L: the code is read from a file and typed or pasted. 24 characters ≈ 119 bits. */
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** PHP extensions the application needs on the server. */
    public const array EXTENSIONS = ['ctype', 'curl', 'dom', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'tokenizer', 'xml', 'zip'];

    public const string FAILURES_FILE = 'install-failures.json';

    /** Failed proofs of ownership allowed per client within FAILURE_WINDOW seconds (no cache or database exists yet). */
    public const int MAX_FAILURES = 10;

    public const int FAILURE_WINDOW = 600;

    private readonly ProbesDatabase $probe;

    public function __construct(private readonly SiteState $site, ?ProbesDatabase $probe = null)
    {
        $this->probe = $probe ?? new MysqlProbe;
    }

    public function site(): SiteState
    {
        return $this->site;
    }

    /** Folder of the active .env, or where step 1 will write it. */
    public function directory(): string
    {
        return $this->site->environmentDirectory() ?? $this->site->installDirectory();
    }

    /** True when .env and storage stay inside platform/ (no safe home folder was found). */
    public function isClassic(): bool
    {
        return $this->directory() === $this->site->basePath;
    }

    public function storageDirectory(): string
    {
        return $this->isClassic() ? $this->site->basePath.'/storage' : $this->directory().'/storage';
    }

    public function codePath(): string
    {
        return $this->privateFolder().'/'.self::CODE_FILE;
    }

    public function pendingPath(): string
    {
        return $this->privateFolder().'/'.self::PENDING_FILE;
    }

    /** The copy in the site folder (the repository root, next to platform/). */
    public function siteCodePath(): string
    {
        return dirname($this->site->basePath).'/'.self::SITE_CODE_FILE;
    }

    /**
     * The copy as hPanel's File Manager shows it ("public_html/WOODEX-SETUP-CODE.txt"), or null when it could not be
     * written (the private file still works).
     */
    public function siteCodeDisplay(): ?string
    {
        $path = $this->siteCodePath();

        if (! is_file($path)) {
            return null;
        }

        return preg_match('#/domains/[^/]+/(public_html(?:/.+)?)$#', $path, $match) === 1 ? $match[1] : $this->site->displayPath($path);
    }

    public function hasCode(): bool
    {
        return $this->readCode() !== null;
    }

    /**
     * Writes a new setup code unless one is waiting already, and keeps the copy in the site folder (a deploy replaces
     * the site folder, so the copy is written again with the same code).
     */
    public function ensureCode(): void
    {
        if (! $this->hasCode()) {
            $folder = $this->privateFolder();

            if (! is_dir($folder) && ! @mkdir($folder, 0750, true)) {
                throw new RuntimeException('Cannot create the data folder '.$this->site->displayPath($folder).'.');
            }

            EnvFile::write($this->codePath(), "Woodex OS setup code\n\n".self::newCode()."\n\n"
                ."Paste this code into the installer (/install on your site). It is deleted when the installation finishes.\n");
        }

        $this->writeSiteCopy();
    }

    /** The waiting code as XXXX-XXXX-…, for the step-2 page after the owner proved ownership another way. */
    public function currentCode(): ?string
    {
        $code = $this->readCode();

        return $code === null ? null : implode('-', str_split($code, 4));
    }

    /**
     * Second proof of ownership (D-59) for owners who cannot find the setup code file. On Hostinger (and cPanel) every
     * database user starts with the hosting account name ("u123456789_…") and only that account can create one in
     * hPanel, so logging in to the LOCAL MySQL server as such a user proves the same as reading the file. Not for
     * remote hosts (anyone can create any user name on their own server), SQLite, or a user without a password.
     *
     * @param  array{connection: string, host: string, username: string, password: string}  $db
     */
    public function databaseMayProveOwnership(array $db): bool
    {
        $account = $this->site->account();

        return $account !== null
            && $db['connection'] === 'mysql'
            && in_array(strtolower(trim($db['host'])), ['localhost', '127.0.0.1', '::1'], true)
            && str_starts_with($db['username'], $account.'_')
            && $db['password'] !== '';
    }

    /**
     * After connecting: MySQL must really have authenticated an account user, not an anonymous user that accepts any
     * name (CURRENT_USER() is then "@localhost").
     *
     * @param  array{connection: string, host: string, username: string, password: string}  $db
     */
    public function databaseProvesOwnership(array $db, ?string $currentUser): bool
    {
        $account = $this->site->account();

        return $this->databaseMayProveOwnership($db) && $account !== null && $currentUser !== null
            && str_starts_with($currentUser, $account.'_');
    }

    public function tooManyFailures(string $client): bool
    {
        return count($this->recentFailures()[self::clientKey($client)] ?? []) >= self::MAX_FAILURES;
    }

    public function recordFailure(string $client): void
    {
        $failures = $this->recentFailures();
        $failures[self::clientKey($client)][] = now()->getTimestamp();

        try {
            EnvFile::write($this->privateFolder().'/'.self::FAILURES_FILE, (string) json_encode($failures));
        } catch (RuntimeException) {
            // Without a writable data folder the installer cannot work anyway.
        }
    }

    public function codeMatches(string $input): bool
    {
        $expected = $this->readCode();
        $given = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));

        return $expected !== null && $given !== '' && hash_equals($expected, $given);
    }

    public static function newCode(): string
    {
        $groups = [];

        for ($group = 0; $group < 6; $group++) {
            $chunk = '';

            for ($i = 0; $i < 4; $i++) {
                $chunk .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $groups[] = $chunk;
        }

        return implode('-', $groups);
    }

    /**
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public function requirements(): array
    {
        $phpOk = version_compare(PHP_VERSION, '8.4.0', '>=');
        $checks = [[
            'label' => 'PHP 8.4 or newer',
            'ok' => $phpOk,
            'detail' => 'This site runs PHP '.PHP_VERSION.($phpOk ? '.' : '. Change it in hPanel → Advanced → PHP Configuration.'),
        ]];

        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $extension): bool => ! extension_loaded($extension)));
        $checks[] = [
            'label' => 'PHP extensions',
            'ok' => $missing === [],
            'detail' => $missing === [] ? 'All '.count(self::EXTENSIONS).' present.' : 'Turn on in hPanel → PHP Configuration → PHP extensions: '.implode(', ', $missing).'.',
        ];

        $directory = $this->directory();
        $checks[] = [
            'label' => 'Private data folder',
            'ok' => is_writable($directory),
            'detail' => $this->isClassic()
                ? 'No home folder was found, so settings stay in platform/. A deploy may replace them: see docs/06 §2.'
                : $this->site->displayPath($directory).' (outside the website, kept between deploys).',
        ];

        $cache = $this->site->basePath.'/bootstrap/cache';
        $checks[] = [
            'label' => 'Cache folder writable',
            'ok' => is_dir($cache) && is_writable($cache),
            'detail' => 'platform/bootstrap/cache',
        ];

        $checks[] = [
            'label' => 'Front-end build',
            'ok' => is_file($this->site->basePath.'/public/build/manifest.json'),
            'detail' => 'platform/public/build (built in the repository; the server has no Node.js).',
        ];

        return $checks;
    }

    /**
     * Connects with the details from the form and refuses databases that belong to another application.
     *
     * @param  array{connection: string, host: string, port: int, database: string, username: string, password: string}  $db
     * @return array{ok: bool, message: string, user?: string}
     */
    public function checkDatabase(array $db): array
    {
        if ($db['connection'] === 'sqlite') {
            return extension_loaded('pdo_sqlite')
                ? ['ok' => true, 'message' => 'SQLite file in the data folder.']
                : ['ok' => false, 'message' => 'The pdo_sqlite PHP extension is off. Use MySQL or turn it on in hPanel.'];
        }

        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            return ['ok' => false, 'message' => 'The pdo_mysql PHP extension is off: turn it on in hPanel → Advanced → PHP Configuration → PHP extensions.'];
        }

        try {
            ['version' => $version, 'tables' => $tables, 'user' => $user] = $this->probe->probe($db);
        } catch (PDOException $exception) {
            return ['ok' => false, 'message' => self::explainMysqlError($exception)];
        }

        if ($tables !== [] && ! in_array('migrations', $tables, true)) {
            return ['ok' => false, 'message' => 'This database already holds '.count($tables).' tables from another application (for example the old v2.1 system). Create a new, empty database in hPanel → Databases and use that one.'];
        }

        if (! self::versionSupported($version)) {
            return ['ok' => false, 'message' => "Database server {$version} is too old: MySQL 8.0 or MariaDB 10.6 or newer is needed."];
        }

        return ['ok' => true, 'user' => $user, 'message' => 'Connected to '.$version.($tables !== [] ? ' (existing Woodex OS tables are kept and updated).' : ' (empty database).')];
    }

    public static function versionSupported(string $version): bool
    {
        if (preg_match('/^(\d+)\.(\d+)/', $version, $match) !== 1) {
            return true; // unknown format: let the migrations decide
        }

        $numeric = (int) $match[1] * 100 + (int) $match[2];

        return stripos($version, 'mariadb') !== false ? $numeric >= 1006 : $numeric >= 800;
    }

    /**
     * Step 1: writes .env (new APP_KEY), the storage folders, the SQLite file when chosen, and the owner details for
     * step 2 (the password only as a hash).
     *
     * @param  array{app_url: string, connection: string, host: string, port: int, database: string, username: string, password: string, owner_name: string, owner_email: string, owner_password: string, enforce_2fa: bool}  $input
     */
    public function configure(array $input): void
    {
        $directory = $this->directory();
        SiteState::ensureStorage($this->storageDirectory());

        $url = rtrim($input['app_url'], '/');
        $https = str_starts_with($url, 'https://');

        if ($input['connection'] === 'sqlite') {
            $file = $this->isClassic() ? $this->site->basePath.'/database/database.sqlite' : $directory.'/database.sqlite';

            if (! is_file($file) && @touch($file) === false) {
                throw new RuntimeException('Cannot create the SQLite database file.');
            }

            $database = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file];
        } else {
            $database = [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $input['host'],
                'DB_PORT' => $input['port'],
                'DB_DATABASE' => $input['database'],
                'DB_USERNAME' => $input['username'],
                'DB_PASSWORD' => $input['password'],
                'DB_TIMEZONE' => '+05:00',
            ];
        }

        $contents = EnvFile::render([
            'Application' => [
                'APP_NAME' => 'Woodex OS',
                'APP_ENV' => 'production',
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'APP_DEBUG' => false,
                'APP_URL' => $url,
                'APP_TIMEZONE' => 'Asia/Karachi',
                'APP_LOCALE' => 'en',
                'APP_FALLBACK_LOCALE' => 'en',
                'APP_MAINTENANCE_DRIVER' => 'file',
            ],
            'Admin location and security (admin at '.$url.'/admin)' => [
                'ADMIN_DOMAIN' => '',
                'ADMIN_PATH' => 'admin',
                'PUBLIC_SITE_URL' => 'https://woodex.com.pk',
                'ENFORCE_2FA' => $input['enforce_2fa'],
                'SECURITY_FRAME_OPTIONS' => 'SAMEORIGIN',
                'BCRYPT_ROUNDS' => 12,
                'TRUSTED_PROXIES' => '',
            ],
            'Logs' => [
                'LOG_CHANNEL' => 'daily',
                'LOG_LEVEL' => 'warning',
                'LOG_DEPRECATIONS_CHANNEL' => 'null',
            ],
            'Database' => $database,
            'Sessions, cache and queue live in the database (no extra services on shared hosting)' => [
                'SESSION_DRIVER' => 'database',
                'SESSION_LIFETIME' => 480,
                'SESSION_ENCRYPT' => false,
                'SESSION_PATH' => '/',
                'SESSION_DOMAIN' => 'null',
                'SESSION_SECURE_COOKIE' => $https,
                'QUEUE_CONNECTION' => 'database',
                'CACHE_STORE' => 'database',
                'BROADCAST_CONNECTION' => 'log',
                'FILESYSTEM_DISK' => 'local',
            ],
            'Email: written to the log until the SMTP mailbox is ready (P-02), then set MAIL_MAILER=smtp' => [
                'MAIL_MAILER' => 'log',
                'MAIL_SCHEME' => 'smtps',
                'MAIL_HOST' => 'smtp.hostinger.com',
                'MAIL_PORT' => 465,
                'MAIL_USERNAME' => '',
                'MAIL_PASSWORD' => '',
                'MAIL_FROM_ADDRESS' => 'noreply@woodex.com.pk',
                'MAIL_FROM_NAME' => 'Woodex Interior',
            ],
            'Website lead forms allowed to post enquiries' => [
                'PUBLIC_LEADS_ALLOWED_ORIGINS' => implode(',', array_unique(['https://woodex.com.pk', 'https://www.woodex.com.pk', $url])),
                'PUBLIC_LEADS_BRIDGE_KEY' => '',
            ],
            'Integrations: fill in when ready (docs/06 section 4)' => [
                'TURNSTILE_SITE_KEY' => '',
                'TURNSTILE_SECRET_KEY' => '',
                'TELEGRAM_BOT_TOKEN' => '',
                'TELEGRAM_WEBHOOK_SECRET' => '',
                'LEGACY_DB_HOST' => 'localhost',
                'LEGACY_DB_PORT' => 3306,
                'LEGACY_DB_DATABASE' => '',
                'LEGACY_DB_USERNAME' => '',
                'LEGACY_DB_PASSWORD' => '',
            ],
        ]);

        EnvFile::write($this->pendingPath(), (string) json_encode([
            'name' => $input['owner_name'],
            'email' => $input['owner_email'],
            'password_hash' => Hash::make($input['owner_password']),
        ], JSON_THROW_ON_ERROR));

        // .env last: its presence is what marks the site as configured.
        EnvFile::write($directory.'/.env', $contents);
    }

    /**
     * Step 2 (runs with the new .env): tables, reference data and the owner account. Safe to repeat after an error.
     *
     * @return array{owner: ?string, log: string}
     */
    public function finish(CreateOwnerAccount $createOwner, ImportBundledWebsite $importWebsite): array
    {
        @set_time_limit(600);
        ignore_user_abort(true);

        $log = [];
        Artisan::call('migrate', ['--force' => true]);
        $log[] = trim(Artisan::output());
        Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);
        $log[] = trim(Artisan::output());

        $owner = null;

        if (User::role('owner')->exists()) {
            $log[] = 'An owner account already exists in this database: kept as it is.';
        } else {
            $pending = $this->readPending();

            if ($pending === null) {
                throw new RuntimeException('The owner details from step 1 are missing. Delete the .env file in the data folder and run the installer again.');
            }

            $owner = $createOwner->handle($pending['name'], $pending['email'], $pending['password_hash'])->email;
            $log[] = "Owner account created: {$owner}.";
        }

        // The pages of the website travel with the code: with an owner in the database they can be written now.
        $pages = $importWebsite();

        if ($pages !== null) {
            $log[] = $pages === '' ? 'The pages of the website are up to date.' : $pages;
        }

        @unlink($this->pendingPath());
        @unlink($this->codePath());
        @unlink($this->siteCodePath());
        @unlink($this->privateFolder().'/'.self::FAILURES_FILE);

        return ['owner' => $owner, 'log' => implode("\n", array_filter($log))];
    }

    /** The hPanel cron command for the scheduler (queue, reminders, heartbeat). */
    public function cronCommand(): string
    {
        $php = '/usr/bin/php';

        if (preg_match('#^(/opt/alt/php\d+)/#', PHP_BINARY, $match) === 1) {
            $php = $match[1].'/usr/bin/php';
        }

        return $php.' '.$this->site->basePath.'/artisan schedule:run';
    }

    private function privateFolder(): string
    {
        // Classic layout: installer files go to storage/app/private (never web-reachable), not into platform/.
        return $this->isClassic() ? $this->site->basePath.'/storage/app/private' : $this->directory();
    }

    /**
     * @return array<string, list<int>>
     */
    private function recentFailures(): array
    {
        $data = json_decode((string) @file_get_contents($this->privateFolder().'/'.self::FAILURES_FILE), true);
        $since = now()->getTimestamp() - self::FAILURE_WINDOW;
        $recent = [];

        foreach (is_array($data) ? $data : [] as $key => $times) {
            $kept = array_values(array_filter(is_array($times) ? $times : [], fn ($time): bool => is_int($time) && $time > $since));

            if ($kept !== []) {
                $recent[(string) $key] = $kept;
            }
        }

        return $recent;
    }

    private static function clientKey(string $client): string
    {
        return substr(hash('sha256', $client), 0, 16);
    }

    /** Best effort: when the site folder is not writable the owner uses the private file. */
    private function writeSiteCopy(): void
    {
        $code = $this->readCode();

        if ($code === null) {
            return;
        }

        $formatted = implode('-', str_split($code, 4));
        $path = $this->siteCodePath();
        $existing = @file_get_contents($path);

        if (is_string($existing) && str_contains($existing, $formatted)) {
            return;
        }

        try {
            EnvFile::write($path, "Woodex OS setup code\n\n{$formatted}\n\n"
                ."Paste this code into the installer (/install on your site). This file cannot be opened from the web and it\n"
                ."is deleted when the installation finishes. The same code is kept in {$this->site->displayPath($this->codePath())}.\n");
        } catch (RuntimeException) {
            // The private file is the source of truth; the form names it.
        }
    }

    private function readCode(): ?string
    {
        $contents = @file_get_contents($this->codePath());

        if (! is_string($contents) || preg_match('/\b([A-Z2-9]{4}(?:-[A-Z2-9]{4}){5})\b/', $contents, $match) !== 1) {
            return null;
        }

        return str_replace('-', '', $match[1]);
    }

    /**
     * @return array{name: string, email: string, password_hash: string}|null
     */
    private function readPending(): ?array
    {
        try {
            $data = json_decode((string) @file_get_contents($this->pendingPath()), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['name'] ?? null) || ! is_string($data['email'] ?? null) || ! is_string($data['password_hash'] ?? null)) {
            return null;
        }

        return ['name' => $data['name'], 'email' => $data['email'], 'password_hash' => $data['password_hash']];
    }

    private static function explainMysqlError(PDOException $exception): string
    {
        $code = (int) ($exception->errorInfo[1] ?? 0);

        return match (true) {
            $code === 1045 => 'Access denied: check the database user name and password (hPanel → Databases → Management).',
            $code === 1044 => 'This user has no access to that database. In hPanel → Databases, add the user to the database.',
            $code === 1049 => 'Unknown database: check the name. On Hostinger it starts with your account prefix, like u123456789_woodex.',
            in_array($code, [2002, 2003, 2005], true) => 'Cannot reach the database server. On Hostinger the host is usually "localhost".',
            default => 'Database error: '.$exception->getMessage(),
        };
    }
}
