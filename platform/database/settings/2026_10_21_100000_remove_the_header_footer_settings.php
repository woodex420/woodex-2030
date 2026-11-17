<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The header/footer editor's settings are retired (owner, 21 Oct 2026).
 *
 * They were read by `layouts/site.blade.php`, the CMS layout that drew a page from its database row. That layout
 * and the renderer behind it are gone: every page is a file under `public/` and carries its own header and
 * footer, which the page builder edits. The fields stayed on the screen for a day, labelled "no longer reaches
 * the site"; now they are gone from the screen and from the store, so nothing pretends to drive the site.
 *
 * The values are deleted, not moved: they described a layout that no longer exists, and a footer the owner
 * edited there was already not on any page. The header and footer themselves are in the 142 page files.
 */
return new class extends SettingsMigration
{
    /** @var list<string> */
    private const array KEYS = [
        'header_cta_label',
        'header_cta_url',
        'social_links',
        'footer_tagline',
        'footer_show_services',
        'footer_show_cities',
        'footer_show_contact',
        'footer_copyright',
    ];

    public function up(): void
    {
        foreach (self::KEYS as $key) {
            $this->migrator->deleteIfExists('site.'.$key);
        }
    }

    public function down(): void
    {
        // Nothing to restore: the layout that read these values no longer exists, and a footer edited there was
        // never on a page. The words live in the page files.
    }
};
