<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Commerce\Services\WoodexCatalogue;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Loads the real Woodex catalogue (170 products of woodexfurniture.pk) as drafts, for a server without a browser
 * at hand: the same import as the button on Commerce → Products → Import. Safe to run again.
 */
final class ImportWoodexCatalogue extends Command
{
    protected $signature = 'commerce:import-catalogue {--as= : E-mail address of the member of staff the import is recorded under}';

    protected $description = 'Load the Woodex catalogue as draft products. Nothing is published or overwritten.';

    public function handle(WoodexCatalogue $catalogue): int
    {
        $email = (string) $this->option('as');
        $user = $email !== '' ? User::query()->where('email', $email)->first() : null;

        if ($user === null) {
            $this->error('Name the member of staff to record the import under: --as=<e-mail address>.');

            return self::FAILURE;
        }
        if (! $user->can('commerce.catalog.manage')) {
            $this->error($email.' may not manage the catalogue.');

            return self::FAILURE;
        }

        $summary = $catalogue->summary();
        $added = $catalogue->import($user);

        $this->info(sprintf(
            'Woodex catalogue (%s, captured %s): %d products in the file, %d added as drafts, %d already there; %d categories added, switched off.',
            $summary['site'], $summary['captured_on'], $summary['total'], $added['products'], $summary['total'] - $summary['new'], $added['categories'],
        ));

        return self::SUCCESS;
    }
}
