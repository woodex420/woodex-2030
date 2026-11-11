<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * V2-3 F9 (docs/14 §5a): the speed dashboard's settings. Google PageSpeed behind a key; the
 * internal checks (image weight, formats, page size, cache) always run with none.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('speed.google_api_key', null);
    }

    public function down(): void
    {
        $this->migrator->delete('speed.google_api_key');
    }
};
