# Phase 2 report: real catalogue

Part A was written on 2026-11-16 before any code changed. Part B is added when the work ends.

## Part A. Start of phase

**Authority.** The owner's message of 2026-11-16, "start complate next", read as: start and complete the next phase of [03](03-LAUNCH-PLAN.md). Recorded as OD-13 in [01](01-OWNER-DECISIONS.md).

### A.1 Goal

The platform can hold the real WOODEX catalogue, and the real catalogue is prepared for it: every product from the live shop, cleansed under stated rules, loaded as a draft, and shown to customers only after the owner publishes it.

### A.2 Scope

| # | Work | Finding |
|---|---|---|
| 2.1 | Extend the product model through the migration ledger: SKU, variants, a draft / published / archived state, whole-rupee prices, "price on request", and stock that is either counted or made to order | F-07 |
| 2.2 | Capture the live product list again and keep the capture in the repository | F-07 |
| 2.3 | Build the cleansing step and the import, with a report the owner can sign line by line | F-07 |
| 2.4 | Let staff run the import and review, publish and archive products from the dashboard | F-07 |
| 2.5 | Remove invented ratings, review counts and placeholder stock from the storefront and the dashboard | F-07 |
| 2.6 | Stop compiling a demo catalogue into the storefront: customers see what the database holds, nothing else | F-07 |
| 2.7 | Give each imported product its own image | F-07 |

### A.3 Files and modules

| Area | Files |
|---|---|
| Database | `scripts/migrations/019_catalogue.sql` and its rollback; the SQLite schema and upgrade steps in `apps/api/server.mjs`; `apps/api/db-adapters.mjs`; `scripts/pg-migrate.mjs` |
| API | Product routes in `apps/api/server.mjs`; new variant and import routes; quotation and order intake where they read a product |
| Catalogue data | `catalog/source/` (capture), `catalog/rules/` (mapping and review notes), `scripts/catalog-build.mjs`, `apps/api/catalog-data.json` (prepared list), `apps/api/catalog-import.mjs` |
| Dashboard | `Catalog.tsx`, `ProductEditor.tsx`, `ProductDetail.tsx`, a new import screen, `lib/api.ts` |
| Storefront | `data/products.ts`, `lib/runtime.ts`, the shop, product, quotation and series pages, the product cards, `scripts/prerender.mjs` |
| Checks | A new module suite for the catalogue on SQLite and PGlite; a test for the cleansing step; the existing suites wherever they create a product |
| Documents | This report, [05](05-LIVE-SITE-AND-CATALOGUE.md), [01](01-OWNER-DECISIONS.md), [02](02-AUDIT-FINDINGS.md), `AGENTS.md`, the counts in `docs/agency-os/00-CURRENT-STATE.md` |

### A.4 Working decisions

The owner has not answered Q-5 or approved the cleansing rules. The work uses the defaults already written down, and every one of them is reversible because nothing is published by the import.

| # | Decision | Basis |
|---|---|---|
| W-1 | The source is the product list on woodexfurniture.pk as captured on 2026-11-16 | The default stated under Q-5 |
| W-2 | Rules R-1 to R-7 of [05](05-LIVE-SITE-AND-CATALOGUE.md) §4 apply as proposed | The owner signs the result, not the rules |
| W-3 | A product keeps the address it has on the live shop | [05](05-LIVE-SITE-AND-CATALOGUE.md) §5: no redirect is needed where the address is reused |
| W-4 | The live categories are kept as they are; none is merged | The import should not restructure the owner's catalogue |
| W-5 | Imported products are made to order: no stock quantity is invented | F-07, placeholder stock |
| W-6 | Product descriptions are not copied from the live shop | The live text repeats one passage across products and contradicts itself on warranty and payment; it belongs to Q-4 |

### A.5 Risks

| Risk | Treatment |
|---|---|
| The hosted database receives migration 019 later than the code, or never | The API detects a database without 019 and keeps serving the catalogue as before; only the new features wait. Covered by a test. |
| A transcription error in the capture | Two pages were captured separately and must agree on every product's name and price before the build continues |
| The storefront shows nothing while the catalogue loads, or when the API is down | It shows a loading state, then a plain "unavailable" message. It no longer falls back to demo products. |
| Product pages are no longer pre-built as HTML | True for this phase and recorded as a finding. Search engines are closed out until Phase 5 in any case (F-11). |
| Images are loaded from the old shop's address | Recorded in the import report; copying the files is a step of the Hostinger phases |
| The Laravel branch also edits `apps/api/server.mjs` | Unchanged from Phase 1: that branch stays on hold (OD-11) |

### A.6 Dependencies

Phase 1 merged (PR #33). No owner input is needed to build. The owner is needed to sign the list, and somebody with access to the hosted database must apply migration 019 there.

### A.7 What will not change

No customer, order, quotation or invoice data. No write by the agent to any hosted database. No existing route path. No change to how money is stored outside the product price. No design change. The demo data stays available for demo and development databases.

### A.8 Exit test

1. A database upgraded with migration 019 passes every existing check on SQLite and on PGlite.
2. The import creates one draft per cleansed product, changes nothing on a second run, and publishes nothing.
3. A draft is invisible to the public API and the storefront until it is published.
4. A published product with a price can be ordered; one marked "price on request" can be quoted and not ordered.
5. The storefront shows no rating, no review count and no stock number that the business did not supply.
6. The owner signs the import report. This step is the owner's and is outside the agent's control.
