# Synthetic preview tooling

Development tools for looking at the admin with **fictional** data. Nothing here is loaded by a production entry point.

| File | What it does |
|---|---|
| `front.php` | Front controller for PHP's built-in web server. Refuses to serve unless the application is local and on a SQLite file named `woodex-synthetic-*` |
| `NativePreviewTransport.php` | Copied unchanged from the source project's `tools/sandbox/`. Makes self-URLs root-relative so navigation stays on the preview host |
| `synthetic_fixtures.php` | Creates four `@woodex.test` staff accounts, a placeholder business identity, and contacts, leads and catalogue lines all named `SYNTHETIC` |
| `preview.env.example` | The environment a preview runs with: SQLite, mail written to the log, no provider keys |

## Run one

From `platform/`, with PHP 8.4 and the locked dependencies installed:

```
cp tools/preview/preview.env.example .env        # then set DB_DATABASE to an absolute path
php artisan key:generate
touch database/woodex-synthetic-furniture-preview.sqlite
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
WX_PW_OWNER=... WX_PW_MANAGER=... WX_PW_SALES=... WX_PW_ACCOUNTS=... php tools/preview/synthetic_fixtures.php staff
php tools/preview/synthetic_fixtures.php data
php -S 0.0.0.0:8080 -t public tools/preview/front.php
```

Generate the four passwords yourself (16 characters or more). They are never written to the repository.

## What is relaxed, and why

- **Frame headers.** `SECURITY_FRAME_OPTIONS` is empty and PDFs allow any `https` frame ancestor, because a hosted preview shows the application inside a frame on another site.
- **Cookies kept on the server for proxied requests.** Some preview proxies remove the `Cookie` and `Set-Cookie` headers. For requests that arrive through such a proxy, `front.php` remembers the cookies on the server instead. Sign-in, password, CSRF and permission checks are unchanged, but **everyone who can open the preview address then shares one session** until sign-out or two idle hours.

Both are acceptable only because the database is synthetic and disposable.

## Never

- Never point this at a real database or a database copy.
- Never use `front.php` as a real site's entry point.
- Never add real customers, prices or credentials to the fixtures.
