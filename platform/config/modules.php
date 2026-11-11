<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Optional modules
|--------------------------------------------------------------------------
| WOODEX OS was built for the Interior studio. The Furniture copy starts with the core only — CRM, quotations,
| invoices, payments, accounts, projects, reports, users and platform tools — and every module below switched
| off until the owner approves it and its provider credentials, wording or data exist.
|
| A switched-off module is closed at the door: its routes answer 404 before a session is started, a record is
| looked up or a handler runs; its screens leave the menu and the search; its scheduled commands do not run.
| The routes stay registered because core screens build links to them (a sent quotation carries a client link).
|
| WOODEX_MODULES is a comma-separated list of the keys below, or * for all of them. The test suite runs with *.
*/

return [

    'available' => [
        'lead_api' => 'Public enquiry endpoint: POST /api/public/leads',
        'website' => 'Public website, CMS page router, theme stylesheets and the Website admin screens',
        'estimator' => 'Estimations and the estimator rate book',
        'client_portal' => 'Signed client pages for quotations, invoices and projects, and the project portal',
        'inbox' => 'Conversations inbox, canned replies and the website chat widget',
        'automation' => 'Automations and message sequences',
        'marketing' => 'Marketing planner, social agent and social publisher',
        'whatsapp' => 'WhatsApp Cloud webhook, templates and delivery checks',
        'telegram' => 'Telegram webhook and bot delivery',
        'google' => 'Google sign-in and Workspace connection',
        'mcp_bridge' => 'MCP endpoint and the read-only bridge API',
        'legacy_bridge' => 'forms.php / chat.php bridge that served the old Interior website',
    ],

    'enabled' => env('WOODEX_MODULES', 'lead_api'),

];
