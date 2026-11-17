<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * P4 part 2: what the studio hands over, and where a happy client should leave their review (PRJ-007).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sales.handover_checklist', [
            'Site cleaned and dusted',
            'Paint and polish touched up',
            'All fittings and fixtures checked',
            'Electrical and lighting tested',
            'Plumbing and sanitary fittings tested',
            'Doors, locks and handles adjusted',
            'Warranty cards and maintenance notes handed over',
            'Spare materials and paint left with the client',
            'Keys handed over',
            'Photographs of the finished space taken',
        ]);

        $this->migrator->add('sales.review_request_days', 3);
    }
};
