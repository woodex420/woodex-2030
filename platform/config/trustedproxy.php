<?php

/*
| Proxies whose X-Forwarded-* headers are trusted (read by Laravel's TrustProxies middleware on each request).
| Empty on a plain Hostinger site. Behind Hostinger CDN or Cloudflare set TRUSTED_PROXIES=* so the real client IP
| (login rate limits) and https are seen. Never trust proxies that do not exist: clients could fake their IP.
*/

return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
