# Phase 1b — Furniture foundation: what was done and what it proves

**Status: evidence record.** Not an acceptance, not a release, not approval to deploy.
`main` is untouched. Everything is on branch `arena/69363bda-woodex-2030`.

**Run:** 2026-11-11 · **Source:** `woodex420/woodexinterior` @ `603428c` · **Results measured at commit:** `0f4e152`
Later commits on the branch change documentation and one preview tool file only.

---

## 1. What the owner approved

After the Phase 1a preview the owner was asked four things and answered **"start as you recommend"**.
That was read as approval of the recommended option for each:

| # | Decision | Recorded as |
|---|---|---|
| D-2 | SQLite for the visual preview, MySQL proven in CI | Accepted |
| D-5 | Time storage | **UTC**, with a date-boundary audit first. Decided; **not implemented yet** — section 9 |
| D-6 | The target repository is public | Accepted. Checked first: all 2,059 source files were already public, byte for byte — section 3 |
| — | Copy the reviewed application into `platform/` on this branch | Done |
| — | A MySQL job in CI | Done |
| — | Real-browser checks | Done |

Earlier decisions still stand: D-1 preview, D-3 rebuild one module at a time, D-4 keep the React storefront, D-7 "WOODEX OS" for the admin and "Woodex Furniture" on documents.

Not yet recorded in `docs/agency-os/`: the reversal of D-01 ("do not rewrite"). That belongs with a merge decision, which has not been asked for.

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
| `e44a584`, `dfed817` | Synthetic preview tooling |
| `657da76` | No client link is offered while the client portal is off |
| `ec3799d`, `9539265` | CI workflow for MySQL |
| `0f4e152` | One inherited test that relied on the Interior bot name as a default |

Since the import, 34 imported files were edited, 5 were removed and 14 were added. `platform/PROVENANCE.md` explains how to check any file against the source.

The existing Node applications were not changed. Their own CI passed on every push of this branch.

---

## 3. The import

| | Files |
|---|---|
| In the source `platform/` | 2,059 |
| Imported | 1,475 |
| Quarantined (11 Interior content migrations, outside the migration path) | 11 |
| Excluded (Interior pages, photographs, website bundle, logos, price data, project history, Nohemi typeface) | 573 |

**Nothing new became public.** Before the copy, every file under `platform/` in the hand-off archive was compared with the public source repository at the same commit: 2,059 of 2,059 have the same git blob id. After staging, every one of the 1,486 imported and quarantined files was compared again: identical in content and mode.
The archive's three `EXPORT_INFO` hand-off notes are not in the public repository and its Drive description says "Keep private", so they were **not** copied.

The per-file decision and hash are in `platform/PROVENANCE/import-manifest.tsv`.
Every migration was read before use: `03-MIGRATION-REGISTER.md`.

---

## 4. Module switches

`platform/config/modules.php` names twelve optional modules. `WOODEX_MODULES` lists the ones that are on. Shipped default: `lead_api` only.

| Module | Default | What is closed while it is off |
|---|---|---|
| `lead_api` | **on** | — (the public enquiry endpoint, `POST /api/public/leads`) |
| `website` | off | Public site routes, the CMS page router for unknown URLs, theme stylesheets, all Website admin screens, SEO agent and site backup schedules |
| `estimator` | off | Estimations screens and the estimator rate book |
| `client_portal` | off | Signed client pages for quotations, invoices and projects; the portal sign-in; the portal admin screen; the "Share link" on a sent quotation |
| `inbox` | off | Conversations, canned replies, chat widget and its API, the chat bell in the header |
| `automation` | off | Automations and sequences screens; the automation runner schedule |
| `marketing` | off | Marketing screens; social agent and publisher schedules |
| `whatsapp` | off | WhatsApp webhook, templates screen, webhook retry and health schedules |
| `telegram` | off | Telegram webhook; the Telegram outbox schedule |
| `google` | off | Google sign-in and Workspace connection routes |
| `mcp_bridge` | off | MCP endpoint and the bridge API |
| `legacy_bridge` | off | `forms.php` / `chat.php` bridge of the old Interior website |

What "closed" means, as tested:

- **78 routes** answer 404. The check runs first in the middleware order, before cookies are read, a session starts, a record is looked up, a signature is checked or a handler runs. A guest gets 404, not a redirect to sign-in; so does a signed-in owner.
- The screens leave the menu and the search. Six groups remain: Overview, CRM, Sales, Accounts, Projects, System.
- Eight scheduled commands do not run.
- An unknown URL gets a plain 404 instead of being handed to the Interior CMS.

**Known limits.** Tabs inside shared screens still mention switched-off modules (for example WhatsApp and Telegram under Connections, the bot under Settings). The send dialog and the invoice screen still speak of a client link. These are screen-level changes for Phase 2.

---

## 5. Tests

Measured on SQLite with the sandbox PHP build, then on MySQL in CI.

| State | Tests | Passed | Skipped | Failed |
|---|---|---|---|---|
| Source, untouched, SQLite | 2,063 | 2,054 | 0 | 9 — the sandbox PHP has no `xmlwriter` and no `pdo_mysql` |
| This branch, SQLite | 2,046 | 2,003 | 34 | 9 — the same nine |
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

- **Seeding.** An installation now seeds only roles and permissions, pipeline stages, number series and the chart of accounts. The Interior price book, quotation templates, canned replies, sequences, website theme and page templates are seeded inside the test suite only.
- **Configuration.** The public site address and the Telegram bot name no longer default to the Interior studio's. `.env.example` carries no Interior address.
- **Interior logo.** Not shipped. A non-Furniture document simply carries no default logo.

---

## 7. MySQL evidence from CI

Workflow `.github/workflows/os-ci.yml`, PHP 8.4, disposable MySQL in the runner. No deployment, no hosted database, no provider credentials.

| Check | Result |
|---|---|
| Server | MySQL 8.4.13 |
| Migrate an empty database | 93 migrations; **106 tables, 152 foreign keys** — the same counts as on SQLite |
| Quarantined Interior migrations | 0 recorded as run; all 11 still in the quarantine folder |
| Neutral seed, run as an installation would | 8 roles, 45 permissions, 8 stages, 33 accounts; **0** Interior reference rows |
| Rebuild from nothing a second time | passed |
| Test suite, first complete run (`9539265`) | 2,011 passed, 34 skipped, **1 failed** |
| Test suite after the fix (`0f4e152`) | **2,012 passed, 34 skipped, 0 failed** — 10,484 assertions, in four groups |

The one failure was an inherited test that depended on the Interior bot name being a default; it now sets its own. It could not be seen locally because on the sandbox PHP it already failed earlier for a missing extension.

The run log could not be downloaded from the sandbox, so the workflow publishes its totals and every failing test as annotations, which the GitHub API returns.

---

## 8. Preview and browser evidence

The preview now runs the code on this branch, with the shipped module default, on a new synthetic SQLite database.

**Sign-in through the platform's preview.** Measured: the preview proxy removes the `Cookie` and `Set-Cookie` headers. A correct password was accepted and the next request arrived without a session. The preview's front controller now remembers cookies on the server for requests that arrive through that proxy. Sign-in, password, CSRF and permission checks are unchanged.
Consequence: everyone who can open the preview address shares one session until sign-out or two idle hours. Acceptable only because the data is synthetic.
The request log then showed the owner's own browser signing in through the proxy and using Leads, Contacts, Tasks, Connections and Platform, with live updates answering 200.

**Checks over HTTP** against the new preview:

| Area | Result |
|---|---|
| Sign-in, sign-out, session, CSRF | 21 / 21 |
| Permissions — 97 admin screens × 4 roles | 356 / 356 derived expectations; 27 screens closed for every role; no server errors |
| Lead → quotation → approval → PDF, three roles | 33 / 33; PDF totals equal the stored totals |
| Public API | 17 / 18 — the open one is D3 |
| Throttle, assets, final database integrity | 10 / 10 |

**Real browser** (headless Chromium 153), desktop 1366 × 900 and phone 390 × 844:

| Pass | Result |
|---|---|
| Directly | 18 / 18 |
| Through a proxy that strips cookies the way the platform's does | 18 / 18 |
| JavaScript errors thrown on pages | 0 |

It covers: sign-in with a wrong and a right password, the menu, a live search on Leads, a sent quotation with its document preview, a closed screen answering 404, no sideways scrolling at phone width, the phone menu, and sign-out.
Screenshots: `evidence/browser-01` to `browser-05`.

Three requests fail in the browser console, all for files left out on purpose: the Interior photograph the sign-in page preloads, and two Nohemi font files the built stylesheet still names. Headings fall back to another font. To clear with the Phase 2 rebrand, which rebuilds the stylesheet.

One check-tool mistake, stated plainly: the first PDF text check on the new preview failed because the extractor dropped a compressed stream that ended in a newline byte. The PDF was correct; the extractor was fixed and the check re-run, 33 / 33.

---

## 9. UTC storage — decided, audited, not implemented

The application still stores Pakistan time (`APP_TIMEZONE=Asia/Karachi`, database session `+05:00`). The owner's decision is UTC storage with Pakistan-time display.

The audit that the decision asked for, measured in `platform/`:

| What has to change | Places | Files |
|---|---|---|
| "Now" or "today" taken in application time | 515 | 220 |
| Day, week, month or year boundaries | 98 | 33 |
| Queries by the calendar part of a stored moment (`whereDate` and similar) | 33 | 20 |
| A date turned into text for storage or comparison | 133 | 55 |
| Dates a person typed, parsed | 13 | 11 |
| Moments formatted for a person in templates | 407 | 88 |
| Scheduler entries with a time of day and no explicit time zone | 21 | 1 |
| Tests that pin the clock | 76 | 34 |
| Raw SQL date functions | 0 | — |

What helps: the schema already separates moments (`timestamp`) from business dates (`date`), and no date logic lives in SQL.
What it costs: about 250 files, and every "today" between midnight and 05:00 Pakistan time, where the UTC date is still yesterday.

Proposed approach, for separate approval: one business-time helper for "today" and for day and month ranges; queries by range instead of by calendar part; one display helper for templates; an explicit time zone on scheduled times; tests at the 00:00–05:00 boundary. It must land **before any real record is stored**. The Furniture database is empty today, which is the cheapest moment there will be.

---

## 10. Differences from the proposal

| Proposal said | What was done | Why |
|---|---|---|
| Routes of switched-off modules are unregistered | They stay registered and answer 404 before anything else runs | Core screens build links to them — a sent quotation carries its client link. Unregistering made those screens fail |
| `views/site` and `views/website` held back | Imported | They are the client pages of deferred modules, not Interior public templates, and tests cover them |
| `create_document_libraries` adapted | Unchanged | Non-Furniture documents need the row it writes — `03-MIGRATION-REGISTER.md`, section 3 |
| `EXPORT_INFO` kept as provenance | Not copied | Not public, and marked "Keep private". The manifest replaces it |
| `public/images/` excluded | Furniture marks kept, Interior mark excluded | Furniture PDFs use the Furniture mark |
| Identity strings adapted in 25 application files and 22 views | Not done | That is the Phase 2 rebrand. Only configuration defaults were made neutral |
| About 45 sandbox tooling files adapted | One file reused unchanged, plus a new front controller and fixtures | The rest is tied to the Interior checkout |

---

## 11. Still Interior-shaped

Do not use for real business until these are resolved and separately approved.

- The 21 settings migrations write the Interior studio's name, address, phone, e-mail, payment terms and signatories. Furniture values must come from the owner (D-8).
- The sign-in page and the admin footer name the Interior studio. Furniture quotation PDFs print terms and two signatories from source defaults.
- Validation messages use the Interior phone number as their example.
- Lead scoring, the handover checklist and the default quotation scope are written for interior fit-out work.
- The seeded accountant role cannot open the Accounts screens.
- Time is stored in Pakistan time (section 9).

---

## 12. For the owner's attention

While comparing with the public Interior repository, its file list showed `docs/2025-01-LEAD-IMPORT-REVIEW.csv`. The file was **not opened**. If it holds real enquiries, it is public today. Worth a look by someone with authority over that repository.

---

## 13. Not done, not claimed

- No deployment, no Hostinger connection, no hosted database, no real data.
- No provider integration was exercised. No mail was sent; the queue worker and the scheduler were not run.
- Nothing was merged. No pull request was opened.
- The source project's own claims about its release gates were not re-verified beyond the test suite.
