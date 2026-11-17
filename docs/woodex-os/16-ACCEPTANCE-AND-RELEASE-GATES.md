# WOODEX OS — acceptance and release gates

**Written 2026-11-17, after the owner lifted the holds (decision DL-022). Code: branch `arena/7ecb4f83-woodex-2030`.**

The owner's specification sets the rules by which this work is judged: five release gates (document 10 §7),
25 critical scenarios (§3), eleven flows to be pressed in a browser (§2), four severities of defect with a release
rule each (§8), a report for every release (§9), and ten conditions before a module counts as finished
(document 07 §15). This document says where each of them stands.

**How it is kept true.** The tables in §1 to §5 are not typed by hand. They are written from five small files in
[`acceptance/`](acceptance/) by `scripts/verify-acceptance.mjs`, which runs in CI on every push and fails when

- a scenario, flow, criterion, condition or severity is not worded as the specification words it;
- a test that is named as proof is no longer declared in its file, or a named journey step is gone;
- a file, a list line (T-…) or a decision (DL-…) that is named does not exist;
- the screens that are open in the admin's menu are not exactly the screens the acceptance sheets cover;
- a gate is marked passed while one of its criteria is not met, or a module accepted without the owner's decision;
- this document does not show what the five files say.

To change a table, change its file and run `node scripts/verify-acceptance.mjs --write`. Figures inside a
sentence ("2,280 tests") are readings of 17 November 2026; the release report of that day,
[`17-RELEASE-2026-11-17.md`](17-RELEASE-2026-11-17.md), says how each was taken.

## The short version

1. **No gate is passed.** Gate A waits for the owner's approval of the design. Gate B is decided module by
   module, on the sheets of §4. Gate C has not started. Gate D cannot start before the Hostinger details and the
   two secrets are there. Gate E is blocked by one open critical defect, which only the owner can close:
   the old dashboard's password (D-002).
2. **What 17 November changed.** The admin is Furniture-only and carries Woodex's real catalogue: 170 products as
   drafts, on the hosted workspace too. The React shop's database lost an open door (D-001), received catalogue
   migration 019, and its migration ledger agrees with the files again. The acceptance scenarios, the defect
   register and the module sheets exist and are checked.
3. **What only the owner can do now**, in this order: change the old dashboard's password (T-021) · load the real
   products into the React shop and withdraw the sample ones (T-091) · look at the hosted workspace and approve
   or return the design (T-005) · read the sheets of §4 and approve or return each module's requirements ·
   send the Hostinger details (T-006).

---

## 1. The five release gates

A gate is passed when every one of its criteria is met **and** the owner has said so; the decision is then a row
of the decision log.

<!-- generated:gates — written by scripts/verify-acceptance.mjs --write; do not edit by hand -->
| Gate | State | Criteria met | What it waits for |
|---|---|---|---|
| **A — Design foundation** | Not passed | 1 of 4 | The owner's approval of the design on the hosted workspace (T-005). The two other open points are the agent's work. |
| **B — Module readiness** | Not passed | 0 of 4 | Passed module by module. Each open module has its sheet in §4; the owner signs or returns it (T-171). |
| **C — Puck readiness** | Not passed | 0 of 3 | The prototype of T-115. Not started; it is wave 10. |
| **D — Hostinger readiness** | Not passed | 0 of 4 | The owner's Hostinger details and the two SSH secrets entered in GitHub (T-006). Nothing on the host can be proven before that. |
| **E — Production cutover** | Not passed | 0 of 5 | The owner changes the old dashboard's password (T-021): the one open critical defect. Then gate D, and the owner's written approval of each module's move (T-181). |

**Gate A — Design foundation**

| Criterion (document 10 §7) | State | Where it stands | Closed by |
|---|---|---|---|
| Approved design tokens. | Partly | Tokens, shell and the Reimagined theme the owner asked for (DL-017) are built and pinned by tests (`Unit/AdminDesignTokensTest.php`, `Feature/ThemeTokensTest.php`). The approval itself has not been given. | T-005 |
| Shared component examples. | Open | No screen shows the shared components and their states side by side. | T-050, T-041 |
| Responsive shell prototype. | Met | Sidebar, top bar and phone drawer are built. On 17 November a browser opened 241 screens as four roles at 1366 px and at 390 px; none was wider than the window (`scripts/os-screens.mjs`). | — |
| Accessibility review. | Partly | Counted by tools on every screen: labels, names, one main heading, 24 px targets. No review by keyboard and by screen reader. | T-054 |

**Gate B — Module readiness**

| Criterion (document 10 §7) | State | Where it stands | Closed by |
|---|---|---|---|
| Requirements, API and schema approved. | Open | Documents 05 and 07 are the requirements, and the schema is 106 migrations that build an empty MySQL 8.4 in CI on every push. No module has had its requirements, its addresses and its schema approved as a set. | T-171, T-063 |
| Unit/API/browser tests pass. | Partly | Unit and feature tests pass: 2,280 on 17 November, and the same suite on MySQL 8.4 in CI. In a browser the journeys and a crawl of every screen pass; which of the eleven flows of document 10 they press is in §3. | T-165, T-080 |
| Permission matrix tested. | Partly | No admin address opens without a sign-in, and one role is checked against every address (`Feature/AdminRouteAccessTest.php`); the other roles are tested module by module (§4). The roles are the inherited eight, not yet the owner's matrix. | T-060, T-004 |
| Data reconciliation ready. | Partly | Ready for the catalogue only: 170 products, with a report that accounts for every row and every change (`docs/furniture-2030/catalogue/IMPORT-REPORT.md`). No other record has an importer. The old database holds little to move: on 17 November 1 lead, 1 client, no order, quotation or invoice. | T-101, T-178, T-179 |

**Gate C — Puck readiness**

| Criterion (document 10 §7) | State | Where it stands | Closed by |
|---|---|---|---|
| Compatibility prototype accepted. | Open | Not started. The Website Studio in the code is the HTML builder inherited from Interior; it is switched off. | T-115 |
| Round-trip editing and renderer parity. | Open | Not started. | T-115 |
| Draft security, publish and rollback tested. | Open | Not started. | T-115 |

**Gate D — Hostinger readiness**

| Criterion (document 10 §7) | State | Where it stands | Closed by |
|---|---|---|---|
| PHP/MySQL compatibility verified. | Partly | Verified away from the host: every migration from an empty database and the whole suite run on MySQL 8.4 in CI on every push (`.github/workflows/os-ci.yml`). Not verified on the account: its PHP version and extensions, MySQL or MariaDB. | T-131, T-006 |
| Cron/queue strategy proven. | Open | Designed — one cron line calls the scheduler, two timed jobs drain the queue — and never run on the host. | T-134 |
| Backup and restore tested. | Open | The Laravel admin holds no real record yet, and no backup of it has been taken or restored. | T-137 |
| Security and resource checks passed. | Open | The outside security check exists and passes for the hosted workspace on Vercel (`.github/workflows/vercel-live-check.yml`); it has not been pointed at Hostinger, and nothing has been measured against the plan's limits. | T-139, T-142, T-068 |

**Gate E — Production cutover**

| Criterion (document 10 §7) | State | Where it stands | Closed by |
|---|---|---|---|
| Owner approval. | Partly | Given in general on 17 November: the owner lifted the holds to start a production-ready deployment (DL-022). The move of each module is approved in writing on its sheet. | T-181, T-171 |
| Migration snapshot. | Open | No snapshot has been taken for a move. What the old database held on 17 November was read and is written down: 148 sample products, 1 lead, 1 client, 1 user, no order, quotation, invoice or payment. | T-164, T-180 |
| Rollback plan. | Partly | Each change of 17 November has its own way back: the undo in `deploy/supabase/2026-11-17-close-data-api.sql`, the rollback file `scripts/migrations/rollback/019_catalogue.rollback.sql`, the earlier deployment kept by Vercel. A written plan for going back after a module has moved does not exist. | T-180, T-185 |
| Monitoring and support plan. | Partly | Both sites are asked from outside after every merge and every six hours (`.github/workflows/live-check-after-merge.yml`, `.github/workflows/vercel-live-check.yml`); a failure mails the repository's owner. No error tracker, no runbook. | T-138, T-142, T-185 |
| No unresolved critical defects. | Open | One critical defect is open: D-002 (the owner password of the old dashboard was printed in a public document). | T-021 |
<!-- /generated:gates -->

---

## 2. The 25 critical scenarios

"Proven" means: the rule is in the code and the named tests fail if it is broken. The tests run on SQLite here
and on MySQL 8.4 in CI. A scenario of a module that Laravel does not have yet cannot be proven; its line says
when it arrives.

<!-- generated:scenarios — written by scripts/verify-acceptance.mjs --write; do not edit by hand -->
**25 scenarios: 8 proven, 5 partly, 12 in modules that are not built.**

**CRM and sales**

| # | Scenario (document 10 §3) | State | Proved by (under `platform/tests/`) | Note | Closed by |
|---|---|---|---|---|---|
| AS-01 | A public enquiry creates one lead. | Proven | `PublicLeadApiTest` › captures a web enquiry as contact + lead with normalised phone and attribution<br>`PublicLeadApiTest` › joins a repeat enquiry from the same phone to the open lead instead of duplicating it | Proven on the Laravel enquiry address. The React shop's forms still write to the old API, so no public enquiry reaches Laravel yet. | T-100 |
| AS-02 | Duplicate detection identifies likely existing contact. | Proven | `ContactsTest` › refuses a duplicate phone in any format and points to the existing contact<br>`ContactsTest` › merges a duplicate contact and moves its leads and timeline | By phone number, however it is written. Several numbers and e-mail addresses per person come later. | T-083 |
| AS-03 | Lead assignment is permission checked and audited. | Proven | `LeadShowTest` › lets a manager reassign the lead<br>`LeadShowTest` › does not let a sales user act on another person's lead<br>`LeadsIndexTest` › bulk assigns, moves and deletes selected leads within permissions | A reassignment asks for a reason and writes it into the lead's history. Not yet pressed in a browser. | T-165 |
| AS-04 | Estimate totals are calculated server-side. | Partly | `Sales/EstimatesTest` › prices an estimate from the rate book with an honest essential-to-premium band<br>`Sales/EstimatesTest` › prices rate book lines at today's rates inside the same computation<br>`Unit/QuoteCalculatorTest` › applies line discounts, quote discount, other charges, tax and rounding in order | The arithmetic runs on the server and is tested. The Estimations screen is switched off: its rate book is Interior's, and Furniture's rates are not in yet. | T-085, T-003 |
| AS-05 | Quote approval is required for configured roles/thresholds. | Proven | `QuoteSendingTest` › measures the discount against the sender's role limit and the minimum margin<br>`QuoteSendingTest` › blocks sending above the limit until a manager approves exactly those figures<br>`QuoteSendingTest` › lists pending approvals for managers and lets them decide | The limits in force are defaults. The owner's own limit for each role is still to come. | T-004 |
| AS-06 | A rejected quote cannot be converted. | Proven | `QuotationSafetyTest` › rechecks the persisted acceptability of a stale sent quotation<br>`QuotationSafetyTest` › does not open projects or advances from unaccepted quotes | A declined, expired, void or draft quotation cannot be accepted, and no project or invoice is created from it. | — |
| AS-07 | Issued document snapshot remains stable after product price changes. | Partly | `QuotesTest` › copies rate book items into the quote so later rate changes do not alter it<br>`DocumentLibraryTest` › serves the exact frozen quote PDF by default after sending<br>`QuotationSafetyTest` › returns the same advance and never re-freezes an existing issued PDF | Proven for rate-book lines and for the frozen PDF. A quotation line cannot yet be chosen from the product catalogue, so a product's price change is not tested. | T-086 |

**Finance**

| # | Scenario (document 10 §3) | State | Proved by (under `platform/tests/`) | Note | Closed by |
|---|---|---|---|---|---|
| AS-08 | Payment amount cannot exceed allowed allocation unless overpayment policy is defined. | Proven | `Sales/InvoiceScreenTest` › refuses to collect more than is outstanding<br>`PaymentsTest` › spreads a payment over the oldest invoices first and keeps the rest as credit<br>`PaymentIntegrityTest` › validates the whole explicit map before retaining any allocation or receipt<br>`Accounts/PayablesTest` › refuses a payment larger than the balance | The policy in force: from an invoice no more than is outstanding can be collected; money received beyond the invoices it settles is kept as the client's credit. | — |
| AS-09 | Partial payments calculate outstanding balance correctly. | Proven | `PaymentsTest` › spreads a payment over the oldest invoices first and keeps the rest as credit<br>`PaymentIntegrityTest` › pays the preferred invoice before older debt and retains only the final excess as credit<br>`PaymentIntegrityTest` › preserves paisa exactly and refuses scientific or overprecise amounts on the staff form<br>`Accounts/GlSalesPostingTest` › splits a part-settled receipt between receivables and client credit |  | — |
| AS-10 | Reversal requires permission and reason. | Proven | `Sales/InvoiceScreenTest` › voids an issued invoice with a reason and reverses its ledger entry instead of deleting it<br>`Sales/InvoiceScreenTest` › requires a reason before voiding<br>`Sales/InvoiceScreenTest` › does not offer the void to a sales user, and refuses it if called<br>`Accounts/AccountsLedgerTest` › lets the owner post and reverse a manual entry from the journals screen, and blocks the admin |  | — |
| AS-11 | Financial reports reconcile to source transactions. | Partly | `Accounts/PayablesTest` › leaves the trial balance balanced after bills and payments<br>`Accounts/TrialBalanceTest` › shows the posted accounts with their debits, credits and a balanced total<br>`Overview/ControlCentreTest` › reconciles today and business-line collections without counting unallocated cash twice | The books balance after every tested posting, and the day's collections reconcile. Not every report states its definition and is tested against its source records. | T-082, T-114 |
| AS-12 | Currency and tax behavior are explicit. | Partly | `PaymentIntegrityTest` › never allocates to non-payable or foreign-currency invoice targets<br>`AdvanceInvoiceTest` › keeps the tax rate of the quotation on the advance<br>`Accounts/GlSalesPostingTest` › splits sales tax to taxes payable when the quotation carries it | Quotations and invoices carry their currency and their tax rate. There are no tax rules and no currency setting: PKR is written into the code and tax is a rate typed per document. | T-008, T-064, T-087 |

**Inventory and production**

| # | Scenario (document 10 §3) | State | Proved by (under `platform/tests/`) | Note | Closed by |
|---|---|---|---|---|---|
| AS-13 | Goods receipt posts stock once only. | Not built | — | No purchasing or stock module in Laravel. The rule and its test exist in the Node system (`scripts/test-stock-ledger.mjs`) and are ported first. | T-110 |
| AS-14 | Duplicate receipt submission is idempotent. | Not built | — | As above. | T-110 |
| AS-15 | Transfer creates matched source and destination ledger movements. | Not built | — | As above. | T-109 |
| AS-16 | Reservation reduces available stock but does not corrupt physical stock. | Not built | — | As above. | T-109 |
| AS-17 | Adjustment requires reason and appropriate approval. | Not built | — | As above. | T-109 |

**Puck / Website Studio**

| # | Scenario (document 10 §3) | State | Proved by (under `platform/tests/`) | Note | Closed by |
|---|---|---|---|---|---|
| AS-18 | Unauthorized user cannot access editor or draft preview. | Not built | — | No Puck editor exists. The Website Studio in the code is the HTML builder inherited from Interior, and it is switched off. | T-115 |
| AS-19 | Unsupported component types are rejected by server. | Not built | — | As above. | T-115 |
| AS-20 | Draft save creates a new revision. | Not built | — | As above. | T-115 |
| AS-21 | Preview uses same renderer as published page. | Not built | — | As above. | T-115 |
| AS-22 | Publish requires appropriate role/approval. | Not built | — | As above. | T-115 |
| AS-23 | Rollback restores previous valid revision. | Not built | — | As above. | T-115 |
| AS-24 | Product block uses canonical catalogue data. | Not built | — | As above. | T-115, T-105 |
| AS-25 | Media deletion is blocked or warns when referenced. | Partly | `StorefrontMediaTest` › reuses an existing library image and blocks deleting connected images | Proven in the media library for images that the catalogue uses. Pages cannot refer to media yet: there is no Puck editor. | T-115 |
<!-- /generated:scenarios -->

---

## 3. The eleven browser flows

The journey signs in to a rehearsal of the hosted workspace (Vercel's own PHP runtime, the committed code, the real
catalogue) and presses its way through; the crawl beside it opens every screen as each of four roles on a desktop
and on a phone. A flow counts as pressed only when the journey does what a person would do, not when a screen
merely opens.

<!-- generated:flows — written by scripts/verify-acceptance.mjs --write; do not edit by hand -->
**11 flows: 1 pressed in full, 3 in part, 7 not pressed.** The journey has 15 steps.

| Flow (document 10 §2) | State | Steps of `scripts/os-journeys.mjs` that press it | Note | Closed by |
|---|---|---|---|---|
| Lead capture. | Partly | create a lead from the New menu<br>the lead is still there after a reload, and its page opens | A member of staff creates the lead. The shop's public form is not in a journey: the React shop does not write to Laravel yet. | T-100, T-165 |
| Lead assignment and conversion. | Not pressed | — |  | T-165, T-080 |
| Estimate to quotation. | Not pressed | — | Estimations is switched off. | T-085 |
| Quotation approval and issue. | Partly | write a draft quotation for that client<br>price one line; the total follows<br>the quotation PDF | Draft, price and PDF are pressed. Approval above a limit and sending are not; issuing is blocked until the owner's terms and signers are confirmed. | T-080, T-084 |
| Invoice and payment allocation. | Not pressed | — |  | T-080 |
| Product browse and order creation. | Partly | find a product of the real catalogue by its code, and open it | Browsing only, on the real catalogue. Orders do not exist in Laravel. | T-106 |
| Purchase order and goods receipt. | Not pressed | — | No module. | T-110 |
| Stock transfer/reservation. | Not pressed | — | No module. | T-109 |
| Project task and handover. | Not pressed | — | The journey adds a task to a lead, not to a project. | T-165 |
| Page edit, preview, approval and publish. | Not pressed | — | No Puck editor. | T-115, T-116 |
| Permission-denied scenarios. | Pressed in full | a sales person is told plainly what they may not open<br>a page that does not exist gets the admin's own 404<br>sign out; the admin asks for a sign-in again |  | — |
<!-- /generated:flows -->

---

## 4. Acceptance of each open module

One sheet per module, for the screens that are open today. The ten conditions are those of document 07 §15.
"Does not apply" is used only with its reason. A module is accepted when conditions 1 to 9 are met and the owner
signs the tenth; until then the owner can already approve its requirements (condition 1) or return the sheet
with remarks — §7 says how.

<!-- generated:modules — written by scripts/verify-acceptance.mjs --write; do not edit by hand -->
| Sheet | Screens | Met | Partly | Open | Does not apply | Owner's decision |
|---|---|---|---|---|---|---|
| 4.1 **Customers** | Leads, Contacts, Tasks & Visits | 2 | 6 | 2 | 0 | Not asked yet |
| 4.2 **Sales** | Quotations, Invoices, Payments, Documents | 1 | 7 | 2 | 0 | Not asked yet |
| 4.3 **Commerce** | E-commerce, Products, Categories, Series | 1 | 8 | 1 | 0 | Not asked yet |
| 4.4 **Workspace** | Dashboard, Approvals, Reports | 2 | 5 | 2 | 1 | Not asked yet |
| 4.5 **Operations** | Projects, Board, Vendors & costs, Handovers | 1 | 5 | 3 | 1 | Not asked yet |
| 4.6 **Accounts** | Ledger & reports, Payables, Income & expenses | 2 | 4 | 3 | 1 | Not asked yet |
| 4.7 **Website Studio — the two open screens** | Shop customization, Media | 1 | 6 | 3 | 0 | Not asked yet |
| 4.8 **System** | Settings, Access & team, Connections, Platform, Audit & activity, Feature status | 2 | 5 | 2 | 1 | Not asked yet |

29 screens are open in the menu, and each is on exactly one sheet.

### 4.1 Customers

Screens: Leads, Contacts, Tasks & Visits · document 07 §4 · owner's decision: Not asked yet

| # | Condition (document 07 §15) | State | Where it stands | Closed by |
|---|---|---|---|---|
| 1 | Approved requirements. | Partly | Document 07 §4 is the requirement and document 05 its screens; where the build differs is in document 15 §8. Not approved as a module. | T-171 |
| 2 | Data ownership and schema. | Partly | Tables for leads, contacts, companies, tasks and visits exist and build on MySQL in CI. Who holds the records is not settled: an enquiry from the React shop is still written to the old database, which held 1 lead and 1 client on 17 November. | T-100, T-102 |
| 3 | API contract. | Partly | The enquiry address for the shop exists and is tested: validation, repeat enquiries, limits, a trap for robots (`Feature/PublicLeadApiTest.php`, `Feature/VersionedLeadApiTest.php`). The common rules of `/api/v1` — answer shape, error codes, a key against double sending — are not written. | T-063 |
| 4 | Permission matrix. | Partly | No address opens without a sign-in, and one role is checked against every address (`Feature/AdminRouteAccessTest.php`). A sales person cannot open a colleague's lead, and only a manager reassigns one or merges contacts (`Feature/LeadShowTest.php`, `Feature/ContactsTest.php`). The roles are the inherited eight; the owner's matrix and its test are still to come. | T-060, T-004 |
| 5 | UI list/detail/create/edit/action flows. | Met | Leads: list, board, page, create, edit, reassign, import from a sheet, export. Contacts: list, page, a duplicate refused, merge. Tasks and visits: list, add, book. The lead, its task, the search, the export and the import are also pressed in the browser journey. | — |
| 6 | Loading/empty/error states. | Partly | An empty list says so and offers the next step; a refusal and an error have their own pages (`Feature/ErrorPagesTest.php`). There is no placeholder while a list loads and no notice when the connection is lost, and the five states are not yet one standard. | T-041, T-051 |
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,280 tests; the same suite on MySQL 8.4 in CI): `Feature/LeadsIndexTest.php`, `Feature/LeadShowTest.php`, `Feature/ContactsTest.php`, `Feature/TasksAndVisitsTest.php`, `Feature/LeadCsvImportTest.php`, `Feature/Crm/LeadCsvExportTest.php`, `Feature/PipelineBoardTest.php`, `Unit/PhoneTest.php`. | — |
| 8 | Browser acceptance tests. | Partly | Pressed in a browser: create a lead, reopen it, add a task, find it by search, export, upload a sheet. Not pressed: reassigning a lead and turning it into a quotation. | T-165, T-080 |
| 9 | Migration/reconciliation plan if replacing existing behavior. | Open | Planned, not built: the shop's forms write to Laravel, the old records are imported with a trial run and a comparison, and the old way in is closed. | T-100, T-101, T-102 |
| 10 | Documentation and owner sign-off. | Open | Described in document 07 §4 and in document 15 §8. No guide yet for the people who will use it. Not signed. | T-171, T-186 |

### 4.2 Sales

Screens: Quotations, Invoices, Payments, Documents · document 07 §5 · owner's decision: Not asked yet

| # | Condition (document 07 §15) | State | Where it stands | Closed by |
|---|---|---|---|---|
| 1 | Approved requirements. | Partly | Document 07 §5 is the requirement and document 05 its screens; where the build differs is in document 15 §8. Not approved as a module. | T-171 |
| 2 | Data ownership and schema. | Partly | Tables for quotations and their versions, invoices, payments and receipts exist; a document is frozen when it is sent. The old system has its own quotations and invoices — none recorded on 17 November — and one writer is still to be decided. | T-103 |
| 3 | API contract. | Partly | A quote request from the shop has its address and its tests (`Feature/StorefrontQuoteRequestApiTest.php`); the client's pages for a quotation and an invoice open on signed links (`Feature/QuotePortalTest.php`). The common rules of `/api/v1` are not written. | T-063 |
| 4 | Permission matrix. | Partly | No address opens without a sign-in, and one role is checked against every address (`Feature/AdminRouteAccessTest.php`). Sending above a role's discount limit needs a manager (`Feature/QuoteSendingTest.php`); only accounts confirm a payment (`Feature/PaymentsTest.php`); a sales person cannot void an invoice (`Feature/Sales/InvoiceScreenTest.php`). The roles are the inherited eight; the owner's matrix and its test are still to come. | T-060, T-004 |
| 5 | UI list/detail/create/edit/action flows. | Partly | Quotations: list, create, builder, approval, send, revise, PDF, the client's page. Invoices: list, page, collect, void. Payments: list, record, confirm, send back. Documents: library and design. Issuing a document is blocked until the owner's terms and signers are confirmed, and Furniture has no template or price list yet. | T-084, T-003 |
| 6 | Loading/empty/error states. | Partly | An empty list says so and offers the next step; a refusal and an error have their own pages (`Feature/ErrorPagesTest.php`). There is no placeholder while a list loads and no notice when the connection is lost, and the five states are not yet one standard. | T-041, T-051 |
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,280 tests; the same suite on MySQL 8.4 in CI): `Feature/QuotesTest.php`, `Feature/QuoteSendingTest.php`, `Feature/QuotationSafetyTest.php`, `Feature/PaymentsTest.php`, `Feature/PaymentIntegrityTest.php`, `Feature/Sales/InvoiceScreenTest.php`, `Feature/DocumentLibraryTest.php`, `Unit/QuoteCalculatorTest.php`. | — |
| 8 | Browser acceptance tests. | Partly | Pressed in a browser: a draft quotation for a client, one priced line with its total, the quotation PDF, the client's statement PDF. Not pressed: approval, sending, the client's acceptance, the invoice, the payment. | T-080 |
| 9 | Migration/reconciliation plan if replacing existing behavior. | Open | Planned, not built: quotations, invoices and payments of the two systems merged, with Furniture's templates. | T-103 |
| 10 | Documentation and owner sign-off. | Open | Described in document 07 §5 and in document 15 §8. No guide yet for the people who will use it. Not signed. | T-171, T-186 |

### 4.3 Commerce

Screens: E-commerce, Products, Categories, Series · document 07 §6 · owner's decision: Not asked yet

| # | Condition (document 07 §15) | State | Where it stands | Closed by |
|---|---|---|---|---|
| 1 | Approved requirements. | Partly | Document 07 §6 is the requirement and document 05 its screens; where the build differs is in document 15 §8. Not approved as a module. | T-171 |
| 2 | Data ownership and schema. | Partly | Products with their revisions, categories and series are in the Laravel database: the 170 products of woodexfurniture.pk and the category tree, as drafts. The React shop does not read them: it reads the old catalogue, which still holds 148 sample products. | T-105, T-091 |
| 3 | API contract. | Partly | Categories and photographs reach the React shop through a bridge with tests (`Feature/CategoryStorefrontSyncTest.php`, `Feature/StorefrontMediaTest.php`). Products are not offered to the shop yet, and the common rules of `/api/v1` are not written. | T-105, T-063 |
| 4 | Permission matrix. | Partly | No address opens without a sign-in, and one role is checked against every address (`Feature/AdminRouteAccessTest.php`). Seeing the catalogue and changing it are two permissions, asked on the address of every screen (`platform/routes/admin.php`). The roles are the inherited eight; the owner's matrix and its test are still to come. | T-060, T-004 |
| 5 | UI list/detail/create/edit/action flows. | Partly | Products: list with search by name or code, filters, actions on a selection, editor, preview, import and export. Categories: tree and editor. Series: manager and editor. The readiness command still lists work, among it video in galleries. | T-088 |
| 6 | Loading/empty/error states. | Partly | An empty list says so and offers the next step; a refusal and an error have their own pages (`Feature/ErrorPagesTest.php`). There is no placeholder while a list loads and no notice when the connection is lost, and the five states are not yet one standard. | T-041, T-051 |
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,280 tests; the same suite on MySQL 8.4 in CI): `Feature/NativeProductsTest.php`, `Feature/WoodexCatalogueImportTest.php`, `Feature/CommerceCategoriesTest.php`, `Feature/CommerceTaxonomyTest.php`, `Feature/SeriesRulesTest.php`, `Feature/CommerceWorkflowTest.php`, `Feature/CommerceReadinessTest.php`. | — |
| 8 | Browser acceptance tests. | Partly | Pressed in a browser: a product of the real catalogue is found by its code, is a draft, and its editor opens. Not pressed: publishing a product; an order, which does not exist in Laravel. | T-165, T-106 |
| 9 | Migration/reconciliation plan if replacing existing behavior. | Partly | Done for the catalogue: 170 products loaded from the capture of woodexfurniture.pk, with a report that accounts for every row and every change (`docs/furniture-2030/catalogue/IMPORT-REPORT.md`); the loading can be repeated and is tested (`Feature/WoodexCatalogueImportTest.php`). Open: the photographs, the owner's review of the points to check, and the shop reading from Laravel. | T-089, T-090, T-105 |
| 10 | Documentation and owner sign-off. | Open | Described in document 07 §6 and in document 15 §8. No guide yet for the people who will use it. Not signed. | T-171, T-186 |

### 4.4 Workspace

Screens: Dashboard, Approvals, Reports · document 07 §1 to §3 · owner's decision: Not asked yet

| # | Condition (document 07 §15) | State | Where it stands | Closed by |
|---|---|---|---|---|
| 1 | Approved requirements. | Partly | Document 07 §1 to §3 is the requirement and document 05 its screens; where the build differs is in document 15 §8. Not approved as a module. | T-171 |
| 2 | Data ownership and schema. | Met | It keeps no records of its own: it reads what the other modules hold, and an approval is stored with the quotation it decides. | — |
| 3 | API contract. | Does not apply | The screens are Livewire components: there is no API between screen and server, and nothing outside the admin reads this module. | — |
| 4 | Permission matrix. | Partly | No address opens without a sign-in, and one role is checked against every address (`Feature/AdminRouteAccessTest.php`). Approvals opens only with a permission to approve (`platform/routes/admin.php`). The roles are the inherited eight; the owner's matrix and its test are still to come. | T-060, T-004 |
| 5 | UI list/detail/create/edit/action flows. | Partly | Dashboard: figures for a period, six live queues, what needs attention, pipeline, new leads, recent activity. Approvals: quotations only. Reports: a list of reports with exports. Missing: the dashboard sections of document 05 §7, one approval queue for every kind, a definition on each report. | T-044, T-081, T-082 |
| 6 | Loading/empty/error states. | Partly | An empty list says so and offers the next step; a refusal and an error have their own pages (`Feature/ErrorPagesTest.php`). There is no placeholder while a list loads and no notice when the connection is lost, and the five states are not yet one standard. | T-041, T-051 |
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,280 tests; the same suite on MySQL 8.4 in CI): `Feature/Overview/ControlCentreTest.php`, `Feature/Overview/ControlViewTest.php`, `Feature/ReportsKitTest.php`, `Feature/QuoteSendingTest.php`. | — |
| 8 | Browser acceptance tests. | Partly | The journey lands on the dashboard after signing in, and the crawl opens all three screens as each role that may see them. No journey decides an approval or reads a report's figures. | T-165, T-081 |
| 9 | Migration/reconciliation plan if replacing existing behavior. | Open | The reports of the old dashboard are to be merged and their figures compared with the records they come from. Not started. | T-114 |
| 10 | Documentation and owner sign-off. | Open | Described in document 07 §1 to §3 and in document 15 §8. No guide yet for the people who will use it. Not signed. | T-171, T-186 |

### 4.5 Operations

Screens: Projects, Board, Vendors & costs, Handovers · document 07 §9 · owner's decision: Not asked yet

| # | Condition (document 07 §15) | State | Where it stands | Closed by |
|---|---|---|---|---|
| 1 | Approved requirements. | Partly | Document 07 §9 is the requirement and document 05 its screens; where the build differs is in document 15 §8. Not approved as a module. | T-171 |
| 2 | Data ownership and schema. | Partly | Projects, tasks, costs and handovers have their tables. The old system has its own projects; members and dependencies are to be merged. | T-112 |
| 3 | API contract. | Does not apply | The screens are Livewire components: there is no API between screen and server, and nothing outside the admin reads this module. The client portal, which would read it, is switched off. | T-112 |
| 4 | Permission matrix. | Partly | No address opens without a sign-in, and one role is checked against every address (`Feature/AdminRouteAccessTest.php`). The roles are the inherited eight; the owner's matrix and its test are still to come. | T-060, T-004 |
| 5 | UI list/detail/create/edit/action flows. | Partly | Projects, the board, costs and handovers open and are tested. Members and dependencies between tasks are missing. | T-112 |
| 6 | Loading/empty/error states. | Partly | An empty list says so and offers the next step; a refusal and an error have their own pages (`Feature/ErrorPagesTest.php`). There is no placeholder while a list loads and no notice when the connection is lost, and the five states are not yet one standard. | T-041, T-051 |
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,280 tests; the same suite on MySQL 8.4 in CI): `Feature/ProjectsTest.php`, `Feature/ProjectCostsTest.php`, `Feature/Projects/ProjectCostPostingTest.php`, `Feature/Projects/ProjectCostsScreenTest.php`. | — |
| 8 | Browser acceptance tests. | Open | Opened by the crawl; nothing in it is pressed in a browser. | T-165 |
| 9 | Migration/reconciliation plan if replacing existing behavior. | Open | Planned, not built: projects of the two systems merged. | T-112 |
| 10 | Documentation and owner sign-off. | Open | Described in document 07 §9 and in document 15 §8. No guide yet for the people who will use it. Not signed. | T-171, T-186 |

### 4.6 Accounts

Screens: Ledger & reports, Payables, Income & expenses · document 07 §8 · owner's decision: Not asked yet

| # | Condition (document 07 §15) | State | Where it stands | Closed by |
|---|---|---|---|---|
| 1 | Approved requirements. | Partly | Document 07 §8 is the requirement and document 05 its screens; where the build differs is in document 15 §8. Not approved as a module. | T-171 |
| 2 | Data ownership and schema. | Partly | A double-entry ledger with periods, journal lines that cannot be edited, bills and expenses. The chart of accounts is a generic one until the person responsible for the accounts has reviewed it. | T-013, T-113 |
| 3 | API contract. | Does not apply | The screens are Livewire components: there is no API between screen and server, and nothing outside the admin reads this module. | — |
| 4 | Permission matrix. | Partly | No address opens without a sign-in, and one role is checked against every address (`Feature/AdminRouteAccessTest.php`). The accountant reads the books and cannot change them; sales and support cannot open them (`Feature/AccountantReadAccessTest.php`). The roles are the inherited eight; the owner's matrix and its test are still to come. | T-060, T-004 |
| 5 | UI list/detail/create/edit/action flows. | Met | Journals, trial balance, bills and their payments, income and expenses: list, create, and void by a reversing entry instead of deletion. | — |
| 6 | Loading/empty/error states. | Partly | An empty list says so and offers the next step; a refusal and an error have their own pages (`Feature/ErrorPagesTest.php`). There is no placeholder while a list loads and no notice when the connection is lost, and the five states are not yet one standard. | T-041, T-051 |
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,280 tests; the same suite on MySQL 8.4 in CI): `Feature/Accounts/AccountsLedgerTest.php`, `Feature/Accounts/TrialBalanceTest.php`, `Feature/Accounts/PayablesTest.php`, `Feature/Accounts/ExpensesLedgerTest.php`, `Feature/Accounts/GlSalesPostingTest.php`. | — |
| 8 | Browser acceptance tests. | Open | Opened by the crawl; nothing in it is pressed in a browser. | T-080 |
| 9 | Migration/reconciliation plan if replacing existing behavior. | Open | Planned, not built: expenses, credit notes and payment schedules of the old system merged, then reviewed by the person responsible for the accounts. | T-113 |
| 10 | Documentation and owner sign-off. | Open | Described in document 07 §8 and in document 15 §8. No guide yet for the people who will use it. Not signed. | T-171, T-186 |

### 4.7 Website Studio — the two open screens

Screens: Shop customization, Media · document 07 §13 · owner's decision: Not asked yet

| # | Condition (document 07 §15) | State | Where it stands | Closed by |
|---|---|---|---|---|
| 1 | Approved requirements. | Partly | Document 07 §13 is the requirement and document 05 its screens; where the build differs is in document 15 §8. Not approved as a module. | T-171 |
| 2 | Data ownership and schema. | Partly | The shop's design settings and the media library are stored in Laravel. The React shop reads them only through the bridge between the two systems, which is switched off on the live shop. The photographs of the catalogue are not in the library yet. | T-105, T-089 |
| 3 | API contract. | Partly | Photographs reach the React shop through a bridge with tests (`Feature/StorefrontMediaTest.php`). The common rules of `/api/v1` are not written. | T-063 |
| 4 | Permission matrix. | Partly | No address opens without a sign-in, and one role is checked against every address (`Feature/AdminRouteAccessTest.php`). The roles are the inherited eight; the owner's matrix and its test are still to come. | T-060, T-004 |
| 5 | UI list/detail/create/edit/action flows. | Partly | Shop customization and the media library open and are tested. Every other screen of Website Studio is switched off or not built. | T-116 |
| 6 | Loading/empty/error states. | Partly | An empty list says so and offers the next step; a refusal and an error have their own pages (`Feature/ErrorPagesTest.php`). There is no placeholder while a list loads and no notice when the connection is lost, and the five states are not yet one standard. | T-041, T-051 |
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,280 tests; the same suite on MySQL 8.4 in CI): `Feature/ShopContentTest.php`, `Feature/MasterMediaTest.php`, `Feature/StorefrontMediaTest.php`, `Feature/CatalogImagesTest.php`. | — |
| 8 | Browser acceptance tests. | Open | Opened by the crawl; nothing in it is pressed in a browser. | T-116 |
| 9 | Migration/reconciliation plan if replacing existing behavior. | Open | Planned, not built: today's pages and their media moved. | T-117 |
| 10 | Documentation and owner sign-off. | Open | Described in document 07 §13 and in document 15 §8. No guide yet for the people who will use it. Not signed. | T-171, T-186 |

### 4.8 System

Screens: Settings, Access & team, Connections, Platform, Audit & activity, Feature status · document 07 §14 · owner's decision: Not asked yet

| # | Condition (document 07 §15) | State | Where it stands | Closed by |
|---|---|---|---|---|
| 1 | Approved requirements. | Partly | Document 07 §14 is the requirement and document 05 its screens; where the build differs is in document 15 §8. Not approved as a module. | T-171 |
| 2 | Data ownership and schema. | Partly | Users, roles and permissions, settings, the audit log and the connections' settings are Laravel's own tables. The old system keeps its own users and five roles — 1 user on 17 November — and one matrix from the two is still to be made. | T-060 |
| 3 | API contract. | Does not apply | The screens are Livewire components: there is no API between screen and server, and nothing outside the admin reads this module. | — |
| 4 | Permission matrix. | Partly | No address opens without a sign-in, and one role is checked against every address (`Feature/AdminRouteAccessTest.php`). The audit log opens only for those who may audit (`Feature/AuditLogTest.php`). The roles are the inherited eight; the owner's matrix and its test are still to come. | T-060, T-004 |
| 5 | UI list/detail/create/edit/action flows. | Partly | Settings, Access & team, Connections, Platform, Audit & activity and Feature status open and are tested. Missing: one screen for currency, time zone and tax; a screen for sign-in locks and open sessions; the two planned screens. | T-064, T-065, T-056 |
| 6 | Loading/empty/error states. | Partly | An empty list says so and offers the next step; a refusal and an error have their own pages (`Feature/ErrorPagesTest.php`). There is no placeholder while a list loads and no notice when the connection is lost, and the five states are not yet one standard. | T-041, T-051 |
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,280 tests; the same suite on MySQL 8.4 in CI): `Feature/UsersAndSettingsTest.php`, `Feature/SettingsAndRulesTest.php`, `Feature/AuditLogTest.php`, `Feature/SystemScreensTest.php`, `Feature/FeatureStatusTest.php`, `Feature/InstallerTest.php`, `Feature/SecurityHeadersTest.php`. | — |
| 8 | Browser acceptance tests. | Met | What document 10 asks of it in a browser is pressed: signing in, signing out, the admin's own page for an address that does not exist, and a plain refusal for a screen a role may not open. | — |
| 9 | Migration/reconciliation plan if replacing existing behavior. | Open | The first installation on the real host and the merge of the two systems' users are not done. | T-133, T-060 |
| 10 | Documentation and owner sign-off. | Open | Described in document 07 §14 and in document 15 §8. No guide yet for the people who will use it. Not signed. | T-171, T-186 |
<!-- /generated:modules -->

---

## 5. Defect register

Every finding of a round is entered here with its severity. A defect is never removed: it is closed, with the day
and the way it was closed. A medium defect may be accepted by the owner for a release; a critical or a high one
may not.

<!-- generated:defects — written by scripts/verify-acceptance.mjs --write; do not edit by hand -->
| Severity | Means (document 10 §8) | Release rule | Open | Accepted | Closed |
|---|---|---|---|---|---|
| **Critical** | Data loss, privilege bypass, financial corruption, site unavailable | Blocks release | 1 | 0 | 1 |
| **High** | Core workflow broken, wrong totals or status, major security or privacy issue | Blocks the release it affects | 1 | 0 | 1 |
| **Medium** | A workaround exists; significant experience or non-core defect | Needs the owner's acceptance | 6 | 0 | 2 |
| **Low** | Cosmetic or a minor inconvenience | May be scheduled later | 1 | 0 | 0 |

**Not closed (9)**

| # | Severity | Found | Defect | Closed by |
|---|---|---|---|---|
| D-002 | Critical | 2026-11-16 | **The owner password of the old dashboard was printed in a public document.** The repository is public, and so is its history. Whoever read that document can sign in to `woodex-2030.vercel.app/admin` as the owner — since 17 November also to load, publish and withdraw products. Only the owner can close it: sign in, Settings, Change password. Making the repository private does not close it; the password stays known. | T-021 |
| D-006 | High | 2026-11-17 | **Preview builds of the React shop use the production database.** In the shop's Vercel project the database address is set for Production, Preview and Development alike. Vercel builds a preview of every branch that is pushed, so code that nobody has reviewed runs against the live data, and each preview address signs in to the same records. Harmless while the database holds sample products and one lead; not once it holds customers and orders. | T-164 |
| D-007 | Medium | 2026-11-17 | **The React shop sells the sample catalogue it was first filled with.** 148 products are on sale on `woodex-2030.vercel.app`; none of them is Woodex's, none has a code. The real 170 are prepared in the shop's build. Since migration 019 the old dashboard can load them: Product Catalog, Catalogue import. Rehearsed on a copy filled like the live database: 170 arrive as drafts, a second run changes nothing, and after publishing and withdrawing the demo catalogue a visitor gets 170 real products. | T-091 |
| D-008 | Medium | 2026-11-17 | **The catalogue's photographs are on woodexfurniture.pk, not in the system.** Each of the 170 products links to its photograph on the present website, in the Laravel admin and in the catalogue prepared for the React shop. If that website is changed or switched off, every product loses its photograph. | T-089 |
| D-009 | Medium | 2026-11-17 | **116 of the 170 real products carry a point to check, and 33 have no price.** The points are in the import report and on each product: a code made up for a product that had none, a name that differs from its page, a reduction that looks like a typing error (one product shows PKR 15,000 down from PKR 185,000). | T-090 |
| D-010 | Medium | 2026-11-17 | **The shop's database has no row-level security.** The second lock behind D-001. With the privileges gone the tables cannot be reached through the Data API; row-level security keeps it that way if a privilege is ever granted again by mistake. | T-163 |
| D-011 | Medium | 2026-11-17 | **No backup of the shop's database is known to exist.** None was taken before the changes of 17 November, and whether the Supabase plan keeps any has not been checked. What the database held can be rebuilt today: its 148 products are the seed file's, and both changes have their undo. That stops being true with the first real order. | T-187 |
| D-012 | Medium | 2026-11-16 | **The admin sends no Content-Security-Policy.** The other security headers are sent and checked from outside. A policy is the remaining one. | T-068 |
| D-013 | Low | 2026-11-16 | **The hosted workspace sends its stylesheet and scripts with no caching.** `max-age=0`: every visit asks again. The files carry a fingerprint in their name and could be kept for a year. | T-152 |

**Closed (4)**

| # | Severity | Found | Defect | Closed |
|---|---|---|---|---|
| D-001 | Critical | 2026-11-17 | **The React shop's database could be read and changed through Supabase's Data API.** None of its 72 tables had row-level security, and Supabase's two API roles held every privilege on all of them — users, sessions, password resets, orders and payments included. Anyone holding the project's publishable key could read and write every row. The shop itself never uses that API. Whether anybody did is not known from the database; Supabase's dashboard keeps a short log of API requests. | 2026-11-17. Every privilege on tables and sequences taken from the two roles, also for tables created later (`deploy/supabase/2026-11-17-close-data-api.sql`, DL-024). Read back: the two roles hold a privilege on 0 of 72 tables, and the owner of the tables holds everything on all of them. |
| D-003 | High | 2026-11-17 | **A Furniture-only product showed and sent Interior's name.** 71 of the 241 screens opened by the four roles carried the word Interior; the WhatsApp greeting on a lead, a contact, a quotation and a visit and the staff invitation named Woodex Interior; the client's pages of a quotation and an invoice printed INTERIOR STUDIO under the mark. | 2026-11-17. Commit `0d8ae53`: one setting names the lines on sale and the admin ships with Furniture alone. The crawl of all four roles finds the word on no screen; 25 tests pin it (`Feature/FurnitureOnlyTest.php`). |
| D-004 | Medium | 2026-11-17 | **Two settings decided how a time is stored and how it is read.** Had the application's time zone and the database session's time zone ever disagreed, every stored time would have shifted by five hours. They agreed, so no record was wrong. | 2026-11-17. Commit `d7d6c29`: the session's zone is derived from the application's (DL-023); tests pin both, one of them on MySQL in CI (`Feature/TimeStorageTest.php`). |
| D-005 | Medium | 2026-11-17 | **Three rows of the shop's migration ledger were written in another form than the migration runner writes.** Rows 16, 17 and 18 carried names without `.sql` and checksums with a prefix, one of them cut short. The runner compares checksums and would have refused the live database as changed after the fact. The files had not changed: the recorded values were the beginnings of the files' real checksums. | 2026-11-17. The three rows corrected to the runner's form, each guarded by its old value (DL-024). Read back: 19 rows, 19 migration files, every checksum equal. |
<!-- /generated:defects -->

---

## 6. What may go out, by these rules

| What | May it go out? | Why |
|---|---|---|
| The hosted admin workspace, `woodex-os.vercel.app` | **Yes, as a preview** | It says on every screen what it is. It holds the real catalogue as drafts and no other real record, and it keeps nothing that is typed |
| Pull request #36 merged into `main`; Vercel then rebuilds the React shop from it | **Yes** | Released by the owner (DL-022). The shop's test gate and the Laravel suite are green on the branch, and Vercel has built the shop from it. The shop's database holds sample products, one lead and no order. The open critical defect is a password: the merge neither causes it nor makes it worse |
| Real products on sale in the React shop | **The owner's step** (T-091) | Publishing is a decision per product. By publishing, the owner accepts D-008 (photographs on the old website) and D-009 (points to check) for those products, or settles them first |
| Real customers and orders on either system: the cutover of gate E | **No** | D-002 is critical and open. D-006 is high and open: previews share the production database. For the Laravel admin, gate D has not started |

---

## 7. How the owner accepts or returns

Write it in the chat or on the pull request, in your own words. The agent records it as a row of the decision log
and in the file behind the table, and the check then lets the table say so.

| You can decide today | Say | What it changes |
|---|---|---|
| The design of the admin (gate A) | "Design approved", or what to change | Criterion 1 of gate A; line T-005 |
| The requirements of a module | "Requirements of Customers approved", or "Return Customers:" and your remarks | Condition 1 on that sheet |
| A medium defect, for a release | "Accept D-008 for now" | The defect is shown as accepted, with your decision; it stays in the register |
| A module, once its conditions 1 to 9 are met | "Accept Customers" | Condition 10; gate B for that module |
| A gate | "Gate A passed" | The gate, when the check finds every criterion met |

A critical or a high defect cannot be accepted: it is closed, or the release it blocks waits.

---

## 8. The report every release carries

From 17 November every release report has the ten points of document 10 §9, as its ten headings and in this
order; the check refuses a report without them:

1. Commit/branch/build identifier · 2. Environment · 3. Test command and result · 4. Browser/device coverage ·
5. Defects open/closed by severity · 6. Migration reconciliation · 7. Backup/restore status ·
8. Performance observations · 9. Screenshots or recording of key flows · 10. Explicit owner approval status.

A report that moves a module from the old system to the new one adds the fourteen points of document 09 §6,
each answered yes or no with its evidence: source routes and tables inventoried · target owner identified · data
mapping approved · schema and constraints ready · import tested on a copy · counts and critical totals
reconciled · duplicate and orphan report reviewed · permission and API tests pass · the React workflow passes
against Laravel staging · background jobs and integrations tested · source writes disabled or redirected · target
write authority enabled · monitoring and rollback available · the owner accepts the module.

The first report in this form is [`17-RELEASE-2026-11-17.md`](17-RELEASE-2026-11-17.md).
