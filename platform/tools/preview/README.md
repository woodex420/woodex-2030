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

**The short way, about one minute:** `bash platform/tools/preview/sandbox/setup.sh`. It builds everything described
below outside the repository (default `~/.cache/woodex-os`): the complete PHP from npm, Composer, two work trees
with the locked packages, and four small commands — `bin/wxsync` (copy the tree after an edit), `bin/wxtest` (Pest
with the settings CI uses), `bin/wxrehearse` (serve the demo as Vercel does) and `bin/wxenv` (the rehearsal's
passwords, made on that machine, and the browser for `scripts/os-journeys.mjs` and `scripts/os-screens.mjs`). Its
first lines say what it leaves and how to run the suite and the code style. The rest of this section is what it
does, step by step, and why.

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

CI has no `platform/.env`; a sandbox with a running preview has one. A test that replaces the site fixture can
pass here and fail there (it happened on 2026-11-16: 200 here, a redirect to the installer in CI). To run a
changed test as CI does: `cp -al <runtime> /tmp/ci-like && rm /tmp/ci-like/.env`, then run it in that copy.

Remember what this cannot show: the nine excused tests are not tested here at all. Only CI tests them.

### A complete PHP, from npm

The nine excused tests do run in such a sandbox with the PHP that Vercel's builder ships. It is PHP 8.5 with
`pdo_mysql`, `exif`, `xmlwriter` and `gd`, built for AlmaLinux 9, and it runs on Debian and Ubuntu:

```
npm install --prefix <dir> vercel-php@0.9.0
N=<dir>/node_modules/@libphp/almalinux-9-v85/native
LD_LIBRARY_PATH="$N/lib:/usr/lib64:/lib64" PHP_INI_SCAN_DIR=":<dir>/node_modules/vercel-php/conf" \
  PHP_INI_EXTENSION_DIR="$N/php/modules" "$N/php/php" -d opcache.enable=0 -d opcache.enable_cli=0 -d memory_limit=1024M ...
```

Put that in a small script and pass it as `WOODEX_PHP`. Keep the two `opcache` switches: this PHP's own
`php.ini` stores compiled scripts in `/tmp` and never reads a changed file again, so an edited test would keep
running in its old form. Measured on 2026-11-16: the whole suite passes with it (2198 passed, 34 skipped,
none failed), with nothing excused.
MySQL itself is still only in CI: the two installer tests need the driver, not a server.

### A database server, from GitHub: two writers at once

SQLite lets one writer in at a time. What two people do to the books when they write at the same moment shows
only on MySQL or MariaDB, and that is what `tools/ci/two-writers.php` tries (master list T-093). CI runs it on
MySQL 8.4, MariaDB 10.11 and MariaDB 11.4, and that run is the proof. To see a fault in seconds instead of
waiting for CI, one server can be had in such a sandbox: MariaDB 10.2.11, kept as a file in the Git tree of the
MariaDB4j project. It asks for `libaio`, which cannot be installed there; the script compiles a stand-in.

```
bash platform/tools/preview/sandbox/database.sh            # once, a few seconds: ~/.cache/woodex-os/mariadb
bash platform/tools/preview/sandbox/database.sh --serve    # as a background process: 127.0.0.1:33106
bash platform/tools/preview/sandbox/database.sh --create   # the database woodex_test and bin/wxdb
cd ~/.cache/woodex-os/work/platform && . ../../bin/wxdb
../../php85 tools/ci/two-writers.php --runs=10              # --list names the steps, --only=a,b picks some
```

Three things cost an hour each when this was built, on 2026-11-18:

- **It is an old server.** It deadlocks where a newer one may only wait (a writer that holds one row and then
  asks for the rows around it) and it needs `--explicit-defaults-for-timestamp=1` to take the migrations. A step
  that holds here and fails in CI, or the other way round, is to be expected; CI decides.
- **The writers are started with PHP's own binary, not with the small script.** Without `-d opcache.enable_cli=0`
  they ran the files as they were BEFORE an edit (see "Keep the two opcache switches" above): a repair seemed to
  change nothing. The command passes the switch itself now.
- **Read a deadlock, do not guess it.** `SHOW ENGINE INNODB STATUS` as root prints, under LATEST DETECTED
  DEADLOCK, the two statements, the lock each one holds and the lock each one waits for. `SET GLOBAL general_log`
  with `log_output=FILE` shows the order in which each writer really sent its statements.

A real browser is available too: `npm install @sparticuz/chromium puppeteer-core` ships a headless Chromium inside the npm package, and it runs with `AWS_EXECUTION_ENV=AWS_Lambda_nodejs22.x` set so that its bundled libraries are unpacked.

## Looking at every screen, and pressing through the main jobs

Three tools, written for the analysis of the hosted demo (`docs/woodex-os/14-PRODUCTION-TODO.md` §17). They need
Node, Playwright's Chromium (or `E2E_CHROMIUM_PATH`), and the demo passwords in `WX_PW_*`; none of them is ever
written to a file.

| Tool | What it does |
|---|---|
| `tools/vercel/serve-demo.sh [port]` | Builds the sample-data workspace and serves it exactly as Vercel does (its PHP runtime, its launcher, `vercel.json`), on `127.0.0.1:8191` by default, until stopped |
| `scripts/os-screens.mjs` (repository root) | Signs in as each demo role and opens every screen it can reach, on a desktop and on a 390 px phone. Reports per role: pages not fine, links that lead nowhere, fields without a label, controls under 24 px, pages wider than the window, the height of the top bar, sizes and times. Exit code 1 if a page or a download is not fine. `OS_BASE`, `OS_ROLES`, `OS_MAX_PAGES`, `--out`, `--shots` |
| `scripts/os-journeys.mjs` (repository root) | Fourteen steps that **write** records (a lead, a task, a draft quotation…). Refuses a page without the sample-data notice unless `OS_ALLOW_REAL=1`. `OS_TRACE=1` prints every request with its time |

A crawl of the four roles takes about five and a half minutes; the journeys about twenty seconds.

