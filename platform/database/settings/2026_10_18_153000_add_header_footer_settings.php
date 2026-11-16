<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * V2-3 structured header/footer editor (docs/14): the header button, the social profiles and the
 * footer's words and switches. Defaults keep today's footer exactly as it is — every column on.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('site.header_cta_label', null);
        $this->migrator->add('site.header_cta_url', null);
        $this->migrator->add('site.social_links', []);
        $this->migrator->add('site.footer_tagline', null);
        $this->migrator->add('site.footer_show_services', true);
        $this->migrator->add('site.footer_show_cities', true);
        $this->migrator->add('site.footer_show_contact', true);
        $this->migrator->add('site.footer_copyright', null);
    }

    public function down(): void
    {
        $this->migrator->delete('site.header_cta_label');
        $this->migrator->delete('site.header_cta_url');
        $this->migrator->delete('site.social_links');
        $this->migrator->delete('site.footer_tagline');
        $this->migrator->delete('site.footer_show_services');
        $this->migrator->delete('site.footer_show_cities');
        $this->migrator->delete('site.footer_show_contact');
        $this->migrator->delete('site.footer_copyright');
    }
};
