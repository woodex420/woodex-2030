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
- **Cookies kept on the server for proxied requests.** Some preview proxies remove the `Cookie` and `Set-Cookie` headers. For requests that arrive through such a proxy, `front.php` remembers the cookies on the server instead. Sign-in, password, CSRF and permission checks are unchanged, but **everyone who can open the preview address then shares one session** until sign-out or two idle hours. The proxy's access header is never stored or logged; a one-way hash of it names the cookie file.

Both are acceptable only because the database is synthetic and disposable.

## Never

- Never point this at a real database or a database copy.
- Never use `front.php` as a real site's entry point.
- Never add real customers, prices or credentials to the fixtures.

## In a sandbox that can reach only GitHub and npm

Measured in the agent sandbox used for this work: Packagist, getcomposer.org, php.net, Debian mirrors, container registries and GitHub release downloads are all blocked. PHP and Composer still arrive through the GitHub API. Keep everything outside the git tree.

```
# PHP 8.4 CLI, static build (NativePHP/php-bin @ c4d0b4ad6df1f0fef196c22b2cac0d26b4658b38, bin/linux/x64/php-8.4.zip)
gh api repos/NativePHP/php-bin/git/blobs/cc2591752b49bca8c73b897b5c933cb8122ab5d8 \
   -H "Accept: application/vnd.github.raw" > php-8.4-linux-x64.zip
echo "7d6f8623d6fb3600c01b81a92249558a9f84a042f407c947158ec3f96f0337bd  php-8.4-linux-x64.zip" | sha256sum -c -

# Composer 2.11.2, run from source with the packages in its own lock file
python3 tools/preview/sandbox/bootstrap_composer.py <dir> 2.11.2

# The locked application packages; every download is an api.github.com archive at the locked commit
COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$(gh auth token)\"}}" \
  php <dir>/composer-src/bin/composer install --no-interaction --prefer-dist --no-scripts \
  --ignore-platform-req=ext-exif --ignore-platform-req=ext-xmlwriter
php artisan package:discover
```

That PHP build has no `pdo_mysql`, `exif` or `xmlwriter`. Consequences: SQLite only; nine tests fail there that pass in CI (two installer tests that need MySQL, seven that draw a two-factor QR code). MySQL evidence comes from the `os-ci.yml` workflow.

### Running the test suite there

```
WOODEX_PHP=<php> sh tools/preview/sandbox/run-suite.sh <out-dir>          # five groups, SQLite in memory, about five minutes
WOODEX_PHP=<php> python3 tools/preview/sandbox/suite-summary.py <out-dir> # totals, and every failure with its cause
```

Read a run **by cause, not by name**. The summary excuses a failure only when its own output shows one of the two missing extensions, and only while the PHP binary really lacks it. Anything else is printed as `UNEXPLAINED` and the exit code is 1.
The reason: on 2026-11-11 a test that already failed here for the missing `xmlwriter` kept failing after a change on this branch, for a new reason. The list of failing names did not change, and only the MySQL run in CI caught it.

Remember what this cannot show: the nine excused tests are not tested here at all. Only CI tests them.

A real browser is available too: `npm install @sparticuz/chromium puppeteer-core` ships a headless Chromium inside the npm package, and it runs with `AWS_EXECUTION_ENV=AWS_Lambda_nodejs22.x` set so that its bundled libraries are unpacked.
