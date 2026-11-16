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

---

## Part B. End of phase

Written on 2026-11-16 at commit `f826e6e`, after the full gate. Format: scope, evidence, not done, safety, status.

### B.1 Scope delivered

| # | Work | Result |
|---|---|---|
| 2.1 | Product model | Migration `019_catalogue.sql` with its rollback: SKU, a `product_variants` table, the states draft / published / archived, whole-rupee prices, "price on request", and stock that is counted or made to order. A new product is a draft; it cannot be published without a SKU (its own, or an option's). |
| 2.2 | Capture | `catalog/source/woodexfurniture.pk/2026-11-16/`. The live quotation page lists 171 products. |
| 2.3 | Cleansing and import | `scripts/catalog-build.mjs` turns the capture into `apps/api/catalog-data.json`: **170 products** (1 left out and listed), 33 on request, 116 with a point for the owner to check. The owner's reading copy is [`catalogue/IMPORT-REPORT.md`](catalogue/IMPORT-REPORT.md) with `import-list.csv`. |
| 2.4 | Dashboard | The catalogue list shows states, SKUs and "on request"; **Product Catalog → Catalogue import** loads the list as drafts and can withdraw the demo catalogue; the product editor has options with their own SKU and price. |
| 2.5 | Nothing invented | The 10,109 invented reviews and every rating are gone. The public API no longer publishes the quantity on hand. "PKR 0" is never shown for a product without a price. |
| 2.6 | No compiled catalogue | The storefront shows what the database holds. With the API unreachable it says so. The 148 demo products remain as the seed for demo and development databases only. |
| 2.7 | Images | Each imported product carries the address of its own photograph on woodexfurniture.pk. The files are **not copied**: see B.4. |

About 100 files changed against `main`: roughly 10,200 lines added and 4,000 removed.

### B.2 The exit test (A.8)

| # | Test | Result | Proof |
|---|---|---|---|
| 1 | A database with 019 passes every existing check on both engines | **Pass** | Gate: 13 of 13 module suites on SQLite (836 checks) and on PostgreSQL (853 checks); the core battery on both |
| 2 | The import creates one draft per product, repeats nothing, publishes nothing | **Pass** | `test:catalog` sections 10–11; two deliberate faults ("the import publishes", "a replaced demo product stays on sale") each fail it |
| 3 | A draft is invisible until published | **Pass** | `test:catalog` section 2; the browser journey |
| 4 | A priced product can be ordered; one "on request" can be quoted, not ordered | **Pass** | `test:catalog` sections 7–8; the browser journey orders one and is refused the other, with the reason on screen |
| 5 | No rating, review count or stock number the business did not supply | **Pass** | `smoke:render` fails if any of the 148 demo products is in the storefront bundle (`0/148`); the journey reads the pages |
| 6 | The owner signs the import report | **Open — the owner's** | — |

### B.3 Evidence

**The gate.** [`evidence/results/phase2-gate-2026-11-16.tsv`](evidence/results/phase2-gate-2026-11-16.tsv): 39 steps, every one exit 0, including the browser step. That step walks five journeys in a browser (sign-in, shop to CRM, quotation template, page builder, and the catalogue from the import to the checkout) and opens all 44 dashboard screens twice: on the dev server and in the production build.

**Would the checks notice a broken catalogue?** They were tried: the code was broken on purpose, one behaviour at a time, and the checks were run. [`evidence/results/phase2-mutations-2026-11-16.md`](evidence/results/phase2-mutations-2026-11-16.md) lists all of it. In short: 22 faults in the server, 22 fail `test:catalog`; 9 faults in the storefront, 9 fail the browser journey. Two of the server faults passed at first: the checks never sent a price, so a server that trusted the page's price looked correct. The checks were corrected. A third fault had no check at all until one was written. Three attempts changed no behaviour and are not counted.

### B.4 Not done, and limits

| | |
|---|---|
| **Migration 019 is not applied to the hosted database.** | The agent does not write to hosted databases. Until someone with access applies it (`scripts/migrate.mjs`), the deployed site runs as before: the present catalogue is served, ordered and quoted; SKUs, drafts, options and the import answer "needs migration 019". This state is tested on a PostgreSQL database stopped at migration 018, and so is applying 019 to it afterwards. |
| **The import report is not signed.** | 33 products have no price, 4 prices look wrong, SKUs were generated, and four new SKU prefixes were introduced (MTG, CTB, SF, OS). Nothing is on sale until the owner publishes it. |
| **Photographs load from woodexfurniture.pk.** | If that site changes, the pictures break; the shop then shows a neutral placeholder, not a broken image. Copying the files is a step of the Hostinger phase. |
| **Product pages are not pre-built as HTML.** | They are rendered in the browser from the API. Search engines are closed out until Phase 5 (F-11). |
| **The menu, the footer and the home page still name categories of the demo catalogue.** | They are hard-coded. So are company claims — testimonial ratings, room-package prices, "Free Assembly", "24/7" (Q-4). |
| **Roles other than the owner still meet refusals** on the overview figures, leads and events (F-06). | Phase 3. |
| **Not looked into:** the dashboard's top-right chip reads "Ayesha Rehman · Admin · Woodex" while signed in as Admin. | Recorded in [02](02-AUDIT-FINDINGS.md). |
| **Not verified:** the deployed site after this merge. | It is checked after the deployment and reported to the owner; this document is written before it. |

### B.5 Found on the way

Fixed in this phase, each with a check that fails without the fix where a check is possible:

- Cart and quotation lines were identified by product alone: two options of one product became one line.
- The printed estimate invented a quotation number; baskets priced on request totalled "PKR 0".
- The product page promised free delivery (one tab: "within UAE above AED 2,000") while the checkout charged.
- "Latest Collection" on the home page linked to pages that do not exist; Wishlist and Share did nothing.
- Every refused order or quotation said "please try again", whatever the reason.
- `seed:data:check` compared the seed snapshot with itself.
- The catalogue screens asked for data that sales, finance and view-only accounts may not read.
- **The sign-in form could overwrite what was being typed** with the demo account. Measured: 1 scripted sign-in in 120 was refused. Only databases that have the demo account; production has none. What is typed now stays, and every browser journey signs in through one helper.
- The quotation journey waited for a sentence the screen no longer shows — found by the full gate, because no partial run had opened that screen.
- The catalogue suite never proved that the server ignores a price sent by the page.

A limit of local runs, not a defect of the product: the dashboard's page preview opens the storefront on port 5174. The browser gate must have that port; see [`evidence/README.md`](evidence/README.md).

### B.6 What changes for staff and customers

- A new product is a **draft**. It needs a SKU before it can be published.
- An order and a quotation are priced by the catalogue. A price sent by a page is ignored.
- A product without a price shows "Price on request" and can be quoted, not ordered.
- The shop shows the delivery charge it will apply: PKR 2,500, free from PKR 50,000 — **the owner should confirm this rule**; it is what the code already charged.
- One order line holds at most 100 pieces.
- Reviews and star ratings are gone from every page.

### B.7 Safety

| | |
|---|---|
| Data migrations | One, additive: 019. Applied by the test suites to disposable databases only. Not applied to any hosted database. A rollback script is beside it. |
| Production impact | The merge deploys the new code. With 019 absent the catalogue behaves as before (B.4). No customer, order, quotation or invoice record is touched. |
| Rollback | Redeploy the previous build (`b8cb5e8`) in Vercel. No data step is needed, because none was taken. |
| Approval used | The owner's instruction of 2026-11-16 to finish and merge Phase 2 first (OD-15). |

### B.8 Status

**Complete, except the two steps that are the owner's:** signing the import report, and having migration 019 applied to the hosted database.
