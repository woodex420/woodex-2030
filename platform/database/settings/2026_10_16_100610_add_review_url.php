<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * P4 part 2: the Google review link a client is asked to use three days after handover (PRJ-007).
 * The owner pastes their own place link in Settings -> Business; while it is empty no review email is sent.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('business.google_review_url', '');
    }
};
