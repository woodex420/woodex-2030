<?php

/*
| Woodex OS application configuration (infrastructure-level values only).
| Business rules that the owner can change (numbering, terms, SLA, hours,
| banks...) live in typed settings classes in app/Settings, not here.
*/

return [

    'admin' => [
        // Production: admin.woodex.com.pk with an empty path. Local/CI: /admin on any host.
        'domain' => env('ADMIN_DOMAIN') ?: null,
        'path' => trim((string) env('ADMIN_PATH', 'admin'), '/'),
    ],

    'public_site_url' => env('PUBLIC_SITE_URL', 'https://woodex.com.pk'),

    'security' => [
        'enforce_2fa' => (bool) env('ENFORCE_2FA', false),
        // Owners opt in to enrolment; confirmed authenticator sign-in remains enforced.
        'require_2fa_roles' => ['admin', 'accountant'],
        // X-Frame-Options value; empty disables the header (only for embedded previews).
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'SAMEORIGIN'),
        // Extra origins allowed to frame staff PDFs (e.g. a hosted preview shell).
        'pdf_frame_ancestors' => array_values(array_filter(array_map('trim', explode(',', (string) env('SECURITY_PDF_FRAME_ANCESTORS', ''))))),
    ],

    'public_leads' => [
        // Same list is used by config/cors.php for browser requests.
        'allowed_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PUBLIC_LEADS_ALLOWED_ORIGINS', '')),
        ))),
        // Server-to-server key for the live-site bridge (CAP-007). Header: X-Woodex-Key.
        'bridge_key' => env('PUBLIC_LEADS_BRIDGE_KEY'),
        // Honeypot field name + minimum seconds between form render and submit (CAP-002).
        'honeypot_field' => 'company_website',
        'min_fill_seconds' => 3,
        // A new enquiry from the same contact + line within this window joins the open lead (CAP-003).
        'merge_window_days' => 30,
    ],

    'owner' => [
        'name' => env('OWNER_NAME'),
        'email' => env('OWNER_EMAIL'),
        'password' => env('OWNER_PASSWORD'),
    ],

];
