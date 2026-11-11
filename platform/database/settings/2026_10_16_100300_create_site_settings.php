<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Analytics for the public website (docs/09 §11.3, D-69). Empty by default: nothing is loaded until the owner
 * pastes the IDs into Website → Settings (P-14).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('site.ga4_id', null);
        $this->migrator->add('site.gtm_id', null);
        $this->migrator->add('site.google_site_verification', null);
        $this->migrator->add('site.clarity_id', null);
        $this->migrator->add('site.tracking_enabled', true);
        $this->migrator->add('site.conversion_events', [
            'lead_form_submit',
            'click_call',
            'click_whatsapp',
            'estimator_submit',
            'book_visit_submit',
            'quote_request',
        ]);
    }
};
