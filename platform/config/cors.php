<?php

/*
| Browser cross-origin access. Only the public JSON API is reachable from other
| origins (the current live site posts enquiries here during the bridge period,
| CAP-007). Admin, Livewire and webhooks are never CORS-enabled.
*/

return [

    'paths' => ['api/public/*', 'api/v1/leads', 'api/v1/leads/whatsapp-handoff', 'api/v1/quote-requests'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PUBLIC_LEADS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    // Idempotency-Key: the key of a create under /api/v1 (App\Support\Api\Idempotency).
    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-Woodex-Key', 'Idempotency-Key'],

    // The request number (App\Support\RequestNumber): a form on another site may read it and show it with its
    // own error message, so that a visitor can name the request.
    // Idempotent-Replayed says that a create was answered from its first sending; Retry-After, how long to wait.
    'exposed_headers' => ['X-Request-Id', 'Idempotent-Replayed', 'Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
