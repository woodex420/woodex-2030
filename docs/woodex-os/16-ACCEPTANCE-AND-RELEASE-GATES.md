# WOODEX OS — acceptance and release gates

**Written 2026-11-17, after the owner lifted the holds (decision DL-022). Code: branch `arena/7ecb4f83-woodex-2030`, merged into `main` the same day at 10:22 UTC (merge commit `88ec4cc`).**

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
sentence ("2,434 tests") are readings of 17 November 2026; the two release reports of that day,
[`17-RELEASE-2026-11-17.md`](17-RELEASE-2026-11-17.md) and
[`18-RELEASE-2026-11-17-SECOND.md`](18-RELEASE-2026-11-17-SECOND.md), say how each was taken.

## The short version

1. **No gate is passed.** Gate A waits for the owner's approval of the design. Gate B is decided module by
   module, on the sheets of §4. Gate C has not started. Gate D cannot start before the Hostinger details and the
   two secrets are there. Gate E is blocked by one open critical defect, which only the owner can close:
   the old dashboard's password (D-002).
2. **What 17 November changed.** The admin is Furniture-only and carries Woodex's real catalogue: 170 products as
   drafts, on the hosted workspace too. The React shop's database lost an open door (D-001), received catalogue
   migration 019, and its migration ledger agrees with the files again. The acceptance scenarios, the defect
   register and the module sheets exist and are checked. At 10:22 UTC the branch was merged into `main`, and
   Vercel rebuilt both sites from it; asked from outside afterwards, both answer as they should. The merge also
   made live the changes to the React shop of 11 to 13 November, its Products menu among them, which the release
   report had not listed beforehand (D-014, T-026).
3. **What the afternoon of 17 November changed** (pull request #37; report 18). A sale is pressed through in a
   browser, from the enquiry to the receipt, by the roles that do it, and CI repeats it on every push that touches
   the admin. What that round found is D-015 to D-029: eleven closed, four open. Where the client has no page of
   their own, staff write the client's answer down. The shop's database has its second lock: row-level security
   on all 73 tables (D-010 closed). A new preview of the shop has no database; the previews built before cannot
   be opened without a sign-in to Vercel and are deleted by Vercel within 30 days (D-006 closed).
4. **What the evening of 17 November changed** (pull request #39). The security tests that document 10 asks for
   exist and pass: the sign-in limit, mass assignment, cross-origin rules, stored script, secrets in logs and
   error pages (T-160). Every sensitive action leaves a row in the list of changes: six did not (T-067, D-032).
   A lead's value follows its quotation (T-092, D-024 closed). What the tests found and what was corrected:
   D-030 to D-032. The one high defect is closed; one critical defect is open, and only the owner can close it.
5. **What only the owner can do now**, in this order: change the old dashboard's password (T-021) · look at the
   React shop as the merge rebuilt it, and say whether it stays (T-026) · load the real products into the React
   shop and withdraw the sample ones (T-091) · look at the hosted workspace and approve or return the design
   (T-005) · read the sheets of §4 and approve or return each module's requirements · send the Hostinger details
   (T-006).

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
| Unit/API/browser tests pass. | Partly | Unit and feature tests pass: 2,434 on 17 November, and the same suite on MySQL 8.4 in CI. In a browser both journeys and a crawl of every screen as four roles pass, by hand and, since 17 November, in CI on every push that touches the admin (`.github/workflows/vercel-livewire.yml`); which of the eleven flows of document 10 they press is in §3. | T-165, T-080 |
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
| Security and resource checks passed. | Open | The outside security check exists and passes for the hosted workspace on Vercel (`.github/workflows/vercel-live-check.yml`); it has not been pointed at Hostinger, and nothing has been measured against the plan's limits. Published advisories for the packages are asked of npm and Composer when a lock file changes and every week (`.github/workflows/audit.yml`); none that blocks is open. | T-139, T-142, T-068 |

**Gate E — Production cutover**

| Criterion (document 10 §7) | State | Where it stands | Closed by |
|---|---|---|---|
| Owner approval. | Partly | Given in general on 17 November: the owner lifted the holds to start a production-ready deployment (DL-022). The move of each module is approved in writing on its sheet. | T-181, T-171 |
| Migration snapshot. | Open | No snapshot has been taken for a move. What the old database held on 17 November was read and is written down: 148 sample products, 1 lead, 1 client, 1 user, no order, quotation, invoice or payment. Since 17 November a new preview of the shop has no database; the previews built before cannot be opened without a sign-in to Vercel, and Vercel deletes them within 30 days. | T-180 |
| Rollback plan. | Partly | Each change of 17 November has its own way back: the undo in `deploy/supabase/2026-11-17-close-data-api.sql`, the rollback file `scripts/migrations/rollback/019_catalogue.rollback.sql`, the earlier deployment kept by Vercel. A written plan for going back after a module has moved does not exist. | T-180, T-185 |
| Monitoring and support plan. | Partly | Both sites are asked from outside after every merge, and a timer for every six hours is in the workflow (`.github/workflows/live-check-after-merge.yml`, `.github/workflows/vercel-live-check.yml`); a failure opens an issue, proved with issue #38 on 17 November. GitHub had not yet started the timer by the afternoon of 17 November. No error tracker, no runbook. | T-138, T-142, T-185 |
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
| AS-03 | Lead assignment is permission checked and audited. | Proven | `LeadShowTest` › lets a manager reassign the lead<br>`LeadShowTest` › does not let a sales user act on another person's lead<br>`LeadsIndexTest` › bulk assigns, moves and deletes selected leads within permissions<br>`LeadShowTest` › writes nothing when a lead is given to the person who has it already | A reassignment asks for a reason and writes it into the lead's history. Pressed in a browser: the sale journey hands a lead over twice and finds the reason on its timeline. | — |
| AS-04 | Estimate totals are calculated server-side. | Partly | `Sales/EstimatesTest` › prices an estimate from the rate book with an honest essential-to-premium band<br>`Sales/EstimatesTest` › prices rate book lines at today's rates inside the same computation<br>`Unit/QuoteCalculatorTest` › applies line discounts, quote discount, other charges, tax and rounding in order | The arithmetic runs on the server and is tested. The Estimations screen is switched off: its rate book is Interior's, and Furniture's rates are not in yet. | T-085, T-003 |
| AS-05 | Quote approval is required for configured roles/thresholds. | Proven | `QuoteSendingTest` › measures the discount against the sender's role limit and the minimum margin<br>`QuoteSendingTest` › blocks sending above the limit until a manager approves exactly those figures<br>`QuoteSendingTest` › lists pending approvals for managers and lets them decide | The limits in force are defaults. The owner's own limit for each role is still to come. Pressed in a browser: a sales person with a 5% limit cannot send 8% off, asks, and the sales manager approves from the queue. | T-004 |
| AS-06 | A rejected quote cannot be converted. | Proven | `QuotationSafetyTest` › rechecks the persisted acceptability of a stale sent quotation<br>`QuotationSafetyTest` › does not open projects or advances from unaccepted quotes<br>`Sales/ClientAnswerTest` › records a no with the reason, leaves the lead alone, and the quotation can be revised<br>`Sales/ClientAnswerTest` › refuses an answer for a quotation that is not with the client | A declined, expired, void or draft quotation cannot be accepted, and no project or invoice is created from it. Since 17 November a member of staff can record the client's no, with the reason; it opens nothing. | — |
| AS-07 | Issued document snapshot remains stable after product price changes. | Partly | `QuotesTest` › copies rate book items into the quote so later rate changes do not alter it<br>`DocumentLibraryTest` › serves the exact frozen quote PDF by default after sending<br>`QuotationSafetyTest` › returns the same advance and never re-freezes an existing issued PDF | Proven for rate-book lines and for the frozen PDF. A quotation line cannot yet be chosen from the product catalogue, so a product's price change is not tested. | T-086 |

**Finance**

| # | Scenario (document 10 §3) | State | Proved by (under `platform/tests/`) | Note | Closed by |
|---|---|---|---|---|---|
| AS-08 | Payment amount cannot exceed allowed allocation unless overpayment policy is defined. | Proven | `Sales/InvoiceScreenTest` › refuses to collect more than is outstanding<br>`PaymentsTest` › spreads a payment over the oldest invoices first and keeps the rest as credit<br>`PaymentIntegrityTest` › validates the whole explicit map before retaining any allocation or receipt<br>`Accounts/PayablesTest` › refuses a payment larger than the balance | The policy in force: from an invoice no more than is outstanding can be collected; money received beyond the invoices it settles is kept as the client's credit. | — |
| AS-09 | Partial payments calculate outstanding balance correctly. | Proven | `PaymentsTest` › spreads a payment over the oldest invoices first and keeps the rest as credit<br>`PaymentIntegrityTest` › pays the preferred invoice before older debt and retains only the final excess as credit<br>`PaymentIntegrityTest` › preserves paisa exactly and refuses scientific or overprecise amounts on the staff form<br>`Accounts/GlSalesPostingTest` › splits a part-settled receipt between receivables and client credit<br>`Sales/BillingTimelineTest` › writes a payment taken by accounts, and says where the invoice then stands | Pressed in a browser: 372,600 paid against an invoice of 496,800 leaves 124,200 and the status Partially Paid. | — |
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
**11 flows: 4 pressed in full, 2 in part, 5 not pressed.** The journey has 27 steps.

| Flow (document 10 §2) | State | Steps of `scripts/os-journeys.mjs` that press it | Note | Closed by |
|---|---|---|---|---|
| Lead capture. | Partly | create a lead from the New menu<br>the lead is still there after a reload, and its page opens | A member of staff creates the lead, in both journeys. The shop's public form is not in a journey: the React shop does not write to Laravel yet. | T-100, T-165 |
| Lead assignment and conversion. | Pressed in full | an enquiry is taken and handed to a sales person, with the reason on record<br>the sales person finds the enquiry among their own leads<br>the lead is won and the contact has become a client | The assignment rule gives a new enquiry to somebody; the owner hands it on, is asked why, and the reason is on the timeline. The client's yes moves the lead to Won and makes the contact a client. | — |
| Estimate to quotation. | Not pressed | — | Estimations is switched off. | T-085 |
| Quotation approval and issue. | Pressed in full | write a draft quotation for that client<br>price one line; the total follows<br>the quotation PDF<br>documents can be issued here: terms and a signer are in place<br>a quotation above the discount limit cannot be sent: approval is asked for<br>the approver finds the request in the queue and approves it<br>the approved quotation is sent: frozen, with its PDF<br>the client says yes | Pressed on the rehearsal both ways: with the client's own pages open (the demo) the client accepts on a phone without a sign-in; with them off (the product as it ships) the sales person writes the answer down. On a site whose owner has not confirmed terms and signers nothing can be sent, and the journey reports the site's reason instead of failing (T-084). | T-084 |
| Invoice and payment allocation. | Pressed in full | accounts bill the sale in full and issue the invoice; the ledger has it<br>the advance is paid and confirmed against the invoice<br>the receipt is a numbered PDF<br>the sales person sees on the lead that the invoice went out and the money came in | One invoice of 496,800 and one payment of 372,600 set against it: balance 124,200, status Partially Paid, receipt as a PDF. With the client's pages open the client reports the payment with a bank slip and accounts confirm it; with them off accounts take it on the invoice. A payment spread over several invoices is proven by tests (AS-08, AS-09), not pressed. | — |
| Product browse and order creation. | Partly | find a product of the real catalogue by its code, and open it | Browsing only, on the real catalogue. Orders do not exist in Laravel. | T-106 |
| Purchase order and goods receipt. | Not pressed | — | No module. | T-110 |
| Stock transfer/reservation. | Not pressed | — | No module. | T-109 |
| Project task and handover. | Not pressed | — | A Furniture sale opens a project, but the project screen still carries the stages, the hand-over checklist and the site updates of the Interior module. A journey waits for Furniture's own job steps (T-010, T-033). | T-165, T-033 |
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
| 4.2 **Sales** | Quotations, Invoices, Payments, Documents | 2 | 6 | 2 | 0 | Not asked yet |
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
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,434 tests; the same suite on MySQL 8.4 in CI): `Feature/LeadsIndexTest.php`, `Feature/LeadShowTest.php`, `Feature/ContactsTest.php`, `Feature/TasksAndVisitsTest.php`, `Feature/LeadCsvImportTest.php`, `Feature/Crm/LeadCsvExportTest.php`, `Feature/PipelineBoardTest.php`, `Feature/Sales/LeadValueFollowsQuotationTest.php`, `Unit/PhoneTest.php`. | — |
| 8 | Browser acceptance tests. | Partly | Pressed in a browser: create a lead, reopen it, add a task, find it by search, export, upload a sheet; the owner hands a lead to a sales person and is asked why; the client's yes makes the lead Won and the contact a client. Not pressed: an enquiry arriving through the shop's form (the shop does not write to Laravel yet), booking a visit, merging two contacts. | T-165 |
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
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,434 tests; the same suite on MySQL 8.4 in CI): `Feature/QuotesTest.php`, `Feature/QuoteSendingTest.php`, `Feature/QuotationSafetyTest.php`, `Feature/PaymentsTest.php`, `Feature/PaymentIntegrityTest.php`, `Feature/Sales/InvoiceScreenTest.php`, `Feature/Sales/ClientAnswerTest.php`, `Feature/Sales/BillingTimelineTest.php`, `Feature/DocumentLibraryTest.php`, `Unit/QuoteCalculatorTest.php`. | — |
| 8 | Browser acceptance tests. | Met | Pressed in a browser on the rehearsal, and in CI on every push that touches the admin (`.github/workflows/vercel-livewire.yml`): a quotation above the discount limit is held, approved by the sales manager, sent, accepted by the client — on a page of their own where those pages are on, written down by staff where they are off — invoiced in full, paid in part against a slip, and receipted as a PDF; also the quotation PDF and the client's statement PDF. Each step is pressed by the role that does it. The same journey on staging with real test records is T-133. | — |
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
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,434 tests; the same suite on MySQL 8.4 in CI): `Feature/NativeProductsTest.php`, `Feature/WoodexCatalogueImportTest.php`, `Feature/CommerceCategoriesTest.php`, `Feature/CommerceTaxonomyTest.php`, `Feature/SeriesRulesTest.php`, `Feature/CommerceWorkflowTest.php`, `Feature/CommerceReadinessTest.php`. | — |
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
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,434 tests; the same suite on MySQL 8.4 in CI): `Feature/Overview/ControlCentreTest.php`, `Feature/Overview/ControlViewTest.php`, `Feature/ReportsKitTest.php`, `Feature/QuoteSendingTest.php`. | — |
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
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,434 tests; the same suite on MySQL 8.4 in CI): `Feature/ProjectsTest.php`, `Feature/ProjectCostsTest.php`, `Feature/Projects/ProjectCostPostingTest.php`, `Feature/Projects/ProjectCostsScreenTest.php`. | — |
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
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,434 tests; the same suite on MySQL 8.4 in CI): `Feature/Accounts/AccountsLedgerTest.php`, `Feature/Accounts/TrialBalanceTest.php`, `Feature/Accounts/PayablesTest.php`, `Feature/Accounts/ExpensesLedgerTest.php`, `Feature/Accounts/GlSalesPostingTest.php`. | — |
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
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,434 tests; the same suite on MySQL 8.4 in CI): `Feature/ShopContentTest.php`, `Feature/MasterMediaTest.php`, `Feature/StorefrontMediaTest.php`, `Feature/CatalogImagesTest.php`. | — |
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
| 7 | Unit and integration tests. | Met | Pass in the suite of 17 November (2,434 tests; the same suite on MySQL 8.4 in CI): `Feature/UsersAndSettingsTest.php`, `Feature/SettingsAndRulesTest.php`, `Feature/AuditLogTest.php`, `Feature/SystemScreensTest.php`, `Feature/FeatureStatusTest.php`, `Feature/SwitchedOffModulesTest.php`, `Feature/Security/SignInLimitTest.php`, `Feature/Security/MassAssignmentTest.php`, `Feature/Security/CrossOriginTest.php`, `Feature/Security/StoredScriptTest.php`, `Feature/Security/SecretsStayOutTest.php`, `Feature/Security/SensitiveActionsLeaveATraceTest.php`, `Feature/InstallerTest.php`, `Feature/SecurityHeadersTest.php`. | — |
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
| **High** | Core workflow broken, wrong totals or status, major security or privacy issue | Blocks the release it affects | 0 | 0 | 6 |
| **Medium** | A workaround exists; significant experience or non-core defect | Needs the owner's acceptance | 7 | 0 | 11 |
| **Low** | Cosmetic or a minor inconvenience | May be scheduled later | 3 | 0 | 3 |

**Not closed (11)**

| # | Severity | Found | Defect | Closed by |
|---|---|---|---|---|
| D-002 | Critical | 2026-11-16 | **The owner password of the old dashboard was printed in a public document.** The repository is public, and so is its history. Whoever read that document can sign in to `woodex-2030.vercel.app/admin` as the owner — since 17 November also to load, publish and withdraw products. Only the owner can close it: sign in, Settings, Change password. Making the repository private does not close it; the password stays known. | T-021 |
| D-007 | Medium | 2026-11-17 | **The React shop sells the sample catalogue it was first filled with.** 148 products are on sale on `woodex-2030.vercel.app`; none of them is Woodex's, none has a code. The real 170 are prepared in the shop's build. Since migration 019 the old dashboard can load them: Product Catalog, Catalogue import. Rehearsed on a copy filled like the live database: 170 arrive as drafts, a second run changes nothing, and after publishing and withdrawing the demo catalogue a visitor gets 170 real products. | T-091 |
| D-008 | Medium | 2026-11-17 | **The catalogue's photographs are on woodexfurniture.pk, not in the system.** Each of the 170 products links to its photograph on the present website, in the Laravel admin and in the catalogue prepared for the React shop. If that website is changed or switched off, every product loses its photograph. | T-089 |
| D-009 | Medium | 2026-11-17 | **116 of the 170 real products carry a point to check, and 33 have no price.** The points are in the import report and on each product: a code made up for a product that had none, a name that differs from its page, a reduction that looks like a typing error (one product shows PKR 15,000 down from PKR 185,000). | T-090 |
| D-011 | Medium | 2026-11-17 | **No backup of the shop's database is known to exist.** None was taken before the changes of 17 November, and whether the Supabase plan keeps any has not been checked. What the database held can be rebuilt today: its 148 products are the seed file's, and both changes have their undo. That stops being true with the first real order. On 17 November the agent looked again before applying migration 020: its connection to Supabase shows neither the plan nor any backup, so this could not be settled. That migration changes no row and has a tested rollback (DL-028). | T-187 |
| D-012 | Medium | 2026-11-16 | **The admin sends no Content-Security-Policy.** The other security headers are sent and checked from outside. A policy is the remaining one. | T-068 |
| D-013 | Low | 2026-11-16 | **The hosted workspace sends its stylesheet and scripts with no caching.** `max-age=0`: every visit asks again. The files carry a fingerprint in their name and could be kept for a year. | T-152 |
| D-014 | Medium | 2026-11-17 | **The merge made changes to the live shop that the release report had not listed.** The branch merged on 17 November also held the work done on the React shop between 11 and 13 November. With the merge it went live on `woodex-2030.vercel.app`: the Products menu is a plain list built from the category file shared with the admin, without its two picture tiles and with three more entries; a heading such as "Office Tables" lists the products of all its sub-categories; the connections to the Laravel admin and the second destination of the enquiry forms are in the build, switched off. The shop's gate passed on the merge commit and the outside check passed after it, so nothing is known to be broken; but the owner released the merge without this list. The report now carries it (`17-RELEASE-2026-11-17.md`), and the earlier build is kept by Vercel. | T-026 |
| D-023 | Medium | 2026-11-17 | **The project that a Furniture sale opens is an Interior project.** Accepting a quotation opens a project. Its screen carries the Interior module's stages (Planning, Design, Procurement, Execution, Finishing, Handover), a hand-over checklist that speaks of "the studio list", and site updates. The word "Interior" does not appear, so the Furniture-only check passes; but none of it is how a furniture order is made, delivered and installed. The project is named after the quotation's title, which is "Quotation" when nobody typed one. | T-033, T-010 |
| D-028 | Low | 2026-11-17 | **One low advisory in Laravel, and eleven moderate ones in the shop's build tools.** `laravel/framework` 13.39.0 carries CVE-2026-128083 (low; fixed in 13.40.0): text injected into the output of a task run through the Concurrency process driver, which this application does not use. `npm audit` reports eleven moderate advisories, in the linter, in `react-router` and in a Tailwind plugin; the fix npm offers for `react-router` is a change of major version. None fails the audit, which stops at high. | T-071 |
| D-029 | Low | 2026-11-17 | **Ten functions of the shop's database have no fixed search path.** The triggers and helpers of the schema (`journal_entry_must_balance`, `activities_are_append_only`, `group_concat` and seven more) are created without `SET search_path`. Supabase's security advisor is known to list such a function as a warning; its page could not be read through the agent's connection. Only the owner of the database can create objects there, so nothing can be put in the functions' way today. | T-172 |

**Closed (21)**

| # | Severity | Found | Defect | Closed |
|---|---|---|---|---|
| D-001 | Critical | 2026-11-17 | **The React shop's database could be read and changed through Supabase's Data API.** None of its 72 tables had row-level security, and Supabase's two API roles held every privilege on all of them — users, sessions, password resets, orders and payments included. Anyone holding the project's publishable key could read and write every row. The shop itself never uses that API. Whether anybody did is not known from the database; Supabase's dashboard keeps a short log of API requests. | 2026-11-17. Every privilege on tables and sequences taken from the two roles, also for tables created later (`deploy/supabase/2026-11-17-close-data-api.sql`, DL-024). Read back: the two roles hold a privilege on 0 of 73 tables and on no sequence, and the owner of the tables holds everything. Asked from outside afterwards, the shop serves its 148 products as before. |
| D-003 | High | 2026-11-17 | **A Furniture-only product showed and sent Interior's name.** 71 of the 241 screens opened by the four roles carried the word Interior; the WhatsApp greeting on a lead, a contact, a quotation and a visit and the staff invitation named Woodex Interior; the client's pages of a quotation and an invoice printed INTERIOR STUDIO under the mark. | 2026-11-17. Commit `0d8ae53`: one setting names the lines on sale and the admin ships with Furniture alone. The crawl of all four roles finds the word on no screen; 25 tests pin it (`Feature/FurnitureOnlyTest.php`). |
| D-004 | Medium | 2026-11-17 | **Two settings decided how a time is stored and how it is read.** Had the application's time zone and the database session's time zone ever disagreed, every stored time would have shifted by five hours. They agreed, so no record was wrong. | 2026-11-17. Commit `d7d6c29`: the session's zone is derived from the application's (DL-023); tests pin both, one of them on MySQL in CI (`Feature/TimeStorageTest.php`). |
| D-005 | Medium | 2026-11-17 | **Three rows of the shop's migration ledger were written in another form than the migration runner writes.** Rows 16, 17 and 18 carried names without `.sql` and checksums with a prefix, one of them cut short. The runner compares checksums and would have refused the live database as changed after the fact. The files had not changed: the recorded values were the beginnings of the files' real checksums. | 2026-11-17. The three rows corrected to the runner's form, each guarded by its old value (DL-024). Read back: 19 rows, 19 migration files, every checksum equal. |
| D-006 | High | 2026-11-17 | **Preview builds of the React shop use the production database.** In the shop's Vercel project the database address is set for Production, Preview and Development alike. Vercel builds a preview of every branch that is pushed, so code that nobody has reviewed runs against the live data, and each preview address signs in to the same records. Harmless while the database holds sample products and one lead; not once it holds customers and orders. On 17 November the owner chose the repair (DL-026): take the database address out of Preview and Development. It is made as soon as the Vercel app is switched on again. Since 17 November, 16:05 UTC, the address is set for Production only (T-164): a preview built from then on has no database. Every preview that Vercel built before keeps the address until it is deleted, so this stays open for them. | 2026-11-17. Three things together. The database address is set for Production only since 16:05 UTC, so a preview built from then on has no database (T-164). The previews built before cannot be opened by a stranger: both Vercel projects ask for a sign-in to Vercel on every address except the site's own domain, read from the project settings and asked from outside on every run of the live check, which opens an issue if it stops being so. And Vercel deletes each of them 30 days after it was built: the last by 17 December. They were not deleted by hand (T-188). DL-029. |
| D-010 | Medium | 2026-11-17 | **The shop's database has no row-level security.** The second lock behind D-001. With the privileges gone the tables cannot be reached through the Data API; row-level security keeps it that way if a privilege is ever granted again by mistake. Migration 020 is written and tested (`scripts/migrations/020_row_level_security.sql`, `scripts/test-rls.mjs`); by the owner's word of 17 November (DL-026) it is applied to the live database once a backup or a safe way back is confirmed, which needs the Supabase app switched on. | 2026-11-17. Migration 020 was applied to the live database at 16:07:45 UTC, in one transaction with its ledger row: all 73 tables carry row-level security and the policy `no_access_through_the_data_api`, none forced. The shop's API connects as the owner of the tables and reads as before: asked from outside 67 seconds later, the shop served its 148 products (run 44122391808). The text that was run is kept in `deploy/supabase/2026-11-17-apply-020-row-level-security.sql`. DL-028. |
| D-015 | High | 2026-11-17 | **A quotation that had been sent could never become Accepted or Declined.** Found by pressing the sale through in a browser. The client's own page records a yes, but that page is an optional module and the product ships with it off. The only other route needed a WhatsApp reply, and WhatsApp is not connected. No code could set a quotation to Declined at all. So the ordinary case — the client says yes on the telephone — left the quotation "Sent" for ever: no won lead, no project, no invoice. | 2026-11-17. A member of staff who may send quotations records the client's answer: accepted (who, how: telephone, in person, WhatsApp, e-mail or a signed copy, with a note) or declined (with the reason). The record names who wrote it down. A recorded yes does exactly what the client's page does. `92f90f9`; `tests/Feature/Sales/ClientAnswerTest.php`; pressed in the sale journey (DL-025). |
| D-016 | High | 2026-11-17 | **With the client pages off, the admin handed out links to pages that do not open.** After "Send now" the admin showed an empty "Share" dialog and the message "Share the link with the client"; the WhatsApp text and the e-mail carried a link; every invoice, drafts too, offered a "Client link"; the project showed a portal card. Each link answered "not found", because the client pages are switched off. A client would have received a dead link in place of the quotation. | 2026-11-17. One answer to "is there a client page?" for quotations and one for invoices; while there is none the PDF is the document and each screen and message says what to do with it. `92f90f9`; `tests/Feature/Sales/ClientPagesOffTest.php`; pressed in the sale journey with the pages off. |
| D-017 | High | 2026-11-17 | **A new installation could never issue a document: Settings had no field for the invoice terms.** A document cannot be issued without invoice terms, and a new installation starts with none. Settings offered no field to write them, and asked for the Interior brand's two sets of terms although that brand is not on sale. The notice said only that something was missing. | 2026-11-17. Settings → Sales rules has the field; the Interior terms are asked for only where that brand is on sale; the notice lists point by point what is missing; the other rules can be saved while the wording is undecided. `92f90f9`, `6b3a1ea`; `tests/Feature/FurnitureSettingsTest.php`. |
| D-018 | Medium | 2026-11-17 | **Invoices and payments never reached the client's timeline.** The timeline of a lead and of a contact offers "Invoice" and "Payment" as filters, but nothing wrote either kind. The record of a sale stopped at "accepted": the sales person who won the order could not see on their lead whether the invoice had gone out or the advance had come in. | 2026-11-17. The issue and the voiding of an invoice, a payment the client reported, its confirmation with the receipt number, and a claim that was sent back are written to the timeline. Without amounts: a lead is read by everybody who may open it, the figures by those who may open the invoice. `6b3a1ea`; `tests/Feature/Sales/BillingTimelineTest.php`; read by the sales person in the last step of the sale journey. |
| D-019 | Medium | 2026-11-17 | **The details of a lead showed a leftover of the screen in place of the lead's value.** The "Value" row of every lead's Details card printed the last key of a list further up the page ("call_back") instead of the expected value typed for the lead. | 2026-11-17. The row shows the value. `6b3a1ea`; test "shows the value typed for a lead in its details, not a leftover of the screen" (it fails on the old view). |
| D-020 | Medium | 2026-11-17 | **The client's page of a discounted invoice did not add up.** The page listed the lines (540,000) and the total (496,800) with nothing between them. Discount, rent, transport, tax and rounding were on the PDF only. | 2026-11-17. The page shows subtotal, each adjustment and the total when there is any adjustment. `6b3a1ea`; two tests in `BillingTimelineTest.php`; read on the client's page in the sale journey. |
| D-021 | Medium | 2026-11-17 | **Confirming a payment the client reported asked nothing.** "Confirm", a small link beside "Send back" in the list of payments, numbers a receipt and books the amount in the ledger with one click. Neither can be taken back. | 2026-11-17. The link asks first, naming the amount and the client. `6b3a1ea`; the sale journey checks that the question was asked. |
| D-022 | Low | 2026-11-17 | **Small things on the quotation, invoice and lead screens.** A sales person, who has no "Create full invoice" button, was told to use it. "Give to client" stayed on a quotation that was already answered. A draft invoice said "issued" with a date. The record that an invoice was raised lost the number of its project. Choosing the person who already has a lead wrote a second "Assigned to …" on the timeline and told them again. | 2026-11-17. Each corrected. `92f90f9`, `6b3a1ea` and the commit that closes T-080; tests in `ClientAnswerTest.php`, `ClientPagesOffTest.php` and `LeadShowTest.php`. |
| D-024 | Low | 2026-11-17 | **A lead's value does not follow its quotation.** The expected value of a lead is typed by hand. Sending or accepting a quotation does not set it, so the pipeline board and the "Expected value" order count a sale won for 496,800 as nothing unless somebody typed the figure. | 2026-11-17. A sent quotation gives the lead its total when the lead has no value or still carries the total of an earlier sending; a figure typed by hand is kept; an accepted quotation sets the value. The pipeline's "Won" adds up to the accepted quotations. `app/Domain/Sales/Actions/SetLeadValueFromQuote.php`; ten tests in `LeadValueFollowsQuotationTest.php`. DL-029. |
| D-025 | High | 2026-11-17 | **Every link to a client's project page was refused, and the portal screen carried a script no browser could read.** Where the client's pages are switched on (the demo; a site that turns the module on), the link to a client's project page answered "No access" wherever the admin gave it: on the project, on the Client portal screen, in the e-mail "Open my project", in the WhatsApp message and in the review request. The link was signed over the whole address; the page checks the path only. On the Client portal screen the "Copy" button also carried a Blade directive as plain text, so the browser reported a script error as soon as one project existed. No test had ever opened the screen or one of its links; the crawl missed it because it ran on a site without a project. The product as it ships has these pages off and showed no link. | 2026-11-17. The link is signed over the path and the site's address is put in front (`app/Domain/Projects/Services/PortalLinks.php`); the button reads the link from the element. `ClientPortalTest.php` opens each of the five links and the screen. In CI the journeys now run before the crawl, so every screen is opened with the records of a sale in it. |
| D-026 | Medium | 2026-11-17 | **Open screens still offered what belongs to switched-off modules.** A switched-off module is closed at its own addresses, but three open screens offered its things. The project that a sale opens had "Case study": a draft page for the public website, written from the Interior studio's template, while the website is off. Settings showed "WhatsApp" (the Cloud API's keys and costs) and "Bot" (the chat bot's prompt, written for the Interior studio). Integrations listed Telegram, WhatsApp, Google, Gmail, Drive, the Facebook page and the AI bridge with their buttons; one of them makes an access key for an endpoint that is closed. A lead could also be asked to price an estimate while the estimator is off. | 2026-11-17. Each is offered only while its module is on, and answers "not found" when asked for anyway: `SwitchedOffModulesTest.php`, 43 tests, which also ask for the two Interior quotation designs and every address of the estimator. Removing a stored connection and revoking a bridge key stay possible, because they only take away. DL-027. |
| D-027 | Medium | 2026-11-17 | **Four packages of the admin had published security advisories.** The first run of the new audit (T-069) reported nine advisories in `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing` and `symfony/serializer` 8.1.10, among them CVE-2026-118516 (a host placeholder of a route could match the path) and CVE-2026-118515 (file objects built from untrusted data). Packagist states no severity for them; the audit treats an advisory without one as blocking. The fixed versions were published on 4 November. | 2026-11-17. The four packages are at 8.1.11 in `platform/composer.lock`, with no other requirement changed; the whole suite passes on them and the audit in CI reports no blocking advisory. What is left is D-028. |
| D-030 | Medium | 2026-11-17 | **Saving the sales rules switched the follow-up of quotations off.** Settings → Sales rules has a box "follow up quotations automatically". Whoever saved that screen, for any reason, switched it off: the box had no validation rule, so it was not among the checked values and was read as "off", ticked or not. It could not be switched on from the screen at all. Sent quotations were then no longer chased, and nothing said so. Found when every change of a setting began to be recorded (T-067): a save that changed nothing wrote a change. | 2026-11-17. The box has its rule (`SettingsPage::saveSales`). `SensitiveActionsLeaveATraceTest.php`: a screen saved as it was opened changes nothing, and the box saves as it says. |
| D-031 | Low | 2026-11-17 | **Three smaller things the security tests found.** The invoice page kept the number of its invoice where the browser could change it: the page then showed another invoice without having been opened for it (today every person who may open one invoice may open them all, so nothing was gained by it). Two records handed out a secret when turned into an array for a browser or a log: the hash of a client's six-digit portal code, and a visitor's chat key. And the example settings file switches debugging on: copied by hand to a live site, a fault there would have shown the request, the queries and the code to whoever caused it. | 2026-11-17. The invoice's number is locked, and a test finds every page that keeps its record unlocked. The two columns are hidden, and a test finds every secret-looking column that is not. A site that calls itself production refuses the debugging switch and its health report says the settings file is wrong (`app/Support/DebugGuard.php`). Tests in `tests/Feature/Security/`. DL-029. |
| D-032 | Medium | 2026-11-17 | **Six sensitive actions left no trace.** The list of changes (System → Audit log) had no row for an invitation, a change of role, a deactivation or a reactivation of a person, nor for any change of a setting: who may give which discount, what a document says, who signs it. Document 08 §7 asks for an audit event for each. | 2026-11-17. Each writes a row with who did it and what changed; a secret setting is recorded as changed, never with its value (`ManageStaff`, `RecordSettingsChange`). `SensitiveActionsLeaveATraceTest.php` lists nineteen sensitive actions and fails when one is silent. |
<!-- /generated:defects -->

---

## 6. What may go out, by these rules

| What | May it go out? | Why |
|---|---|---|
| The hosted admin workspace, `woodex-os.vercel.app` | **Yes, as a preview** | It says on every screen what it is. It holds the real catalogue as drafts and no other real record, and it keeps nothing that is typed |
| Pull request #36 merged into `main`; Vercel then rebuilds the React shop from it | **Yes — and it went out** on 17 November, 10:22 UTC (merge commit `88ec4cc`) | Released by the owner (DL-022). The shop's test gate and the Laravel suite were green on the branch and are green on the merge commit; both sites, asked from outside after it, answer as they should. The shop's database holds sample products, one lead and no order. The open critical defect is a password: the merge neither causes it nor makes it worse. One thing was not said beforehand: the merge also made live the changes to the shop of 11 to 13 November, its Products menu among them (D-014, T-026; `17-RELEASE-2026-11-17.md`) |
| Pull request #37 merged into `main`; Vercel then rebuilds the hosted admin demo and the React shop | **Yes, when every check of the pull request is green** | The owner's answer of 17 November (DL-026). No file of the React shop is changed; what changes in the demo is listed in report 18 before the merge, as D-014 asks |
| Real products on sale in the React shop | **The owner's step** (T-091) | Publishing is a decision per product. By publishing, the owner accepts D-008 (photographs on the old website) and D-009 (points to check) for those products, or settles them first |
| Real customers and orders on either system: the cutover of gate E | **No** | D-002 is critical and open. For the Laravel admin, gate D has not started |

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

The first report in this form is [`17-RELEASE-2026-11-17.md`](17-RELEASE-2026-11-17.md); the second is
[`18-RELEASE-2026-11-17-SECOND.md`](18-RELEASE-2026-11-17-SECOND.md), written before its merge.
