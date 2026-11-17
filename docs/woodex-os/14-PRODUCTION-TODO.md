# WOODEX OS — master to-do list to complete the Laravel admin

**Version 2 · written 2026-11-17** · Branch `arena/7ecb4f83-woodex-2030` (pull request #36) · Replaces version 1 of the same morning.

This is the one list of everything between today and a complete, production Laravel admin. It holds **every
task that was already pending and every new one** found by analysing the owner's specification set, including
the three documents received on 17 November (10 QA and acceptance, 11 roadmap, 12 the owner's decision log).
The analysis itself, requirement by requirement with the evidence, is
[`15-SPECIFICATION-ANALYSIS.md`](15-SPECIFICATION-ANALYSIS.md). The checks made on the hosted admin itself
(every screen, as every role, on a desktop and a phone) are kept in §17.

**How to read a line.** `T-…` is the line's number; it does not change. **Pri** is the priority of document 11:
P0 security, data integrity, sign-in, hosting, the core sales flow · P1 commerce, purchasing, stock, projects,
finance · P2 page builder, automation, marketing, advanced reports · P3 convenience. **NEW** marks a line that
was not in version 1. **Status:** Done · Next (can start now, needs nobody) · Later (after the wave or line
named) · Owner (waits for the owner). The old numbers of version 1 are mapped in §16.

**"Complete" means** (documents 07 §15, 10 §7, 11): every one of the 68 destinations is either accepted by the
owner or deliberately dropped; the five release gates A to E are passed; staff work in one admin on Hostinger
with real, backed-up data; the old dashboard is no longer needed.

---

## 1. Where it stands

| | |
|---|---|
| Live demo | `https://woodex-os.vercel.app/admin`, commit `7e5fffc`, **sample data only**. One account per role. Checked from outside by a GitHub workflow: 35 of 35 |
| Destinations | 68. For an owner: **29 open**, 13 still in the old React dashboard, 21 built but switched off, 5 not built |
| Accepted modules | **0.** A module is accepted only with browser acceptance, a migration plan and the owner's sign-off (document 07 §15; T-171) |
| Release gates | A design: built, not approved · B modules: none · C page builder: not started · D Hostinger: not started · E cutover: not started |
| Tests | 2,244 pass, 34 skipped, 0 fail (PHP 8.5, SQLite); the same suite on MySQL 8.4 in CI. 14 browser steps pass on the rehearsal |
| Production host | **Hostinger shared hosting ("Business") with MySQL** — the owner's decision (D-004, D-005, OD-3). Its staging site has never started (two failed checks, 13 November) |
| Real data | None in Laravel. The live business runs on the React shop, the React dashboard and the Node API (Vercel + Supabase) |
| The other admin | `woodex-2030.vercel.app/admin` — the sign-in titled "Woodex Workspace", with a username — is the **old React dashboard**, a different system. The Laravel admin is `woodex-os.vercel.app/admin` — "Welcome back", with an e-mail address. The old one stays in use for its 13 destinations until each is moved (T-025) |

**This list in numbers:** 128 lines — 3 done, 28 can start now, 70 wait for an earlier line or wave, 27 wait for the owner. 55 are new since version 1. By priority: 29 P0, 68 P1, 24 P2, 7 P3.

---

## 2. What the analysis of the attached documents changed

1. **Three documents were new** (10, 11, 12); the other eight are identical to the copies already stored. All are now in `spec/`. The PRD (`01-MASTER-PRD.md`) is still missing.
2. **Your decision log (12) says "keep the React dashboard"** (D-003, D-009). On 16 November you chose the Laravel admin instead, and today's request names it. That later decision is followed. Because the log reached us after it, one word of confirmation is asked (T-001).
3. **Hosting is already decided in your log**: Hostinger shared hosting with MySQL. Version 1 asked you to choose between Hostinger and Vercel; that question is withdrawn. The Vercel site stays what it is, a demo.
4. **"Woodex Interior" is still in screens that are open today** — in the WhatsApp greeting a salesperson sends from a lead, in the invitation e-mail to new staff, under the logo of the public invoice page, as the first choice on "New quotation". Your log excludes Interior from this product (D-008). New block §4, priority P0.
5. **Document 10 names 25 acceptance scenarios, 12 security tests, 11 browser flows and five release gates.** Ten of the twelve sales and finance scenarios already have their rule and a passing test in Laravel; two are partly met. The thirteen stock, purchasing and page-builder scenarios have no module yet. Missing in between: browser journeys for the main flows, and five kinds of security test (§11, §12).
6. **Document 11 adds deliverables and exit rules** that were not on the list: the dashboard as a widget framework, the sales flow proven end to end on staging, the Interior cleanup, keyboard tests, and your approval of the design before the shell counts as finished.
7. **Times are stored in Pakistan time, not UTC** as document 03 requires. This must be changed before real data is entered, because changing it afterwards means converting every stored time (T-061).

---

## 3. Decisions and inputs only the owner can give

IDs in brackets are the owner's own log (document 12) or earlier records.

| # | Pri | What is needed | Default in force until answered | Blocks | Status |
|---|---|---|---|---|---|
| T-001 | P0 | **NEW** Confirm: the admin is the Laravel admin; the React dashboard is retired module by module. This changes D-003 and D-009 of your log for the dashboard (not for the shop) | Yes — your decision of 16 November (DL-001, DL-005) | everything | Owner |
| T-002 | P0 | Merge pull request #36 into `main`. The shop redeploys from `main`; its test gate is green on the branch | Not merged (DL-011) | one code line; the timed live check | Owner |
| T-003 | P0 | Furniture's own price list, terms, quotation wording and signers (D-8) | No template or rate is installed; issuing documents is blocked | T-084, T-085, T-103 | Owner |
| T-004 | P0 | **NEW** Who may approve quotations, discounts, expenses, purchase orders and publishing, and the discount limit of each role (O-010). Which staff roles exist and how many people in each (Q-6) | Quotations: by permission, with a limit per role set in Settings; the owner may approve anything | T-060, T-081 | Owner |
| T-005 | P1 | **NEW** Approve the design foundation: sidebar, colours, top bar, cards — on the live demo (gate A; document 11, phase 1). Includes the sidebar's tone: light, as in the Reimagined theme, or charcoal | Light sidebar (DL-017) | gate A | Owner |
| T-006 | P0 | Hostinger: the plan's limits, PHP version and extensions, SSH, Composer, cron (O-001, O-002, D-10); and the two SSH secrets entered in GitHub (environment `hostinger-staging`) — never in chat | Plan: "Business" (OD-3). Nothing verified on the account | T-130 to T-142 | Owner |
| T-007 | P1 | Addresses: which domain or subdomain for the shop, the admin and the API (O-003) | `woodex-os.vercel.app` for the demo | T-140, T-063 | Owner |
| T-008 | P0 | Confirm the currency (PKR) and the tax treatment: rates, included or added, on which documents (P-001, P-002, Q-4) | PKR; one tax rate typed per document | T-064, T-087 | Owner |
| T-009 | P0 | A mailbox for sending (SMTP) on the Furniture domain (Q-7) | Mail is written to a log, not sent | T-135 | Owner |
| T-010 | P1 | **NEW** Are delivery and installation two separate jobs? (O-005) | Two milestones of one job | T-107 | Owner |
| T-011 | P1 | **NEW** Product options and variants, and the stock rule: sell only what is in stock, or make to order? (O-006) | Variants as described in the catalogue import; no stock rule | T-104, T-109 | Owner |
| T-012 | P1 | **NEW** Warehouses and branches: how many, where (O-007) | One | T-109 | Owner |
| T-013 | P1 | **NEW** Accounting: who reviews it, the chart of accounts, and whether the ledger screens are hidden at launch (O-008, OD-5, Q-8) | A generic chart; ledger screens visible to owner and accountant | T-113, gate B for finance | Owner |
| T-014 | P2 | **NEW** WhatsApp and Telegram: provider and account for Furniture (O-009, OD-6) | Disconnected; click-to-chat links only | T-118 | Owner |
| T-015 | P2 | **NEW** Is the client portal part of the product? (P-004) | Built, switched off | T-112 | Owner |
| T-016 | P2 | **NEW** Keep today's shop addresses; approve the list of redirects before cutover (P-008) | Keep | T-121 | Owner |
| T-017 | P1 | Which existing customer and order records may be moved (D-9, OD-7) | None | T-101 | Owner |
| T-018 | P1 | One person and one document for the business facts: warranty, delivery areas and charges, returns, company facts (Q-4) | — | T-106 to T-108, invoices | Owner |
| T-019 | P1 | Sign `docs/furniture-2030/catalogue/IMPORT-REPORT.md` (the real catalogue, 170 products) | Not signed | T-104 | Owner |
| T-020 | P1 | Migration 019 on the shop's Supabase database, by someone with database access | Not run | the live shop's catalogue | Owner |
| T-021 | P0 | Change the old system's owner password (it was printed in a public document) and make the repository private | On hold by your decision (DL-012) | — | Owner |
| T-022 | P1 | Two-factor sign-in required for owner and admin? | Optional | T-065 | Owner |
| T-023 | P2 | The missing `01-MASTER-PRD.md` | Documents 05 and 07 stand in | — | Owner |
| T-024 | P3 | **NEW** "The actual AI agent failure example and task" (O-011): your log's own open question. Needed only if you want that diagnosis | — | — | Owner |
| T-025 | P2 | The old address `woodex-2030.vercel.app/admin` still opens the old React dashboard ("Woodex Workspace"), and is easily taken for the new admin. Choose: leave it until cutover, or put a line on that sign-in page that links to the Laravel admin | Left as it is. A link is a small change to the old dashboard; it would ship with T-002 | — | Owner |

**Already answered — no action:** online payment at checkout: no, cash on delivery at launch (O-004, OD-4), so payments are recorded by hand (P-003) · a staging account for visual checks: the demo, one account per role (O-012) · production host: Hostinger shared hosting, MySQL (D-004, D-005).

**Built as your log assumes — say so only if it is wrong:** one organisation, no branches as separate workspaces (P-006) · the admin is in English (P-007) · quotation approval goes by role (P-005; the limits are T-004).

---

## 4. Furniture only — your boundary D-008 (new block)

The Laravel code came from the Interior application. Modules that are switched off are out of sight. But the
**open** screens still carry Interior, and some of it reaches customers. Document 11 lists the same work as a
deliverable of its phase 1: "Branding cleanup from Interior-specific UI labels".

| # | Pri | Task | Done when | Who | Status |
|---|---|---|---|---|---|
| T-030 | P0 | **NEW** One setting that makes the admin Furniture-only. Today "New quotation", "New invoice" and the document designer start on the **Interior** line, and "New quotation" offers "Woodex Interior" first. The dashboard, the revenue report, Approvals, the lead form, assignment rules, the description library, quotation templates, the chart of accounts and bank accounts offer "Interior" and "Project (turnkey)" as a business line; lead lists print the line beside each lead | No open screen shows the word "Interior" for any of the four roles — checked by the crawl of every screen and by a test; new records are Furniture records | Agent | Next |
| T-031 | P0 | **NEW** Messages that leave the admin name the wrong company: the WhatsApp greeting on a lead, a contact, the lead board and a quotation ("…from Woodex Interior"), the visit confirmation, the invitation e-mail to new staff ("the Woodex Interior sales and projects system"), and the sender name the installer writes (`Woodex Interior`) | Each message is rendered in a test for a Furniture record and carries the company name from Settings | Agent | Next |
| T-032 | P0 | **NEW** Public pages a client opens: the invoice page, the holding page and the portal pages print "INTERIOR STUDIO" under the logo; the quotation page prints it for any line but Furniture | Each page shows the Furniture name; a test opens each | Agent | Next |
| T-033 | P1 | **NEW** Interior lists and fields inside open screens: quotation types (Renovation, Civil Work, Interior Designing, Fit-out, Architecture), lead fields (property type, area in square feet, budget band), the pipeline step "Site Visit" | Replaced by Furniture's own wording (needs T-003, T-018) or hidden by the setting of T-030 | Agent, owner | Later (T-003) |
| T-034 | P1 | **NEW** Interior code that stays switched off (two Interior quotation PDFs, the estimator's rate book, case-study drafts, chat-bot texts): prove it cannot be reached while its module is off | A test asks for each and gets "not found"; each is removed or adapted in its module's wave | Agent | Next |
| T-035 | P1 | **NEW** Tests that expect two brands (for example "offers both brands…"; 27 test titles mention Interior or "the studio") | Each changed expectation is listed in the round's report with the reason; no test is deleted | Agent | Next (with T-030) |

---

## 5. Design and experience (wave 1 · gate A · document 11, phases 1 and 2)

| # | Pri | Task | Done when | Who | Status |
|---|---|---|---|---|---|
| T-040 | P1 | Tokens, navigation registry, sidebar, top bar, the Reimagined theme on the shared parts, own error pages, touch targets, labels | Tests and crawl clean; screenshots in `evidence/` | Agent | **Done** `537d89a`, `493ce22`, `7e5fffc` |
| T-041 | P1 | One table component (sort, filters, pagination, selection, bulk actions) in place of hand-written tables, with pagination, dialog and toast standards and the five states: loading, empty, error, no permission, success | One list per area uses it; a test renders each state | Agent | Next |
| T-042 | P1 | **NEW** On a phone, the key lists (leads, quotations, invoices, tasks) show cards instead of a table that scrolls sideways (document 05 §9) | At 390 px each row's main facts and its action are visible without sideways scrolling | Agent | Later (T-041) |
| T-043 | P1 | **NEW** A warning before leaving a form with unsaved changes (document 05 §8). None exists; six editors only say on screen that edits are unsaved | Leaving a changed create or edit form asks first; tested in a browser | Agent | Next |
| T-044 | P1 | Dashboard as document 05 §7, built as a widget framework (document 11, phase 2). Present: period figures, six live queues, needs-attention, pipeline, new leads, recent activity. **NEW parts:** sales funnel with conversion rate; overdue ageing and payables due; project health and milestones; every chart states its dates, currency and definition; every widget has loading, empty, error and no-access states | Each figure's link opens the same number of rows; a widget whose module is not here is absent | Agent | Next |
| T-045 | P1 | Screen pass, area by area: E-commerce, CRM, Sales first (DL-008); page patterns of document 05 §8; selected states that are still charcoal move to the theme's green; greys still written directly into a view move to the theme's roles; the dark theme looked at on every screen (so far on three) | Before-and-after screenshots of one screen per area at 1366 px and 390 px, light and dark | Agent | Next |
| T-046 | P2 | Help entry in the top bar; pinned (favourite) screens; recently opened screens (document 05 §3 and §4; document 11, phase 2) | Present, usable by keyboard, remembered per person, tested | Agent | Next |
| T-047 | P3 | **NEW** Search finds a screen by keywords and by its old name, not only by its title (document 05 §3) | "stock" finds Stock Ledger; "clients" finds Contacts | Agent | Later (T-046) |
| T-048 | P3 | **NEW** The signed-in person and a link to Settings at the foot of the sidebar (document 05 §4; the Reimagined admin has it) | Present in the expanded sidebar and the phone drawer | Agent | Later (T-005) |
| T-049 | P2 | **NEW** Text smaller than 12 px: about twenty places (group labels 10.5 px, the line under the brand 9.5 px, count pills, keyboard hints). Document 05 §5 sets 12 px as the smallest | Nothing in the admin is set below 12 px, or the owner accepts named exceptions | Agent | Next |
| T-050 | P2 | **NEW** A reference screen showing every shared component and state (gate A: "shared component examples") | One screen under System, for owner and admin | Agent | Later (T-041) |
| T-051 | P2 | **NEW** Placeholders while a list loads, and a notice when the connection is lost (document 05 §6) | Seen in a browser with the network slowed and cut | Agent | Later (T-041) |
| T-052 | P2 | Page weight: menu, search templates and icons are about 110 KB of every page | A near-empty page is under 60 KB of HTML | Agent | Next |
| T-053 | P2 | Phones with real amounts of data: a table in a grid column can push the page wider than the window | The crawl on realistic data shows no screen wider than a phone | Agent | Later (T-155) |
| T-054 | P1 | Keyboard and screen-reader acceptance (document 10 §5). **NEW:** browser tests for the sidebar by keyboard, the command palette (open, search, select, Escape), focus inside dialogs and drawers, and announced errors; then one person's pass without a mouse | The tests run with the journeys; sign-in, a lead and a quotation done by keyboard only | Agent, owner's team | Next |
| T-055 | P3 | Every open page asks the server every 30 seconds (the notification bell) | Measured on the real host; slowed if it matters | Agent | Later (T-130) |
| T-056 | P2 | **NEW** The two planned screens, Workspace preferences and Team onboarding, and release notes on Feature status (documents 05 §2, 07 §14) | Both open; a new member is led through first sign-in and two-factor | Agent | Later (wave 2) |

---

## 6. Foundations (wave 2 — identity, access, audit, data rules)

Wave 2 replaces 15 routes of the old API. T-061 comes first: it is cheap today and expensive once real data exists.

| # | Pri | Task | Done when | Who | Status |
|---|---|---|---|---|---|
| T-060 | P0 | One role and permission matrix from the two systems (eight Laravel roles, five in Node), sign-in locks, no route without a permission | A matrix test proves each role's access on every route | Agent | Later (T-004) |
| T-061 | P0 | **NEW** Store times in UTC and show them in Pakistan time (document 03 §2). Today they are stored in Pakistan time (`APP_TIMEZONE=Asia/Karachi`, `DB_TIMEZONE=+05:00`) | The database holds UTC; every screen, PDF and export shows Pakistan time; tests pin both. **Before any real data** | Agent | Next |
| T-062 | P1 | **NEW** A request number on every response and every log line; logs in a structured form (documents 02 §9, 04 §2) | An error page shows the number; the same number finds the log line | Agent | Next |
| T-063 | P1 | **NEW** The `/api/v1` rules for the shop and outside clients: the answer shape `data` / `meta` / `error` with codes, pagination, allowed filters, an idempotency key on every create, money as whole minor units, and how the shop or an outside client signs in. Recorded with it: staff need no JSON API, because the admin's screens are rendered by Laravel | The seven existing endpoints follow the rules; a contract test fails on a different shape | Agent | Later (T-001) |
| T-064 | P1 | **NEW** Business settings in one screen: currency, time zone, tax rules (document 07 §14). Today "PKR" is written into the code in eleven files and tax is a rate typed per document | Changing the setting changes new documents; issued documents keep their own values | Agent | Later (T-008) |
| T-065 | P1 | A screen for sign-in locks and open sessions; two-factor enforced if T-022 says so | An owner can see and end a session and lift a lock | Agent | Later (T-060) |
| T-066 | P2 | **NEW** A currency code on payments, bills, expenses and journal lines (only quotations and invoices carry one) | Needed only if a second currency is ever used; decided with T-008 | Agent | Later (T-008) |
| T-067 | P1 | **NEW** A test that every sensitive action writes an audit row: approvals, publishing, postings, payments, role changes (document 08 §7) | The test lists the actions and fails when one is silent | Agent | Next |
| T-068 | P1 | Content-Security-Policy on every page | The crawl of all roles shows no blocked resource; a test pins the header | Agent | Next |
| T-069 | P1 | `composer audit` and `npm audit` on every push | Both run in CI and fail on a known high-severity issue | Agent | Next |
| T-070 | P1 | Answers no longer name PHP; the https-only header is sent on the Vercel address | Rehearsal and live check | Agent | **Done** `493ce22` |

---

## 7. Modules that are open today — gaps against documents 07 and 11

| # | Pri | Task | Done when | Who | Status |
|---|---|---|---|---|---|
| T-080 | P0 | **NEW** The core sales flow proven end to end in a browser (document 11, phase 3): enquiry → lead → contact → quotation → approval above the limit → issue → the client accepts → invoice → payment claimed and verified → receipt. Every step has its rule and test today; the chain has never been run as one. The estimate step joins with T-085 | One journey passes on the rehearsal with sample records — there the switch "the owner has confirmed the documents" (T-084) is set for the rehearsal only; then on staging with real test records (the phase's exit rule) | Agent | Next |
| T-081 | P1 | **NEW** Approvals as one queue for every kind (quotations today; expenses, purchase orders, stock adjustments and publishing as they arrive), with filters by kind, requester, age, amount and state, the requester told of the outcome, and nobody approving their own request (document 07 §2) | A second kind of approval appears in the same queue with the same rules | Agent | Later (T-004) |
| T-082 | P1 | **NEW** Reports: a catalogue filtered by role, a stated definition, time zone and currency on each report, exports limited by permission and written to the audit, large exports through the queue (document 07 §3) | Each report names its definition; an export leaves an audit row | Agent | Later (wave 9) |
| T-083 | P1 | **NEW** Contacts: several phone numbers and e-mail addresses per person, with "main" and "verified" marks (document 07 §4). Today four fixed fields | A contact holds any number of methods; duplicate detection reads all of them | Agent | Later (wave 3) |
| T-084 | P0 | Documents: issuing is blocked until terms and signers are confirmed | The owner confirms once; a sent quotation carries them | Agent, owner | Later (T-003) |
| T-085 | P1 | Estimations: Furniture's rates in place of the Interior rate book; module switched on | An estimate becomes a quotation with the same totals | Agent | Later (T-003) |
| T-086 | P1 | **NEW** A quotation line chosen from the catalogue keeps the product and its price as issued | A later price change does not alter a sent quotation (tested for rate-book lines today) | Agent | Later (T-104) |
| T-087 | P1 | **NEW** Tax rules on quotations and invoices; credit notes and payment schedules as the old dashboard has them | An invoice shows the rule it was issued under | Agent | Later (T-008) |
| T-088 | P1 | Commerce readiness: `php artisan commerce:readiness --production` lists seven blockers. Three are the environment (not production, no permanent address, no MySQL driver); four are work: video in galleries, full shop and series management, products, prices and quote requests switched over to Laravel, acceptance on the target host | The command exits clean on staging | Agent | Later (T-104, T-130) |

---

## 8. Modules to move from the old system (waves 3 to 11)

"Routes" is the number of Node API routes the wave replaces: a measure of size.

| # | Pri | Wave | Task | Routes | Done when | Status |
|---|---|---|---|---:|---|---|
| T-100 | P0 | 3 | The shop's enquiry and quote-request forms write to Laravel. An adapter is needed: field names, the reference returned, the WhatsApp hand-off (`docs/laravel-os/05` §4) | 28 | An enquiry from the shop is in the Laravel CRM | Later (T-001, T-130) |
| T-101 | P1 | 3 | Import of leads, contacts, companies, tasks and meetings: repeatable, with a dry run and a reconciliation | — | Counts, statuses and duplicates agree with the source | Later (T-017) |
| T-102 | P0 | 3 | Close the Node write path for CRM: one writer | — | The old form endpoint answers "moved" | Later (T-100) |
| T-103 | P0 | 4 | Sales documents merged: quotations, invoices, payments, with Furniture templates | 17 | A quotation becomes an invoice and a receipt with totals computed on the server | Later (T-003) |
| T-104 | P1 | 5 | The real catalogue (170 products) imported as drafts: SKUs, options, price on request; series | 19 | Every product is a reviewed draft; nothing is published without the owner | Later (T-019, T-011) |
| T-105 | P1 | 5 | The shop reads products, categories and series from `/api/v1`; the Node catalogue is closed | — | The shop shows only what Laravel has published | Later (T-104, T-063) |
| T-106 | P1 | 6 | Orders: created in the admin and by the shop, lines with prices as ordered, status history by explicit steps, payment state kept apart from delivery state, cancellation and return | 35 | An order placed in the shop is delivered and closed in the admin | Later (T-105, T-018) |
| T-107 | P1 | 6 | Delivery and installation: schedule, address, team, proof, customer told | — | A delivery is closed with its proof | Later (T-010) |
| T-108 | P1 | 6 | Support and warranty: cases, installed items, the warranty terms as sold, service visits | — | A claim is opened against an order and resolved | Later (T-018) |
| T-109 | P1 | 7 | Stock: an append-only ledger, warehouses, transfers, reservations, adjustments with a reason and approval, a reconciliation report. The five stock scenarios of document 10 and their tests are ported first | 33 | Every stock change is a ledger row; ledger and balances agree; the oversell tests pass on MySQL | Later (T-012) |
| T-110 | P1 | 7 | Suppliers merged with vendors; purchase orders with approval and revisions; goods receipts (partial, damaged, posted once); supplier invoices matched three ways, one payable authority with bills | — | A receipt posts stock exactly once, also when sent twice | Later (T-109) |
| T-111 | P1 | 8 | Production: bills of material, job cards, stages, material issue, a quality gate, the operations board | 11 | The quality gate cannot be passed without an audited override | Later (T-109) |
| T-112 | P1 | 9 | Projects merged (members, dependencies); client portal switched on with tests that one client never sees another's data | 30 | The isolation tests pass | Later (T-015) |
| T-113 | P1 | 9 | Finance merged: expenses, credit notes, payment schedules; reviewed by the accounting owner. Launch scope by your decision OD-5: invoices, receipts and payables; the double-entry ledger only if T-013 says so | — | The trial balance balances; a posted entry changes only by reversal; the reviewer signs | Later (T-013) |
| T-114 | P2 | 9 | Reports merged (with T-082) | — | Figures agree with their source records | Later (T-113) |
| T-115 | P2 | 10 | **Page-builder prototype (gate C):** the ten proofs of document 06 §11 — pinned Puck version, edit-save-reload, preview equals the shop's rendering, schema versions, media rights, private drafts, conflict handling, approve-publish-roll back, product blocks read the live catalogue, existing pages adapt without loss | — | The owner accepts the prototype before any broad build | Later (T-105) |
| T-116 | P2 | 10 | Website Studio on Puck: all pages, navigation, sections and templates, theme, SEO, redirects, blog, portfolio, forms | 28 | A page is edited, approved, published and rolled back | Later (T-115) |
| T-117 | P2 | 10 | Today's pages moved: a mapping table from each block to its Puck component; representative pages first; screenshots compared | — | Selected pages are approved; the old content is kept | Later (T-116) |
| T-118 | P2 | 10 | Inbox for Furniture's channels | — | A message is answered from the admin; consent and opt-out are kept | Later (T-014) |
| T-119 | P2 | 10 | Automation with a dry run, retries without duplicates, an approval for high-impact actions | — | A rule is tried without sending, then switched on | Later (T-118) |
| T-120 | P2 | 10 | Marketing: campaigns, UTM attribution to leads, landing pages | — | A lead shows the campaign it came from | Later (T-116) |
| T-121 | P0 | 11 | Cutover: final data move and reconciliation, Hostinger production, the Node API, Vercel and Supabase retired, a stated recovery point | 5 | Document 09 §9, steps 9 to 13 — **with separate written approval** (gate E) | Later (all) |

**Every one of the 68 destinations, and the line that completes it.**

| State today | Destinations | Lines |
|---|---|---|
| Open (29) | Dashboard, Approvals, Reports · Leads, Contacts, Tasks & Visits · Quotations, Invoices, Payments, Documents · E-commerce, Products, Categories, Series · Projects, Board, Vendors & costs, Handovers · Ledger & reports, Payables, Income & expenses · Shop customization, Media · Settings, Access & team, Connections, Platform, Audit & activity, Feature status | Gaps: T-030 to T-088. Then acceptance, module by module: T-171 |
| In the old dashboard (13) | Orders · Delivery & Installation · Support & Warranty · Operations board, Production · Suppliers, Purchase Orders, Goods Receipts, Supplier Invoices · Stock Ledger, Warehouses, Stock Transfers, Reservations | T-106 · T-107 · T-108 · T-111 · T-110 · T-109 |
| Built, switched off (21) | Estimations · Client portal · Conversations, Chat Bot · Automations, Sequences, WhatsApp Templates · Marketing · All pages, Page builder, Blog, Portfolio, Forms, Theme, SEO, SEO agent, Speed, Site health, Website Settings, Redirects, Find & replace | T-085 · T-112 · T-118 · T-119 · T-120 · T-116 |
| Not built (5) | Site overview, Navigation, Sections & templates · Workspace preferences, Team onboarding | T-116 · T-056 |

**Not part of the admin, named so that nothing is lost:** document 11's phase 6, the redesign of the shop itself (design system, home and page templates, catalogue pages, quote journeys, search-engine work). Its plan is in `docs/furniture-2030/`. Where it meets the admin it is on this list: the shop's forms (T-100), the catalogue it reads (T-105), its pages in the page builder (T-115 to T-117), its build on Hostinger (T-141).

---

## 9. Hosting on Hostinger — the production host (gate D · wave S)

| # | Pri | Task | Done when | Who | Status |
|---|---|---|---|---|---|
| T-130 | P0 | The staging site starts. The release package builds in GitHub; the live test has failed twice (13 November: the admin did not start; the notes point to a missing `vendor/` folder) | The workflow "Hostinger live staging smoke" passes | Agent | Later (T-006) |
| T-131 | P0 | **NEW** Compatibility verified on the account: PHP 8.4.1 or newer with the needed extensions, MySQL or MariaDB version, whether PHP may start programs (backups need `mysqldump`), Composer | The SSH check job passes and its findings are written into the plan | Agent | Later (T-006) |
| T-132 | P0 | A deploy job from GitHub: source outside the public folder, settings on the host only, the last release kept for rolling back | A push to a release branch updates staging; rolling back is one step | Agent | Later (T-130) |
| T-133 | P0 | First installation: tables and the first owner, through the web installer or `php artisan woodex:install`; no default password | The owner signs in on staging with their own account and second factor | Agent, owner | Later (T-132) |
| T-134 | P0 | Timed jobs: 30 entries, 8 every minute, the queue drained by two of them. One cron line calls the scheduler | The Health screen shows a heartbeat younger than two minutes for one hour; a queued notification leaves within a minute | Agent | Later (T-132) |
| T-135 | P0 | Mail through SMTP (ports 465 or 587; the host caps its own mail at 100 a day) | "Forgot your password?" reaches a real mailbox | Agent | Later (T-009) |
| T-136 | P1 | Uploads: size limits, a folder outside the release, still there after a deployment | A product photo survives a deployment | Agent | Later (T-132) |
| T-137 | P0 | Backups and one proven restore | A backup has been restored into an empty database and the admin opened on it | Agent, owner | Later (T-133) |
| T-138 | P1 | Errors and failed jobs reach a person: an error tracker or the built-in alert rules by mail | A forced error is seen within five minutes | Agent | Later (T-135) |
| T-139 | P1 | **NEW** Behaviour within the plan's limits (document 10 §2 and §6). Read from Hostinger's documentation, not yet seen on the account: 120 processes, 60 entry processes, a request stopped after 120 s. The crawl and the journeys run against staging with realistic data | No request over 5 s; no limit hit | Agent | Later (T-133) |
| T-140 | P1 | https, the domain, the admin's own host name | The admin answers on its address; the old one redirects; the https-only header is seen on the real domain | Agent | Later (T-007) |
| T-141 | P1 | **NEW** The shop's static build served from the same account; cookie and cross-origin rules between shop and API (document 09 §9, steps 6 and 7) | A form on the staging shop reaches the staging admin | Agent | Later (T-100) |
| T-142 | P2 | The outside check on a timer: for the demo now, for staging and production later. The six-hourly timer is in the workflow; GitHub starts timers only from `main`. A failure should open an issue (today GitHub only mails the repository's owner) | It runs every six hours from `main`; a forced failure opens an issue | Agent | Later (T-002) |

---

## 10. The Vercel demo

| # | Pri | Task | Done when | Who | Status |
|---|---|---|---|---|---|
| T-150 | P1 | The demo is live with the current build and says on every screen that it is a demo; checked from outside (35 checks) and in the rehearsal (74 checks) | — | Agent | **Done** `493ce22`, `7e5fffc` |
| T-151 | P2 | A signed-in check of the live demo. Needs the demo owner's password as a GitHub secret, which only the owner can add | The journeys pass against the live address | Owner, agent | Owner |
| T-152 | P3 | Long caching for the stylesheet and scripts (sent with `max-age=0` today) | One year, immutable | Agent | Next |
| T-153 | P3 | Function size: 226 of the 250 MB allowed, 153 MB of it Vercel's PHP runtime. Leaving tests and tools out saves under 2 MB, so the aim is a guard, not a diet | The rehearsal prints the size and fails above 240 MB | Agent | Next |
| T-154 | P3 | Measure a cold start (warm: 0.08 to 0.17 s to the first byte from a GitHub runner) | Written into `13-VERCEL-DEPLOYMENT.md` | Agent | Next |
| T-155 | P2 | Richer sample data — Furniture's own only (D-008): quotations, invoices, products, a project | Every area's main list shows at least five sample rows | Agent | Later (T-003) |
| T-156 | P2 | When the real admin exists: remove the demo or move it to a preview address | `WOODEX_DEMO` is not set in production | Owner | Later (T-133) |

A real admin on Vercel is **not planned** (D-004, D-005). What it would need is kept in [`13-VERCEL-DEPLOYMENT.md`](13-VERCEL-DEPLOYMENT.md) §2.

---

## 11. Security (document 10 §4)

| # | Pri | Task | Done when | Who | Status |
|---|---|---|---|---|---|
| T-160 | P1 | **NEW** The security tests document 10 lists that do not exist yet: mass assignment (extra fields in a form are ignored), cross-origin rules, the sign-in limit (5 a minute, set but untested), stored script in rich text and notes, secrets in logs and error pages | One test file per kind; each fails when its protection is removed | Agent | Next |
| T-161 | P1 | **NEW** A sweep for direct object references: every screen that opens a record by its number is tried as a role that should not see it | The route-access test covers records, not only screens | Agent | Later (T-060) |

Already tested: access without sign-in and across roles on every route · another client's quotation, invoice or
payment · upload type and content · webhook signatures and replays · a form without its token (419). Headers,
CSP and audits are T-068 to T-070; passwords and the repository are T-021; two-factor is T-022.

---

## 12. Proof: tests, acceptance and release gates (document 10)

| # | Pri | Task | Done when | Who | Status |
|---|---|---|---|---|---|
| T-165 | P0 | **NEW** Browser journeys for the eleven flows of document 10 §2. Three exist in part. To add now: lead assignment and conversion; approval and issue; invoice and payment allocation; project task and handover (T-080 is the first of them). To add with their modules: estimate to quotation, order creation, purchase order and receipt, stock transfer, page edit to publish | Each flow is one named journey that passes on the rehearsal | Agent | Next |
| T-166 | P1 | **NEW** The acceptance matrix kept as a file: each of the 25 scenarios of document 10 §3 with the test that proves it; CI fails when a named test disappears | The matrix is in the repository and checked | Agent | Next |
| T-167 | P1 | The crawl of every screen and the journeys run in CI on the rehearsal (by hand today) | A push that breaks a screen fails the workflow | Agent | Next |
| T-168 | P1 | **NEW** Performance (document 10 §6): a query budget on every list screen and the dashboard; a run with realistic volumes (thousands of leads and documents); an index review | The budgets are tests; the run's findings are in the report | Agent | Later (T-155) |
| T-169 | P1 | **NEW** A defect register with the four severities of document 10 §8 and their release rules | Every finding of a round is in it with its severity | Agent | Next |
| T-170 | P1 | **NEW** The round report extended to document 10 §9: devices covered, defects by severity, reconciliation, backup state, performance, the owner's approval; and the 14-point cutover checklist of document 09 §6 for every wave | The next wave report uses it | Agent | Next |
| T-171 | P0 | **NEW** Acceptance of each module by the ten conditions of document 07 §15, the last being the owner's sign-off. One acceptance sheet per module, starting with the 29 open destinations: CRM, then Sales, then Commerce | Each open module has its sheet, signed or returned with remarks by the owner | Agent, owner | Later (T-080, T-165) |

**In force on every push today:** the Laravel suite on MySQL 8.4 (`os-ci.yml`) · the Vercel rehearsal, 74 checks (`vercel-livewire.yml`) · the outside check of the live address, 35 checks (`vercel-live-check.yml`) · the React and Node gate of the shop (`ci.yml`).

**The five gates of document 10 §7.**

| Gate | Passed when | Lines |
|---|---|---|
| A — design foundation | The owner approves tokens, shell and components; an accessibility review is done | T-005, T-041, T-050, T-054 |
| B — module readiness (per module) | Requirements, schema and permissions approved; unit, screen and browser tests pass; reconciliation ready | T-171; T-080 to T-088; each wave |
| C — page builder | The Puck prototype is accepted | T-115 |
| D — Hostinger | PHP and MySQL verified; cron and queue proven; a restore done; security and load checked | T-130 to T-139 |
| E — cutover | The owner's approval; snapshot; rollback plan; monitoring; no critical defect open | T-121, T-181 |

---

## 13. Moving the data (documents 03 §10, 09)

| # | Pri | Task | Done when | Who | Status |
|---|---|---|---|---|---|
| T-175 | P1 | **NEW** The old system's tables — 72 by today's count of its schema files (`npm run docs:verify`); the master plan counted 74 — read from its migrations and checked against the live database (reading only, with the owner's word), each mapped to its Laravel owner | A table in the repository: source table, target, owner, wave | Agent | Later (T-001) |
| T-176 | P1 | **NEW** Differences between PostgreSQL and MySQL tested on real shapes: dates, JSON, decimals, upper and lower case, enumerations | A test per kind on MySQL in CI | Agent | Later (T-175) |
| T-177 | P1 | **NEW** One rule for identifiers; old-to-new numbers kept (the table `legacy_refs` exists) | Every importer writes the mapping | Agent | Later (T-175) |
| T-178 | P1 | An importer per module: repeatable, with a dry run | Run twice, the second run changes nothing | Agent | Later (per wave) |
| T-179 | P1 | **NEW** A reconciliation report per domain (document 09 §7): totals, by status, relations, duplicate contacts, document totals, balances, stock by product and warehouse | Read by the owner before each switch | Agent | Later (per wave) |
| T-180 | P1 | **NEW** One full rehearsal on an empty MySQL, and a rehearsal of going back and of catching up | Written into the cutover plan | Agent | Later (wave 10) |
| T-181 | P0 | The owner's approval before any real record moves, and before production | In writing, per module | Owner | Owner |

---

## 14. Running it

| # | Pri | Task | Done when | Who | Status |
|---|---|---|---|---|---|
| T-185 | P1 | A runbook: deploy, roll back, restore, change a password, add a staff account | Written, and each step done once on staging | Agent | Later (T-137) |
| T-186 | P1 | Training and handover (wave 11) | Each role has done its main job in the new admin | Agent, owner | Later (wave 11) |

---

## 15. Order of work

1. **Now, needing nobody** (28 lines): Furniture only (T-030 to T-032, T-034, T-035) · UTC times (T-061) · the sales flow end to end (T-080) and the other journeys (T-165) · the missing security tests (T-160) · tables and states, dashboard, screen pass (T-041, T-044, T-045) · CSP and audits (T-068, T-069) · request numbers (T-062) · the matrix, the defect register and the report (T-166, T-169, T-170).
2. **As soon as you answer** T-001, T-003, T-004, T-006 and T-008: the role matrix, Furniture's documents, the Hostinger staging site, tax and currency settings.
3. **Then the waves** 3 to 10, in the order of §8, each closed by its report and your acceptance.
4. **Last:** cutover (T-121), with its own written approval.

**The five answers that unblock the most:** T-001 (confirm the Laravel admin) · T-006 (Hostinger details and the two secrets) · T-003 (Furniture's price list and terms) · T-004 (who approves what) · T-002 (merge #36).

---

## 16. Numbers of version 1

Version 1 used letters that collide with the owner's own log (O-…, P-…). Decision-log rows DL-018 and DL-019
cite version 1 and are not edited: its §2 is §17 here, and its lines are now:

| Version 1 | Now | Version 1 | Now |
|---|---|---|---|
| O-1 (host) | answered by D-004, D-005 | H-1 (database settings on Vercel) | not planned; `13-VERCEL-DEPLOYMENT.md` §2 |
| O-2 (MySQL for Vercel) | not needed | H-2, H-3 (first installation, later migrations) | T-133, T-132 |
| O-3 | T-006 | H-4 (uploads) · H-5, H-7 (timed jobs, queue) | T-136 · T-134 |
| O-4 | T-003 | H-6 (mail) · H-8 (backups) · H-9 (domain) | T-009 and T-135 · T-137 · T-140 |
| O-5, O-6, O-7, O-8 | T-017, T-018, T-019, T-020 | H-10, H-11, H-12 | T-154, T-153, T-152 |
| O-9 | T-002 | H-13 (wave S) | T-130 to T-142 |
| O-10 | T-021 | S-1, S-4 · S-2, S-3 | T-068, T-069 · T-070 (S-3's rest: T-140) |
| O-11 | T-023 | S-5, S-6, S-7 | T-022, T-021, T-060 |
| O-12 | T-005 | S-8 | standing rule: the demo passwords exist only as Vercel settings; if one leaks, change the four values and redeploy |
| O-13 | T-007 | U-1, U-2, U-3 · U-4, U-5, U-6, U-7 | T-040 · T-041, T-044, T-045, T-046 |
| O-14 | T-025 | U-8, U-9, U-10, U-11 | T-052, T-053, T-054, T-055 |
| Waves 2 to 11 | T-060 to T-067, T-100 to T-121 | E-1, E-2, E-3 · P-1, P-2, P-3, P-4 | T-155, T-150, T-156 · T-138, T-142, T-185, T-186 |
| Q-1, Q-2, Q-4, Q-6 (gates in force) | unchanged; named in §12 | Q-3, Q-5 | T-151, T-167 |

---

## 17. The checks made on `woodex-os.vercel.app/admin` (16–17 November)

**How.** The sandbox cannot reach the live address (the connection is cut during TLS), and no tool available
can sign in to it. So the signed-in analysis was made on a **rehearsal**: the same commit, served by Vercel's own
PHP 8.5 runtime and launcher (`vercel-php@0.9.0`), read-only tree, same `vercel.json`. The live address itself is
checked from outside by a GitHub workflow that never signs in. What only the live deployment can show is
listed at the end of this section.

| Check | Tool | Result after that round |
|---|---|---|
| Every screen opens, as each of the four demo roles, on a desktop and then on a 390 px phone | `scripts/os-screens.mjs` | Owner 122 pages, sales manager 41, sales 38, accountant 38: **all open**; no console error |
| Links that lead nowhere | same | 0 (were 9) |
| Wider than the window | same | 0 at 1366 px; 0 at 390 px (were 2 screens, and 1 more caused and fixed in that round) |
| Top bar on a phone | same | one row, 56 px (was two rows, 113 px) |
| Fields without a label | same | 0 (were 30 on 8 screens) |
| Controls smaller than 24 px on a phone | same | 0 (were about 130 on 5 screens) |
| One `h1`, images with text, buttons with a name | same | all pass |
| Time to first byte on the rehearsal | same | median 50–59 ms, 95th percentile 72–93 ms (not Vercel's network) |
| Fourteen journeys that press things and write records: sign in, create a lead, reload, add a task, search, draft quotation, price a line, quotation PDF, statement PDF, CSV export, upload a sheet, 404, sign out, a forbidden screen | `scripts/os-journeys.mjs` | **14 of 14 hold** |
| The deployment itself: refuses to start without settings, hands out no file, signs in, every linked screen opens, cookie size, sign-out | `platform/tools/vercel/check-entry.sh` | **74 of 74** |
| **The live address, from outside, without signing in**: sign-in page, wrong password refused, headers, cookie marks, own 404 and 419 pages, eight files that must stay closed | `vercel-live-check.yml` (GitHub) | **35 of 35** on 17 November (run 44029572727). It also reported: `strict-transport-security` is sent by Vercel (two years, preload); the stylesheet is sent with `max-age=0` (T-152); first byte of the sign-in page 0.17 s from the runner, warm |
| Headers | curl, `SecurityHeadersTest` | `nosniff`, `SAMEORIGIN`, referrer policy, permissions policy, `noindex`; sign-in page `no-store`. `X-Powered-By` **removed** in that round. No Content-Security-Policy yet (T-068). |
| Cookies | curl | session and form-token cookies are `Secure`, `HttpOnly` (session), `SameSite=Lax`, 2 hours; a form without its token gets 419 |
| Error pages | `ErrorPagesTest` | own pages for 403, 404, 419, 429, 500, 503 (were the framework's bare pages) |
| Page weight | crawl | HTML median 161 KB, largest 310 KB; a near-empty page is 144 KB (15.6 KB compressed), about 110 KB of it the menu and the search templates (T-052) |

**Found and fixed in that round** (commits `493ce22` and `7e5fffc`): nine dead links; the two-row top bar;
sideways scrolling on Users and Access (and on Due payments, which that round's own table headings had caused);
thirty unlabelled fields; bare error pages; small touch
targets; the quotation form that seemed to do nothing when a choice was missing; the PHP version in every
answer; the demo calling itself a "local preview"; "Last90days" in the dashboard's period list.

**What those checks could not cover** — each is a line of this list:

- the live deployment after a real sign-in (T-151);
- a cold start on Vercel (T-154);
- mail, timed jobs and lasting uploads: the demo has none of them; they are proven on Hostinger (T-134 to T-136);
- realistic amounts of data: the demo has 10 leads, 10 contacts and nothing else (T-155), so most Sales, Commerce,
  Projects and Accounts screens were seen empty.
