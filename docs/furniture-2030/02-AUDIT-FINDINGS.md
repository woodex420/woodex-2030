# Audit findings

**Commit audited:** `9b8cdca` (`main`, merge of PR #32) · **Date:** 2026-11-16 · **Environment:** Node 22.22, local SQLite and PGlite, headless Chromium. No change was made to the code, to production, or to any hosted database.

The first audit ran on 2026-11-11 and its report was lost with the sandbox it was written in. Everything below was re-run on 2026-11-16 unless a line says otherwise.

## 0. Status after Phase 1 (2026-11-16)

The sections below are the audit as it was written, against commit `9b8cdca`. They are kept as the record of what was found. This table says where each finding stands now; the detail is in the [Phase 1 report](06-PHASE-1-REPORT.md).

| Finding | Status | What changed |
|---|---|---|
| F-01 production login in a public document | **Open — owner action** | The line is gone from the current files and the secret scan now reads prose, so it cannot come back unnoticed. Deleting a line does not un-publish it. The finding closes when the owner confirms that the password has been changed. |
| F-02 ids of 0 on PostgreSQL | **Fixed** | All 63 tables with an integer id are listed; `npm run test:return-id` compares the list with the database catalogue. |
| F-03 other PostgreSQL-only failures | **Fixed** | Four causes found and fixed. Twelve module suites pass on both engines, 662 checks each. |
| F-04 migrator loses data, reports success | **Fixed** | Rewritten; `npm run test:migrate` counts rows itself and reproduces the old failure. |
| F-05 two screens crash; error card cannot be dismissed | **Fixed** | A browser gate now opens all 43 dashboard routes in CI. |
| F-06 owner-only routes | Open | Phase 3. Needs Q-6. |
| F-07 demo catalogue, no SKU or variants | Open | Phase 2. Needs Q-4 and Q-5. |
| F-08 floating-point money · F-09 no SQLite foreign keys · F-10 missing launch features · F-11 demo crawlable · F-13 Hostinger guide · F-15 company facts | Open | Later phases, as planned in [03](03-LAUNCH-PLAN.md). |
| F-12 dependency advisories | **High ones fixed** | 2 high → 0. 11 moderate remain: react-router 6 in the storefront, and build tooling. |
| F-14 smaller defects | **Two of three fixed** | Status pill and the empty-id request. Interior branding in the dashboard stays open (needs Q-4). |

Corrections to this audit, found while fixing:

- **F-02 undercounted.** It reported 30 unlisted tables: those whose generated id the server reads back. Reading the catalogue instead of the source text gives 41 unlisted tables out of 63 with an integer id.
- **F-03's list was partly an artefact.** The harness used for it loaded data through the broken migrator (F-04) and some tests opened the SQLite file directly, so a few items were symptoms of those. Run without either, the real PostgreSQL-only causes were four: timestamps and dates arriving as objects instead of text (scheduling a meeting; warranty end dates), reference numbering that cast non-numeric text to a number (recording an expense), an integer written into a boolean column (opening a ticket), and one column assigned twice in an update (resolving a ticket, completing a service visit — not in the audit's list).
- **F-09's count.** The catalogue has 86 foreign keys, not 59.
- **Route count.** The dashboard declares 43 distinct routes; `/support` is declared twice and the second declaration, a "planned" placeholder, can never be reached.
- **§1 overstated `smoke:hostinger`.** It says that check shows the single-process shape serves the dashboard. The check fetched the dashboard's HTML shell and nothing the shell loads; in a browser the dashboard was blank. See F-16 below.
- **§3 said the browser journeys could not run here.** They can, with a Chromium taken from the npm registry; [`evidence/README.md`](evidence/README.md) records how. All four journeys and the new screen check were run locally for Phase 1.

Found during Phase 1, not in this audit:

| New finding | Severity | Status |
|---|---|---|
| Analytics draws nothing and logs an error per point when a series is all zeros (a site with no traffic yet). | Low | Fixed |
| Two seeded hero blocks name photographs that do not exist: the demo landing page and the "collection intro" page template. | Low | Fixed for new databases; a database seeded earlier, including the Vercel demo, keeps the broken name until that page is edited. |
| On PostgreSQL a DATE column was returned as a midnight timestamp, so the same date read differently on the two engines. | Medium | Fixed: both engines return `YYYY-MM-DD`. |
| The old secret scan read each file from the last commit, so a secret in an uncommitted or new file was never scanned. | Medium | Fixed: it reads the working tree. |
| **F-16.** In the single-process shape planned for Hostinger (`npm run start:hostinger`), the dashboard is a blank page: every address under `/dashboard/` is answered with the dashboard's HTML shell, including its own script and stylesheet. `smoke:hostinger` passed because it fetched only the shell. The Vercel deployment is not affected; it serves those files itself. | **High** for the Hostinger launch | Fixed. `smoke:hostinger` now fetches every script and stylesheet the shells name; the browser check passes 43 of 43 screens on this shape. |
| The copy tool masked the password in the connection string it prints with a pattern that showed half of a password containing `:` and all of one containing `/`. | Medium | Fixed and tested. |
| Chart grid labels round to thousands, so a chart of small numbers shows `0` on every grid line. | Low | Open |

## 0b. Status after Phase 2 (2026-11-16)

Phase 2 is reported in [07](07-PHASE-2-REPORT.md). What it changes in the table above:

| Finding | Status | Note |
|---|---|---|
| F-07 demo catalogue, no SKU or variants | **Fixed in the code; two owner steps open** | The product model has SKUs, options, states and "price on request" (migration 019). The real catalogue — 170 products — is prepared and loads as drafts. Open: the owner signs the import report; migration 019 is applied to the hosted database. |
| F-06 owner-only routes | Open, **with new detail** | Every role other than the owner is refused on `/api/stats` (14 requests on the Overview screen), `/api/stats/revenue`, `/api/leads` and `/api/events`; the view-only role also on `/api/quotes`. Phase 3. |
| F-11 search engines | Open, **with a follow-up** | Product pages are no longer pre-built as HTML: the catalogue lives in the database. They need server rendering before the shop is opened to search engines. |

Defects found during Phase 2 and fixed in it (each is described in the report, B.5): cart and quotation lines identified by product alone; an invented quotation number on the printed estimate; "PKR 0" totals; a free-delivery promise the checkout did not keep; links to pages that do not exist; buttons that did nothing; refusals that all read "please try again"; a seed check that compared a file with itself; 10,109 invented reviews; the quantity on hand published to anyone; a sign-in form that could overwrite what was being typed.

Found and **left open**:

| | |
|---|---|
| The header, the footer, the category row and the home page link to categories of the demo catalogue | Hard-coded. To be driven by the catalogue. |
| Company claims on the storefront | Testimonial ratings, room-package prices, price answers in the FAQ, "Free Assembly", "24/7", the delivery rule. Waiting for Q-4. |
| Product photographs are loaded from woodexfurniture.pk | To be copied in the Hostinger phase. |
| The dashboard's top-right chip reads "Ayesha Rehman · Admin · Woodex" while signed in as Admin | Not investigated. |
| The dashboard's page preview assumes the storefront is on port 5174 in development | Affects local runs only. |

## 1. What passes

The repository's own definition of done was run in full on the untouched checkout. All 31 steps exit 0 ([results](evidence/results/gate-2026-11-16.tsv)).

| Gate | Result |
|---|---|
| `secret-scan`, `docs:verify`, `check:deploy`, `seed:data:check`, `test:router`, `check:migration`, `test:blocks` | pass |
| `typecheck:all`, `smoke:render` | pass |
| `qa` on SQLite | 108 checks pass |
| `qa:pg` on PGlite | pass |
| 18 module tests (`test:integrity` … `test:throttle`) on SQLite | all pass, 0 failed checks |
| `vercel:build` | 76 of 76 routes prerendered |
| `smoke:hostinger` | the single-process Hostinger shape serves pages, dashboard, API, sitemap and robots |

In a headless Chromium, signed in as the demo owner, 36 of 38 dashboard menu routes render, the storefront home and shop render with all 148 demo products, and sign-in works when the dashboard is embedded in another site with browser storage blocked ([results](evidence/results/browser-check-2026-11-16.json)). A route that renders is not proof that every action on it works.

## 2. Findings

Severity: **Critical** act today · **High** blocks a launch · **Medium** fix before or shortly after launch · **Low** tidy up.

### F-01 Critical: a production owner login is printed in a public document

A document under `docs/` stated the production owner e-mail and password in plain text. The repository is public and `main` is unprotected (GitHub API, 2026-11-16). The location is deliberately not repeated here.

`npm run secret-scan` passes because it matches token patterns, not prose. The audit of 2026-11-11 reported "no secrets" for the same reason, and that statement was wrong.

Not tested: whether the password still works. No sign-in to production was attempted.

**Action:** the owner changes the password now. Then remove the line, review production sessions and the audit log for unknown sign-ins, and make the repository private. Deleting the line alone is not enough because it stays in the Git history.

### F-02 High: on PostgreSQL, new rows in 30 tables come back with id 0

The PostgreSQL adapter appends `RETURNING id` only for tables named in `RETURN_ID_TABLES` (`apps/api/db-adapters.mjs`). The list has 22 tables. `server.mjs` reads the generated id back at 56 places covering 47 tables; 30 of them are not on the list ([derivation](evidence/results/return-id-gap-2026-11-16.json)). On SQLite the id is always available, so nothing fails there.

Running the repository's own module tests against PostgreSQL semantics, with only the database pin changed:

| Module suite | Checks passed | Checks failed | First failure |
|---|---|---|---|
| public-surface | 20 | 0 | none |
| procurement | 6 | 18 | creating a supplier returns 500 |
| supplier-invoices | 10 | 40 | supplier created |
| production | 9 | 12 | creating a BOM returns 500 |
| ops-api | 10 | 43 | creating a supplier returns 500 |
| inventory | 31 | 36 | stock drift on all 148 products (see F-04) |
| finance | 43 | 24 | posting a journal entry returns 500 |
| crm | 14 | 45 | creating a company returns 500 |
| delivery | 6 | 43 | planning a dispatch run returns 500 |
| support | 1 | 4 | installed asset under warranty returns 500 |
| credentials | 26 | 4 | issuing a password reset returns 500 |

**1 of 11 suites passes** ([results](evidence/results/pg-modules-head-2026-11-16.json)). The same suites all pass on SQLite.

This is why the gate is green: every module test pins `DATABASE_URL: ""`, which selects SQLite, and `qa:pg` does not call the module endpoints. The tracker marks these modules Verified; that verification holds for SQLite only.

Production runs PostgreSQL through the same adapter. The defect was reproduced on PGlite, not against the production database.

**Fix:** append `RETURNING id` for every insert whose table has an `id` column instead of keeping a list, or complete the list and add a test that fails when a table is read back but unlisted. Then run the module tests on both drivers in CI.

### F-03 High: further PostgreSQL-only defects appear once F-02 is patched

In a scratch copy with the 30 tables added to the list, 7 suites still report failures ([results](evidence/results/pg-modules-with-id-list-completed-2026-11-16.json)). After removing the failures caused by the tests reading the SQLite file directly, these remain and need triage:

- recording an expense returns 500;
- scheduling a meeting returns 500;
- opening a support ticket returns 500;
- a stock adjustment returns 500;
- the check that a journal memo can be corrected fails;
- the warranty end date comes back as a timestamp instead of a date, and re-sending the same date returns 409.

### F-04 High: the SQLite to PostgreSQL migrator loses data and reports success

`scripts/pg-migrate.mjs --truncate` exits 0 and prints "ALL GREEN", yet 9 tables are empty in the target ([results](evidence/results/migrator-truncate-2026-11-16.txt)):

| Table | Rows in SQLite | Rows in PostgreSQL |
|---|---|---|
| `leads` | 6 | 0 |
| `clients` | 13 | 0 |
| `client_links` | 21 | 0 |
| `quotes` | 5 | 0 |
| `quote_versions` | 5 | 0 |
| `invoices` | 2 | 0 |
| `payments` | 1 | 0 |
| `tasks` | 2 | 0 |
| `stock_movements` | 148 | 0 |

On the demo dataset that is 203 of 416 rows, including the whole stock ledger (14,652 units).

Cause: the copy loop runs `TRUNCATE <table> CASCADE` just before copying each table. `leads` and `clients` are copied before `companies`, and `stock_movements` before `storage_locations`, but they reference those tables. When the loop reaches the parent, the cascade empties the children already copied, and everything that references them. The verification ran earlier, so it does not notice.

Without `--truncate` the same command exits 1 on a duplicate key in `accounts`, because the migrations seed that table first. So the cut-over procedure in `docs/architecture/POSTGRES-CUTOVER.md` cannot succeed as written: it fails without the flag and loses data with it.

`npm run qa:pg` builds its database through this path, so the PostgreSQL rehearsal runs on the reduced copy.

**Fix:** truncate all tables once, before the copy loop; order the copy by the PostgreSQL foreign keys; verify row counts after the last table, not after each one.

### F-05 High: two dashboard screens crash, and the error screen cannot be dismissed

Purchase Orders and Production show "This screen hit an error" with `(products.data ?? []).map is not a function`. Both pages read `GET /api/products` as an array; the API returns `{ total, items, limit, offset }`. Six other pages read the same endpoint correctly.

The error screen replaces the whole workspace. "Go to Overview" changes the address with `history.replaceState`, which the router does not observe, so the same screen renders again; "Try again" re-renders the same failing page.

Nothing in the gate loads a dashboard screen after sign-in: the response type is an unchecked generic, `smoke:render` mounts the sign-in screen only, and the module tests call the API directly. The tracker lists both modules with a working frontend.

### F-06 High: 84 of 214 API routes work for the owner role only

The access gate maps a path to a capability in `capsFor()`. Paths with no mapping fall through to `owner-only`. That covers all of Inventory (18 routes), Delivery (13) and Support (12), most of the finance depth (15) and the CRM depth (14) ([inventory](inventory/api-routes.md), [roles](inventory/roles-and-capabilities.md)).

Confirmed against the running demo: the sales role gets 403 on companies, meetings, deliveries, tickets and warehouses; the finance role gets 403 on expenses.

A team cannot use those modules unless every member is an owner, which removes the separation of duties the roles exist for. The tracker records RBAC as partial.

### F-07 High: the catalogue is demo data and the product model has no SKU or variants

The 148 products come from `apps/api/seed-data.json`. Measured through the API: 0 products have a SKU, 0 have variants or options, 18 distinct image files serve all 148 products, every product has stock 99, and ratings run from 4.1 to 5 with 10,109 reviews in total. Those ratings and reviews are invented and are shown on the public demo, against the house rule "no invented data in the UI".

The live shop lists 171 products; only 2 names matched the seed on 2026-11-11. See [05](05-LIVE-SITE-AND-CATALOGUE.md).

### F-08 Medium: money is stored as floating point

SQLite stores all 36 money-like columns as `REAL` ([tables](inventory/database-tables.md)). The PostgreSQL schema uses `DOUBLE PRECISION` for 13 money-like columns and `NUMERIC` for 14, by a name-based count. All 148 demo prices are whole rupees and the server recomputes totals, so no wrong amount was observed. The risk is rounding once percentages, tax and part-payments are applied to real orders.

### F-09 Medium: SQLite declares no foreign keys

The foreign keys (59 table-to-table references by this audit's count) and the CHECK constraints live in the PostgreSQL schema and migrations. SQLite declares 0. If production runs on a SQLite file, none of that integrity work applies. See the database choice in [04](04-HOSTINGER-BUSINESS-PREFLIGHT.md).

### F-10 Medium: launch features that do not exist yet

Taken from the repository's own records (the tracker and `00-CURRENT-STATE.md`), not re-tested in this audit: checkout creates an order request and takes no payment; no e-mail is sent anywhere, so quotations, invoices and password resets have no delivery path; WhatsApp is a click-to-chat link; attachments are pointers, with no file upload; tax records are not captured on invoices or bills. Cash on delivery (OD-4) fits the order-request model, but there is no cash-on-delivery state, confirmation message or receipt yet.

### F-11 Medium: the public demo is open to search engines; the real shop is closed to them

`woodex-2030.vercel.app/robots.txt` allows crawling of a site that carries the WOODEX name with demo products and invented reviews. `woodexfurniture.pk/robots.txt` is `Disallow: /`, which blocks every search engine from the real shop. Both checked 2026-11-16.

### F-12 Medium: dependency advisories

`npm audit` reports 14 advisories: 0 critical, 2 high (`body-parser`, and `zod` as a direct dependency), 12 moderate.

### F-13 Medium: the Hostinger guide describes an upload flow Hostinger no longer uses for Node.js

`docs/DEPLOYMENT.md` §2 uploads files and starts the app by hand. Hostinger now builds Node.js apps from GitHub or a ZIP into a managed folder that is overwritten on every deployment. A data directory inside the app folder would be wiped on each deploy. See [04](04-HOSTINGER-BUSINESS-PREFLIGHT.md).

### F-14 Low: smaller defects seen in the browser

- The live-status pill in the top bar reads "CONNECT…" even when the live stream is connected. The top bar handles the `polling` and `down` states and shows the connecting label for everything else, including `live`.
- The Stock Reservations screen requests availability with an empty product id and gets 400 twice on load.
- The dashboard still carries Interior branding in code: the workspace switcher and Settings say "Woodex Interiors", and the Overview links to `woodexinteriors.com` as a demo link.

### F-15 Low: company facts differ between properties

The storefront metadata says "since 2009" and names showrooms in Lahore, Karachi and Islamabad. The Interior site says "since 2016". The live Furniture site says "25+ years". See Q-4 in [01](01-OWNER-DECISIONS.md).

## 3. Not verified

- **Production.** No request that writes was sent to production, and no hosted database was opened. F-02 to F-04 are shown on PGlite.
- **Browser journeys.** `npm run e2e` needs Playwright's Chromium, which cannot be installed here. The browser check in this audit loads routes; it does not click through workflows.
- **Hostinger.** Nothing was deployed. [04](04-HOSTINGER-BUSINESS-PREFLIGHT.md) rests on Hostinger's documentation and on `smoke:hostinger` passing locally.
- **Live catalogue.** The product-quality figures in [05](05-LIVE-SITE-AND-CATALOGUE.md) are from 2026-11-11 and the raw list was lost; it must be crawled again before migration.
- **Performance and accessibility.** Not measured.
