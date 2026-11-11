# Phase 1b — Furniture foundation: what was done and what it proves

**Status: evidence record.** Not an acceptance, not a release, not approval to deploy.
`main` is untouched. Everything is on branch `arena/69363bda-woodex-2030`. Nothing is merged and no pull request is open.

**Run:** 2026-11-11 · **Source:** `woodex420/woodexinterior` @ `603428c` · **Results measured at commit:** `0f4e152`
Later commits on the branch change documentation and preview tooling only.

Every number below was measured in this run and re-checked against the repository, the CI results or the request log before this record was written. Where something is an inference, it says so.

---

## 1. What the owner approved

After the Phase 1a preview, four next steps were recommended. The owner replied **"start as you recommend"**.
That reply was read as approval of all four:

| Step | Result |
|---|---|
| Copy the reviewed application into `platform/` on this branch | Done — section 3 |
| Prove it on MySQL in CI, because this sandbox cannot run MySQL (D-2) | Done — section 7 |
| Check it in a real browser | Done — section 8 |
| Store time in UTC, after a date-boundary audit (D-5) | **Audit done. The change itself is not made** — section 9 |

The copy puts the code in a public repository (D-6). Before copying, it was checked that the copy publishes nothing that was not already public — section 3.

If that reading is wrong for any step, it can be backed out: the work is a short series of commits on one branch, listed in section 2.

Earlier decisions still stand: D-1 preview first, D-3 rebuild one module at a time, D-4 keep the React storefront, D-7 "WOODEX OS" for the admin and "Woodex Furniture" on documents.
Still open: D-8 Furniture prices, terms, wording and signatories; D-9 which existing records to migrate; D-10 Hostinger plan details.

Not yet recorded in `docs/agency-os/`: the reversal of its decision D-01 ("do not rewrite"). That belongs with a merge decision, which has not been asked for.

---

## 2. What is in the repository now

| Commit | What it does |
|---|---|
| `6926664` | The proposal and the Phase 1a evidence (documents only) |
| `dfa43b0` | **Verbatim import** of the reviewed subset into `platform/`. No file edited |
| `5a34367` | Test triage: tests that needed Interior content or Interior repository tooling |
| `7630726` | Module switches |
| `e93388d` | Three defects found in the source, each with a test |
| `4884cbe` | Business-neutral seed and configuration defaults |
| `e44a584`, `dfed817`, `b5ea714` | Synthetic preview tooling |
| `6971584` | Sandbox test runner that reports failures by cause |
| after `6971584` | Corrected evidence record |
| `657da76` | No client link is offered while the client portal is off |
| `ec3799d`, `9539265` | CI workflow for MySQL |
| `0f4e152` | One inherited test that relied on the Interior bot name as a default — section 7 |

`platform/` holds 1,497 files, 15.2 MB.
Since the verbatim import, 33 imported files were edited, 5 were removed and 14 were added. `platform/PROVENANCE.md` explains how to check any file against the source.

The existing Node applications were not changed. Their own CI passed on every push of this branch.

---

## 3. The import

| | Files |
|---|---|
| In the source `platform/` | 2,059 |
| Imported | 1,475 |
| Quarantined (11 Interior content migrations, kept outside the migration path, never run) | 11 |
| Excluded (Interior pages, photographs, website bundle, logos, price data, project history, Nohemi typeface) | 573 |

The per-file decision and hash are in `platform/PROVENANCE/import-manifest.tsv`.
Every migration was read before use: `03-MIGRATION-REGISTER.md`.

**Nothing new became public.**

- Before the copy, every file under `platform/` in the hand-off archive was compared with the public source repository at the same commit: 2,059 of 2,059 have the same git blob id.
- After staging, all 1,486 imported and quarantined files were compared again: identical in content and mode.
- One more file comes from outside `platform/`: `tools/preview/NativePreviewTransport.php`, copied unchanged from the archive's `tools/sandbox/`. It was not part of the blob comparison. The archive's own manifest lists it as a file of the same public repository, and it is a 4 KB address helper with no business content.
- The archive's three `EXPORT_INFO` hand-off notes are not in the public repository, and the Drive file is described as "Keep private". They were **not** copied.

**How the Interior repository was touched.** Its visibility, file list and blob ids were read through GitHub's public API, for the comparison above. Nothing was written to it, it was not cloned, and no file in it was opened. The Interior website, admin and database were never contacted.

---

## 4. Module switches

`platform/config/modules.php` names twelve optional modules. `WOODEX_MODULES` lists the ones that are on. Shipped default: `lead_api` only.

| Module | Default | Routes | What is closed while it is off |
|---|---|---|---|
| `lead_api` | **on** | 1 | — (the public enquiry endpoint, `POST /api/public/leads`) |
| `website` | off | 26 | Public site routes, theme stylesheets, all Website admin screens, the CMS page router for unknown URLs; the SEO agent and site backup schedules |
| `estimator` | off | 4 | Estimations screens and the estimator rate book |
| `client_portal` | off | 16 | Signed client pages for quotations, invoices and projects; the portal sign-in; the portal admin screen; the "Share link" on a sent quotation |
| `inbox` | off | 8 | Conversations, canned replies, the chat widget and its API, the chat bell in the header |
| `automation` | off | 2 | Automations and sequences screens; the automation runner schedule |
| `marketing` | off | 2 | Marketing screens; the social agent and publisher schedules |
| `whatsapp` | off | 3 | WhatsApp webhook, templates screen; the webhook retry and health schedules |
| `telegram` | off | 2 | Telegram webhook; the Telegram outbox schedule |
| `google` | off | 7 | Google sign-in and Workspace connection routes |
| `mcp_bridge` | off | 5 | MCP endpoint and the bridge API |
| `legacy_bridge` | off | 3 | `forms.php` / `chat.php` bridge of the old Interior website |

What "closed" means, as tested:

- Of 191 routes, 79 belong to a module and **78 are closed** by default. A closed route answers 404. The check runs first, before cookies are read, a session starts, a record is looked up, a signature is checked or a handler runs. A guest gets 404, not a redirect to sign-in; so does a signed-in owner.
- The screens leave the menu and the search. Six groups remain: Overview, CRM, Sales, Accounts, Projects, System.
- Eight scheduled commands do not run.
- An unknown URL gets a plain 404 instead of being handed to the Interior CMS.

**Known limits.** Tabs inside shared screens still mention switched-off modules — for example WhatsApp and Telegram under Connections, and the chat bot tab under Settings. The send dialog and the invoice screen still speak of a client link. These are screen-level changes for Phase 2.

---

## 5. Tests

| State | Tests | Passed | Skipped | Failed |
|---|---|---|---|---|
| Source, untouched, SQLite in the sandbox | 2,063 | 2,054 | 0 | 9 — the sandbox PHP has no `xmlwriter` (7 tests draw a QR code) and no `pdo_mysql` (2 installer tests) |
| This branch, SQLite in the sandbox | 2,046 | 2,003 | 34 | 9 — the same nine tests, each checked for the same cause |
| **This branch, MySQL 8.4, CI** | 2,046 | **2,012** | 34 | **0** |

How 2,063 became 2,046: 34 removed, 17 added.

| Action | Tests | What |
|---|---|---|
| Removed | 34 in 5 files | `DirectSyntheticPreviewTest` (19) — the sign-in-free demo launcher, not shipped. `DeployManifestTest` (3) — the Interior repository's deploy manifest. `HomeFileTest` (4), `HomeCutoverTest` (2), `LegacyUrlMapTest` (6) — the Interior home page and legacy URL map |
| Skipped by name, with a reason | 34 | The two estimator suites (24), which copy the Interior price file. Ten tests that read Interior page files or legacy URL data. Each runs again the moment its file exists |
| Adapted | 5 | Four that used an Interior logo or the Interior 404 page only as a sample. One that took the Interior bot name from a default |
| Added | 17 | `ModuleSwitchTest` (11), `SourceDefectFixesTest` (4), `ProductionSeederScopeTest` (2) |

The inherited suite runs with every module switched on, so that code stays tested. `ModuleSwitchTest` pins the shipped default.

**Not gates yet.** The codebase's own style checker reports 12 issues and its static analysis reports 3 findings. All of them are present in the source; none was introduced here, and the new and changed code passes both. Neither is a CI step until those are cleared.

---

## 6. Defects fixed, and other changes to behaviour

| # | Defect in the source | Fix |
|---|---|---|
| D1 | An unsigned post to the WhatsApp webhook, and the verification handshake, threw a server error when WhatsApp was not connected | Both refuse with 403 |
| D2 | A "not found" raised inside a Livewire action came back as a redirect | The CMS page router answers page reads only and no longer claims Livewire's addresses |
| M1 | One migration's rollback dropped a table name that was never created | Corrected |

All three also exist in the Interior source and are worth fixing there.
Still open by choice: D3 — a GET on the POST-only enquiry address answers 404 rather than 405. To decide with the `/api/v1` contract.

Changed for Furniture:

- **Seeding.** An installation now seeds only roles and permissions, pipeline stages, number series and the chart of accounts. The Interior price book, quotation templates, canned replies, sequences, website theme and page templates are seeded inside the test suite only. The Interior-flavoured demo seeder no longer runs automatically.
- **Configuration.** The public site address and the Telegram bot name no longer default to the Interior studio's. `.env.example` carries no Interior address.
- **Interior logo.** Not shipped. A non-Furniture document simply carries no default logo.
- **Client link.** A sent quotation offers no "Share link" while the client portal is off.

---

## 7. MySQL evidence from CI

Workflow `.github/workflows/os-ci.yml`, PHP 8.4, a disposable MySQL inside the runner. No deployment, no hosted database, no provider credentials.

| Check | Result |
|---|---|
| Server | MySQL 8.4.13 |
| Migrate an empty database | 93 migrations; **106 tables, 152 foreign keys** — the same counts as on SQLite |
| Quarantined Interior migrations | 0 recorded as run; all 11 still in the quarantine folder |
| Neutral seed, run as an installation would | 8 roles, 45 permissions, 8 stages, 33 accounts; **0** Interior reference rows |
| Rebuild from nothing a second time | passed |
| Test suite, first complete run (`9539265`) | 2,011 passed, 34 skipped, **1 failed** |
| Test suite after the fix (`0f4e152`) | **2,012 passed, 34 skipped, 0 failed** — 10,484 assertions, in four groups |

**The one failure was introduced here, and CI caught it.**
Commit `4884cbe` removed the Interior bot name as a configuration default. One inherited test relied on that default.
In the sandbox that test was already one of the nine that fail for a missing extension. After the change it still failed — for a new reason. The list of failing tests did not change, so the new reason went unnoticed: names were compared, not causes.
The test now sets its own bot name. The sandbox summary, `platform/tools/preview/sandbox/suite-summary.py`, now prints the cause of every failure and rejects a run with an unexplained one. Run against the earlier output, it flags this failure.
The message of commit `0f4e152` says the failure "could not be caught locally". That sentence is wrong; this paragraph is the correct account.

The run log could not be downloaded from the sandbox, so the workflow publishes its totals and every failing test as annotations, which the GitHub API returns.

---

## 8. Preview and browser evidence

The preview runs the code on this branch, with the shipped module default, on a new synthetic SQLite database.

### Sign-in through the platform's preview

Measured: the platform's preview proxy removes the `Cookie` and `Set-Cookie` headers. A correct password was accepted and the next request arrived without a session.
The preview's front controller now remembers cookies on the server for requests that arrive through that proxy. Sign-in, password, CSRF and permission checks are unchanged.

**Consequence: everyone who opens the preview address shares one session**, until someone signs out or two hours pass without a request. Anyone's sign-out signs everyone out. This is acceptable only because every record is synthetic. It must never be used with real data.

The request log then showed a browser arriving through the platform's public edge, between 18:52 and 19:14 UTC:

| | |
|---|---|
| Requests | 56 |
| Sign-ins accepted | 3 |
| Screens opened, all answered 200 | Dashboard, Leads, one lead, Contacts, Tasks, Quotations, New quotation, Accounts, Payables, Expenses, Connections, Platform |
| Live updates | 15, of which 14 answered 200 |

The visitor was sent back to sign-in twice. The first time was 13 seconds after an automated check signed out of the session they then shared. The second came 71 seconds later; its cause was not established. The likely one is the change made at about that time, which gave each proxy token its own cookie file and so emptied the shared one.
Since that change, an automated check signing out no longer signs the visitor out: the next check signed out at 18:59:57 and the visitor's following requests still answered 200.

### Checks over HTTP

| Area | Result |
|---|---|
| Sign-in, sign-out, session, CSRF | 21 / 21 |
| Permissions — 97 admin addresses as each of 4 roles, 388 requests | 356 have an expectation derived from the route's own rule and the role's stored permissions: **356 / 356 met**. The other 32 — three guest-only pages and five two-factor helper addresses, as each role — were recorded without a verdict. 27 addresses answer 404 for every role. No server errors |
| Lead → quotation → approval → PDF, three roles | 33 / 33; PDF totals equal the stored totals |
| Public API | 17 / 18 — the open one is D3 |
| Throttle, assets, final database integrity | 10 / 10 |

### Real browser

Headless Chromium 153, desktop 1366 × 900 and phone 390 × 844.

| Pass | Result |
|---|---|
| Directly | 18 / 18 |
| Through a proxy that strips cookies the way the platform's does | 18 / 18 |
| JavaScript errors thrown on pages | 0 |

It covers: sign-in with a wrong and a right password, the menu, a live search on Leads, a sent quotation with its document preview, no "Share link", a closed screen answering 404, no sideways scrolling at phone width, the phone menu, and sign-out.
Screenshots: `evidence/browser-01` to `browser-05`.

Three requests fail in the browser, all for files left out on purpose: the Interior photograph the sign-in page preloads, and two requested Nohemi font files (the built stylesheet names three). Headings fall back to Inter, which ships. To clear with the Phase 2 rebrand, which rebuilds the stylesheet.

One check-tool mistake: the first PDF text check on the new preview failed because the extractor dropped a compressed stream that ended in a newline byte. The PDF was correct; the extractor was fixed and the check re-run, 33 / 33.

---

## 9. UTC storage — decided, audited, not implemented

The application still stores Pakistan time: `APP_TIMEZONE=Asia/Karachi`, database session `+05:00`.
The owner's decision is UTC storage with Pakistan-time display.

The audit that decision asked for. Counts are text matches in `platform/`, so they are a map of where to look, not a list of defects.

| What has to be looked at | Places | Files | Where |
|---|---|---|---|
| "Now" or "today" taken in application time — `now(`, `today(` | 517 | 220 | `app` |
| Day, week, month, quarter or year boundaries — `startOf…(`, `endOf…(` | 98 | 33 | `app` |
| Queries by the calendar part of a stored moment — `whereDate(`, `whereMonth(`, `whereYear(`, `whereDay(`, `whereTime(` | 35 | 20 | `app` |
| A date turned into text — `toDateString(`, `toDateTimeString(` and similar | 138 | 60 | `app` |
| Text parsed into a date — `Carbon::parse(`, `createFromFormat(` | 19 | 15 | `app` |
| Moments formatted for a person — `->format(`, `->diffForHumans(` and similar | 409 | 88 | templates |
| Scheduler entries with a time of day and no explicit time zone | 21 | 1 | `routes/console.php` |
| Raw SQL date functions | 0 | — | `app` |
| Tests that pin the clock | 76 | 34 | `tests` |

Together: 229 of 702 application files, 88 of 208 templates and the scheduler file — 318 files — plus 34 test files.

What helps: the schema already separates moments from business dates — the migrations declare a moment in 199 places and a plain date in 24 — and no date logic lives in SQL.
What it costs: every "today" between midnight and 05:00 Pakistan time, when the UTC date is still yesterday.

Proposed approach, for separate approval: one business-time helper for "today" and for day and month ranges; queries by range instead of by calendar part; one display helper for templates; an explicit time zone on scheduled times; tests at the 00:00–05:00 boundary.
It must land **before any real record is stored**. The Furniture database is empty today, which is the cheapest moment there will be.

---

## 10. Differences from the proposal

| Proposal said | What was done | Why |
|---|---|---|
| Routes of switched-off modules are unregistered | They stay registered and answer 404 before anything else runs | Core screens build links to them — a sent quotation carries its client link. Unregistering made those screens fail |
| `views/site` and `views/website` (7 files) held back | Imported | They are the client pages of deferred modules, not Interior public templates, and tests cover them |
| `create_document_libraries` adapted so only Furniture rows are written | Unchanged | Non-Furniture documents need the row it writes — `03-MIGRATION-REGISTER.md`, section 3. The one migration that was edited is the rollback fix M1 |
| Interior price book, templates and message copy replaced in the seeders | Kept, but run inside the test suite only | The inherited tests rely on them; an installation no longer writes them |
| `EXPORT_INFO` kept as provenance | Not copied | Not public, and marked "Keep private". The manifest replaces it |
| `public/images/` excluded | Furniture marks kept, Interior mark excluded | Furniture PDFs use the Furniture mark |
| Identity strings adapted in 25 application files and 22 views | Not done | That is the Phase 2 rebrand. Only configuration defaults were made neutral |
| About 45 sandbox tooling files adapted | One file reused unchanged, plus a new front controller and fixtures | The rest is tied to the Interior checkout |
| Roughly 1,300 files and 15 MB | 1,497 files, 15.2 MB | The file estimate was low; the size is as predicted |

---

## 11. Still Interior-shaped

Do not use for real business until these are resolved and separately approved.

- The 21 settings migrations write the Interior studio's name, address, phone, e-mail, website, payment terms and signatories. Furniture values must come from the owner (D-8).
- The sign-in page and the admin footer name the Interior studio. Furniture quotation PDFs print terms and two signatories from source defaults.
- Validation messages use the Interior phone number as their example.
- Lead scoring, the handover checklist and the default payment milestones of a new quotation are written for interior fit-out work.
- The seeded accountant role cannot open the Accounts screens.
- Time is stored in Pakistan time (section 9).

---

## 12. For the owner's attention — the Interior repository

The hand-off archive withheld 157 files of the Interior repository because they may hold private material. Its manifest names them:

- 156 files under `docs/audit/evidence/` — screenshots, PDFs and data files, "may contain account/customer/financial details; not reviewed for redistribution";
- `docs/2025-01-LEAD-IMPORT-REVIEW.csv`, 455 bytes — "Interior lead-import report excluded by data boundary".

The repository they were withheld from is **public**. If the manifest is right, those files can be read by anyone today.
None of them was opened or downloaded here, and it was not checked whether they are still present. Worth a look by someone with authority over that repository.

---

## 13. Not done, not claimed

- No Laravel deployment, no Hostinger connection, no hosted database, no real data. The existing Vercel Git integration automatically builds the unchanged Node storefront/dashboard on branch pushes; GitHub reports completed deployments. This does not deploy `platform/`: `vercel.json` still builds the Node apps and serves their output.
- No provider integration was exercised. No mail was sent; the queue worker and the scheduler were not run.
- The browser assets were not rebuilt. `platform/public/build` is the source's build.
- Nothing was merged. No pull request was opened.
- The source project's own claims about its release gates were not re-verified beyond the test suite.
- The HTTP and browser checks ran on SQLite. On MySQL, the evidence is the test suite and the schema checks in section 7; no person has used a MySQL-backed copy.

## 14. Reproducibility follow-up

The repository runner was executed end to end from the test mirror: **2,003 passed, 34 skipped, 9 environment failures**, all classified by cause. The summary exited 0; the earlier regression output exits 1.

Reset-recovery tools are retained outside Git in `/home/user/_woodex_os_source/phase-1b/`. A rebuild into an empty, separate runtime installed PHP, Composer and all 166 packages, then created 93 migrations, 106 tables, 8 roles, 45 permissions and four synthetic users. Auth, permissions, API, workflow and miscellaneous checks reproduced the section 8 results. Chromium reproduced **18/18 directly and 18/18 through the cookie-stripping proxy**, with no JavaScript exceptions and the same known missing assets. The temporary server was stopped; the owner-facing preview was not rebuilt or signed out.
