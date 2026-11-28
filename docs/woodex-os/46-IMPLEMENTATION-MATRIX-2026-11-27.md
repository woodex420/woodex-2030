# The implementation matrix, 27 November 2026

The owner asked on 27 November for "a fresh audit … the implementation gap matrix and … the actual blockers",
and sent the programme in one piece: the "WOODEX 2030 — Master System Implementation Prompt" (decision
[DL-049](12-DECISION-LOG.md)). This is that matrix. It is the status document of his list
(`WOODEX_2030_IMPLEMENTATION_STATUS.md`), kept under this folder's names, as he asked: "update rather than
duplicate". Section 6 says where each of his twelve documents lives.

Read on 27 November: `main` at `31f2306` with the work of [document 45](45-RELEASE-2026-11-27-A-PRODUCT-FROM-THE-ADMIN-TO-THE-SHOP.md)
on top, and the two live sites as they answered that day. **Updated on 28 November** with the round of
[document 47](47-RELEASE-2026-11-28-A-VISITOR-ASKS-FOR-A-PRODUCT-OF-THE-ADMIN.md): blocker 4 is gone, and with it
changed row 8 and row 9 of P0 and section 7. Nothing else here was read again. The detail of every line stays where it is kept: the
master list ([document 14](14-PRODUCTION-TODO.md)), the plan ([21](21-PENDING-WORK-PLAN-AND-TESTS.md)), the
audit ([43](43-DEEP-AUDIT-2026-11-23.md)) and the acceptance tables ([16](16-ACCEPTANCE-AND-RELEASE-GATES.md)).

## 1. In one page

**There are two systems, and only the older one keeps data.**

- **The shop and the Agency OS dashboard** (React, an Express server, a PostgreSQL database at Supabase) are live
  at `woodex-2030.vercel.app`. They keep what they are given. The shop shows 148 **sample** products.
- **The Laravel admin** (`platform/`) is the system the programme moves to, module by module. It is live only as a
  **demonstration** at `woodex-os.vercel.app`: it starts from an empty database again and again, and keeps
  nothing. The 170 real products of woodexfurniture.pk are in its import, as drafts.

**What this round proved** (document 45): a product typed into an installed Laravel admin, with a photograph, is
what a visitor of the shop sees, in its category and on its own page; a change in the admin changes the shop; a
withdrawn product is gone. With a price, and priced on request. On a rehearsal in a sandbox and in CI on Apache
and MariaDB. **Not on a live site, because no live admin has a database.**

**What the round of 28 November added** (document 47): a visitor of such a shop can ask for the product. Its page
offers the quotation basket and WhatsApp and no cart; the basket arrives in the admin as an enquiry that names
the product, its code, how many, and its price as published, or "price on request" without a figure. Proved the
same way: a rehearsal in a sandbox and CI. Not on a live site.

**So the programme is not complete, and by the owner's own rule nothing here is called complete**: "Do not claim
completion until the actual database, browser workflow and deployment have been verified."

## 2. The blockers, the most urgent first

| # | Blocker | Who can remove it | What it holds back |
|---|---|---|---|
| 1 | **The old dashboard's password is public** (D-002, critical; audit AU-01). It was printed in a document of a public repository and has not been changed | **The owner**, five minutes: `woodex-2030.vercel.app/dashboard` → Settings → "Your password". Then the repository private | Everything the old dashboard holds: enquiries, orders, customers |
| 2 | **No database for the real admin exists** (his P0 point 5). Whatever is typed into the admin today is typed into a demonstration | **The owner**: the ten steps of [document 24](24-HOSTINGER-STEP-BY-STEP.md) on Hostinger (T-006), or his word that the host is another one (blocker 3) | Every "is it in the database" of his acceptance on a live site; the photographs (T-089); the switch of the live shop; every module's move |
| 3 | **Hosting and architecture: the prompt and the decisions in force name different things** (section 5) | **The owner**, one answer: which Hostinger plan he has | Next.js, PostgreSQL, Docker, Redis, tenants. Nothing of them is begun, and nothing built so far depends on the answer |
| 4 | ~~A visitor cannot yet ask for a product that only the admin has~~ **Gone on 28 November** (D-060 closed; document 47). The quotation basket for such a product arrives in the admin as an enquiry with its lines. No cart is offered for it until the admin takes orders (T-106) | — | Nothing any more. For the switch of the live shop, three settings on each side join the list ([document 24](24-HOSTINGER-STEP-BY-STEP.md), §6) |
| 5 | **The real products are drafts**: 170, their photographs only linked (T-089), 116 with a point to check and 33 without a price (T-090) | The owner reviews (T-090); the agent copies the photographs once a server exists (T-089) | A shop that shows Woodex's own products |
| 6 | **No backup of the live shop's database is known** (D-011, audit AU-05) | **The owner**: it is his Supabase account | Any change to that database, and the day the old system is closed |
| 7 | **Facts and claims on the shop that nobody confirmed** (D-063): the street, the hours, the delivery charge and the free-delivery threshold, named clients and projects, counts | **The owner**: T-194 and the open points of [document 44](44-RELEASE-2026-11-25-THE-SHOPS-CURES.md) | A shop that may be shown to customers |

## 3. The eight classes

The owner's own classes, and what each means in the tables below.

| Class | Means here |
|---|---|
| **Verified** | Built, held by tests, pressed in a browser against a database that keeps its data, **on the deployed site** |
| **Not integrated** | Built and tested, and pressed in a browser on a rehearsal; no live site uses it |
| **Switched off** | Built, behind a switch that is off where it matters |
| **Partial** | Some of it exists |
| **Placeholder** | A screen or a text that stands for something not built |
| **Demo only** | Works on the demonstration, which keeps nothing; not accepted by the owner |
| **Missing** | Nothing of it exists |
| **Blocked by the owner** | Waits for something only he can give or do |

Nothing of the Laravel admin is "Verified" in this sense, and nothing can be before blocker 2 is gone.

## 4. The matrix

### P0

| # | His point | Class | Today | Missing | Lines |
|---|---|---|---|---|---|
| 1 | Audit the branch and the live failure | Done | This document, document 45 and the audit of 23 November (43). The live failure (issue #59) was one answer of Vercel's own protection, not the demo; the check is cured | — | — |
| 2 | Rotate exposed credentials | **Blocked by the owner** | One is known: the old dashboard's owner password (D-002). The scan that runs on every push reads the files as they stand and finds nothing; it does not read the history, so a secret that was once committed and taken out again would not be seen by it | His change of the password; the repository private | T-021 |
| 3 | Confirm hosting and architecture | **Blocked by the owner** | In force: the shop stays React (DL-003), the admin is Laravel with Livewire (DL-005), Hostinger "Business" shared hosting with MySQL (DL-020), one organisation (his specification). The prompt names others | His answer (section 5) | T-006 |
| 4 | Pull request #48 and issue #59 | Done | #48 is inside the work of document 45 by a merge and closes with it; #59 is explained and closes with it | — | — |
| 5 | A persistent Laravel production database | **Missing; blocked by the owner** | The release installs itself in a browser into an empty database: proved on every push on Apache with MariaDB 10.11, and the suite on MySQL 8.4 and MariaDB 11.4 | A host and an empty database | T-006, T-133 |
| 6 | Canonical product ownership | **Not integrated** | The admin is the only writer of a product. With the link on, the shop's own database changes no price of it, and the shop's server reads and never writes | The live switch | T-105 |
| 7 | Native product create, edit and publish | **Not integrated** | Create, photograph, code, description, measures, category, price or "Price on request", save, publish, change, withdraw, archive; every published version is kept. Publication is refused without a name, a code no other product has, an address of its own, an active category, a description, delivery and warranty texts, a main photograph with its describing text, a price or the tick, and the saved preview opened by the person who publishes | His five steps (draft → review → approved → published → archived): the admin has draft, published, withdrawn and archived; "reviewed" is a tick, and publishing is a permission of its own, but an approval is not a state that is kept (T-199). A database-level guard of the code (T-193) | T-090, T-193, T-199 |
| 8 | The storefront reads published Laravel products | **Not integrated; switched off live** | The list in pages, one product, its photographs, the categories and the media, in the contract of `/api/v1`; the shop reads them through its own server. His seventeen steps pass, with a price and on request, and since 28 November three more: no cart is offered for such a product, a visitor's quotation basket is sent, and it is in the admin as an enquiry with its lines (document 47) | The addresses for series, filters and search of his list (T-192); an order for such a product (T-106); what an open page costs the host (T-200); then the switch | T-192, T-106, T-200 |
| 9 | Security and data-integrity blockers | **Partial** | The defect register (document 16 §5): 86 entries, 69 closed, as of 28 November. D-081 was seven advisories against Symfony packages that the first push of 27 November found and the same push cured. The round of 28 November closed D-060 and entered and closed five of its own: the admin counted every visitor of the shop as one address (D-082, high); a category of the shop could say that nothing is listed in it, which the live shop could do too (D-085, medium); a basket that had been sent stayed in the header (D-086, low); and two faults of the rehearsal itself (D-083, D-084, low). Open: 1 critical (blocker 1), 1 high (blocker 7), 11 medium, 4 low | Blockers 1, 6 and 7; two-factor sign-in (T-022); style and analyser in CI (T-196) | document 16 §5 |

### P1

| His area | Class | Today | Missing | Lines |
|---|---|---|---|---|
| Storefront redesign | **Missing** | The shop was cured on 25 November (width on a phone, names for a screen reader, one set of contact facts), not redesigned. No design system is written | A design he approves; his word on Next.js (section 5) | T-194, T-198 |
| Admin shell and sidebar | **Demo only** | The admin has its shell: a sidebar, a dashboard, and Feature status, which lists all 68 destinations in ten groups: 31 open, 21 built and off, 13 still in the old dashboard, 3 not built | His redesign is not begun; no module is accepted | T-045 |
| Light and dark themes | **Partial** | The admin has light, dark and "as the device": Workspace preferences. The shop has one theme | The shop's dark theme, with the redesign | — |
| CRM and sales | **Demo only** | Open in the admin: Leads, Contacts, Tasks and visits, Quotations, Invoices, Payments, Documents. Built for another business and made furniture-only; Furniture's own price list, terms and signers are not in (T-003). Off: Conversations, Chat Bot, Estimations | His price list and terms; the shop's forms writing to Laravel (T-100); acceptance | T-003, T-100, T-171 |
| Finance, projects and BOQ | **Demo only** | Open: Ledger and reports, Payables, Income and expenses, Projects, Board, Vendors and costs, Handovers. Off: Client portal | As above | T-171 |
| Inventory and procurement | **Not integrated** | In the old dashboard, on its own database: Suppliers, Purchase orders, Goods receipts, Supplier invoices, Stock ledger, Warehouses, Stock transfers, Reservations. Nothing of them in Laravel | The move (waves 3 to 11 of the plan) | document 21 |
| Production and quality control | **Not integrated** | In the old dashboard: Production with bills of material, and quality control | The move | document 21 |
| Delivery and support | **Not integrated** | In the old dashboard: Orders, Delivery and installation, Support and warranty | The move; orders first (T-106) | T-106 |
| Page builder | **Switched off** | Thirteen screens of the admin manage a website that Laravel would serve; Furniture's shop is a React application, so they are off. The plan is a new editor after a prototype | The prototype's ten proofs | T-115, T-116 |
| Multi-tenant base | **Missing** | One organisation, by his own specification ("single-business configuration, not multi-tenancy"). The old dashboard has none either: its tracker says "Tenancy / RLS: not started" | His word that several businesses are wanted, and when | section 5 |

### P2

| His area | Class | Today | Missing |
|---|---|---|---|
| Integrations | **Switched off** | WhatsApp, Telegram and a Facebook Page: built in the admin, off; each needs an account of his (T-014, T-120) | The accounts |
| Automation | **Switched off** | Automations and Sequences, off: they need the inbox first (T-119) | The inbox |
| Reporting | **Demo only** | Reports and the dashboard, on the demonstration | Real data |
| SEO | **Partial** | The shop writes static pages with titles, descriptions and structured data; the demo address is closed to search engines (DL-048). The admin's SEO screens are off with the page builder | A real catalogue first |
| Performance | **Partial** | Measured in the audit of 23 November; the admin's built files are kept for a year by a browser (T-152). On a shared host: not measured | T-197 |
| Monitoring | **Partial** | A request number on every answer, structured logs, a health screen, and a check of both live sites four times a day and after every merge; a failure opens an issue | The same check for the real admin, once it exists (T-142) |
| Documentation | **Partial** | The documents of this folder, checked against the code on every push. No guide yet for the people who will use the admin | T-186 |
| Cutover | **Missing** | Nothing is cut over. The order is written: document 21 | Blockers 1 to 6 |

## 5. One question decides the architecture

The prompt names a target: **Next.js** for the shop, **PostgreSQL** with row-level security and **tenants**,
**Redis**, and a **Hostinger VPS with Docker**, "subject to owner confirmation of the actual plan".

The decisions in force, each from the owner's own words or papers, name something else:

| | In force | Where | The prompt |
|---|---|---|---|
| The shop | React with Vite, kept | DL-003 | Next.js |
| The admin | Laravel with Livewire | DL-005 | Laravel (the same) |
| The database | MySQL or MariaDB | DL-020; his specification, D-004 | PostgreSQL |
| The host | Hostinger "Business", shared hosting. His specification assumes of it: no lasting Node server and no Redis "unless the exact plan confirms it" | DL-020; his specification, D-005 and document 02 §7 | A VPS with Docker and Redis |
| Businesses | One organisation | His specification: document 03 ("not multi-tenancy"), assumption P-006 | Several, kept apart |

**Nothing was changed on either side.** The question that decides all of it is one he can answer from his
Hostinger account: **which plan is it?** On "Business" (shared hosting) the decisions in force are the only ones
that can run. On a VPS the prompt's target can be built, and then it is a decision of its own, with its cost: the
admin's suite passes on MySQL, MariaDB and SQLite today, and has never run on PostgreSQL; a Next.js shop is a
rebuild of a shop that works.

What document 45 built holds under either answer: the contract of `/api/v1`, the admin's rules for a product, and
the shop's reader use nothing that one database has and the other lacks.

## 6. His twelve documents, and where each lives

He asked for twelve documents by name, and for existing ones to be updated rather than doubled.

| His document | Lives in | State |
|---|---|---|
| Implementation status | **This document**, with the master list (14) and the plan (21) | Current |
| Architecture decisions | [12-DECISION-LOG.md](12-DECISION-LOG.md): 49 decisions, never edited after the day | Current |
| API contract | [29-API-V1-RULES.md](29-API-V1-RULES.md) and `platform/resources/api/v1.openapi.json`, held to the code key by key | Current |
| Migration register | [36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md](36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md), from `acceptance/tables.json` | Current |
| Database plan | Documents 36 and [38](38-RELEASE-2026-11-20-VALUES-AND-RECORD-NUMBERS.md) | Written for MySQL; waits for section 5 |
| Security review | [43-DEEP-AUDIT-2026-11-23.md](43-DEEP-AUDIT-2026-11-23.md) and the defect register (16 §5) | Current |
| Deployment guide | [24-HOSTINGER-STEP-BY-STEP.md](24-HOSTINGER-STEP-BY-STEP.md) for the host, and [13-VERCEL-DEPLOYMENT.md](13-VERCEL-DEPLOYMENT.md) for the demonstration | Written for shared hosting; waits for section 5 |
| Acceptance report | [16-ACCEPTANCE-AND-RELEASE-GATES.md](16-ACCEPTANCE-AND-RELEASE-GATES.md) and one release report for each round (the last: 45) | Current |
| Owner decisions | Document 14 §3 and `docs/furniture-2030/01-OWNER-DECISIONS.md` | Current; 30 lines wait for him |
| Design system | **None yet** | Missing: with the redesign |
| Storefront implementation | **None yet**: the shop's pages are described in documents 43 and 44 only | Missing: with the redesign |
| Integration matrix | **None yet**: the connections are listed on the admin's Connections screen | Missing: P2 |

## 7. What the next rounds are

In the order of the plan (document 21), each its own pull request with its own report:

1. **T-191: done on 28 November** (document 47). A visitor asks for a product of the admin by quotation basket;
   D-060 is closed. An order for such a product is T-106. The live shop can be switched once blockers 2 and 5
   are gone.
2. **T-192** and **T-193** (the agent, can start now): the addresses for series, filters and search; one code
   for one product, held by the database. Then **T-200**: what an open page of the shop costs the admin's host.
3. **With the owner's step for blocker 2**: the real admin on its host, the 170 products with their photographs
   (T-089), his review (T-090), and his acceptance, module by module (T-171).
4. **P1 and P2**, after his answer to section 5.
