# WOODEX OS on Vercel

The Laravel admin (`platform/`) deployed as its own Vercel project, separate from the shop.
Decisions: DL-014 (deploy on Vercel), DL-015 (a demo until a database exists), DL-022 (the catalogue it shows is
Woodex's real one).

## 1. What is deployed

| | |
|---|---|
| Vercel project | `woodex-os` (team `woodexs-projects`), Root Directory `platform`, linked to this repository |
| Address | `https://woodex-os.vercel.app` (public). Addresses of single deployments ask for a Vercel login |
| Shop project | `woodex-2030`. Untouched: another project, another address, another database |
| Runtime | `vercel-php@0.9.0` (PHP 8.5), a community runtime. Vercel has no official PHP support |
| Mode today | **Preview workspace**: Woodex's real catalogue as drafts; sample customers, leads and documents (section 2) |

A push to a branch builds a preview of `woodex-os`. The project's production branch is `main`, and `main` holds
a `platform/` folder since pull request #36 was merged (17 November 2026, 10:22 UTC, merge commit `88ec4cc`;
DL-022 lifted DL-011): a push to `main` now updates production by itself. For any other commit, production is
updated on purpose (section 6).

## 2. The two modes

The entry point `platform/api/index.php` decides. Anything else answers `503 Admin service is not configured.`

### Demo — `WOODEX_DEMO=1`

For looking at the admin. It is not the business system.

- The deployment builds a small SQLite workspace (`composer run vercel` → `tools/vercel/build-demo.php`):
  all tables, the reference data, four staff accounts on the reserved domain `woodex.test`, ten contacts and ten
  leads named `SYNTHETIC` — and, since 17 November 2026, **Woodex's real catalogue**: the 170 products captured
  from woodexfurniture.pk with their codes, prices and categories, every one a draft. The build stops unless it
  counts 170 drafts and no published product. Their photographs are not copied: each product links to its
  photograph on woodexfurniture.pk (line T-089).
- Every screen carries a notice. With the catalogue loaded it reads "Preview workspace — real catalogue, sample
  records" and says that nothing typed is kept; without it, "Demo workspace — sample data". The sign-in page says
  "NOT PRODUCTION".
- It cannot use anything real. The entry empties every setting that names a server or holds a key (database,
  legacy database, mailbox, WhatsApp, Telegram, Google, Turnstile, AWS) before Laravel starts, also when the
  project carries such settings for another environment. Mail is written to the log.
- It encrypts with a key that the build makes for that deployment alone, never with the project's `APP_KEY`.
  With a shared key, a link signed in the demo would also be accepted by the real admin.
- **What is typed into it does not last.** Each running copy of the function has its own copy of the workspace
  in `/tmp`. Vercel starts and stops copies as it likes, so a record may disappear on the next click, and two
  visitors may see different data. Uploaded files behave the same way.
- Signing in does last: the session travels in an encrypted cookie, so a new copy still knows the visitor.
- The password is not shown on the sign-in page. `WOODEX_DEMO_SHOW_PASSWORD=1` shows the demo owner's password
  there, which opens the demo to everyone who has the address.

Settings in the Vercel project (Production and Preview): `WOODEX_DEMO=1`, and the four demo passwords
`WX_PW_OWNER`, `WX_PW_MANAGER`, `WX_PW_SALES`, `WX_PW_ACCOUNTS` (16 characters or more). No key and no
database setting is needed.

### Real — `APP_KEY` and a MySQL database

Not possible yet. Two things are missing:

1. **A MySQL database** that Vercel can reach (MySQL 8.0+ or MariaDB 10.6+). The shop's Supabase database is
   PostgreSQL and cannot be used; the application is written and tested for MySQL.
2. **A way to create the tables and the first owner account.** The web installer writes files (`.env`, the setup
   code, the owner details) and Vercel's file system is read-only, so the installer is closed on Vercel. The
   one-click "update the database" screen in the admin works there, but only after an owner can sign in.
   Options, to be chosen when the database exists: (a) a manually started GitHub workflow that runs
   `migrate`, the reference data and "create owner" against that database with secrets; (b) the same from a
   computer with PHP. Neither is built.

Also true for the real mode on Vercel, by the platform's nature:

- **Uploads do not persist** (product photos, documents, the media library). They need external file storage.
- **Nothing runs on a schedule.** The queue, reminders and the heartbeat need a cron caller; none is configured.
- A request may take a few seconds when Vercel starts a new copy.

Hostinger is the production host: MySQL on shared hosting, by the owner's own decision log (D-004, D-005;
DL-020). A real admin on Vercel is therefore **not planned**; this section is kept as the record of what it would
take. Vercel is where the admin can be seen.

## 3. How a request is served

```
visitor → Vercel → routes of vercel.json
            ├─ a file of public/ (build, assets/*, builder, fonts, images, sounds, favicon) → static file
            └─ everything else → the function: Vercel's launcher → PHP's built-in server → api/index.php → Laravel
```

- Laravel's storage is `/tmp/woodex-livewire/storage`; nothing is written into the application folder.
- The site counts as configured without a `.env` file (`SiteState::configuredByServer()`): the entry sets
  `WOODEX_CONFIG_FROM_SERVER=1` after it has checked the settings, and the installer answers 404.
- `TRUSTED_PROXIES=*` in `vercel.json`: the function is reachable only through Vercel, which sets the scheme and
  the visitor's address. Without it links and the stylesheet were built with `http`.
- PHP files and names that start with a dot are never served as static files; they go to Laravel, which has no
  such page.

## 4. What was wrong before, found by rehearsing with Vercel's own parts

| Finding | Effect on Vercel | Fix |
|---|---|---|
| The entry set the storage path with `putenv()`; Laravel reads it from `$_ENV`/`$_SERVER` only | Storage stayed in the read-only application folder | Written to all three |
| No `.env` on Vercel, so every page went to the installer, which cannot write there | The admin could never open, with or without a database | `SiteState::configuredByServer()` |
| The forwarded scheme was not trusted | Links and stylesheet with `http` on an `https` page | `TRUSTED_PROXIES=*` |
| Only `/build/*` and `/favicon.ico` were routed to static files | `/favicon.svg` and every other file of `public/` answered 404 | Routes for every folder of `public/`, checked by a test |
| The sample records were made with model factories, which need a development package | The demo could not be built with `--no-dev` | Records written out in full |
| The Health page gave Hostinger advice and a green tick for the uploads folder | Wrong on a host without a disk | It says that nothing written there lasts |
| The command palette offered "Canned replies" and "WhatsApp templates" while their modules were off | A "not found" page, on every host | The palette checks the module switch, as the sidebar does |
| `platform/.vercelignore` | Vercel reported "Removed 0 ignored files" for a Git deployment with a Root Directory | Kept for uploads by the Vercel CLI; nothing relies on it |
| PHP's own server finds the entry in a folder named `api`, and announced every request below `/api` as the script `/api/index.php` with the rest behind it. **Not found by the rehearsal, which did not ask:** found from outside on 19 November (defect D-041) | Everything under `/api` answered the admin's "This page does not exist": the enquiry address and all of `/api/v1` | The entry announces itself as `/index.php`, the front controller it stands in for. Three addresses under `/api` are among the checks of §5, and the live check asks two more |

The runtime's `php.ini` keeps compiled scripts in `/tmp` and never reads a changed file again
(`opcache.file_cache=/tmp`, `validate_timestamps=0`). Within one copy of the function no PHP file changes, so
this is harmless there. It matters to anyone who runs that PHP elsewhere: clear `/tmp/<32 hex digits>` or
switch the cache off, or an earlier run's code is served.

## 5. How it is checked

`platform/tools/vercel/check-entry.sh`, in CI as the job "Vercel's PHP runtime: build the demo, sign in, open
every screen" (workflow `vercel-livewire.yml`), on every change under `platform/`.

It uses Vercel's own parts, fetched from npm: the PHP 8.5 runtime of `vercel-php@0.9.0` with its `php.ini`, and
its launcher. It installs the packages with the builder's own Composer command, runs `composer run vercel`, makes
the tree read-only, and serves it from `/var/task` through the `routes` and `env` of `vercel.json`. Only
Vercel's network is replaced, by `tools/vercel/launcher-stand-in.cjs`. 84 checks, among them:

- without settings, with half the settings, or with a database that is not MySQL: 503;
- a real deployment's build touches nothing; a demo without passwords fails the build;
- no file of the application is handed out (database, key, `composer.json`, PHP source, dot-files);
- a wrong password is refused; the demo owner signs in; **every screen the dashboard links to opens**
  (50 screens: the sidebar, the command palette, the dashboard's own links);
- every stylesheet, script, icon and font those pages load is served;
- the longest cookie is under 1 KB (a browser keeps at most 4 KB);
- the Health page says that this host keeps no files;
- a new copy of the function keeps the visitor signed in; signing out works;
- every real setting is empty inside the demo, and its key is its own.

Each rule was also broken on purpose once, to see the check fail: site not marked as configured; storage path
by `putenv()`; real settings not emptied; the project's key used; `TRUSTED_PROXIES` removed.

```
npm install --prefix /tmp/vercel-php vercel-php@0.9.0
cd platform            # a tree with vendor/ and without .env
VERCEL_PHP=/tmp/vercel-php/node_modules bash tools/vercel/check-entry.sh [--build] [--read-only]
```

`scripts/test-vercel-livewire-root.mjs` (workflow `ci.yml`) checks the files themselves: both copies of the
entry are identical, no secret and no demo switch is in the repository, and every file in `platform/public` has
a route.

**The same demo, kept running, for a browser.** `tools/vercel/serve-demo.sh [port]` builds the demo and serves it
through the same parts until stopped. Two scripts in the repository's `scripts/` folder use it:

- `os-screens.mjs` signs in as each demo role and opens every screen it can reach, on a desktop and then on a
  390 px phone: status, console errors, links that lead nowhere, fields without a label, controls smaller than
  24 px, anything wider than the window, page size and time.
- `os-journeys.mjs` presses through two journeys, 28 steps that write records. A working day: a lead, a task,
  the search, a draft quotation with a priced line, two PDFs, the CSV export, an upload, a product of the real
  catalogue, the 404 and 403 pages, sign-out. The sale, by the people who do each part: an enquiry handed to a
  sales person, a quotation above the discount limit, its approval, sending, the client's yes, the invoice, the
  payment, the receipt. It refuses to run against a page without the sample-data notice. `OS_STRICT=1` makes a
  step that is left out a failure; `WOODEX_MODULES=lead_api` before `serve-demo.sh` rehearses the product as it
  ships, with the client's own pages off.

```
cd platform && WX_PW_OWNER=… WX_PW_MANAGER=… WX_PW_SALES=… WX_PW_ACCOUNTS=… \
  VERCEL_PHP=/tmp/vercel-php/node_modules bash tools/vercel/serve-demo.sh 8191
node scripts/os-screens.mjs --out /tmp/screens      # same four WX_PW_* values; OS_BASE defaults to :8191
node scripts/os-journeys.mjs --out /tmp/journeys    # WX_PW_OWNER, WX_PW_SALES
```

**What this cannot show:** Vercel's own network and build machines. After a deployment, read the build log for
the `[demo] workspace ready` line, and run the workflow "Vercel live check (WOODEX OS)"
(`vercel-live-check.yml`): from outside, without any secret, it opens the sign-in page of the deployed address,
tries a wrong password, reads the security headers (the content policy among them) and the marks on the session
cookie, asks for a screen that does not exist (the admin's own 404 page, with the request number that the
answer's `X-Request-Id` header carries) and sends a form without its token (419), asks for files that
must stay closed, and asks two addresses of `/api/v1`: one that is not there (the API's own error shape, with the
request number in the body and the header alike, and no cookie) and the list of replaced photographs (a whole
list with its size). Its findings are one notice on the run. It never signs in: a signed-in check of the live
address needs the demo password as a GitHub secret, which only the owner can add (line T-151 of
[`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md)).

## 6. Deploying

- A push to any branch builds a preview (`woodex-os-git-<branch>-woodexs-projects.vercel.app`, Vercel login
  needed).
- A push to `main` updates production (section 1). Eight minutes later the
  workflow "Live check after a merge" asks the deployed address, and the React shop, from outside
  (`live-check-after-merge.yml`).
- Production (`woodex-os.vercel.app`) is updated by creating a production deployment of a chosen commit, in
  Vercel's dashboard ("Promote to Production" on a preview) or through the API.
- A change of a project setting takes effect at the next deployment.
- The second way to deploy, a package built by `scripts/package-vercel-livewire.mjs` and uploaded with the Vercel
  CLI, switches Composer scripts off, so it cannot build a demo. It is kept for a real deployment.

## 7. Sizes

The function holds 226 MB of files: Vercel's PHP runtime 153 MB, the application 74 MB (packages 56 MB,
`public/` 8 MB, tests and tools 1.7 MB). The platform's limit is 250 MB. `tests/` and `tools/` could be left out
with the builder's `excludeFiles` option; that is not done, because it could not be rehearsed.
