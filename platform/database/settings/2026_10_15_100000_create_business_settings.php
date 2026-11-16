<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Public business details. Values shown on the current website; P-01…P-03 still need owner confirmation.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('business.legal_name', 'Woodex Interior');
        $this->migrator->add('business.brand_name', 'Woodex Interior');
        $this->migrator->add('business.furniture_brand_name', 'Woodex Furniture');
        $this->migrator->add('business.tagline', 'Thoughtful interiors. Natural materials. Spaces that feel unmistakably yours.');
        $this->migrator->add('business.address', 'M-71 Zainab Tower, Model Town Link Road, Lahore');
        $this->migrator->add('business.city', 'Lahore');
        $this->migrator->add('business.phone', '+923224000768');
        $this->migrator->add('business.whatsapp', '+923224000768');
        $this->migrator->add('business.email', 'woodexinterior.pk@gmail.com');
        $this->migrator->add('business.website', 'https://woodex.com.pk');
        $this->migrator->add('business.ntn', null);
        $this->migrator->add('business.founded_year', 2016);
        $this->migrator->add('business.opens_at', '10:00');
        $this->migrator->add('business.closes_at', '19:00');
        $this->migrator->add('business.working_days', [1, 2, 3, 4, 5, 6]);
        $this->migrator->add('business.social_links', []);
    }
};
