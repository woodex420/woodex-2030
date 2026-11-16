# Launch plan

**Goal.** Replace the PHP shop on woodexfurniture.pk with this platform: the storefront for customers and the dashboard for staff, running on the owner's Hostinger Business plan, taking orders with cash on delivery.

**Starting point.** The code covers most of the business already, and its own gate is green. The audit in [02](02-AUDIT-FINDINGS.md) shows what the gate does not see: defects on PostgreSQL, two broken screens, owner-only access to five modules, and a demo catalogue. The plan fixes what exists before adding anything.

No phase authorises the next. Each one ends with a report and waits for the owner.

## 1. What launch includes

| In the launch | Not in the launch |
|---|---|
| Storefront with the real, approved catalogue | Online payment gateway |
| Checkout with cash on delivery (OD-4) | Bank transfer |
| Quotations, orders, delivery and installation | Full accounting: ledger, credit notes, payment schedules (OD-5) |
| Invoices, receipts and payables | Multi-company tenancy |
| CRM, inventory, procurement, production, support | WhatsApp provider API (click-to-chat stays) |
| E-mail notifications through SMTP | Automation and AI features |
| Staff roles that match the real team | A versioned `/api/v1` with OpenAPI documentation |
| Redirects from the old shop's addresses | Customer and order history from the old shop (OD-7) |

Items on the right stay possible later; none is designed out.

## 2. Phases

Sizes are rough working effort, revised at the start of each phase. They assume owner decisions arrive when asked for.

| Phase | Purpose | Rough size | Exit test |
|---|---|---|---|
| 0 | Close the security exposure — **open**: the document is corrected; waiting for the owner to confirm the password change | Hours, owner | The production password is changed and the document corrected |
| 1 | Make what exists correct — **delivered 2026-11-16**, see the [report](06-PHASE-1-REPORT.md) | 1–2 weeks | Module tests pass on both databases; every dashboard menu screen renders; the migrator loses no rows |
| 2 | Real catalogue | 2–3 weeks | The owner signs the product list on staging |
| 3 | Sell and operate | 2–3 weeks | A cash-on-delivery order runs from checkout to receipt, with each step done by the right staff role |
| 4 | Hostinger staging | About 1 week | The checklist in [04](04-HOSTINGER-BUSINESS-PREFLIGHT.md) §4 passes |
| 5 | Cutover | Days | Separate owner approval; old addresses redirect; search engines allowed |

### Phase 0: security

Owner actions from [01](01-OWNER-DECISIONS.md): change the production owner password (Q-1), decide repository visibility (Q-2), and decide whether search engines may see the demo (Q-11). The agent then removes the credential line and tightens the secret scan as part of Phase 1.

### Phase 1: make what exists correct

Approved by the owner on 2026-11-16 (OD-9) and delivered the same day. The table below is the plan as approved; the [report](06-PHASE-1-REPORT.md) records the result of each item and eight further defects fixed on the way. It changed no schema and added no feature.

| # | Work | Finding | Done when |
|---|---|---|---|
| 1.1 | Return the generated id for every insert on PostgreSQL | F-02 | The module harness shows no id-related failure, and a new test fails if any table is read back without it |
| 1.2 | Fix the remaining PostgreSQL-only failures | F-03 | Expense, meeting, ticket, stock adjustment, journal memo and warranty date checks pass on PGlite |
| 1.3 | Run every module test on both databases in the gate and in CI | F-02 | One command runs each suite on SQLite and on PGlite; tests that read the SQLite file directly go through the adapter instead |
| 1.4 | Fix the migrator | F-04 | Source and target row counts match for all 71 tables with and without `--truncate`; a test fails on any difference; the cut-over runbook matches the tool |
| 1.5 | Fix the Purchase Orders and Production screens, and the error screen's buttons | F-05 | Both screens render; "Go to Overview" leaves the error screen; the products response has one shared type |
| 1.6 | Add a screen check to the gate | F-05 | CI signs in and loads every dashboard menu route; a screen that shows the error boundary fails the build |
| 1.7 | Remove the credential from the document and extend the secret scan to catch credentials written as prose | F-01 | The scan fails on a planted example and passes on the repository |
| 1.8 | Small fixes: live-status label, the empty-id request, the high-severity dependency advisories | F-12, F-14 | Each verified in the browser check or by `npm audit` |

**Exit.** The full gate in [`../../AGENTS.md`](../../AGENTS.md) §3 is green, plus the three new gates from 1.3, 1.4 and 1.6. The tracker's Verified marks then hold on both databases.

**Will not change.** No table or column, no route path, no production data, no deployment.

### Phase 2: real catalogue

- Extend the product model with SKU, variants, a draft or published state and whole-rupee prices, through the migration ledger ([05](05-LIVE-SITE-AND-CATALOGUE.md) §3).
- Crawl the live product list again and build the import with the cleansing rules the owner approves ([05](05-LIVE-SITE-AND-CATALOGUE.md) §4).
- Remove invented ratings, review counts and placeholder stock from the storefront (F-07).
- Give each product its own images.

Needs from the owner: Q-5 and the cleansing rules. Exit: the import report and the staging catalogue are signed by the owner.

### Phase 3: sell and operate

- Cash on delivery as an explicit order path: placed, confirmed, dispatched, delivered, paid, with a receipt.
- E-mail for order, quotation and password-reset messages through SMTP (Q-7); WhatsApp as click-to-chat.
- Access rules for the real team, so inventory, delivery, support and the rest are not owner-only (F-06, Q-6).
- One rounding rule for money, with tests, before real orders carry tax or discounts (F-08).
- Storefront content from the owner's written facts: warranty, delivery, returns, company details (Q-4).
- Furniture branding in place of the Interior strings (F-14); accounting screens hidden if the owner confirms (Q-8).

Exit: one scripted run takes a cash-on-delivery order from checkout to receipt, switching staff role at each step, on both databases.

### Phase 4: Hostinger staging

Deploy to a temporary address on the Business plan and run the checklist in [04](04-HOSTINGER-BUSINESS-PREFLIGHT.md) §4. Choose the database from measurements (Q-10). Set up scheduled off-site backups and perform one restore. Rewrite the Hostinger section of [`../DEPLOYMENT.md`](../DEPLOYMENT.md) from what was done.

Needs from the owner: Q-12.

### Phase 5: cutover

Production work, with its own approval. Build the redirect map from a full crawl and Search Console ([05](05-LIVE-SITE-AND-CATALOGUE.md) §5), back up the PHP shop, move the domain, open the site to search engines, and watch errors and orders for the first days. The backup of the old shop is the rollback.

## 3. How this fits the existing roadmap

[`../agency-os/04-ROADMAP.md`](../agency-os/04-ROADMAP.md) phases A to D (integrity, stock ledger, operations, support) are built. Phase 1 here makes them hold on PostgreSQL and in the browser. Its phase E (tenancy) stays deferred by A-2. Its phase G (payments) is answered for launch by cash on delivery; a gateway comes later.

From the [tracker](../WOODEX_2030_MASTER_IMPLEMENTATION_TRACKER.md) queue: the e-mail adapter (item 14) and a first step on roles (item 13) move into Phase 3; scheduled off-site backup (item 16) into Phase 4; tenancy (12), provider messaging (18), the payment gateway (19) and queues (20) stay after launch.

## 4. How each phase is reported

At the start: goal, scope, files and modules, risks, dependencies, what will not change, and the exit test. At the end: what was done, files changed, tests run with results, defects found, decisions taken, and the evidence. Code reaches `main` only through a pull request whose CI run is green.
