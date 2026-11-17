# WOODEX OS — release report, 17 November 2026, second merge

The report of pull request #37, in the ten points of document 10 §9. It is written **before** the merge, so
that what the merge changes on each live site is known first: the report of the morning's merge
([`17-RELEASE-2026-11-17.md`](17-RELEASE-2026-11-17.md)) lacked that, which is defect D-014.

**On whose word.** On 17 November the owner answered five questions (decision DL-026). The fifth: the agent
merges into `main` when every check is green. At about 16:00 UTC he switched the Vercel and Supabase connections
on and wrote "start"; his second and third answers were then carried out by hand, before the merge (DL-028).

## What was changed by hand on the live systems, before the merge

| Where | What | Read back |
|---|---|---|
| **Vercel**, the shop's project `woodex-2030`, 16:05 UTC | The database address is set for Production only. Until then it was set for Production, Preview and Development alike, so every preview of every pushed commit was a second shop on the live database (D-006). No value was read | The setting, read again: Production only. The live shop is untouched: it is the Production deployment |
| **Supabase**, the shop's database, 16:07:45 UTC | Migration 020: row-level security on all 73 tables, with one policy that lets nobody in through Supabase's Data API. One transaction, 167 ms, no row changed. The text that was run is kept in `deploy/supabase/2026-11-17-apply-020-row-level-security.sql` | 73 of 73 tables locked, none forced; the policy on each; 20 rows in the migration ledger; the shop's API role counts 148 products as before. Asked from outside 67 seconds later, the shop serves its 148 products (run 44122391808) |
| **GitHub**, issue #38 | Opened by the live check on purpose, to prove that a failed check opens an issue; added to and closed by the next proof run | Closed at 16:03 UTC |

**Left as it is, because deleting cannot be undone and was not asked:** the previews of the shop that Vercel
built before 16:05 UTC still hold the live database's address. D-006 stays open for them; line T-188 asks the
owner's word.

## What the merge changes on each live site

Compared file by file, `88ec4cc` (today's `main`) against the head of the pull request: 72 files — 44 under
`platform/` (the Laravel admin), 14 documents, 7 scripts, 4 workflows, 2 under `deploy/` (the copy of the admin's
entry point, and the record of what was run on the database) and one line of `package.json`. **No file of the
React shop, of its API or of its dashboard is changed** (`apps/`, `api/`, `vercel.json`, `package-lock.json`,
`.htaccess`, `index.php`: none).

| Site | What is different after the merge |
|---|---|
| **The hosted admin demo**, `woodex-os.vercel.app` | Rebuilt by Vercel from `main`. (1) **The client has pages of their own, in the demo only** (DL-026): a quotation, an invoice and a project each have a link that opens without a sign-in. (2) **Documents can be sent and issued in the demo**, on sample terms and a sample signer that say so: "SAMPLE TERMS — for this demo only", "Sample Signer · Sample only — not a real signatory". (3) Staff can write down the client's answer to a quotation: accepted or declined, by whom and how. (4) Settings has a field for the invoice terms. (5) A lead's timeline shows that an invoice went out and that money came in, without amounts. (6) Confirming a payment that a client reported asks first. (7) **Gone, because their modules are off:** the Settings sections "WhatsApp" and "Bot"; seven cards of Integrations (Telegram, WhatsApp, Google account, Gmail, Google Drive, Facebook Page, AI bridge); the card "Case study" on a project. (8) The links to a client's project page open; until now each answered "No access" (D-025). (9) Four Symfony packages are at 8.1.11 (D-027). As before, the demo keeps nothing that is typed |
| **The React shop**, `woodex-2030.vercel.app` | Rebuilt by Vercel from `main`, from the same shop files as today. The build runs its own tests first; they now also build the throwaway test database with migration 020. For a visitor and for staff nothing changes |
| **The shop's database** (Supabase) | Nothing more. No code that the merge puts live reads the migration files or writes the migration ledger. The merge brings the file of migration 020 into `main`; the database has had it since 16:07:45 UTC, so `main` and the database agree: 20 files, 20 ledger rows |
| **GitHub** | A new workflow asks npm and Composer for published advisories when a lock file changes and every Monday. A failed live check on `main` opens an issue. On every push that touches the admin a browser presses both journeys and then opens every screen |
| **Hostinger** | Nothing. No workflow of this merge reaches it |

**What does not go out.** No product is published. No customer, lead or order is moved. Nothing is installed on
Hostinger. The old previews of the shop are not deleted (T-188).

**The way back.** For the merge: Vercel keeps the deployments that are live now and can put either back in its
dashboard; in the repository, `git revert -m 1` of the merge commit, which Vercel then builds. For migration 020:
its rollback file (§7). For the Vercel setting: tick Preview and Development again on the variable.

---

## 1. Commit/branch/build identifier

| | |
|---|---|
| Branch | `arena/04517247-woodex-2030`, pull request #37, from `main` at `88ec4cc` |
| Commits | `fba67ad` records after the morning's merge · `92f90f9` staff write down the client's answer; no link where there is no page; invoice terms in Settings · `6b3a1ea` the timeline of invoice and money; what pressing the sale found · `56b6019` the sale as a journey; the demo's settings · `b05ee77` migration 020 with its test and rollback; the records · `6906546`, `0e23127` the browser in CI · `f8edfee` switched-off modules closed; the audit; an issue on a failed live check · `8fa12ab` the client's project links; four packages; the records · `fe95efc` what the live check proves after migration 020 · then the commit that carries this report in its final form: records only |
| Merge commit | Not known when this was written. It is on pull request #37 |
| Hosted admin demo | Vercel project `woodex-os`. Vercel built a preview of `8fa12ab` without error (GitHub status "Deployment has completed", 16:06:41 UTC), from the lock file with the four packages at 8.1.11 |
| React shop | Vercel project `woodex-2030`. Vercel built a preview of `8fa12ab` without error (16:06:06 UTC) |
| Shop database | Supabase project `woodex-2030`, PostgreSQL 17.11. Migration ledger: 20 rows since 16:07:45 UTC; 19 before |

## 2. Environment

| Where | What ran there |
|---|---|
| Sandbox | PHP 8.5 (static build), SQLite, Node 22, headless Chromium, an embedded PostgreSQL for the shop's tests. It reaches GitHub and npm only: neither live site, nor the database, nor Packagist |
| Rehearsal | The `platform/` tree served by Vercel's own PHP runtime and launcher, built as Vercel builds it, with the demo's settings and with the product's settings |
| GitHub Actions | PHP 8.4 with MySQL 8.4 for the Laravel suite; Node for the shop's gate; the rehearsal with a browser; the outside check of both live sites; the audit of the packages |
| Live systems | The two sites are asked from outside by GitHub Actions. The shop's database and the shop's Vercel settings were reached through the two connections the owner switched on, never through a sign-in to the shop |

## 3. Test command and result

| Command | Result |
|---|---|
| The Laravel suite, `sh tools/preview/sandbox/run-suite.sh`, on SQLite, with the four packages at 8.1.11 | **2,359 passed, 36 skipped, 0 failed** (13,552 assertions). In the morning: 2,280. The same suite on MySQL 8.4 in CI: passed on `8fa12ab` |
| `tests/Feature/SwitchedOffModulesTest.php` | 43 pass. With the check taken out of the Integrations screen, 20 fail |
| `tests/Feature/ClientPortalTest.php` | 13 pass. With the old signing put back, the two new tests fail |
| `node scripts/os-journeys.mjs`, then `node scripts/os-screens.mjs`, on the rehearsal, in CI on `8fa12ab` | With the demo's settings: **27 of 27 steps, then 248 pages as four roles, nothing wrong**. With the product's settings: 27 of 27 steps. The same in the sandbox |
| The shop's gate, `node scripts/ci-test.mjs` (CI) | Passed on `f8edfee` and `8fa12ab`. It had failed on `0e23127`: a test of the catalogue expected migration 019 to be the last one. It now expects every migration after 018, in order |
| `node scripts/test-rls.mjs` | 17 checks pass on the embedded PostgreSQL, 73 tables |
| The text of migration 020 as sent to the live database, first on the embedded PostgreSQL | Applied: 73 of 73 tables, ledger 19 → 20. With a failing guard: nothing kept. Sent a second time: refused |
| The audit, `.github/workflows/audit.yml` | First run, on `f8edfee`: Composer **failed, nine advisories in four Symfony packages** (D-027). On `8fa12ab`, with the four at 8.1.11: both jobs pass. Composer: "blocking 0, lower 1" (Laravel, low). npm: no high or critical, eleven moderate |
| The outside check of both live sites, after migration 020 (run 44122391808, 16:08:52 to 16:09:05 UTC) | Passed: the admin demo answers as it should; the shop serves 148 published products, 12 of 12 checks |
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

29 in the register (document 16 §5). Not closed: 1 critical, 1 high, 7 medium, 4 low. Closed: 16.

| In this pull request | |
|---|---|
| Closed | D-015 to D-022 (what pressing the sale found) · D-025 high: every link to a client's project page was refused · D-026 medium: open screens offered things of switched-off modules · D-027 medium: four packages with published advisories · D-010 medium: the shop's database had no row-level security |
| Opened and still open | D-023 medium: the project that a Furniture sale opens is an Interior project (T-033, T-010) · D-024 low: a lead's value does not follow its quotation (T-092) · D-028 low: one low advisory in Laravel and eleven moderate ones in the shop's build tools (T-071) · D-029 low: ten functions of the shop's database have no fixed search path (T-172) |
| Open as before | D-002 critical: the old dashboard's password was printed in a public document (T-021, the owner's step) · D-006 high: the previews of the shop built before 16:05 UTC still reach the live database (T-188, the owner's word) · D-011 medium: no backup known (T-187) |

## 6. Migration reconciliation

| Database | State |
|---|---|
| The shop's PostgreSQL (live) | **20 rows in the ledger for the 20 files**, read at 16:07:58 UTC. Row 20: `020_row_level_security.sql`, the checksum of the file as it is in the repository, applied 2026-11-17T16:07:45.520Z, 167 ms: the form the runner writes, so the runner and the schema audit read it as their own |
| Migration 020 | Enables row-level security on every table and adds one policy that lets nobody in through the Data API. The owner of the tables, which is the role the shop's API uses, works as before. It refuses to run as any other role. It changes no row |
| The hosted schema audit, `npm run audit:schema` | Not run: it needs the database address as the secret `PRODUCTION_DATABASE_URL`, which is not set. CI says "hosted schema drift audit skipped" |
| Laravel | 106 migrations, none added or changed in this pull request |

## 7. Backup/restore status

**No backup of the shop's database could be confirmed** (D-011, T-187): the connection the agent uses shows
neither the Supabase plan nor any backup. The owner's condition for migration 020 was a backup or a safe way
back. The agent relied on a safe way back and wrote down why (DL-028): the migration changes no row; it is one
transaction; its rollback file, `scripts/migrations/rollback/020_row_level_security.rollback.sql`, is tested
(apply, roll back, apply again) and returns to exactly the state read before; the text was rehearsed first; and
the role the shop's API connects as owns every table. The hosted admin demo has nothing to back up. The restore
drill of the shop (`npm run test:restore`) runs in the shop's build and passed.

## 8. Performance observations

| Measured | Result |
|---|---|
| First byte of a page, on the rehearsal (not Vercel's network) | Median 71 to 75 ms and 95th percentile 110 to 118 ms, by role |
| Page ready | Median 150 to 163 ms |
| Migration 020 on the live database | 167 ms, in one transaction |
| The browser job in CI | About nine minutes: the build, both journeys twice, every screen once |
| One journey run | About two minutes |
| The Laravel suite in the sandbox | About six minutes |

Not measured: a cold start on Vercel (T-154); realistic amounts of data (T-168).

## 9. Screenshots or recording of key flows

No new picture is stored. What the browser did is kept by each run of the job `browser-on-vercel-runtime` for 14
days, as the artifact `browser-on-the-rehearsal`: the account of every journey step, the report of every screen,
and the log of the rehearsal. One line on the run page says the result; on `8fa12ab`: "27 of 27 steps held · 248
pages opened as 4 roles" and "27 of 27 steps held".

## 10. Explicit owner approval status

| | |
|---|---|
| This merge | Approved in advance by the owner's fifth answer of 17 November (DL-026): the agent merges when every check is green |
| The client's pages in the demo; sample terms and a sample signer in the demo | The owner's first and fourth answers (DL-026) |
| Migration 020 on the live database; the live database out of new preview builds | The owner's third and second answers (DL-026), carried out after his "start" of about 16:00 UTC (DL-028). The judgment that a safe way back existed is the agent's, and the owner can return it |
| Deleting the old previews of the shop | **Not approved and not done.** It waits for the owner's word (T-188) |
| Decisions the agent took inside the list lines | DL-025 and DL-027. Each can be returned by the owner |
| Any module, any gate | Not accepted. No acceptance sheet is signed (document 16 §4) |
