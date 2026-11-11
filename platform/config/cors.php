<?php

/*
| Browser cross-origin access. Only the public JSON API is reachable from other
| origins (the current live site posts enquiries here during the bridge period,
| CAP-007). Admin, Livewire and webhooks are never CORS-enabled.
*/

return [

    'paths' => ['api/public/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PUBLIC_LEADS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-Woodex-Key'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
