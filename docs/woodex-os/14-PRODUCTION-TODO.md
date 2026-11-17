# WOODEX OS — master to-do list to production

**Written:** 2026-11-17 · **Branch:** `arena/7ecb4f83-woodex-2030` (pull request #36) · **Read with:** [`00-MASTER-PLAN.md`](00-MASTER-PLAN.md) (why and in which waves) and [`12-DECISION-LOG.md`](12-DECISION-LOG.md) (what the owner decided).

This is the one list of everything between today and a production admin. Each line says **who** has to act,
**what "done" means**, and **where it stands**. Nothing is on it twice. When a line is finished, its status
changes here in the same commit as the work.

**"Production ready" means, for this programme:** staff sign in to one admin with their own accounts; what they
enter is kept in a database that is backed up and has been restored once; every screen they are shown works on
a desktop and on a phone; timed jobs, mail and uploads work; the old dashboard is no longer needed for any job;
and the owner has accepted it in writing (master plan §6, Wave 11).

Status words: **Done** (with the commit) · **Next** (the coming rounds, in the order of §4) · **Later** (has a
wave in the master plan) · **Owner** (cannot move without the owner) · **Open** (found, not yet scheduled).

---

## 1. Where it stands today

| | |
|---|---|
| Live demo | `https://woodex-os.vercel.app/admin` — the Laravel admin with **sample data only**. Vercel project `woodex-os`, Hobby plan, region `iad1` (US East). What is typed there is lost when Vercel starts a new instance. |
| Real admin | **Does not exist yet.** No database, no first owner account, no file storage, no timed jobs, no mail. Section 3 B. |
| Screens | 68 destinations in 10 groups. For an owner today: **29 available**, 13 still done in the old React dashboard, 21 built but switched off (not adapted to Woodex Furniture), 5 not built. The *Feature status* screen under System shows the same list live. |
| Tests | 2,244 pass, 34 skipped, 0 fail on PHP 8.5 with SQLite (sandbox, 2026-11-17); the same suite runs on MySQL 8.4 in CI on every push. |
| Shop | `woodex-2030.vercel.app` (React + Node, Supabase). Untouched by this work; stays until Wave 11. |
| Old admin | `woodex-2030.vercel.app/admin` is the React dashboard. It stays the place of work for the 13 destinations above until each is moved. |

---

## 2. Analysis of `woodex-os.vercel.app/admin` (16–17 November)

**How.** The sandbox cannot reach the live address (the connection is cut during TLS), and no tool available
can sign in to it. So the signed-in analysis was made on a **rehearsal**: the same commit, served by Vercel's own
PHP 8.5 runtime and launcher (`vercel-php@0.9.0`), read-only tree, same `vercel.json`. The live address itself is
checked from outside by a GitHub workflow that never signs in. What only the live deployment can show is
listed at the end of this section.

| Check | Tool | Result after this round |
|---|---|---|
| Every screen opens, as each of the four demo roles, on a desktop and then on a 390 px phone | `scripts/os-screens.mjs` | Owner 122 pages, sales manager 41, sales 38, accountant 38: **all open**; no console error |
| Links that lead nowhere | same | 0 (were 9) |
| Wider than the window | same | 0 at 1366 px; 0 at 390 px (were 2 screens, and 1 more caused and fixed in this round) |
| Top bar on a phone | same | one row, 56 px (was two rows, 113 px) |
| Fields without a label | same | 0 (were 30 on 8 screens) |
| Controls smaller than 24 px on a phone | same | 0 (were about 130 on 5 screens) |
| One `h1`, images with text, buttons with a name | same | all pass |
| Time to first byte on the rehearsal | same | median 50–59 ms, 95th percentile 72–93 ms (not Vercel's network) |
| Fourteen journeys that press things and write records: sign in, create a lead, reload, add a task, search, draft quotation, price a line, quotation PDF, statement PDF, CSV export, upload a sheet, 404, sign out, a forbidden screen | `scripts/os-journeys.mjs` | **14 of 14 hold** |
| The deployment itself: refuses to start without settings, hands out no file, signs in, every linked screen opens, cookie size, sign-out | `platform/tools/vercel/check-entry.sh` | **74 of 74** |
| Headers | curl, `SecurityHeadersTest` | `nosniff`, `SAMEORIGIN`, referrer policy, permissions policy, `noindex`; sign-in page `no-store`. `X-Powered-By` **removed** in this round. No Content-Security-Policy yet (S-1). |
| Cookies | curl | session and form-token cookies are `Secure`, `HttpOnly` (session), `SameSite=Lax`, 2 hours; a form without its token gets 419 |
| Error pages | `ErrorPagesTest` | own pages for 403, 404, 419, 429, 500, 503 (were the framework's bare pages) |
| Page weight | crawl | HTML median 161 KB, largest 310 KB; a near-empty page is 144 KB (15.6 KB compressed), about 110 KB of it the menu and the search templates (U-8) |

**Found and fixed in this round** (commit `493ce22` and the one after it): nine dead links; the two-row top bar;
sideways scrolling on Users and Access (and on Due payments, which this round's own table headings had caused);
thirty unlabelled fields; bare error pages; small touch
targets; the quotation form that seemed to do nothing when a choice was missing; the PHP version in every
answer; the demo calling itself a "local preview"; "Last90days" in the dashboard's period list.

**What the analysis could not cover** — each is a line in §3:

- the live deployment after a real sign-in (Q-3);
- a cold start on Vercel (H-10);
- mail, timed jobs and lasting uploads: the demo has none of them (H-4…H-6);
- realistic amounts of data: the demo has 10 leads, 10 contacts and nothing else (E-1), so most Sales, Commerce,
  Projects and Accounts screens were seen empty.

---

## 3. The list

### A. Decisions and inputs only the owner can give

Asked in the session of 17 November (four questions); the questions were skipped, so the defaults below are in
force and can be changed by saying so.

| # | What is needed | Default in force | Blocks |
|---|---|---|---|
| O-1 | **Where the real admin runs.** Hostinger, as the specification says, or Vercel with a MySQL database. On Hostinger the 30 timed jobs, uploads, backups and the installer work as built; on Vercel each needs a replacement (H-2…H-8), and Vercel's Hobby plan is meant for personal, non-commercial projects — a business admin there needs the Pro plan (check Vercel's current terms). | Vercel stays a demo; nothing real is built on it | all of B |
| O-2 | If Vercel: a MySQL 8 database (not Supabase — that is PostgreSQL) in a region near the function. The values are entered **in Vercel by the owner**, never in chat or in the repository. | — | H-1 |
| O-3 | If Hostinger: the two SSH secrets in a GitHub environment and the plan details (decision D-10). | — | Wave S |
| O-4 | **Furniture's own price list, terms, wording and signers** (decision D-8). Until then no quotation template or rate is installed outside the tests, and the demo's Sales screens stay empty. | The Interior studio's templates are *not* used, not even in the demo | E-1, Wave 4 |
| O-5 | Which existing customer records may move (D-9). | none move | Wave 3 |
| O-6 | One person and one document for the business facts: warranty, delivery areas and charges, returns, tax, company history (Q-4 of `docs/furniture-2030/01-OWNER-DECISIONS.md`). | — | shop content, invoices |
| O-7 | Sign `docs/furniture-2030/catalogue/IMPORT-REPORT.md` (the real catalogue, 170 products). | not signed | Wave 5 |
| O-8 | Migration 019 on the shop's Supabase database, run by someone with database access (the agent must not). | not run | shop Phase 2 |
| O-9 | **Merge pull request #36 into `main`.** The shop redeploys from `main`; its test gate is green on the branch. | not merged (DL-011) | one code line |
| O-10 | Change the live owner password of the old system and make the repository private (on hold by DL-012; the old password was printed in a public document). | on hold | S-6 |
| O-11 | The missing `01-MASTER-PRD.md` named by specification 08. | specs 05 and 07 stand in | — |
| O-12 | The sidebar: light, as in the Reimagined admin (in force, DL-017), or charcoal, as in specification 05. One setting: `WOODEX_ADMIN_SIDEBAR_TONE=charcoal`. | light | — |
| O-13 | A domain for the admin (for example `admin.` on the company domain). | `woodex-os.vercel.app` | H-9 |
| O-14 | Whether `woodex-2030.vercel.app/admin` (the old dashboard) should point to the new admin. Recommended: leave it until Wave 11. | left as it is | — |

### B. A real admin: hosting and first installation

Written for Vercel, because that is where the demo runs. If O-1 is answered "Hostinger", H-2 to H-8 fall away
(the host does these) and Wave S of the master plan takes their place.

| # | Task | Who | Done when | Status |
|---|---|---|---|---|
| H-1 | Database settings in the Vercel project: `APP_KEY`, `DB_CONNECTION=mysql`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `APP_URL`; `WOODEX_DEMO` removed | Owner (O-2), agent guides | The entry leaves demo mode: `/up` answers 200 with the real settings and the rehearsal's "real" checks pass against a MySQL service | Owner |
| H-2 | **First installation without the web installer** (it writes files; Vercel's disk is read-only): a GitHub workflow, started by hand, with the database settings as secrets of a protected environment, that runs `php artisan migrate --force` and `php artisan woodex:install` (reference data and the first owner from `OWNER_NAME`, `OWNER_EMAIL`, `OWNER_PASSWORD`) | Agent, then owner starts it | The workflow has created the tables and the owner on an empty MySQL in CI; then once on the real database; the owner signs in | Next (after O-1) |
| H-3 | Later migrations: the same workflow runs `migrate --force` before a production deployment | Agent | A deployment with a new migration is rehearsed in CI: migrate, then serve | Next |
| H-4 | **File storage.** Uploads (product images, media library, lead sheets, document logos, attachments) need an S3-compatible bucket: add `league/flysystem-aws-s3-v3`, set `FILESYSTEM_DISK`, check every upload and download path | Agent; owner supplies the bucket | An uploaded image is still there after a new deployment; the journey "upload a sheet" passes on the real deployment | Next (after O-1) |
| H-5 | **Timed jobs.** 30 entries in `routes/console.php`, eight of them every minute (two queue workers, reminders, SLA check, automation, heartbeat). Nothing calls them on Vercel. A signed route called by a scheduler; Vercel's Hobby plan runs a cron job at most once a day (check the current terms), so every-minute jobs need the Pro plan or an outside caller | Agent | The *Health* screen shows the scheduler's heartbeat younger than two minutes for one hour | Next (after O-1) |
| H-6 | **Mail.** `MAIL_MAILER` is `log`. An SMTP provider, sender domain with SPF and DKIM | Owner supplies; agent wires | "Forgot your password?" delivers a message to a real mailbox | Owner |
| H-7 | **Queue.** 14 queued classes; `QUEUE_CONNECTION=database` needs a worker (H-5) or `sync` for a small team | Agent | A queued notification is sent within a minute of its cause | Next |
| H-8 | **Backups.** The built-in backup calls `mysqldump`, which is not in Vercel's PHP runtime package: use the database provider's backups, and export uploads | Owner (provider), agent documents | A backup has been restored once into an empty database and the admin opened on it | Owner |
| H-9 | Domain and `APP_URL` (O-13); `ADMIN_DOMAIN` if the admin gets its own host | Owner, agent | The admin answers on its domain over https; the old address redirects | Owner |
| H-10 | Measure a cold start and the distance to the database (the function runs in `iad1`; choose the region beside the database) | Agent | First byte after a cold start and warm, written into `13-VERCEL-DEPLOYMENT.md` | Next |
| H-11 | Function size: 226 MB of 250 MB. Leave `tests/` and `tools/` out of the function | Agent | Size under 200 MB, rehearsal still 74 of 74 | Open |
| H-12 | Long cache for fingerprinted files under `/build/assets/` (the live check prints what is sent today) | Agent | `cache-control` of the stylesheet is one year, immutable; the page still loads after a deployment | Open |
| H-13 | Wave S of the master plan: Hostinger staging starts, a deploy job, a proven restore | Agent, owner (O-3) | The staging live test passes | Owner |

### C. Security

| # | Task | Who | Done when | Status |
|---|---|---|---|---|
| S-1 | **Content-Security-Policy** on every admin page. Proposed start: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'` — then tightened (Alpine and Livewire need the two `unsafe` words until their strict builds are used) | Agent | The crawl of all roles, with every module switched on, shows no blocked resource in the console; a test pins the header | Next |
| S-2 | `X-Powered-By` removed on Vercel and on every other host | Agent | Rehearsal check and `SecurityHeadersTest` | **Done** `493ce22` |
| S-3 | HSTS: the application sends it in production over https (tested); confirm on the real deployment | Agent | The live check prints the header on the real domain | Next (with H-9) |
| S-4 | Dependency audit in CI: `composer audit` and `npm audit --omit=dev`; neither runs today | Agent | Both run on every push and fail on a known high-severity issue | Next |
| S-5 | Two-factor sign-in required for owner and admin (`ENFORCE_2FA`, off by default) | Owner decides, agent sets | An owner without a second factor is sent to enrol | Open |
| S-6 | Rotate the old system's owner password; private repository (O-10) | Owner | — | Owner (on hold) |
| S-7 | Wave 2 of the master plan: one role and permission matrix, sign-in locks, audit of every change, no route without a permission | Agent | The matrix test of Wave 2 | Later |
| S-8 | The demo passwords exist only as Vercel settings. If one leaks, change the four `WX_PW_*` values and redeploy | Owner | — | standing rule |

### D. Design and experience (Wave 1; the Reimagined theme, DL-017)

| # | Task | Who | Done when | Status |
|---|---|---|---|---|
| U-1 | Tokens, navigation registry, sidebar (slices 1.1–1.3) | Agent | — | **Done** `537d89a` |
| U-2 | Top bar: one row, trail, search, "New", user menu with theme (slice 1.4) | Agent | `AdminTopBarTest`; 56 px on a phone | **Done** `493ce22` |
| U-3 | **The Reimagined theme on the shared parts**: light sidebar with the current screen filled green, brand tile, bold tight headings, cards with 12 px corners and a hair of shadow, figures with upper-case labels and tinted icon tiles, status pills with an edge, the theme's arrow on selects, "nothing here" blocks. Charcoal sidebar kept as a setting. Five shared components carry it into every screen (305 cards, 143 pills, 97 page headers, 54 "nothing here" blocks, 51 figures), with the sidebar and the stylesheet | Agent | Contrast test for three tones; suite green; crawl clean; before-and-after screenshots in `evidence/` | **Done** (this round) |
| U-4 | Components, the rest of slice 1.5: tables (one component instead of hand-written markup), pagination, modal, toasts, and the five states (loading, empty, error, no permission, success) on every list | Agent | Each state can be seen on one list per area; a test renders each | Next |
| U-5 | Dashboard to specification 05 §7 (slice 1.6): every figure links to its records; no section for a module that is not here. The period figures and the queue figures already use the theme | Agent | Each figure's link opens the same number of rows | Next |
| U-6 | Screen pass, area by area (slice 1.7), in the order of DL-008: E-commerce, CRM, Sales first. Selected states that are still charcoal pills (stage steps, activity types, business line) move to the theme's green | Agent | Before-and-after screenshots of one screen per area at 1366 px and 390 px | Next |
| U-7 | Specification 05 items not built: a help entry in the bar, pinned and recently opened screens | Agent | Present, keyboard-usable, tested | Open |
| U-8 | Page weight: the menu (55 KB), the search templates (about 64 KB) and 115 inline icons (44 KB) are sent with every page. Load the search list on first use; draw icons from one sprite | Agent | A near-empty page is under 60 KB of HTML | Open |
| U-9 | Phones with real amounts of data: a table inside a grid column can push the page wider than the window (one case found and fixed). General rule: grid columns may shrink (`min-width: 0`) | Agent | The crawl on a demo with realistic data (E-1) shows 0 screens wider than a phone | Open (needs E-1) |
| U-10 | A person's check with a screen reader and with the keyboard only. The crawl counts labels, names and sizes; it cannot hear | Agent with the owner's team | Sign-in, create a lead and write a quotation without a mouse | Open |
| U-11 | Every open page asks the server again every 30 seconds (the notification bell) while it is visible. Fine for a small team; on a metered host it is the main source of requests | Agent | Measured on the real deployment; slowed or replaced if it matters | Open |

### E. The demo

| # | Task | Who | Done when | Status |
|---|---|---|---|---|
| E-1 | Richer sample data: quotations, invoices, payments, products, a project, tasks — so every area can be reviewed. Needs O-4 (templates), or the owner's word that the Interior studio's templates may be shown as samples | Owner decides, agent builds | Every area's main list shows at least five sample rows; the journeys still pass | Owner |
| E-2 | The demo notice on every screen and in the user menu ("Demo workspace") | Agent | `PreviewLoginTest` | **Done** `493ce22` |
| E-3 | Remove the demo once the real admin exists, or move it to a preview address | Owner decides | `WOODEX_DEMO` is not set in production | Later |

### F. Moving the modules (master plan §6)

The size column is the number of routes of the old Node API that the wave replaces.

| Wave | Scope | Size | Needs from the owner | Status |
|---|---|---:|---|---|
| 2 | Identity, access, audit | 15 | — | Later |
| 3 | CRM; the shop's enquiry forms write to Laravel | 28 | O-5 | Later |
| 4 | Estimations, quotations, invoices, payments | 17 | O-4 | Later |
| 5 | Real catalogue and media; the shop reads `/api/v1` | 19 | O-7 | Later |
| 6 | Orders, delivery, support | 35 | checkout methods, delivery and warranty terms (O-6) | Later |
| 7 | Stock and purchasing | 33 | — | Later |
| 8 | Production | 11 | — | Later |
| 9 | Projects, finance, reports | 30 | the accounting owner's review | Later |
| 10 | Website Studio and growth | 28 | one approval per provider | Later |
| 11 | Cutover: final data move, old systems retired, training | 5 | **separate written approval** | Later |

### G. Operations

| # | Task | Who | Done when | Status |
|---|---|---|---|---|
| P-1 | Errors seen by someone: the function writes to Vercel's log only. An error tracker, or the built-in alert rules with mail (H-6) | Agent, owner chooses | A forced error reaches a person within five minutes | Open |
| P-2 | The live check on a timer (it runs on request and on a change of its own file today) | Agent | It runs every hour and a failure opens an issue | Next |
| P-3 | A runbook: deploy, roll back, restore, rotate a password, add a staff account | Agent | Written, and each step done once | Later |
| P-4 | Training and handover (Wave 11) | Agent, owner | — | Later |

### H. Proof: the tools and gates every line above relies on

| # | Gate | Where | Status |
|---|---|---|---|
| Q-1 | Laravel suite on MySQL 8.4 | `os-ci.yml`, every push | In force |
| Q-2 | The Vercel rehearsal, 74 checks | `vercel-livewire.yml`, every change under `platform/` | In force |
| Q-3 | **A signed-in check of the live deployment.** Needs the demo owner's password as a GitHub secret (`WX_PW_OWNER`), which only the owner can add; then `os-journeys.mjs` runs against the live address. Until then the signed-in analysis rests on the rehearsal | Owner adds the secret, agent wires the job | Owner |
| Q-4 | The live check from outside: sign-in page, wrong password, headers, cookie marks, own 404 and 419 pages, closed files | `vercel-live-check.yml` | In force (extended this round) |
| Q-5 | The crawl and the journeys in CI, on the rehearsal (they run by hand today) | Agent | Next |
| Q-6 | The React and Node gate (shop) | `ci.yml` | In force |

---

## 4. Order of work

1. **This round (done):** the findings of the analysis fixed; the Reimagined theme on the shared parts; this list.
2. **Next round, needs nobody:** U-4 (tables and the five states), U-5 (dashboard), U-6 for E-commerce, CRM and
   Sales; S-1 (Content-Security-Policy) and S-4 (audits); Q-5 and P-2.
3. **As soon as O-1 is answered:** H-2 to H-7 for the chosen host, then H-10; a real sign-in for the owner.
4. **Then the waves**, in the order of §3 F, each ending with its own report and the owner's acceptance.

The three answers that unblock the most: **O-1** (host), **O-4** (Furniture's price list and terms), **O-9**
(merge #36).
