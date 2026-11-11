<?php

declare(strict_types=1);

namespace App\Support\Install;

/**
 * Where this installation keeps its secrets and writable data (docs/06 §2, D-57).
 *
 * Hostinger's "Deploy from GitHub" copies the whole repository into the site folder and replaces the files there on
 * every push. The .env file, uploads, logs and compiled views must survive that and must never be reachable from the
 * web, so a Git-deployed site keeps them in the hosting account's home folder, outside every public_html:
 *
 *     ~/woodex-os/<site>/.env
 *     ~/woodex-os/<site>/storage/
 *
 * <site> comes from the deploy folder: domains/interior.woodex.com.pk/public_html → "interior.woodex.com.pk".
 * A classic install (platform/.env exists: local development, CI, the SSH install of docs/06 §3) keeps everything in
 * platform/ exactly as before.
 */
final class SiteState
{
    public const string FOLDER = 'woodex-os';

    /** Folders Laravel expects inside storage/. Created when missing (platform/storage is not fully in Git). */
    public const array STORAGE_TREE = [
        'app/private',
        'app/public',
        'framework/cache/data',
        'framework/sessions',
        'framework/testing',
        'framework/views',
        'fonts', // dompdf font cache (quotation PDFs)
        'logs',
    ];

    /** System users that are never a hosting account (a database user prefix of theirs proves nothing). */
    private const array SYSTEM_USERS = ['root', 'www-data', 'nobody', 'apache', 'nginx', 'httpd', 'http', 'daemon', 'litespeed', 'lsadm'];

    public function __construct(
        /** The Laravel application folder (platform/). */
        public readonly string $basePath,
        /** The hosting account's home folder, or null when it could not be found safely. */
        public readonly ?string $home,
        /** The hosting account name (Hostinger: "u123456789"); null = detect when asked (only the installer asks). */
        private readonly ?string $account = null,
    ) {}

    /**
     * The hosting account name: the user PHP runs as (Hostinger and cPanel run each account's PHP as that account),
     * else the owner of the application files. Null for system users or anything that does not look like an account.
     */
    public function account(): ?string
    {
        $name = $this->account;

        if ($name === null && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $entry = @posix_getpwuid(posix_geteuid());
            $name = is_array($entry) ? $entry['name'] : null;
        }

        if ($name === null || $name === '') {
            $name = get_current_user();
        }

        return preg_match('/^[a-z][a-z0-9]{2,31}$/', $name) === 1 && ! in_array($name, self::SYSTEM_USERS, true) ? $name : null;
    }

    public static function detect(string $basePath): self
    {
        $basePath = rtrim($basePath, '/');

        return new self($basePath, self::detectHome($basePath));
    }

    /** platform/.env exists: everything stays in platform/. */
    public function usesClassicLayout(): bool
    {
        return is_file($this->basePath.'/.env');
    }

    /** The deployed repository sits inside a web folder (a Hostinger Git deploy) rather than outside it (SSH). */
    public function insideWebFolder(): bool
    {
        return preg_match('#/(public_html|www|htdocs)(/|$)#', dirname($this->basePath)) === 1;
    }

    /** The external folder for this site (it may not exist yet), or null when no safe home folder was found. */
    public function externalDirectory(): ?string
    {
        return $this->home === null ? null : $this->home.'/'.self::FOLDER.'/'.$this->siteKey();
    }

    /** The folder that holds the active .env, or null while the site is not configured. */
    public function environmentDirectory(): ?string
    {
        if ($this->usesClassicLayout()) {
            return $this->basePath;
        }

        $external = $this->externalDirectory();

        return $external !== null && is_file($external.'/.env') ? $external : null;
    }

    public function isConfigured(): bool
    {
        return $this->environmentDirectory() !== null;
    }

    public function usesExternalState(): bool
    {
        return ! $this->usesClassicLayout() && $this->environmentDirectory() !== null;
    }

    /**
     * Where the installer writes .env and storage/: the external folder when it exists or can be created, otherwise
     * platform/ (classic). A classic Git-deployed site warns on the Health page because a deploy may replace it.
     */
    public function installDirectory(): string
    {
        $external = $this->externalDirectory();

        if ($external !== null && (is_dir($external) || @mkdir($external, 0750, true))) {
            return $external;
        }

        return $this->basePath;
    }

    /** A path shown to the owner: relative to the home folder (what hPanel's File Manager shows), never absolute. */
    public function displayPath(string $path): string
    {
        if ($this->home !== null && str_starts_with($path, $this->home.'/')) {
            return substr($path, strlen($this->home) + 1);
        }

        $root = dirname($this->basePath);

        return str_starts_with($path, $root.'/') ? '(site folder)/'.substr($path, strlen($root) + 1) : basename($path);
    }

    /**
     * The site name used for the external folder, from the deploy folder (the repository root = parent of platform/):
     * domains/<domain>/public_html[/<sub>] → <domain>[-<sub>]; anything else → the path with "/" turned into "-".
     */
    public function siteKey(): string
    {
        $root = dirname($this->basePath);
        $relative = $this->home !== null && str_starts_with($root, $this->home.'/')
            ? substr($root, strlen($this->home) + 1)
            : ltrim($root, '/');

        if (preg_match('#^domains/([^/]+)/public_html(?:/(.+))?$#', $relative, $match) === 1) {
            $sub = isset($match[2]) ? trim(str_replace('/', '-', $match[2]), '-') : '';

            return self::clean($match[1].($sub !== '' ? '-'.$sub : ''));
        }

        return self::clean($relative);
    }

    /** Creates the storage folders Laravel needs (cheap is_dir checks when they exist). */
    public static function ensureStorage(string $storagePath): void
    {
        foreach (self::STORAGE_TREE as $folder) {
            if (! is_dir($storagePath.'/'.$folder)) {
                @mkdir($storagePath.'/'.$folder, 0775, true);
            }
        }
    }

    private static function clean(string $key): string
    {
        $key = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $key), '-.');

        return $key !== '' ? $key : 'site';
    }

    /**
     * The home folder must be an ancestor of the app and must not be (inside) a web folder. Sources, in order:
     * WOODEX_HOME (server variable, for unusual hosts), the account's passwd entry, $HOME, and /home/<user>/.
     */
    private static function detectHome(string $basePath): ?string
    {
        $candidates = [];

        foreach ([$_SERVER['WOODEX_HOME'] ?? null, $_ENV['WOODEX_HOME'] ?? null, getenv('WOODEX_HOME')] as $override) {
            if (is_string($override) && $override !== '') {
                $candidates[] = $override;
            }
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $entry = @posix_getpwuid(posix_geteuid());

            if (is_array($entry) && $entry['dir'] !== '') {
                $candidates[] = $entry['dir'];
            }
        }

        $env = getenv('HOME');

        if (is_string($env)) {
            $candidates[] = $env;
        }

        if (preg_match('#^(/home\d*/[^/]+)/#', $basePath, $match) === 1) {
            $candidates[] = $match[1];
        }

        foreach ($candidates as $candidate) {
            foreach (array_unique([rtrim($candidate, '/'), rtrim((string) realpath($candidate), '/')]) as $home) {
                if ($home === '' || ! str_starts_with($basePath, $home.'/')) {
                    continue;
                }

                if (preg_match('#/(public_html|www|htdocs)(/|$)#', $home) === 1) {
                    continue;
                }

                return $home;
            }
        }

        return null;
    }
}
