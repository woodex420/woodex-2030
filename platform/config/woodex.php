<?php

/*
| Woodex OS application configuration (infrastructure-level values only).
| Business rules that the owner can change (numbering, terms, SLA, hours,
| banks...) live in typed settings classes in app/Settings, not here.
*/

return [

    'admin' => [
        // Production: a dedicated admin host with an empty path. Local/CI: /admin on any host.
        'domain' => env('ADMIN_DOMAIN') ?: null,
        'path' => trim((string) env('ADMIN_PATH', 'admin'), '/'),
        // The sidebar in the light theme: "light" (white, the current screen filled green: the Reimagined
        // admin, decision DL-017) or "charcoal" (the dark sidebar of specification 05). Anything else is light.
        'sidebar_tone' => env('WOODEX_ADMIN_SIDEBAR_TONE', 'light') === 'charcoal' ? 'charcoal' : 'light',
    ],

    /*
    | The business lines this installation sells, first one first. Woodex Furniture sells one: furniture
    | (owner decision D-008: nothing of Woodex Interior in this product). The application came from the Interior
    | studio's, which sold three — interior, furniture, project; naming more than one brings back the choosers,
    | filters and labels that tell them apart. Read through App\Domain\Crm\Enums\BusinessLine::offered().
    */
    'business_lines' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WOODEX_BUSINESS_LINES', 'furniture')),
    ))),

    'public_site_url' => env('PUBLIC_SITE_URL', env('APP_URL', 'http://localhost')),

    // Full HTTPS base URL, including /dashboard where required. No shared authentication or data sync.
    'legacy_dashboard_url' => env('LEGACY_DASHBOARD_URL', ''),

    'security' => [
        'enforce_2fa' => (bool) env('ENFORCE_2FA', false),
        // Owners opt in to enrolment; confirmed authenticator sign-in remains enforced.
        'require_2fa_roles' => ['admin', 'accountant'],
        // X-Frame-Options value; empty disables the header (only for embedded previews).
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'SAMEORIGIN'),
        // Content-Security-Policy on every page (App\Support\ContentPolicy, master list T-068).
        // "enforce" is the default. "report" blocks nothing and only makes the browser write to its console what
        // it would have blocked: for finding a fault without breaking a screen. "off" sends no policy.
        'csp' => env('SECURITY_CSP', 'enforce'),
        // Extra origins allowed to frame staff PDFs (e.g. a hosted preview shell).
        'pdf_frame_ancestors' => array_values(array_filter(array_map('trim', explode(',', (string) env('SECURITY_PDF_FRAME_ANCESTORS', ''))))),
    ],

    'logging' => [
        // One line in the log for a request (App\Support\RequestLog, master list T-062): "errors" writes it for
        // every answer from 400 up (the default), "all" for every request, "off" for none. A fault is written to
        // the log by Laravel in any case. A refusal is written as a warning, a fault as an error, any other
        // answer as "info": with LOG_LEVEL=warning (what the installer writes) "all" adds nothing — set
        // LOG_LEVEL=info with it.
        'requests' => env('LOG_REQUESTS', 'errors'),
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

    // Approved category cutover. Disable to return a 503 and use the storefront fallback.
    'catalogue_source_image_root' => env('CATALOGUE_SOURCE_IMAGE_ROOT', base_path('../apps/api/public/img')),
    'category_sync_enabled' => (bool) env('CATEGORY_SYNC_ENABLED', true),
    // Round 1 of the product cutover (T-105). Off, the products address answers 503 and the shop keeps its own
    // list — which is what ships, because every one of the 170 real products is still a draft: a shop reading
    // only published products would show an empty catalogue. Turned on with PRODUCT_SYNC_ENABLED=1 once the
    // products are published through the admin's review (T-090) and the real admin is on Hostinger (T-133).
    // The categories' switch above is on, and stays on: that connection works and is proven.
    'product_sync_enabled' => (bool) env('PRODUCT_SYNC_ENABLED', false),

    'owner' => [
        'name' => env('OWNER_NAME'),
        'email' => env('OWNER_EMAIL'),
        'password' => env('OWNER_PASSWORD'),
    ],

];
