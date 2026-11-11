<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Install\DatabaseUpdates;
use Illuminate\Console\Command;

/**
 * After a deploy over SSH: the same steps as the admin's "Apply update" button (D-58).
 */
final class UpdateCommand extends Command
{
    protected $signature = 'woodex:update';

    protected $description = 'Apply database updates after a deploy: migrations, permissions, number sequences, cache clear.';

    public function handle(DatabaseUpdates $updates): int
    {
        $pending = $updates->pending();
        $this->components->info($pending === [] ? 'No pending migrations.' : count($pending).' pending migration(s).');
        $this->line($updates->apply());
        $this->components->info('Woodex OS is up to date.');

        return self::SUCCESS;
    }
}
