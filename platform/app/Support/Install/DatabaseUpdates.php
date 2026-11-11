<?php

declare(strict_types=1);

namespace App\Support\Install;

use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache as CacheFacade;
use RuntimeException;
use Throwable;

/**
 * Database changes that arrive with a deploy (D-58). Hostinger's Git deploy cannot run "php artisan migrate", so the
 * admin checks for new migrations and the owner applies them with one click (or "php artisan woodex:update" over SSH).
 *
 * The check is cached per set of migration files: a deploy that adds a migration changes the key, so the first admin
 * request after it looks again. New permissions and reference data ship as migrations too (AGENTS.md).
 */
class DatabaseUpdates
{
    public function __construct(private readonly Migrator $migrator, private readonly Cache $cache) {}

    /**
     * @return list<string> migration names that have not run yet
     */
    public function pending(): array
    {
        $files = $this->migrator->getMigrationFiles($this->paths());

        if (! $this->migrator->repositoryExists()) {
            return array_keys($files);
        }

        return array_values(array_diff(array_keys($files), $this->migrator->getRepository()->getRan()));
    }

    public function isCurrent(): bool
    {
        return (bool) $this->cache->remember($this->cacheKey(), now()->addHour(), fn (): bool => $this->pending() === []);
    }

    /**
     * Migrations, then the reference data that only ever adds what is missing (permissions, number
     * sequences, chart of accounts + payment-method ledger mappings). The chart seeder keys every
     * row by its stable code and never overwrites an owner's edit, so a database that already has
     * accounts is untouched — and one that got the accounts TABLES from an earlier one-click update
     * but never the seeded rows finally receives the defaults the payments module needs to post.
     *
     * Serialized by a lock: MySQL DDL is not transactional, so two update clicks racing each other
     * (the production incidents of 17 Oct) both pass the resumable-migration guards before either
     * commits its CREATE TABLE — the loser fatals with "table already exists" and can leave the
     * table created without its migrations row. One update runs at a time; a second click gets an
     * honest answer instead of a race.
     */
    public function apply(): string
    {
        $lock = null;
        $acquired = false;

        try {
            $lock = CacheFacade::lock('woodex:database-update', 600);
            $acquired = $lock->get();
        } catch (Throwable) {
            $lock = null; // A cache store without lock support must not block updates at all.
            $acquired = true;
        }

        if (! $acquired) {
            throw new RuntimeException('An update is already running — on shared hosting it can take a few minutes. Wait, then reload this page. Do not click Update again.');
        }

        try {
            return $this->run();
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
                // An expired lock releases itself; never let cleanup fail a finished update.
            }
        }
    }

    private function run(): string
    {
        @set_time_limit(600);
        ignore_user_abort(true);

        $output = [];
        Artisan::call('migrate', ['--force' => true]);
        $output[] = trim(Artisan::output());
        Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => NumberSequenceSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => ChartOfAccountsSeeder::class, '--force' => true]);
        $output[] = 'Permissions, number sequences and the chart of accounts checked.';

        // The media library learns about the theme's own images (`/assets/img/...`) the pages show. It adds only
        // what is missing, so every update may run it.
        Artisan::call('woodex:import-site-media');
        $output[] = trim(Artisan::output());

        $this->clearCaches();
        $output[] = 'Caches cleared.';

        $this->cache->forget($this->cacheKey());

        return implode("\n", array_filter($output));
    }

    /** Cached config, routes and views from the previous code would hide the new code. */
    protected function clearCaches(): void
    {
        Artisan::call('optimize:clear');
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return array_values(array_unique([...$this->migrator->paths(), database_path('migrations')]));
    }

    private function cacheKey(): string
    {
        $names = array_keys($this->migrator->getMigrationFiles($this->paths()));

        return 'woodex.schema-current.'.md5(implode('|', $names));
    }
}
