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
| Mode today | **Preview workspace**: Woodex's real catalogue as drafts; sample customers, leads and documents (section 2). Since 21 November 2026 **every module is on there**, and Feature status links to the old dashboard (section 2, "What the project sets") |

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

#### What the project sets beside them, since 21 November 2026

Two more settings stand on the project. They are written in one file of the repository,
`platform/tools/vercel/as-deployed.conf`, and three checks read that file (section 5).

| Setting | Value | What it does in the demo |
|---|---|---|
| `WOODEX_MODULES` | `*` | Every module is on. Without it the demo keeps its own choice: the enquiry address and the client's own pages (DL-026) |
| `LEGACY_DASHBOARD_URL` | `https://woodex-2030.vercel.app/dashboard` | Feature status links its 13 entries for the old dashboard to that dashboard, each in a new tab. The two do not share a sign-in |

**Where they came from.** Read from Vercel's own record of activity on 21 November at 12:26 UTC: both were added
that morning with an access token of the account `woodex420`, `WOODEX_MODULES` at 07:32 UTC and
`LEGACY_DASHBOARD_URL` at 07:50 UTC, for production and preview, each followed within seconds by a new deployment
of production from the same commit (`86db34d`). At 07:12 UTC the demo owner's password had been read with the same
token. No commit asked for any of it, and the agent's conversation made no write on Vercel. The agent took both
settings for the owner's and changed neither (DL-045); he is asked to say that they were his.

**What a visitor of the demo gets since then**, as read on the rehearsal with these settings:

- Feature status says 52 available here, 13 in the old dashboard, none switched off, 3 not built. Before: 32, 13,
  20 and 3. The 20 that came on are the modules the product ships switched off: Estimations, Conversations and
  Chat Bot, Automations, Sequences and WhatsApp Templates, Marketing, and thirteen screens of the Website Studio.
- **The thirteen screens of the Website Studio run a website that Laravel itself would serve. This product has
  none**: the shop is the React application (document 25 §5). They open, and they are empty. The page builder
  says "This site has no pages yet".
- These addresses answer now, where they were "not found": `/robots.txt` (it allows everything but the admin and
  the client's links, and names a site map), `/sitemap.xml` (empty), `/site/tags.js`, `/assets/theme.css`,
  `/chat/widget.js` (it says that the web chat is switched off), and the old website's form address
  `/api/forms.php`, which takes an enquiry into the demo's records, as `/api/v1/leads` did before. Every page
  still says that it is to be kept out of search engines: the admin's in the answer's header, the client's code
  page and the "not found" page in the page itself.
- These answer and refuse: the address for an AI assistant, `/api/mcp` (401 without a key; a key can only be
  issued by a person who is signed in, and the demo's records hold none), `/api/bridge/v1` (401), the WhatsApp
  webhook (403), the Telegram webhook and the web chat's start (404). Staff sign-in with Google sends the
  visitor back to the sign-in page: no Google application is set, and the demo empties every key before Laravel
  starts.
- **With Conversations on, every screen asks the server again every 10 seconds while it is in front.** It was
  every 30 seconds. On Vercel each asking is one more request for the function to answer.
- Four screens of the Website Studio draw their own page and do not carry the notice that nothing typed is kept:
  the page builder, Blog, Portfolio and Forms.

**What it brought to light, cured with pull request #55.** With the public website on, its router owns every
address that nothing else does. Of the 18 files of the application that the rehearsal asks by name, it sent
eleven to the same address with a slash at its end (301) before it said "not found", `/.env` and the demo's
database file among them. Nothing was handed out; the outside check asks three of the eleven and failed on them
at 11:49 UTC (issue #54). A file's name is now left alone: it is not found at once. The crawl
with every module on found two faulty screens of the Website Studio, both cured: a dead link on its Forms screen,
and the page builder, which asked for a page that is not there and was wider than a phone's window.

**To take the settings back**: delete the two settings on the project, deploy production again, and take the two
lines out of `platform/tools/vercel/as-deployed.conf`. Until the file follows, the outside check fails and says
why.

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
            │     └─ a built file with a marked name (build/assets/<name>-<8 characters>.<ext>) → kept one year
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
Vercel's network is replaced, by `tools/vercel/launcher-stand-in.cjs`. 130 checks (21 November), among them:

- without settings, with half the settings, or with a database that is not MySQL: 503;
- a real deployment's build touches nothing; a demo without passwords fails the build;
- no file of the application is handed out (database, key, `composer.json`, PHP source, dot-files): 18 files
  asked by name, each "not found" or "forbidden". A redirect counts as a failure;
- **the same 18 files again with what the project on Vercel sets** (`tools/vercel/as-deployed.conf`, section 7
  of the script): with every module on, an address that could be a page is still sent to its form with a slash,
  and a file's name is not, with `?preview=1` either;
- a wrong password is refused; the demo owner signs in; **every screen the dashboard links to opens**
  (50 screens: the sidebar, the command palette, the dashboard's own links);
- every stylesheet, script, icon and font those pages load is served;
- the longest cookie is under 1 KB (a browser keeps at most 4 KB);
- the Health page says that this host keeps no files;
- a new copy of the function keeps the visitor signed in; signing out works;
- every real setting is empty inside the demo, and its key is its own;
- the function is smaller than 240 MB, and the same measurement fails at a limit 1 MB below today's size
  (section 7);
- the eight built files with a marked name are kept one year, and nothing else is (section 8);
- the first answer of a new copy of the function carries the cold-start mark, the second and third do not
  (section 9).

Each rule was also broken on purpose once, to see the check fail: site not marked as configured; storage path
by `putenv()`; real settings not emptied; the project's key used; `TRUSTED_PROXIES` removed; the one-year rule
taken out; the one-year rule widened to every built file; the cold-start mark taken out; the mark sent on every
answer; the website's router as it was until 21 November (12 checks fail: eleven files answer 301, and so does
`/.env?preview=1`).

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

**CI runs both scripts on the demo as it is deployed**: `serve-demo.sh` is started with the settings of
`tools/vercel/as-deployed.conf` (`set -a; . tools/vercel/as-deployed.conf; set +a`). Since 21 November that is
every module on: 390 pages as four roles, where the demo's own choice of modules gave 335. The journeys are then
pressed once more on the product as it ships.

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

**It also asks whether the live demo runs with the modules that were rehearsed** (since 21 November, DL-045). It
reads `as-deployed.conf` and asks the live site for an address that nothing owns and that could be a page. With
the public website on, the answer is a redirect to the same address with a slash; with it off, "not found". When
the answer and the file disagree, the check fails and names the file. That is the one module it can tell from
outside, and it is the one that changes what an unknown address gets.

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

**The function holds 188.4 MB in 11,220 files; Vercel's limit is 250 MB.** Measured on 20 November 2026 by the
rehearsal in CI (`cc9b695`), on the packages as Vercel's builder installs them (without the development
packages). Before the packages were brought up to date the same day it was 187.8 MB in 11,190 files:

| Part | MB |
|---|---|
| Vercel's PHP runtime (`@libphp/almalinux-9-v85`, with `vercel-php@0.9.0`) | 115.6 |
| The application's packages (`vendor/`) | 53.6 |
| `public/` | 7.9 |
| `tests/` and `tools/` | 2.3 |
| The rest of the application | 8.9 |

- **The guard.** `tools/vercel/function-size.cjs` counts the files the builder puts into the function: every file
  of the folder except `node_modules/`, `vercel.json`, `.vercelignore` and `.vercel/`, plus the runtime's
  `native/php`, `native/php/modules` and `native/lib` without the three programs the builder leaves out. The
  rehearsal runs it on every change under `platform/`, prints the size as a notice on the run, and **fails above
  240 MB**. On every run it also tries the same measurement at a limit 1 MB below the size just measured, and
  that must fail: so the guard is known to be able to fail on the day it is needed.
- **This page said 226 MB until 20 November, 153 MB of it the runtime.** How those two numbers were measured is
  not recorded, and they could not be reproduced: the runtime's files as the builder takes them are 115.6 MB.
  With the development packages installed the same folder measures 292 to 295 MB, which is why the size is
  judged only on a tree built as the builder builds it (`--build`).
- **What it does not know.** Vercel prints no size of the function in its build log (read for the deployment of
  `b16fd49`), so the number is this repository's own count of the files, not Vercel's. Vercel's limit applies to
  the files unpacked; if Vercel counts differently, the 10 MB between 240 and 250 is the margin.
- `tests/` and `tools/` could be left out with the builder's `excludeFiles` option. That would save 2.3 MB of
  188.4 and is not done.

## 8. How long a browser keeps a file

Vite gives the stylesheets and scripts it builds a name that changes when their content changes:
`build/assets/admin-Bic4CXJA.css`. A file with such a name never changes, so a browser may keep it.

| What | `Cache-Control` | Why |
|---|---|---|
| The eight built files with a marked name (`/build/assets/<name>-<8 characters>.<ext>`) | `public, max-age=31536000, immutable` (one year) | A new build gives a new name |
| `build/assets/site.css`, `site.js` (fixed names), the build's manifest, every other file of `public/` | Vercel's own: `public, max-age=0, must-revalidate` (ask again each time) | The name stays while the content changes |
| A marked name that does not exist | 404, no long life | The rule is applied only to a file that was found |
| A page of the admin | The application's own: `no-store, private` on the sign-in pages, `no-cache, private` on the others | An answer of the function is not a file that was found: the rule does not reach it |

Until 20 November every one of these was sent with `max-age=0`: a browser had to ask again for the stylesheet
and the scripts on each page.

- The rule is the last entry of `routes` in `vercel.json`, after `{"handle": "hit"}`: Vercel applies that part
  only after it has found a file to serve, and a rule there can add headers and nothing else.
- `scripts/test-vercel-livewire-root.mjs` holds the rule: it reads the build's manifest and fails when a marked
  file is not covered, when a file with a fixed name is, when the life is shorter, and when a third fixed name
  appears in `vite.config.js`.
- **Where the form of the rule comes from.** Vercel's own builder for Next.js writes its one-year rule for built
  files in the same place and the same form: after `handle: hit`, headers only, `continue: true` (`@vercel/next`
  31.0.1, read on 20 November). On that rule it also sets `important: true`, a flag that Vercel's routing schema
  marks as deprecated and explains nowhere (`@vercel/routing-utils` 6.6.1); the same builder writes an
  application's own header rules in that place without it. This repository's rule does not set the flag. **If
  the outside check ever finds that the year is not sent, that flag is the first thing to try.**
- **Vercel's network cannot be rehearsed.** The rehearsal applies the rule as Vercel's own development server
  does. That Vercel's network does the same is read from outside after each deployment: the outside check asks
  for the stylesheet the sign-in page names and must read one year, and asks for a marked name that does not
  exist and must read 404 without it.
- **Read from the live site, twice, on 20 November.** The check after the merge that brought the rule (run
  44759289547, 15:10 UTC) and the next timed run (run 44828383242, 22:22 UTC) both read: the stylesheet the
  sign-in page names may be kept for a year; the client pages' stylesheet, whose name is fixed, is asked for
  again; a built file's name that is not there is not found, and is sent with
  `public, max-age=0, must-revalidate`. Vercel's network applies the rule as it is written here, without the
  flag.

## 9. A cold start

A copy of the function that has served nothing yet has to start PHP and prepare Laravel's storage in
`/tmp`. That first answer is slower. The entry now marks it: **an answer from a copy that has just started
carries the header `X-Woodex-Cold-Start: 1`**; every later answer of that copy has no such header. The mark is
set when `/tmp/woodex-livewire` does not exist yet, which is true once in the life of a copy.

The outside check's first request is the sign-in page, timed, and its notice says whether that answer carried the
mark. Read from Vercel on 20 November, for the build of this project: one function, in one region (`iad1`,
Washington), on Vercel's "Fluid" setting, on the Hobby plan. How long Vercel keeps a copy that nobody asks is
Vercel's to decide. The check after a merge comes eight minutes after the push, when Vercel has built anew, and
is expected to meet a copy that has not started yet; whether it did is what the mark says. Readings are written
here as they are taken:

| When (UTC) | Asked from | Cold start | First byte |
|---|---|---|---|
| 20 Nov 2026, 13:55 | a GitHub runner, live build `b16fd49` (before the mark existed) | not said by that build | 2.29 s |
| 20 Nov 2026, 15:10 | a GitHub runner, eight minutes after the merge of pull request #52 (run 44759289547), live build `5e2aa26` | no: the copy was running already | 0.34 s |
| 20 Nov 2026, 22:22 | a GitHub runner, started by the timer (run 44828383242), live build `5e2aa26` | **yes** | **3.89 s** |
| 21 Nov 2026, 06:04 | a GitHub runner, eight minutes after the merge of pull request #53 (run 44873131273), live build `86db34d` | no: the copy was running already | 0.33 s |
| 21 Nov 2026, 11:49 | a GitHub runner, started by the timer (run 44900916067), live build `86db34d`, deployed again at 07:51 UTC with every module on | **yes** | **2.55 s** |
| 21 Nov 2026, 13:10 | a GitHub runner, started by the first push of pull request #55 (run 44907513806), the same live build | **yes** | **2.52 s** |

**Three cold readings, which is what list line T-154 asked for: 3.89 s, 2.55 s and 2.52 s to the first byte.** A
visitor who comes after a quiet time waits two and a half to four seconds for the first page; in the same runs a
later request for that page took 0.15 s, 0.11 s and 0.18 s. The check after a merge was expected to meet a copy
that had not started yet; both times it was read, it met a running one. All three cold readings came hours after
the last deployment. How long Vercel keeps a copy that nobody asks was not found out: between 11:49 and 13:10 UTC
the copy that the first of those runs had started was gone again.

Warm, the same page answers in 0.08 to 0.17 s from a GitHub runner. In the rehearsal on a development machine a
cold start took 0.53 to 0.55 s to the first byte and a warm answer 0.02 to 0.03 s; that says how much of the
wait is the application's own start, not what a visitor of Vercel waits.
