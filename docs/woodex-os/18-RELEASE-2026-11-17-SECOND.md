# WOODEX OS — release report, 17 November 2026, second merge

The report of pull request #37, in the ten points of document 10 §9. It is written **before** the merge, so
that what the merge changes on each live site is known first: the report of the morning's merge
([`17-RELEASE-2026-11-17.md`](17-RELEASE-2026-11-17.md)) lacked that, which is defect D-014.

**On whose word.** On 17 November the owner answered five questions (decision DL-026). The fifth: the agent
merges into `main` when every check is green. The merge waits for every check of pull request #37.

## What this merge changes on each live site

Compared file by file, `88ec4cc` (today's `main`) against the head of the pull request: 69 files — 44 under
`platform/` (the Laravel admin), 12 documents, 7 scripts, 4 workflows, the copy of the admin's entry point under
`deploy/`, and one line of `package.json`. **No file of the React shop, of its API or of its dashboard is
changed** (`apps/`, `api/`, `vercel.json`, `package-lock.json`, `.htaccess`, `index.php`: none).

| Site | What is different after the merge |
|---|---|
| **The hosted admin demo**, `woodex-os.vercel.app` | Rebuilt by Vercel from `main`. (1) **The client has pages of their own, in the demo only** (DL-026): a quotation, an invoice and a project each have a link that opens without a sign-in. (2) **Documents can be sent and issued in the demo**, on sample terms and a sample signer that say so: "SAMPLE TERMS — for this demo only", "Sample Signer · Sample only — not a real signatory". (3) Staff can write down the client's answer to a quotation: accepted or declined, by whom and how. (4) Settings has a field for the invoice terms. (5) A lead's timeline shows that an invoice went out and that money came in, without amounts. (6) Confirming a payment that a client reported asks first. (7) **Gone, because their modules are off:** the Settings sections "WhatsApp" and "Bot"; seven cards of Integrations (Telegram, WhatsApp, Google account, Gmail, Google Drive, Facebook Page, AI bridge); the card "Case study" on a project. (8) The links to a client's project page open; until now each answered "No access" (D-025). (9) Four Symfony packages are at 8.1.11 (D-027). As before, the demo keeps nothing that is typed |
| **The React shop**, `woodex-2030.vercel.app` | Rebuilt by Vercel from `main`, from the same shop files as today. The build runs its own tests first; they now also build the throwaway test database with migration 020. For a visitor and for staff nothing changes |
| **The shop's database** (Supabase) | **Nothing.** No code that the merge puts live reads the migration files or writes the migration ledger. Migration 020 (row-level security) is then in `main` and applied nowhere: see §6 |
| **GitHub** | A new workflow asks npm and Composer for published advisories when a lock file changes and every Monday. A failed live check on `main` opens an issue. On every push that touches the admin a browser presses both journeys and opens every screen |
| **Hostinger** | Nothing. No workflow of this merge reaches it |

**What does not go out.** Migration 020 is not applied to the live database, and the live database's address is
still set for preview builds of the shop (D-006): both were chosen by the owner on 17 November and both wait for
the Supabase and Vercel connections, which were switched off before either could be done (T-163, T-164). No
product is published. No customer, lead or order is moved.

**The way back.** Vercel keeps the deployments that are live now and can put either back in its dashboard. In the
repository: `git revert -m 1` of the merge commit, which Vercel then builds.

---

## 1. Commit/branch/build identifier

| | |
|---|---|
| Branch | `arena/04517247-woodex-2030`, pull request #37, from `main` at `88ec4cc` |
| Commits | `fba67ad` records after the morning's merge · `92f90f9` staff write down the client's answer; no link where there is no page; invoice terms in Settings · `6b3a1ea` the timeline of invoice and money; what pressing the sale found · `56b6019` the sale as a journey; the demo's settings · `b05ee77` migration 020 with its test and rollback; the records · `6906546`, `0e23127` the browser in CI · `f8edfee` switched-off modules closed; the audit; an issue on a failed live check · then the commit that carries this report: the client's project links, the four packages, the records |
| Merge commit | Not known when this was written. It is on pull request #37 |
| Hosted admin demo | Vercel project `woodex-os`. Vercel built a preview of `f8edfee` without error (GitHub status "Deployment has completed", 14:26:51 UTC) |
| React shop | Vercel project `woodex-2030`. Vercel built a preview of `f8edfee` without error (14:26:15 UTC) |
| Shop database | Supabase project `woodex-2030`, PostgreSQL 17. Migration ledger: 19 rows, as after the morning. Not read again in this round: the connection was off |

## 2. Environment

| Where | What ran there |
|---|---|
| Sandbox | PHP 8.5 (static build), SQLite, Node 22, headless Chromium, an embedded PostgreSQL for the shop's tests. It reaches GitHub and npm only: neither live site, nor the database, nor Packagist |
| Rehearsal | The `platform/` tree served by Vercel's own PHP runtime and launcher, built as Vercel builds it, with the demo's settings and with the product's settings |
| GitHub Actions | PHP 8.4 with MySQL 8.4 for the Laravel suite; Node for the shop's gate; the rehearsal with a browser; the outside check of both live sites; the audit of the packages |
| Live sites | Asked from outside by GitHub Actions only. Neither was changed by hand in this round |

## 3. Test command and result

| Command | Result |
|---|---|
| The Laravel suite, `sh tools/preview/sandbox/run-suite.sh`, on SQLite, on the tree of this report with the four packages at 8.1.11 | **2,359 passed, 36 skipped, 0 failed** (13,552 assertions). In the morning: 2,280 |
| `tests/Feature/SwitchedOffModulesTest.php` | 43 pass. With the check taken out of the Integrations screen, 20 fail |
| `tests/Feature/ClientPortalTest.php` | 13 pass. With the old signing put back, the two new tests fail |
| `node scripts/os-journeys.mjs` with `OS_STRICT=1`, on the rehearsal | **27 of 27 steps** with the demo's settings; 27 of 27 with the product's settings (in CI, on `f8edfee`) |
| `node scripts/os-screens.mjs`, after the journeys, on the rehearsal | 248 pages as four roles, each also at 390 px: none failed |
| The shop's gate, `node scripts/ci-test.mjs` (CI, on `f8edfee`) | Passed. It had failed on `0e23127`: a test of the catalogue expected migration 019 to be the last one. It now expects every migration after 018, in order |
| `node scripts/test-rls.mjs` | 17 checks pass on the embedded PostgreSQL, 73 tables |
| The audit, `.github/workflows/audit.yml`, first run on `f8edfee` | npm: no high or critical, eleven moderate. Composer: **failed, nine advisories in four Symfony packages** (D-027). The four are moved to the fixed version in the commit that carries this report; the run of that commit is on the pull request |
| The outside check of both live sites (run 44102455186, from the branch) | Passed: the admin demo and the shop's API answer as they should |
| `node scripts/verify-acceptance.mjs`, `npm run docs:verify`, `node scripts/secret-scan.mjs` | Clean |

The checks of the commit that carries this report are on pull request #37. The merge waits for all of them.

## 4. Browser/device coverage

| Covered | How |
|---|---|
| Desktop, 1366 × 900 | Every screen as owner (128 pages), sales manager (42), sales (39) and accountant (39), opened **after** the journeys, with a won lead, a quotation, an invoice, a payment slip and a project in them; both journeys, each step by the role that does it |
| Phone, 390 px wide | The same 248 pages: none wider than the window. In the sale the client answers the quotation on a phone-sized page |
| Browser | Headless Chromium only |

Not covered: Safari and Firefox; a real phone; the hosted demo after a real sign-in (T-151); a screen reader and
the keyboard alone (T-054).

## 5. Defects open/closed by severity

28 in the register (document 16 §5). Not closed: 1 critical, 1 high, 8 medium, 3 low. Closed: 15.

| In this pull request | |
|---|---|
| Closed | D-015 to D-022 (what pressing the sale found) · D-025 high: every link to a client's project page was refused · D-026 medium: open screens offered things of switched-off modules · D-027 medium: four packages with published advisories |
| Opened and still open | D-023 medium: the project that a Furniture sale opens is an Interior project (T-033, T-010) · D-024 low: a lead's value does not follow its quotation (T-092) · D-028 low: one low advisory in Laravel and eleven moderate ones in the shop's build tools (T-071) |
| Open as before | D-002 critical: the old dashboard's password was printed in a public document (T-021, the owner's step) · D-006 high: previews of the shop use the live database (T-164, waits for the Vercel connection) · D-010: no row-level security on the shop's database (T-163, waits for the Supabase connection) · D-011: no backup known (T-187) |

## 6. Migration reconciliation

| Database | State |
|---|---|
| The shop's PostgreSQL (live) | 19 rows in the ledger for files 001 to 019, as read in the morning. **File 020 is in the repository and not applied.** Until it is, `npm run audit:schema` against the live database reports "on disk but never applied: 020_row_level_security.sql". That check runs in CI only where the secret `PRODUCTION_DATABASE_URL` is set; it is not set, so CI says "hosted schema drift audit skipped" |
| Migration 020 | Enables row-level security on every table and adds one policy that lets nobody in through the Data API. The owner of the tables, which is the role the shop's API uses, works as before. It refuses to run as any other role. Its rollback is `scripts/migrations/rollback/020_row_level_security.rollback.sql`. It changes no row |
| Laravel | 106 migrations, none added or changed in this pull request |

## 7. Backup/restore status

Unchanged since the morning. No backup of the shop's database is known to exist (D-011, T-187). By the owner's
answer (DL-026) migration 020 is applied only after a backup or a safe way back is confirmed; the migration holds
no data and has its rollback. The hosted admin demo has nothing to back up. The restore drill of the shop
(`npm run test:restore`) runs in the shop's build and passed.

## 8. Performance observations

| Measured | Result |
|---|---|
| First byte of a page, on the rehearsal (not Vercel's network) | Median 71 to 75 ms and 95th percentile 110 to 118 ms, by role |
| Page ready | Median 150 to 163 ms |
| The browser job in CI | About nine minutes: the build, both journeys twice, every screen once |
| One journey run | About two minutes |
| The Laravel suite in the sandbox | About six minutes |

Not measured: a cold start on Vercel (T-154); realistic amounts of data (T-168).

## 9. Screenshots or recording of key flows

No new picture is stored. What the browser did is kept by each run of the job `browser-on-vercel-runtime` for 14
days, as the artifact `browser-on-the-rehearsal`: the account of every journey step, the report of every screen,
and the log of the rehearsal. One line on the run page says the result; on `f8edfee`: "244 pages opened as 4
roles · 27 of 27 steps held" and "27 of 27 steps held".

## 10. Explicit owner approval status

| | |
|---|---|
| This merge | Approved in advance by the owner's fifth answer of 17 November (DL-026): the agent merges when every check is green |
| The client's pages in the demo; sample terms and a sample signer in the demo | The owner's first and fourth answers (DL-026) |
| Applying migration 020; taking the live database out of preview builds | Chosen by the owner (DL-026, answers 3 and 2). **Not done:** both wait for the connections to be switched on |
| Decisions the agent took inside the list lines | DL-025 and DL-027. Each can be returned by the owner |
| Any module, any gate | Not accepted. No acceptance sheet is signed (document 16 §4) |
