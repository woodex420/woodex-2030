<?php

declare(strict_types=1);

namespace App\Settings;

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Settings;

/**
 * Saved rate books for the public estimator (docs/audit/16.14).
 *
 * The live rate book is the file `/estimator/` loads (see RateBook); this is only the named snapshots the owner
 * could save before a price change, so an old set of rates can be brought back. Group `estimator`, one JSON
 * property, no new table.
 */
class EstimatorSettings extends Settings
{
    /**
     * name => snapshot. A snapshot is {at, services, finishes, terms}; catalog and terms of the quotation
     * builder are not part of it.
     *
     * @phpstan-var array<string, array{at: string, services: list<mixed>, finishes: list<mixed>, terms: list<mixed>}>
     */
    public array $rate_books = [];

    public static function group(): string
    {
        return 'estimator';
    }

    /**
     * Load the settings, creating the empty row the first time.
     *
     * spatie/laravel-settings refuses to save a property that has no row yet, and this repo seeds settings
     * from the screens rather than from migrations — so the first save on a fresh database needs the row to
     * exist. Returns the settings ready to use.
     */
    public static function shelf(): self
    {
        $table = (string) (config('settings.repositories.database.table') ?? 'settings');

        DB::table($table)->insertOrIgnore([
            'group' => self::group(),
            'name' => 'rate_books',
            'locked' => false,
            'payload' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return app(self::class);
    }
}
