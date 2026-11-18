<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Public website (docs/09)
    |--------------------------------------------------------------------------
    | `cache` renders published pages once and serves them from the Laravel cache. Publishing touches the page,
    | which changes the cache key, so nothing needs flushing. Turn it off while editing content on a live site.
    */
    'cache' => (bool) env('WEBSITE_CACHE', true),

    /*
    |--------------------------------------------------------------------------
    | Page builder, Phase 2 (docs/audit/08 §3)
    |--------------------------------------------------------------------------
    | Each capability has its own switch, so one can be turned off without a deploy or a rollback of
    | the others. Off means the editor hides it and the server ignores it — the saved data stays.
    */
    'builder' => [
        // Per-device spacing: the CSS is only emitted while this is on.
        'responsive_styles' => (bool) env('WEBSITE_BUILDER_RESPONSIVE', true),
        // Multi-select and the section clipboard (copy here, paste on another page).
        'section_clipboard' => (bool) env('WEBSITE_BUILDER_CLIPBOARD', true),
        // Refuse a save when somebody else saved the page after the editor opened it.
        'conflict_check' => (bool) env('WEBSITE_BUILDER_CONFLICT_CHECK', true),
        // Say when an exported v1 file, not the CMS, answers the page's URL (W-01 interim).
        'shadow_notice' => (bool) env('WEBSITE_BUILDER_SHADOW_NOTICE', true),
    ],
];
