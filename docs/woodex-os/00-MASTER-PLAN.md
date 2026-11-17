# WOODEX OS — master plan

**One admin, in Laravel, for Woodex Furniture. The whole Agency OS moves into it, one module at a time.**

| | |
|---|---|
| Status | **Plan, with its four opening decisions answered** on 2026-11-16 (§12; recorded in [`12-DECISION-LOG.md`](12-DECISION-LOG.md)): Livewire admin, Phase 2 first, the Agency OS look, the plan's order. |
| Authority | The owner's specification set in [`spec/`](spec/README.md), then this plan. Where they disagree, the decision log wins (spec 08 §2). |
| What this plan authorises | Work in this repository and in a sandbox. **Nothing here authorises a change to production, to a hosted database, to DNS or to Hostinger.** Each of those needs its own written approval. |
| Written | 2026-11-16, at `aff7f2e` on `arena/1830554a-woodex-2030`; Laravel code read at `063fc7c` on `arena/69363bda-woodex-2030`. |
| Progress | **Wave 0 done; Wave 1: slices 1.1–1.3 done** (2026-11-16). See [`01-WAVE-0-1-REPORT.md`](01-WAVE-0-1-REPORT.md). Sections 0 and 3 below describe the day the plan was written: since then the Laravel application has been run and tested in this sandbox, and both applications are on one branch. |

---

## 0. What was measured, and what was only read

**Measured on 2026-11-16** (commands run, results read):

- Both code lines, counted from source (§3).
- GitHub Actions on the Laravel branch: the Laravel tests pass on MySQL 8.4 — all five jobs, at `540082f`; nothing under `platform/` changed after that commit.
- The Hostinger live test: two runs, two failures, both on 2026-11-13 ("Hosted admin is not ready").
- Vercel production: the React/Node build of the Phase 1 merge (`b8cb5e8`), state ready. No Vercel project is named after Laravel or Livewire.
- A merge rehearsal of the Laravel branch into this one: 11 files conflict (§6, Wave 0).
- PHP can be made to run in this sandbox: the PHP 8.4.21 build named in `platform/tools/preview/README.md` was fetched and its checksum verified.

**Read, not measured.** Everything else about the Laravel application comes from its code and from `docs/laravel-os/` on its branch. At the time of writing the application had **not yet been run in this session**. Its screens are described here from source.

**Not reachable from here.** The Hostinger server and its database.

---

## 1. The goal

Woodex Furniture runs on one system: **WOODEX OS**, a Laravel application with a MySQL database on Hostinger.

- Staff use **one admin**. Every screen of today's Agency OS (the React dashboard and its Node API) exists in it, or has been deliberately dropped.
- The **shop stays a React application**. It reads the catalogue from Laravel and sends enquiries, quote requests and orders to Laravel.
- **Each business record has one writer.** A module moves when its Laravel replacement is accepted; that day the old write path is closed. Two systems never write the same record.
- At the end the Node API, the Vercel deployment and the Supabase database are retired.

This is the route the owner's umbrella plan calls a *modular monolith*, reached by the module-by-module replacement that spec 09 prescribes.

---

## 2. Sources, and where they disagree

| Source | What it gives |
|---|---|
| [`spec/`](spec/README.md) — the owner's documents 02–09 and the umbrella plan | Target architecture, data model, API conventions, **the canonical admin navigation and design tokens (05)**, module behaviour, working rules, migration rules |
| `docs/laravel-os/` (on the Laravel branch) | What the Laravel application contains, what was done on 11–13 November, what is still open |
| `docs/furniture-2030/` (this branch) | Audit of the React/Node system with evidence, inventories of its 223 routes, 47 screens and 74 tables, the prepared real catalogue |

The sources do not fully agree. Spec 08 §2 forbids settling such a difference silently, so each one is listed.

| # | Difference | Settled how |
|---|---|---|
| C-1 | **Admin screens.** The umbrella plan says Blade + Livewire. Spec 02 and 09 say the existing React dashboard, redesigned, on a Laravel API. The Laravel branch built 130 Livewire components and records "native Laravel/Livewire first". | **Decided: Livewire** (D-01, DL-005). §4 keeps the comparison for the record. |
| C-2 | **Repository.** The umbrella plan says a new repository. Spec 08 §1 says "using the existing repository", and the Laravel code already lives in `platform/` here. | This repository (spec 08 is the later, more specific text). Recorded as DL-002. |
| C-3 | **Shop.** The umbrella plan migrates the shop to Blade. Spec 02 and 06 keep the React shop and add Puck. | React shop (spec 02/06; also decision D-4 on the Laravel branch). Recorded as DL-003. |
| C-4 | **Look.** The umbrella plan says navy/charcoal with a wood accent. Spec 05 §5 gives charcoal + WOODEX green — the very values of today's React dashboard (`apps/dashboard/src/index.css`: `#4f9d21`, `#43851c`, `#171b1e`, Inter). | **Decided: spec 05, the Agency OS look** (D-03, DL-007). |
| C-5 | **Which products.** The Laravel preview held the 148 sample products (18 shared photographs). The real catalogue — 170 products captured from woodexfurniture.pk — is prepared on this branch. | The real catalogue is the import source (Wave 5). The 148 are test fixtures only. |
| C-6 | **Which admin.** On 2026-11-16 the owner first answered "the admin is the React dashboard at `/admin`", then asked for this plan with Laravel as the base. | The later instruction is the target (DL-001). The earlier answer stays true of the **live** system until each module is cut over. |

**Missing documents.** Spec 08 names `01-MASTER-PRD.md`; the numbering implies 10 and 11. They were not supplied and are in no branch. Until the PRD arrives, spec 07 (modules) and spec 05 (admin) stand in for it.

---

## 3. Where we are (measured)

| | Agency OS — React + Node | WOODEX OS — Laravel |
|---|---|---|
| Where | `main`; this branch adds Phase 2 | Branch `arena/69363bda-woodex-2030` only. No pull request. Forked before Phase 1. |
| Live? | **Yes** — Vercel + Supabase (PostgreSQL), at the Phase 1 merge | **No.** Never deployed. Hostinger staging did not start (13 Nov). |
| Back end | One Node file, `apps/api/server.mjs`: **223** routes here (214 on `main`), **74** tables, 19 migrations | Laravel 13, Livewire 4, PHP ≥ 8.4.1. **84** schema migrations + 22 settings migrations, 88 models, 13 domain folders |
| Admin | React, **47** routes | **130** Livewire components, 128 named admin routes, 227 Blade views |
| Admin front-end stack | React 19, Tailwind 4 | Tailwind 4, Alpine, Preline, ApexCharts — Blade components `x-admin.*` |
| Tests | 13 module suites on two engines, 6 browser journeys, route gate | 220 test files; five CI jobs on MySQL 8.4, green |
| Roles | owner, editor, sales, finance, viewer (capabilities) | owner, admin, sales, accountant … (permissions; two-factor required for some roles) |
| Modules that work | CRM, quotations, invoices, catalogue, orders, returns, stock ledger, warehouses, transfers, reservations, suppliers, purchase orders, goods receipts, supplier invoices (three-way match), BOM and job cards, deliveries, installed assets, support and warranty, double-entry ledger, expenses, credit notes, payment schedules, projects, page editor, theme | CRM (leads, contacts, companies, tasks, visits), estimations, quotations, invoices, payments, documents, accounts, projects, reports, settings, access, audit — and, built 11–13 Nov: categories, products, media, series, shop sections |
| Switched off | — | website/CMS, inbox, automation, marketing, WhatsApp, Telegram, Google, client portal, estimator, MCP bridge (code imported from the Interior application; not adapted to Furniture) |
| Not there at all | — | orders, checkout, payments reconciliation, stock, purchasing, production, delivery, installation, support, warranty |
| Shop | React, 16 prerendered routes, reads the Node API | Opt-in bridges for leads, quote requests, categories and images; tested on a local preview only |

Two facts frame everything else:

1. **The two systems are complementary, not duplicates.** Laravel is strong where the Agency OS is thin (quotations with revisions and approval, documents, accounts, projects, two-factor sign-in). The Agency OS is strong where Laravel is empty (orders, stock, purchasing, production, delivery, support).
2. **The empty half is the expensive half.** The Laravel branch's own plan says of it: "about 35 tables and well over 100 API routes that already work in Node … measured in months, not days." §6 sizes each wave by the Node routes it has to replace.

---

## 4. Target architecture

Fixed points — from the specification, not open:

| Layer | Target | Source |
|---|---|---|
| Business back end | Laravel, modular by domain (`platform/app/Domain/*`) | spec 02 |
| Database | MySQL on Hostinger, InnoDB, utf8mb4; Laravel migrations own the schema | spec 03 |
| Hosting | Hostinger shared hosting: PHP request cycle, database queue, cron-driven scheduler. No persistent Node, no Redis, no websockets | spec 02 §7 |
| Public API | `/api/v1`, JSON, validated and authorised on the server | spec 04 |
| Shop | The React storefront, built to static files, reading `/api/v1` | spec 02, 06 |
| Page editor | Puck, as a React island inside the admin, **after a prototype passes** spec 06 §11 | spec 06, 08 §9 |
| Money | One exact representation. *To verify first:* what the imported Laravel tables use today (Wave 2) | spec 03 §6 |
| Time | Stored in UTC, shown in Pakistan time. The audit was done on the Laravel branch; the change was not made (Wave 2) | spec 03 |
| Separation | Furniture only. Never a connection to the Interior database; no Interior data | umbrella §2 |

**D-01: what the admin's screens are made of — decided on 2026-11-16: option A, Livewire.** The comparison that was put to the owner:

| | **A — Livewire (chosen)** | B — React on a Laravel API | C — mixed |
|---|---|---|---|
| What it is | One admin rendered by Laravel. React only for the shop and the Puck island. | The React dashboard is kept and redesigned; Laravel serves JSON only. | Livewire where Laravel has screens; the React screens for commerce, stock and production until each is rebuilt. |
| Existing work kept | The 130 Laravel screens | The 47 React screens | Both |
| Work it adds | A Livewire screen for every module rebuilt in Waves 6–8 | A JSON API for everything, **and** React replacements for the 130 Laravel screens | A shared sign-in between two admins, and two design systems to keep alike |
| On Hostinger | One application to deploy | Two builds plus cross-origin sign-in rules | Two builds, two sign-ins |
| Why recommended / not | Fewest moving parts; the business rules must be rebuilt in PHP in every option, and here each is built once | Throws away the half of the admin that already works in Laravel | It is where we are today — acceptable as a transition, not as a destination |

Option C describes the transition, not the destination: until a module's wave is accepted, its React screen stays in use, reached from the Feature status screen (§7.2, slice 1.2).

---

## 5. The one admin: every destination, and where it stands today

The navigation is spec 05 §2, unchanged. "Agency OS" is the React route that does the job today. Dispositions use the owner's vocabulary: KEEP, ADAPT (exists, needs Furniture rules), MERGE (both sides have it), EXTEND, REBUILD (only the Agency OS has it), DEFER.

| Destination | In Laravel today | Agency OS today | Disposition | Wave |
|---|---|---|---|---|
| **WORKSPACE** | | | | |
| Dashboard | native | `/`, `/analytics` | MERGE — real KPIs only, added as modules arrive | 1 |
| Approvals | native (quotations) | no approval queue: a purchase order changes status, and production has an audited override, each as its own action | EXTEND to the other domains (spec 07 §2) | 4, 7, 8 |
| Reports | native | `/analytics` | MERGE | 9 |
| **CUSTOMERS** | | | | |
| Leads | native | `/crm` | MERGE | 3 |
| Contacts, companies | native | `/clients`, `/companies` | MERGE | 3 |
| Tasks & Visits | native | `/tasks`, `/meetings` | MERGE | 3 |
| Conversations | native, module off | `/omnichannel` | ADAPT | 10 |
| Chat Bot | native, module off | — | DEFER until a provider is approved | 10 |
| **SALES** | | | | |
| Estimations | native, module off | — | ADAPT (Furniture rate book) | 4 |
| Quotations | native | `/quotations`, `/quotations/new` | MERGE | 4 |
| Invoices | native | `/invoices` | MERGE | 4 |
| Payments | native | `/payment-schedules` | MERGE | 4 |
| Documents | native; **issuing is blocked** until the owner confirms terms and signers | — | KEEP | 4 |
| **COMMERCE** | | | | |
| Store Overview | native | `/ecommerce` | EXTEND | 5 |
| Products | native: drafts, preview, publish | `/catalog` (list, detail, edit, new, import) | EXTEND — real catalogue, SKU and option parity | 5 |
| Categories | native | inside the catalogue | KEEP | 5 |
| Series | native (organiser) | — | EXTEND (activation, redirects) | 5 |
| Orders | **missing** | `/orders`, `/orders/:id` | REBUILD | 6 |
| Delivery & Installation | **missing** | `/deliveries` | REBUILD | 6 |
| Support & Warranty | **missing** | `/support` | REBUILD | 6 |
| **OPERATIONS** | | | | |
| Operations Board | **missing** | `/operations`, `/operations/:stage` | REBUILD | 8 |
| Projects, board | native | `/projects` | MERGE | 9 |
| Vendors & Costs | native | — | MERGE with suppliers | 7, 9 |
| Client Portal | native, module off | — | DEFER | 9 |
| Handovers | native | — | KEEP | 9 |
| **PROCUREMENT & PRODUCTION** | | | | |
| Suppliers | vendor master only | `/suppliers` | MERGE | 7 |
| Purchase Orders | **missing** | `/purchase-orders` | REBUILD | 7 |
| Goods Receipts | **missing** | `/goods-receipts` | REBUILD | 7 |
| Supplier Invoices | bills exist under Payables | `/supplier-invoices` (three-way match) | REBUILD, one payable authority | 7 |
| Production | **missing** | `/production` | REBUILD | 8 |
| Stock Ledger | **missing** | `/stock-ledger` | REBUILD — the invariants and their tests are ported first | 7 |
| Warehouses | **missing** | `/warehouses` | REBUILD | 7 |
| Stock Transfers | **missing** | `/stock-transfers` | REBUILD | 7 |
| Reservations | **missing** | `/stock-reservations` | REBUILD | 7 |
| **ACCOUNTS** | | | | |
| Ledger & Reports | native | `/ledger` | MERGE | 9 |
| Payables | native | supplier invoices | MERGE | 7, 9 |
| Income & Expenses | native | `/expenses`, `/credit-notes` | MERGE | 9 |
| **GROWTH** | | | | |
| Automations, Sequences, WhatsApp Templates | native, modules off | — | ADAPT, one provider approval each | 10 |
| Marketing | native, module off | `/marketing` | ADAPT | 10 |
| **WEBSITE STUDIO** | | | | |
| All Pages, Visual Page Builder, Navigation, Sections & Templates | an imported HTML-file builder, off — **not** the target | `/website`, `/website/edit/:id` (typed blocks; today's authority) | REBUILD on Puck, after the prototype | 10 |
| Storefront / Shop Design | native shop-section editor | — | EXTEND | 5, 10 |
| Blog, Portfolio, Forms | native, module off | — | ADAPT | 10 |
| Media Library | native, open | — | KEEP | 5 |
| Theme & Design System | native, module off | `/website/theme` | MERGE | 10 |
| SEO, SEO Agent, Speed, Site Health, Website Settings, Redirects | native, module off | — | ADAPT | 10 |
| **SYSTEM** | | | | |
| Settings | native | `/settings` | MERGE | 2 |
| Access & Team | native | team in `/settings`, `/login-locks` | MERGE — one role and permission matrix | 2 |
| Connections, Platform | native | diagnostics | KEEP | 2 |
| Audit & Activity | native | audit API | MERGE | 2 |
| Workspace Preferences, Team Onboarding | placeholders | — | DEFER | later |

Not migrated: `/ui-components` — a design reference, not a business module.

---

## 6. The waves

No wave authorises the next. Each ends with the report format of spec 08 §11 and the checklist of spec 09 §6. "Node routes" is the number of routes in `apps/api/server.mjs` (at `aff7f2e`) that the wave has to replace — a measure of size, not of days.

| Wave | Scope | Node routes | Done when | Needs from the owner |
|---|---|---:|---|---|
| **0 — one code line** | Phase 2 is finished and merged first (D-02). Then the Laravel branch is brought into the working branch: 11 files conflict (`server.mjs`, six storefront files, two dashboard pages, `build-seed-data.mjs`, the state document). Both CI workflows green. Sandbox PHP and Composer documented. | — | One branch holds both applications; the React gate and the Laravel tests both pass on it | — |
| **1 — UI/UX foundation** | §7 | — | Spec 05 §10 acceptance, on every existing screen | — (D-01, D-03, D-04 answered) |
| **2 — identity, access, audit** | One role and permission matrix from the two models. Audit. Login locks. How the shop and any API client authenticate. Money representation verified. UTC storage. | 15 | A matrix test proves each role's access; no route without a permission | — |
| **3 — CRM** | Leads, contacts, companies, tasks, visits/meetings. **First switch of write authority:** the shop's enquiry and quote-request forms write to Laravel. | 28 | An enquiry from the shop is in the Laravel CRM; the Node write path is closed; duplicates reconciled | Which customer records may move (D-9) |
| **4 — sales documents** | Estimations, quotations with revisions and approval, invoices, payments, documents | 17 | A quotation becomes an invoice and a receipt, with correct totals computed on the server | Prices, terms, wording, signers (D-8) |
| **5 — catalogue and media** | Import of the **real** catalogue (170 products) as drafts; SKUs, options, price on request; series; shop sections; the shop reads the catalogue from `/api/v1` | 19 | The shop shows only what Laravel has published; the Node catalogue is closed | Sign-off of the import report |
| **6 — orders, delivery, support** | Checkout and orders with status history; returns; dispatch, delivery with proof, installed assets; tickets, warranty claims, service visits | 35 | An order placed in the shop is delivered and closed in the admin | Checkout methods; delivery and warranty terms |
| **7 — stock and purchasing** | Append-only stock ledger, warehouses, locations, transfers, reservations; suppliers, purchase orders, goods receipts, supplier invoices with three-way match | 33 | Every stock change is a ledger row; the ledger and the balances reconcile; the oversell tests pass on MySQL | — |
| **8 — production** | Bills of material, job cards, material issue, quality gate, operations board | 11 | The quality gate cannot be passed without an audited override | — |
| **9 — projects, finance, reports** | Projects merge; double-entry ledger, expenses, credit notes, payment schedules; reports | 30 | The trial balance balances; a posted entry changes only by reversal | Accounting owner's review |
| **10 — Website Studio and growth** | Puck prototype, then pages, navigation, theme, redirects, blog, portfolio, forms, SEO; inbox, automation, marketing, each behind its own approval | 28 | The editor's preview matches the shop's rendering; a page can be published and rolled back | One approval per provider |
| **11 — cutover** | Final data migration and reconciliation; Hostinger production; Node, Vercel and Supabase retired; training and handover | 5 shared | Spec 09 §9, steps 9–13 | **Separate written approval** |
| **S — staging** (alongside, from Wave 1) | Make the Hostinger staging site start; a deploy job; backup and a proven restore | — | The live test passes; a restore has been done once | SSH secrets in GitHub; rotated passwords; plan details (D-10) |

The order of waves 3–10 is spec 09 §5 with one change: stock and purchasing come before production, because production consumes stock through the ledger.

---

## 7. Wave 1 — the UI/UX upgrade

The upgrade changes how the admin looks and how it is navigated. **It changes no route, no permission, no business rule and no table.**

### 7.1 Tokens (spec 05 §5) — with the contrast validation the spec asks for

Spec 05 gives its colours "subject to contrast validation". Validated here (WCAG 2.1: 4.5 : 1 for text, 3 : 1 for large text and for the edges of controls):

| Pair | Ratio | Verdict |
|---|---:|---|
| White on primary `#4F9D21` | 3.40 | **Fails for text.** Filled buttons use `#43851C` |
| White on `#43851C` | 4.55 | Passes |
| Primary `#4F9D21` as link text on white | 3.40 | **Fails.** Links use `#43851C` or darker |
| Text `#1D2226` on canvas `#F6F7F8` | 14.96 | Passes |
| Secondary text `#66717A` on white / on canvas | 4.99 / 4.65 | Passes |
| Muted `#7B858D` on white / on canvas | 3.76 / 3.51 | **Fails for text.** Captions use the secondary colour |
| White on sidebar `#171B1E` | 17.33 | Passes |
| Muted `#7B858D` on the sidebar | 4.61 | Passes |
| White on danger `#D9534F` | 3.96 | **Fails.** Filled danger buttons need a darker red |
| White on success `#2E8B57` | 4.25 | **Fails**, narrowly. Darker green for filled badges |
| White on warning `#D99A24` | 2.44 | **Fails.** Warning carries dark text (6.57) |
| Border `#E5E8EA` on white | 1.23 | Fine for dividers; **input edges need a darker line** |

So the tokens ship as the spec gives them, plus the four darker "on-fill" values the validation requires. A test computes these ratios from the token file, so a later change cannot silently break them.

### 7.2 Slices, in order

| # | Slice | What changes |
|---|---|---|
| 1.1 | **Tokens** | One token file for the admin: colours, Inter, radii 8/12/16, the spacing scale. The Blade components read tokens, never literal colours |
| 1.2 | **Navigation registry** | One registry in the order of spec 05 §2, with stable ids. Filtered by permission and by module. A destination that is not available is **not shown** — no "Soon", "Legacy" or "2030" labels. A *Feature status* screen under System lists every destination and its state, with the link to the old React screen where one is configured |
| 1.3 | **Sidebar** | Charcoal. Collapsible groups, the active one open. Compact mode. A mobile drawer that traps focus and closes on Escape. Fully usable from the keyboard |
| 1.4 | **Top bar** | Breadcrumbs, search and a command palette (`Ctrl/Cmd + K`) over the same registry, quick-create filtered by permission, notifications, user menu |
| 1.5 | **Components** | The existing `x-admin.*` components restyled to the tokens: buttons, fields, cards, tables, badges, page header, pagination, modal, toasts, and the five states — loading, empty, error, no permission, success. No second component library |
| 1.6 | **Dashboard** | The layout of spec 05 §7, from real data only. Every figure links to the records behind it. A section whose module is not in Laravel yet is absent, not faked |
| 1.7 | **Screen pass** | Each existing screen brought to the page patterns of spec 05 §8, area by area in the order of D-04 |

**Progress (2026-11-17).** Slices 1.1 to 1.4 are done. The look is that of the Reimagined theme since decision
DL-017: the sidebar of 1.3 is light, with the charcoal one as a setting. Slices 1.5 to 1.7 are lines U-4 to U-6
of [`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md).

### 7.3 Acceptance

- Spec 05 §10, checked in a browser at 1366 px and at 390 px.
- A registry test: every item's route exists; no destination appears twice; each role sees exactly its permitted items; a switched-off module shows nothing.
- The Laravel suite stays green on MySQL in CI. No test is deleted or weakened to make room.
- Before-and-after screenshots of the shell and of one screen per area, kept in `docs/woodex-os/evidence/`.

---

## 8. Moving the data

The rules are spec 09 §4, §6 and §7. In short:

1. One module at a time; never two writers.
2. An importer per module: repeatable, with a dry run, and a mapping table from old ids to new ones.
3. Before a switch: a read-only comparison — counts, counts by status, totals, balances, orphans, duplicates. A count that matches is not enough.
4. The source snapshot is kept until the owner accepts the module.
5. **No real record moves before the owner names what may move (D-9).** Until then every fixture is synthetic.
6. A database restore is not a rollback once new transactions have been accepted (spec 09 §10); each cutover states its recovery point beforehand.

---

## 9. Hosting

| | State | Next |
|---|---|---|
| Hostinger staging | A release package builds in GitHub Actions. The live test has **never passed**: on 13 Nov the site redirected and protected its source files, but the admin did not start. The branch notes point to a missing `vendor/` folder; a repair package was built. The database was imported by the owner in phpMyAdmin, per those notes. | Install dependencies on the host, rerun the live test. Needs the two SSH secrets in a GitHub environment. |
| Vercel (Livewire) | **A demo with sample data is live** at `woodex-os.vercel.app/admin` since 16 November (DL-014, DL-015): [`13-VERCEL-DEPLOYMENT.md`](13-VERCEL-DEPLOYMENT.md). A real admin there needs its own MySQL (not Supabase), a first-install job, file storage, a caller for the timed jobs and mail. Runs on a community PHP runtime, on the Hobby plan. | The owner decides whether the real admin runs here or on Hostinger (O-1 of [`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md)). |
| Vercel (React + Node) | Live, Phase 1. | Stays until Wave 11. |

---

## 10. How work is proven

| Gate | Where |
|---|---|
| Laravel tests on MySQL 8.4 — five jobs | GitHub Actions, on every push that touches `platform/` |
| Laravel tests and a running preview | This sandbox: PHP 8.4.21 static build, SQLite. Nine tests cannot run here (they need `pdo_mysql` or `xmlwriter`); only CI runs them |
| The admin in a browser, desktop and phone | This sandbox, Chromium |
| The React gate (AGENTS.md §3) | For every change under `apps/` |
| The report | Spec 08 §11: scope, evidence, not done, safety, status. "It compiles" is not "it is complete" |

---

## 11. Risks

| Risk | Why it matters | What contains it |
|---|---|---|
| Rebuilding the empty half takes months | Waves 6–8 replace 79 Node routes and their rules | The Agency OS keeps working until each wave is accepted; staff are never without a screen |
| Two admins during the transition | Staff must know where each job is done | The Feature status screen (1.2); one module at a time |
| Two catalogues | The Node catalogue (Phase 2) and the Laravel one | Wave 5 imports the real catalogue once and closes the Node write path |
| Staging has never started | Nothing can be accepted on Hostinger | Wave S runs alongside from Wave 1 |
| The merge of the two branches | 11 conflicting files; the storefront was rewritten on both sides | Wave 0, with both test gates as the judge |
| Interior leftovers | The Laravel code came from the Interior application | Modules stay off until adapted; the import already removed Interior data and defaults |
| The repository is public | It now holds the specification and the Laravel code | Owner action: make it private; rotate the passwords exposed earlier |
| Missing PRD | Spec 08 ranks it second | Ask the owner for `01-MASTER-PRD.md` |

---

## 12. Decisions asked of the owner on 2026-11-16 — and the answers

| # | Question | Answer |
|---|---|---|
| D-01 | What the admin's screens are made of: Livewire, React on a Laravel API, or mixed | **Livewire** |
| D-02 | Phase 2 (the real catalogue on the live React shop), which is nearly finished: finish and merge it first, or pause it | **Finish and merge it first** |
| D-03 | The look: Agency OS (charcoal, white, WOODEX green), navy with a wood accent, or the present Laravel look | **Agency OS** — spec 05 |
| D-04 | After the menu and layout, which area first | **The plan's order:** Dashboard, then E-commerce, then CRM and sales |

Still open from earlier, not needed to start Wave 1: D-8 prices, terms, wording and signers; D-9 which records move; D-10 the Hostinger plan; the checkout methods; the providers for WhatsApp, e-mail and SMS.

---

## 13. First task plan (spec 08 §5)

| | |
|---|---|
| Objective | One branch with both applications (Wave 0), then the new shell: tokens, navigation registry, sidebar (slices 1.1–1.3) |
| Current behaviour | The Laravel sidebar is built by `AdminNavigation.php` (12 groups) plus `FurnitureNavigation.php` (2 groups with "Legacy" and "2030" labels); closed modules appear as "Soon" |
| Files likely touched | `platform/app/Support/{AdminNavigation,FurnitureNavigation}.php`, `platform/resources/views/components/admin/sidebar.blade.php`, `platform/resources/views/layouts/admin.blade.php`, `platform/resources/css/{tokens,admin}.css`, their tests |
| Reuse found | The registry, the permission filter and the module filter exist; they are regrouped, not rewritten. Alpine and Preline are already there for the drawer and the collapsible groups |
| Risks | Tests that assert the old group titles or the "Soon" items; the committed Vite build must be rebuilt |
| Acceptance | §7.3 |
| Tests | Registry test, Blade render test, contrast test, the full Laravel suite, browser check at two widths |
| Rollback | Each slice is one commit; reverting it restores the old shell. No data is touched |
