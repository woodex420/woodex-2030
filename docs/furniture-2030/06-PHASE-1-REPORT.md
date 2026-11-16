# Phase 1 report: make what exists correct

**Date:** 2026-11-16 · **Base:** `main` at `9b8cdca` · **Branch:** `arena/58cf1d96-woodex-2030` · **Approved by the owner** as OD-9 in [01](01-OWNER-DECISIONS.md).

## 1. Result

All eight items of Phase 1 in [03](03-LAUNCH-PLAN.md) are done, and eight further defects found on the way are fixed (§4). No table, column or route was added or changed, and no production data was read or written.

The audit had found that the project's checks were green while the software was broken in places the checks did not look. Phase 1 fixed those places and then made the checks look there, so the same kind of defect now fails the build:

| Before | Now |
|---|---|
| On PostgreSQL, the database production runs on, 1 of 11 module test suites passed. Creating a supplier, a ticket, a delivery run or a journal entry failed. | 12 of 12 suites pass on PostgreSQL and on SQLite, 662 checks on each. They run on both in every build. |
| The tool that copies a database into PostgreSQL reported success after emptying nine tables. | It copies every row or changes nothing, and a test counts the rows itself. |
| Purchase Orders and Production crashed when opened. The error screen could not be left. | Both open. A browser check opens all 43 dashboard screens in every build. |
| A production sign-in was printed in a document, and the secret scan could not see it. | The line is removed, and the scan reads prose. **That does not make the password private again: see §2.** |
| 2 high-severity dependency advisories. | 0. |
| Not in the audit, found on the way: in the single-process form planned for Hostinger, the dashboard was a blank page, and the check for that form passed. | Fixed, and that check now loads what the page loads. Vercel was never affected. |

One finding stays open and only the owner can close it: F-01, in §2.

## 2. What the owner needs to do

1. **Change the production owner password. Today.** Sign in to the production dashboard → Settings → Your password. Removing the line from the document does not make the password private again: anything committed to a repository stays in its history. Treat it as known to others until it is changed.
2. **Make the repository private.** GitHub → the repository → Settings → General → Danger Zone → Change repository visibility → Private. The agent tried on 2026-11-16 and GitHub refused (HTTP 403): its access can read and push, not change settings. Nothing on the site loads files from GitHub, so the site is unaffected.
3. **After the deployment, open Purchase Orders and Production on the live dashboard.** The browser check ran on a test copy of the system. Nobody has signed in to production during this work, so those two screens have not been seen working there.

## 3. The eight items

| # | Item | Result | How it is known |
|---|---|---|---|
| 1.1 | Return the new id for every insert on PostgreSQL | Done. All 63 tables that have an integer id are listed; 41 were missing. | `npm run test:return-id` builds the real schema and compares the list with the database's own catalogue, in both directions. Run against the old code it reports 8 problems. |
| 1.2 | Fix the remaining PostgreSQL-only failures | Done. Four causes, listed in §4. | The module suites below. |
| 1.3 | Run every module test on both databases, in the gate and in CI | Done. One command, `npm run test:modules`. | 12 suites × 2 engines, 662 checks each, 0 failures. |
| 1.4 | Fix the migrator | Done. Rewritten; runbook written. | `npm run test:migrate`: 41 checks. The old tool fails it. |
| 1.5 | Fix the two screens and the error screen; one shared products type | Done. | The browser check in 1.6. |
| 1.6 | A gate that signs in and opens every dashboard screen | Done. `npm run e2e:routes`, in CI. | 43 of 43 screens. Run against the old code it fails on 5. |
| 1.7 | Remove the credential line; make the secret scan read prose | Done. | The new scan fails on the old line and passes without it. It tests itself on every run. |
| 1.8 | Status label, the empty-id request, the high advisories | Done. | Browser check; `npm audit`: 0 high. |

## 4. Defects fixed

### Found by the audit

| Finding | Cause | Fix |
|---|---|---|
| F-02 | The list of tables for which PostgreSQL is asked to report the new id stopped at the first 22. Inserts into the other 41 reported id 0, so a purchase order's lines were filed under order 0. | The list is complete, and a test compares it with the database. |
| F-03 | Four things that SQLite accepts and PostgreSQL does not. (a) Timestamps and dates arrived as objects, not text: scheduling a meeting failed. (b) Numbering the next reference (`EXP-0007`) converted every existing reference to a number, including ones that are not numbers: recording an expense failed. (c) An integer was written into a true/false column: opening a ticket failed. (d) An update named one column twice: resolving a ticket and completing a service visit failed. | (a) is fixed once, where rows enter the application, so all 54 places that read rows get the same values on both databases. (b) only converts references that have the expected form. (c) and (d) are corrected in the statements. |
| F-04 | The copy tool emptied each table just before filling it, and emptying a table also empties the tables that refer to it. Four tables were filled before a table they refer to, then emptied again. Each table was checked only when it was filled. | The target is emptied once. Tables are copied in the order the database's own 86 references require. Every table is checked after the last one is copied. All of it is one transaction: a failure leaves the target untouched. |
| F-05 | Two screens read the product list as a plain list; the server sends `{ total, items }`. Each screen had written the shape down for itself, so the type checker had nothing to compare against. The error screen's "Go to Overview" changed the address without telling the router. | The shape is written once, in `useProducts()`, and all eight screens that read products use it. The error screen now covers only the page, so the menu stays; choosing another screen clears it; "Go to Overview" is an ordinary link. |
| F-01 | See §2. | Line removed; scan extended. Not closed. |
| F-12 | `body-parser` 1.20.8 parses every request; `zod` was declared in the storefront and used nowhere. | `body-parser` 1.20.9. `zod` removed. |
| F-14 | The status label showed "CONNECT…" while connected. Stock Reservations asked for availability with no product chosen. | Both fixed. The Interior branding in the dashboard is left for Phase 3: it needs the business facts (Q-4). |

### Found during the phase

| Defect | Found by | Fix |
|---|---|---|
| Analytics drew nothing and logged an error for every point when a chart's values were all zero, which is the state of a new site. | The new browser check | The chart's scale falls back to 1. |
| Two seeded pages named photographs that do not exist. | The new browser check | They name files that exist, and a check fails if a seeded page names a missing one. |
| **In the single-process shape planned for Hostinger, the dashboard was a blank page.** The server answered every address under `/dashboard/` with the dashboard's HTML shell, including the dashboard's own script. `smoke:hostinger` passed because it fetched only the shell. Vercel is not affected. Recorded as F-16. | Pointing the new browser check at the production build | A real file under `/dashboard/` is sent as itself. `smoke:hostinger` now fetches every script and stylesheet the shells name, and fails on the old code with 4 problems. |
| A DATE column came back from PostgreSQL as midnight of that day, and so as a different day on a server whose clock is not UTC. | The module suites | Both databases return `YYYY-MM-DD`. |
| The old secret scan read each file as last committed, so it could not see a change being committed, or a new file. | Rewriting it | It reads the files as they are. |
| The copy tool printed its target with the password masked by a pattern that printed half of a password containing `:` and all of one containing `/`. | Reading it | It asks the URL parser where the password is. Tested. |
| The copy tool created a table in the source database it was reading. | Rewriting it | The source is opened read-only. |
| The check that guarded the copy order tested four hand-picked pairs, and passed while four others were wrong. | Rewriting the tool | The order now comes from the database; the check guards that. |

## 5. Changes in behaviour

Nothing here changes a route, a screen layout or stored data. These are the visible differences:

- **Dates from PostgreSQL.** A DATE column (a warranty end date, a due date) is now returned as `2027-11-10` instead of `2027-11-10T00:00:00.000Z`. This is what SQLite always returned and what the screens were written for.
- **Amounts from PostgreSQL.** Columns of type NUMERIC are now returned as numbers instead of text, again as on SQLite. `"1500.00" + 250` used to give `"1500.00250"`.
- **The copy tool refuses more.** It will not copy into a database that holds rows unless told to replace them (`--truncate`), and for a server it needs a second flag (`--confirm-replace`). A new database always holds 17 rows that the migrations create, so in practice both flags are needed. See the [runbook](../operations/DATA-MIGRATION-RUNBOOK.md).
- **The secret scan is stricter.** It fails on a password written in a document, an `.env` example with a real-looking value, or a connection string that carries a password.
- **The build is slower and stricter.** CI now also runs every module suite on PostgreSQL, the copy test, the id test, the single-process smoke test, and the browser check of every screen, on the dev server and in the built bundle.

## 6. Tests

Run on the final state of the branch, Node 22.22, SQLite and PGlite (PostgreSQL built into Node; the same engine, without a server). [Results](evidence/results/phase1-gate-2026-11-16.tsv).

| Check | Result |
|---|---|
| `secret-scan`, `docs:verify`, `check:deploy`, `seed:data:check`, `test:router`, `check:migration`, `test:blocks` | pass |
| `typecheck:all`, `smoke:render` | pass |
| `qa` on SQLite, `qa:pg` on PGlite | 108 checks each, pass |
| `test:modules` | 12 suites on each engine; 662 checks on SQLite, 662 on PGlite; 0 failed ([per suite](evidence/results/phase1-modules-both-engines-2026-11-16.json)) |
| `test:return-id` | pass |
| `test:migrate` | 41 checks, pass |
| The 18 existing module and integrity tests, one by one | pass |
| `vercel:build` | 76 of 76 routes prerendered |
| `smoke:hostinger` | 18 checks, pass |
| `ci-test`, the whole CI script, run locally: the four browser journeys, then `e2e:routes` on the dev server | pass; 43 of 43 screens ([per screen](evidence/results/phase1-dashboard-routes-2026-11-16.json)) |
| The same run, last step: `e2e:routes` on the production build (the `vercel:build` output, dashboard served under `/dashboard` by one process) | pass; 43 of 43 screens ([per screen](evidence/results/phase1-dashboard-routes-production-build-2026-11-16.json)) |
| `npm audit` | 0 critical, 0 high, 11 moderate |

Each new check was also run against the old code, to confirm that it fails there:

| New check | Against the old code |
|---|---|
| `test:return-id` | 8 problems |
| `test:migrate` | fails: 188 rows in the target instead of 870 |
| `e2e:routes` | fails on 5 screens ([result](evidence/results/phase1-dashboard-routes-on-main-2026-11-16.json)) |
| `secret-scan` | fails on the credential line |
| `test:blocks` (image check) | 2 problems |
| `check:migration` | 2 problems |
| `smoke:hostinger` (script and stylesheet checks) | 4 problems |

## 7. Not done, and not verified

- **Production was not tested.** No sign-in, no write, no database connection. The fixes are proven on PGlite and, after merge, by CI. The `pg` driver that production uses reaches the same code, but it was not run here: there is no PostgreSQL server in the work environment.
- **The live screens were not seen.** See §2, item 3.
- **`e2e:routes` opens screens; it does not press buttons.** A screen that opens and then fails when a form is saved passes it. The four journeys and the module suites cover actions, not every action.
- **Two test suites are still proven on one database only.** `test-audit-log` checks the audit trail on SQLite; `test-throttle` checks the cross-process lockout on SQLite. Both have a small PostgreSQL part. The reasons are in the header of `scripts/test-modules.mjs`.
- **A database seeded before this change keeps the two broken image names**, including the Vercel demo, until those pages are edited.
- **11 moderate advisories remain:** react-router 6 in the storefront, which needs a major upgrade, and build tools that never run in production.
- **The Laravel "WOODEX OS" branch** (`arena/69363bda-woodex-2030`) was read and left untouched (OD-10). It changes three of the same dashboard files and `apps/api/server.mjs`; merging it later will need those resolved by hand.
- **Git history was not rewritten.** That would break every existing clone and pull request, and it cannot recall anything already published. Changing the password is the fix.

## 8. Decisions taken during the phase

| Decision | Why |
|---|---|
| Fix the PostgreSQL value differences once, in the adapter, rather than in each of 54 places that read rows. | One place to get right, and a new screen cannot forget it. |
| Keep a list of tables that report an id, guarded by a test, instead of asking for the id on every insert. | Nine tables have no integer id, and asking for one there is an error. |
| The copy tool replaces; it never merges. | Merging two databases needs rules about which row wins. The tool has none, so it refuses instead of guessing. |
| Read the route list for the browser check from the router's source. | A screen added later is covered without anyone remembering to add it. If a route is written in a way the check cannot read, it stops with an error. |
| Remove `zod` instead of upgrading it. | Nothing imports it. |
| Keep the existing commit identity. | It is the same GitHub account as the rest of `main`'s history. |
| Leave Git history as it is. | See §7. |

## 9. Files

56 files changed or added outside this folder; 9 of them are new.

| Area | Files |
|---|---|
| API | `apps/api/db-adapters.mjs`, `apps/api/server.mjs` |
| Dashboard | `lib/api.ts`; `components/ui/ErrorBoundary.tsx`, `components/layout/AppShell.tsx`, `components/layout/Topbar.tsx`, `components/charts/index.tsx`; pages `Catalog`, `Ecommerce`, `ProductEditor`, `Production`, `PurchaseOrders`, `Quotations`, `StockReservations`, `StockTransfers` |
| New checks | `scripts/test-modules.mjs`, `scripts/test-return-id.mjs`, `scripts/test-pg-migrate.mjs`, `scripts/e2e-dashboard-routes.mjs` |
| New shared test code | `scripts/lib/api-under-test.mjs`, `scripts/lib/api-under-test-child.mjs`, `scripts/lib/pg-fixture.mjs`, `scripts/lib/browser.mjs` |
| Rewritten | `scripts/pg-migrate.mjs`, `scripts/secret-scan.mjs` |
| Changed checks | the twelve module suites `scripts/test-*.mjs`; `scripts/check-migration-coverage.mjs`, `scripts/test-block-coverage.mjs`, `scripts/smoke-hostinger.mjs`, `scripts/ci-test.mjs`; the four `scripts/e2e-*.mjs` journeys |
| CI | `.github/workflows/ci.yml` (one step added) |
| Dependencies | `package.json`, `package-lock.json`, `apps/storefront/package.json` |
| Documents | `AGENTS.md`; `docs/operations/DATA-MIGRATION-RUNBOOK.md` (new); `docs/architecture/POSTGRES-CUTOVER.md`, `docs/DEPLOYMENT.md`, `docs/README.md`, `docs/agency-os/00-CURRENT-STATE.md`, `docs/agency-os/STATUS-REPORT.md` |

## 10. Next

Phase 1 does not authorise Phase 2. Phase 2 (the real catalogue) needs two answers first: Q-5, the source of the product list, and Q-4, who supplies the written business facts. Both are in [01](01-OWNER-DECISIONS.md).
