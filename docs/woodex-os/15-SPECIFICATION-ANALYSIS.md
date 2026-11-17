# WOODEX OS — the specification set against the Laravel admin

**Written:** 2026-11-17, at `d84f487` on `arena/7ecb4f83-woodex-2030` · **Feeds:** [`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md) (the task numbers `T-…` below are lines of that list).

The owner attached eleven documents on 17 November and asked for them to be analysed against the Laravel admin
at `woodex-os.vercel.app/admin`. This is that analysis: for every document, what it requires, what the Laravel
application has today, and the task that closes the gap.

**How the state was established.** From the code at the commit above, not from memory: the navigation registry
was run for an owner account; the tables were listed from a freshly migrated database (106 migrations, 125
tables); the routes were read from `platform/routes/`; the 1,811 test titles in 227 test files were searched
for each acceptance scenario, and the rule behind a scenario was read in the code where no title named it.
"Tested" below means a test exists and passes in the suite (2,244 passed on 17 November); it does not mean the
owner has accepted the module.

**This is the reading at that commit, and it is not rewritten.** Later the same day the admin became
Furniture-only, the real catalogue was loaded, and times were settled (commits `0d8ae53`, `d7d6c29`, `f489a36`).
Where this document and the code now differ — the "Furniture-clean" column of §8, the scenarios and gates of §9 —
the current state is [`16-ACCEPTANCE-AND-RELEASE-GATES.md`](16-ACCEPTANCE-AND-RELEASE-GATES.md), which is checked
against the tests on every push. Test files are named there with their folder; a few are named here without it.

---

## 1. What was attached

| Document | In the repository before? | Result |
|---|---|---|
| 02 System architecture · 03 Database and ERD · 04 API specification · 05 Admin IA and UX · 06 Storefront and Puck Studio · 07 Module specifications · 08 Agent operating instructions · 09 Migration and reuse plan | Yes, in [`spec/`](spec/README.md) since 16 November | **Byte-identical** to the stored copies. Re-read in full for this analysis |
| **10 QA and acceptance** | No | **New.** Stored unchanged in `spec/`. Analysed in §9 |
| **11 Implementation roadmap** | No | **New.** Stored unchanged in `spec/`. Analysed in §10 |
| **12 Decision log** (the owner's own) | No — the programme had started its own log because this one was missing | **New.** Stored unchanged in `spec/`. Reconciled in §11 |
| 01 Master PRD | No | **Still missing.** Document 08 ranks it second in authority (T-023) |

---

## 2. Where the documents and the decisions disagree

Document 08 §2 forbids settling such a difference silently. Each is listed with what is in force.

| # | Difference | In force | Why | Task |
|---|---|---|---|---|
| X-1 | **Which admin.** The owner's log says "Keep existing React storefront and dashboard; reimagine both UIs" (D-003) and "Preserve React storefront and dashboard as separate applications" (D-009). Documents 02, 04, 09 and 11 are written for a React dashboard on a Laravel API. On 16 November the owner chose the opposite for the dashboard: the Laravel admin is the base and its screens are Livewire (programme log DL-001, DL-005; `docs/furniture-2030/01-OWNER-DECISIONS.md` OD-14, OD-15) | **The Laravel (Livewire) admin.** The React dashboard stays in use only until each module is moved. The shop stays React (D-003 and D-009 are unchanged for the shop) | The later explicit decision wins (document 08 §2), and the request of 17 November is to complete the Laravel admin. The owner's log has no date and reads as written before 16 November | **T-001**: the owner confirms, because the log was re-sent after the decision |
| X-2 | **A staff API.** Document 04 §5 lists about 75 `/api/v1` endpoints for the dashboard | **Not built, and not needed**: a Livewire screen is rendered on the server and has no JSON API of its own. `/api/v1` is for the shop and for outside clients only | Follows from X-1 | T-063 |
| X-3 | **Where production runs.** The list of 17 November (version 1) asked the owner to choose between Hostinger and Vercel | **Hostinger shared hosting with MySQL** — the owner's log already says so (D-004, D-005), as does OD-3 ("Hostinger Business plan"). The question is withdrawn. Vercel is the demo | The owner's recorded decision | T-006, T-130… |
| X-4 | **Colours and corners.** Document 05 §5 gives a charcoal sidebar, green `#4F9D21`, 8 px controls | The store's palette (DL-013) and the look of the Reimagined theme (DL-017): light sidebar, green `#629924` / `#52801e`, 4 px controls, 12 px cards. The charcoal sidebar is one setting away | Two later owner messages | T-005 (approval) |
| X-5 | **Smallest text.** Document 05 §5: captions 12 px minimum. The Reimagined theme sets group labels at 10 px, and the admin now has text between 9.5 and 11 px in about twenty places | Not settled. Recommended: 12 px, as the specification says | Readability; an accessibility requirement outranks a look | T-049 |
| X-6 | **Phases and waves.** Document 11 has phases 0–10; the master plan has waves 0–11 and S | The waves, mapped one to one in §10 | The plan was written without document 11 and follows document 09 §5, which document 11 also follows | — |
| X-7 | **Two brands in Sales.** The Laravel code offers "Woodex Interior" and "Woodex Furniture" side by side (it came from the Interior application). The owner's log excludes Interior from this product (D-008), as does document 09 §3 | **Furniture only** — not yet true of the screens | D-008 is "Confirmed — explicit project boundary" | T-030 to T-035 |

---

## 3. Document 02 — system architecture

| Requirement | State in the Laravel admin | Task |
|---|---|---|
| §1 Staff dashboard: React | Changed by decision (X-1): 131 Livewire components, 235 Blade views | T-001 |
| §1 Business back end: Laravel, by domain | Yes: 13 domain folders, 116 action classes, 93 models | — |
| §1 Database: MySQL on Hostinger | The suite runs on MySQL 8.4 in CI. No hosted database exists | T-130 to T-133 |
| §1 Scheduler from cron; database queue | 30 scheduled entries, 8 every minute, two of them queue workers that stop when empty; 14 queued classes. **Nothing calls the scheduler anywhere yet** | T-134 |
| §1 Versioned JSON API | 7 public endpoints: enquiries, basket quote requests, WhatsApp hand-off, categories, media | T-063, T-105 |
| §1, §6 Authentication | Laravel Fortify: session, two-factor (optional), 5 sign-ins a minute. Cookies are Secure, HttpOnly, SameSite=Lax (seen on the live address) | T-022, T-065 |
| §6 CORS set deliberately | An allow-list for the public API paths only | T-160 (a test) |
| §6 Puck draft previews protected | No Puck yet | T-115 |
| §7 Shared-hosting limits: no Node, Redis or WebSockets; PHP request cycle | The design fits: no Redis, no WebSockets, polling. Unproven on the host | T-139 |
| §8 Integrations behind adapters with a disable switch, a connection test, signature checks | Adapters for WhatsApp, Telegram, Google and mail; credentials encrypted at rest; webhook signatures and replays tested. All disconnected for Furniture; their modules are off | T-014, T-118 to T-120 |
| §9 Correlation or request ID | **Missing**: no ID on responses or in the log | T-062 |
| §9 Structured logs | Plain text log | T-062 |
| §9 Failed-job table and retry | Table yes; shown on the Health screen | T-138 |
| §9 Integration delivery log · admin audit log | Yes (`integration_logs`, `webhook_deliveries`, `activity_log`) | T-067 |
| §9 Health endpoint without secrets | `/up`, and a Health screen for staff | — |
| §9 Backup status | Backup jobs are scheduled and monitored; never run on a host | T-137 |
| §9 Error reporting with redaction | **Missing** | T-138 |
| §10 Guardrails 1–8 | Kept: the two systems share no table; totals are computed on the server; the dashboard shows real figures only; nothing was deployed to production data | — |

---

## 4. Document 03 — database

**Principles (§2, §6).**

| Principle | State | Task |
|---|---|---|
| InnoDB, `utf8mb4` | `utf8mb4_unicode_ci`; MySQL's default engine | — |
| **Timestamps in UTC**, shown in the workspace's time zone | **Not met.** `APP_TIMEZONE=Asia/Karachi`, `DB_TIMEZONE=+05:00`: times are stored in Pakistan time. Changing this after real data exists means converting every stored time | **T-061** |
| Money exact, never floating point | Met: integer minor units (`*_minor`) and basis points (`tax_bp`) everywhere | — |
| Currency code on financial records | On quotations, invoices and basket requests. **Not** on payments, bills, expenses, journal lines; `'PKR'` is written into the code in about six places | T-064, T-066 |
| Immutable snapshots of issued documents | Met: frozen quotation versions with a PDF hash; invoice `snapshot` and `pdf_sha256` | — |
| Posted payments are reversed, not deleted | Met: void with a reason reverses the journal entry; a verified payment cannot be undone by rejection | — |
| Foreign keys and indexes | 353 foreign-key definitions, 110 indexes, 81 unique keys in 84 migrations | T-168 (review against real queries) |
| Append-only stock ledger | No stock tables | T-109 |
| No secrets in business tables | Integration credentials are encrypted casts | — |

**Entities (§3).** The specification lists about 120 logical entities; the names differ, the meaning was matched.

| Domain | Present | Present under another shape | Missing |
|---|---|---|---|
| Identity | `users`, `roles`, `permissions`, the three pivot tables, `sessions`, `settings`, `activity_log` | — | Personal access tokens (not needed, X-2) |
| CRM | `leads`, `contacts`, `companies`, `activities`, `tasks`, `site_visits`, `conversations`, `messages`, `assignment_rules` | Lead source is a column; notes are activities | Several contact methods per person with primary and verified flags (fixed columns today) — T-083 |
| Sales | `estimates`, `quotes`, `quote_versions`, `quote_items`, `quote_sections`, `approvals`, `invoices`, `invoice_items`, `payments`, `payment_allocations`, `number_sequences`, `document_designs` | Estimate lines are JSON on the estimate | `tax_rules` (a rate per document today) — T-064, T-087 |
| Commerce | `commerce_products`, `commerce_categories`, `commerce_series`, `commerce_product_media`, revisions and previews for each | Variants are structured data inside the product draft; customers are contacts | `orders`, `order_items`, `order_status_history`, `delivery_jobs`, `installation_jobs`, `warranty_cases`, price lists, carts — T-106 to T-108 |
| Procurement and inventory | `vendors`, `bills`, `bill_items`, `bill_payments` | Supplier invoices are bills | `purchase_orders`, `goods_receipts`, `warehouses`, `stock_ledger_entries`, `stock_reservations`, `stock_transfers`, `inventory_adjustments` and their item tables — T-109, T-110 |
| Production | — | — | All six tables — T-111 |
| Projects | `projects`, `project_milestones`, `project_expenses`, `project_cost_requests`, `project_updates`, `project_handover_items`, `change_requests`, `portal_access_codes`, `vendors` | Tasks are the shared `tasks` | Project members, vendor assignments, milestone dependencies — T-112 |
| Finance | `chart_of_accounts`, `journal_entries`, `journal_lines`, `expenses`, `bills`, `payments`, `accounting_periods`, `bank_accounts`, recurring invoices and expenses | — | — (owner's review: T-013) |
| Website and CMS | `pages`, `page_versions`, `page_templates`, `saved_sections`, `navigation_items`, `theme_tokens`, `redirects`, `master_media_assets` with versions and usages, `content_entries` | These belong to the imported HTML builder: a page is HTML (`canvas_html`), not Puck data | The Puck model of §8: revisions of validated JSON, publish events, schema versions — T-115, T-116 |
| Automation and integrations | `automations`, `automation_steps`, `automation_step_runs`, `automation_sequences`, `automation_enrolments`, `whatsapp_templates`, `canned_replies`, `integration_connections`, `webhook_events`, `failed_jobs` | — | — (all switched off: T-118 to T-120) |

**Migration requirements (§10).** None of the ten steps has been done for real data: no export of the live PostgreSQL schema, no table-to-owner map for its tables (72 by today's count of the schema files; the master plan counted 74), no rehearsal on a clean MySQL. A mapping table for old and new identifiers exists (`legacy_refs`). Tasks T-175 to T-181.

---

## 5. Document 04 — API

| Requirement | State | Task |
|---|---|---|
| §2 Base path `/api/v1`, JSON, UTF-8 | Yes for the 7 endpoints | — |
| §2 Pagination, filter and sort allow-lists | Categories and media lists; no general convention | T-063 |
| §2 Idempotency keys for retryable creates | Basket requests carry a request key and conflict on changed content (tested); a repeated enquiry joins the open lead (tested) | T-063 (as a rule for every create) |
| §2 Correlation ID | Missing | T-062 |
| §3 Envelope `data` / `meta` / `error` with codes | **Not used**: the enquiry endpoint answers `{ok, reference, message}` | T-063 |
| §4 Status codes | 201, 200, 202, 405, 422, 429, 404 are used and tested on the enquiry endpoints | — |
| §5 Resource groups (about 75 endpoints) | Not built for staff (X-2). The shop needs: products and series (read), orders (create, status), published pages, navigation, theme, redirects, forms, warranty cases | T-105, T-106, T-108, T-116 |
| §6 Explicit transitions, never a status patch | Met in the domain layer: one action class per transition (send, approve, accept, void, verify…), each tested | — |
| §7 Server computes totals | Met and tested | — |
| §8 Puck save and publish contract | No Puck yet | T-115 |
| §9 API client requirements | Concern the React shop's client | T-105 |
| §10 Open decisions: money format, throttles, webhook policy | Money: integer minor units. Throttles: set and tested on public forms. Session rather than tokens for staff | T-063 records them |

---

## 6. Document 05 — admin navigation and experience

**Navigation (§2).** 68 destinations in 10 groups are in one registry. For an owner today:

| State | Count | Which |
|---|---:|---|
| Available here | 29 | Dashboard, Approvals, Reports · Leads, Contacts, Tasks & Visits · Quotations, Invoices, Payments, Documents · E-commerce, Products, Categories, Series · Projects, Board, Vendors & costs, Handovers · Ledger & reports, Payables, Income & expenses · Shop customization, Media · Settings, Access & team, Connections, Platform, Audit & activity, Feature status |
| Still in the old dashboard | 13 | Orders, Delivery & Installation, Support & Warranty · Operations board · Suppliers, Purchase Orders, Goods Receipts, Supplier Invoices, Production, Stock Ledger, Warehouses, Stock Transfers, Reservations |
| Built, switched off | 21 | Conversations, Chat Bot · Estimations · Client portal · Automations, Sequences, WhatsApp Templates, Marketing · All pages, Page builder, Blog, Portfolio, Forms, Theme, SEO, SEO agent, Speed, Site health, Website Settings, Redirects, Find & replace |
| Not built | 5 | Site overview, Navigation, Sections & templates · Workspace preferences, Team onboarding |

"Available here" means the screen opens and its tests pass. **None of the 29 is accepted** by the definition of document 07 §15, which ends with the owner's sign-off.

| Requirement | State | Task |
|---|---|---|
| §3 One registry, stable ids, filtered by permission and availability | Met, tested | — |
| §3 Unavailable items hidden; roadmap on a feature-status view; no "Soon" or "Legacy" | Met | — |
| §3 Collapsible groups, the active one open | Met | — |
| §3 Search across title, **keywords and aliases** | Titles only | T-047 |
| §3 Command palette, Ctrl/Cmd + K | Met | — |
| §3 **Pinned or favourite destinations · recently visited** | Missing | T-046 |
| §3 Expanded and compact sidebar; phone drawer with focus kept inside | Met; seen in a browser | T-054 (keyboard tests) |
| §4 Sidebar footer with the person and settings | Missing (the user menu is in the top bar) | T-048 |
| §4 Top bar: trail, search, quick-create, notifications with a count, user menu | Met (slice 1.4) | — |
| §4 **Help or support entry** | Missing | T-046 |
| §5 Tokens | Superseded (X-4); contrast is tested for three tones | T-005 |
| §5 Caption 12 px minimum | Not met in about twenty places (X-5) | T-049 |
| §6 Shared components: buttons, fields, cards, tabs, badges, dialogs, toasts, page headers, upload, media picker, document preview | Exist as `x-admin.*` components, restyled to the theme | T-050 (a reference screen) |
| §6 **Tables with sort, filters, pagination, selection and bulk actions** as one standard | Leads has all of it; other lists are hand-written tables (28 of 131 components paginate) | T-041 |
| §6 States: empty, loading, error, no permission, **offline** | Empty and no-permission exist; loading is a spinner attribute, no skeleton; no offline notice | T-041, T-051 |
| §7 Dashboard: business snapshot, **sales funnel, finance, commerce and operations, projects**, activity | Present: period figures, six live queues, needs-attention, pipeline, new leads chart, recent activity. Missing: funnel with conversion rate, overdue ageing and payables due, project health and milestones; charts do not state currency and definition | T-044 |
| §8 List, detail, create/edit and approval patterns | Followed unevenly | T-045 |
| §8 **Unsaved-change warning** | No warning on leaving a form anywhere. Six editors (page builder, series, category, product, estimate, rates) say on the screen that edits are unsaved | T-043 |
| §8 An approver cannot approve their own request | Met for quotations (the owner excepted), tested | T-081 (other approval types) |
| §9 Phone: drawer, stacked figures, **table-to-card for key workflows** | Drawer and stacking met; tables scroll sideways inside their box | T-042 |
| §9 Focus ring, labels, contrast, no colour-only status | Met: 0 unlabelled fields, 0 small targets, contrast tested; pills carry text | T-054 |
| §10 Acceptance | Not yet declared | T-005 |

---

## 7. Document 06 — storefront and Puck Website Studio

Nothing of Puck exists in the Laravel admin. What exists is the imported HTML-file builder (18 destinations,
switched off), which document 09 §2 calls "not a compatible replacement". The live shop's pages are edited in
the React dashboard's typed-block editor, which remains the authority.

| Requirement | State | Task |
|---|---|---|
| §4 One versioned component registry driving editor, renderer, server validation and preview | Missing | T-115 |
| §5 Editor workflow, 12 steps | Missing | T-116 |
| §6 Lifecycle draft → in review → approved → published; publishing is its own permission | The pattern exists for categories, series, products and the shop page (immutable drafts, preview, explicit publish, tested); not for pages | T-116 |
| §7 Data model: revisions, templates, saved sections, menus, theme history, SEO, redirects, media | Tables exist for the HTML builder; media is the strongest part (assets, versions, usages) | T-116 |
| §9 Media library: type and size checks, alt text, no executables, where-used before deletion | Met and open today (Media screen); tested | — |
| §10 SEO per page, sitemap, redirects | Screens exist, switched off | T-116 |
| §11 **Ten prototype gates** before any broad build | None started | **T-115** (gate C) |
| §12 Mapping table from today's blocks to Puck components; representative pages first | Not started | T-117 |

---

## 8. Document 07 — modules

For each module: is it built in Laravel, is it switched on, is it free of Interior content, is it tested, is
there a browser journey, has the owner accepted it. "Journey" means a step of `scripts/os-journeys.mjs`.

| Module (§) | Built | On | Furniture-clean | Tests | Journey | Accepted | Main gaps | Task |
|---|---|---|---|---|---|---|---|---|
| Dashboard (1) | Yes | Yes | Line filter offers Interior | Yes | Opens | No | Sections of document 05 §7; widget states | T-044 |
| Approvals (2) | Quotations only | Yes | Yes | Yes | No | No | One queue across domains; filters by age and amount; requester notified | T-081 |
| Reports (3) | Yes | Yes | Line filter | Yes | No | No | Role-filtered catalogue, definitions, export audit, queued export | T-082 |
| Leads (4) | Yes | Yes | **No**: greeting says "Woodex Interior"; Interior fields | Yes | Create, search, export, import | No | Shop forms still write to Node | T-030, T-031, T-100 |
| Contacts (4) | Yes | Yes | No (greeting) | Yes: duplicates refused and merged, consent kept | No | No | Several contact methods with flags | T-083 |
| Tasks & Visits (4) | Yes | Yes | No (visit message) | Yes | Task created | No | — | T-031 |
| Estimations (5) | Yes | **Off** | No: Interior rate book | Yes | No | No | Furniture rates | T-085 |
| Quotations (5) | Yes | Yes | **No**: brand chooser, Interior default, Interior types | Yes: totals, role limits, approval, frozen versions, revisions | Draft and PDF | No | Furniture templates and terms; product lines | T-003, T-030, T-086 |
| Invoices (5) | Yes | Yes | No (default line; public page says "INTERIOR STUDIO") | Yes: issue once, void by reversal, ageing | No | No | Tax rules; credit notes | T-032, T-087 |
| Payments (5) | Yes | Yes | Yes | Yes: about thirty tests on allocation, proof, verification, receipts, dues | No | No | — | T-080 |
| Documents (5) | Yes | Yes; **issuing blocked** until terms and signers are confirmed | Brand selector | Yes | Statement PDF | No | Owner's terms | T-003, T-084 |
| Store, Products, Categories, Series (6) | Yes (built for Furniture, 11–13 Nov) | Yes | Yes | Yes | No | No | Real catalogue not imported; 7 readiness blockers | T-088, T-104 |
| Shop design (6) | Yes | Yes | Yes | Yes | No | No | — | T-116 |
| Orders · Delivery & Installation · Support & Warranty (6) | **No** | — | — | — | — | — | Whole modules; work in Node today | T-106 to T-108 |
| Suppliers (7) | Vendor master | Yes, under Vendors & costs | Yes | Yes | No | No | Merge with the Node suppliers | T-110 |
| Purchase orders · Goods receipts · Supplier invoices (7) | **No** (bills exist) | — | — | — | — | — | Whole modules | T-110 |
| Production (7) | **No** | — | — | — | — | — | Whole module | T-111 |
| Stock ledger · Warehouses · Transfers · Reservations (7) | **No** | — | — | — | — | — | Whole modules | T-109 |
| Ledger & Reports · Payables · Income & Expenses (8) | Yes | Yes | Line option | Yes: balanced entries, reversal, periods, trial balance | No | No | Accounting owner's review | T-013, T-113 |
| Projects (9) | Yes | Yes; portal off | Case-study text | Yes | No | No | Members, dependencies; portal isolation | T-112 |
| Inbox (10) | Yes | **Off** | No | Yes | No | No | Furniture channels and consent | T-118 |
| Automation (11) | Yes | **Off** | No | Yes | No | No | Dry run; Furniture wording | T-119 |
| Marketing (12) | Social planner | **Off** | No | Yes | No | No | Campaigns, UTM attribution | T-120 |
| Website Studio (13) | HTML builder, not the target | **Off** | No | Yes | No | No | Puck | T-115, T-116 |
| System and Workspace (14) | Yes | Yes | Invitation e-mail says "Woodex Interior" | Yes | Sign-in, sign-out, 403, 404 | No | Currency, time zone and tax settings; preferences; onboarding; release notes | T-031, T-056, T-064 |

**§15, the completion definition.** No module meets all ten points. The points most often missing: 8 (browser
acceptance), 9 (a migration and reconciliation plan with real data) and 10 (the owner's sign-off).

---

## 9. Document 10 — QA and acceptance (new)

**Test layers (§2).**

| Layer | State | Task |
|---|---|---|
| Unit | 121 tests: money, phone numbers, quotation arithmetic, design tokens. No Puck validators (no Puck) | T-115 |
| API feature | The public endpoints are tested (throttles, replays, validation). The error contract is not the specification's | T-063 |
| Database: migrations from a clean MySQL | In CI on MySQL 8.4, every push | — |
| Database: rollback, precision, immutability | Tested for payments, receipts, journals, frozen documents | — |
| Database: stock invariants | No stock module | T-109 |
| Screens (the specification says "frontend") | Livewire component tests for every open screen; route access by role; search | T-041 (states) |
| **Browser end to end: 11 flows** | 3 exist in part (lead capture by staff, draft quotation, permission denied). Missing: lead assignment and conversion; estimate to quotation; approval and issue; invoice and payment allocation; order creation; purchase order and receipt; stock transfer; project task and handover; page edit to publish | **T-165** |
| **Operational: Hostinger staging, cron, queue, upload limits, backup and restore, failed jobs, load** | None done; staging has never started | T-130 to T-139 |

**Critical acceptance scenarios (§3).**

| Scenario | In Laravel | Evidence | Task |
|---|---|---|---|
| A public enquiry creates one lead | Rule and test | `PublicLeadApiTest`: a repeat enquiry joins the open lead. The live shop's forms still post to Node | T-100 |
| Duplicate detection finds the existing contact | Rule and test | `ContactsTest`: a duplicate phone in any format is refused and points to the contact; merge moves leads and timeline | — |
| Lead assignment is permission-checked and audited | Rule and test | `LeadShowTest`, `LeadsIndexTest`; `AssignLead` writes an activity | T-165 (in a browser) |
| Estimate totals computed on the server | Rule and test; module off | `EstimatesTest` | T-085 |
| Approval required by role and threshold | Rule and test | `QuoteSendingTest`: sending above the role's discount limit is blocked until a manager approves exactly those figures | T-004 (the real limits) |
| A rejected quotation cannot be converted | Rule and test | `QuotationSafetyTest`: accepting a declined, expired or void quotation is refused and no project or invoice is created | — |
| An issued document stays as issued after a price change | Rule and test | `QuotesTest`: rate-book lines are copied; `DocumentLibraryTest`: the frozen PDF is served | — |
| A payment cannot exceed its allocation | Rule and test | `InvoiceScreenTest`: refuses to collect more than is outstanding; `AllocatePayment` rejects an over-allocation; the rest is kept as credit | — |
| Partial payments give the right balance | Rule and test | `PaymentsTest`: a payment is spread over the oldest invoices and the rest kept as credit; `PaymentIntegrityTest` | — |
| A reversal needs permission and a reason | Rule and test | `InvoiceScreenTest`: void with a reason, by reversal; `PaymentsTest`: only accounts may confirm | — |
| Reports reconcile to their sources | Partly | `PayablesTest`: the trial balance balances after bills and payments; a payment-to-journal reconciliation report exists | T-082 |
| Currency and tax are explicit | Partly | Currency and tax rate are stored on quotations and invoices; no tax rules, no currency setting | T-008, T-064 |
| Goods receipt posts once · duplicate receipt is idempotent · transfer makes matched movements · reservation keeps physical stock · adjustment needs a reason and approval | **No module** | These rules and their tests exist in the Node system and are ported first | T-109, T-110 |
| Eight Puck scenarios (editor access, component allow-list, revisions, renderer parity, publish, rollback, canonical product block, media in use) | **No module** | — | T-115 |

**Security tests (§4).**

| Test | State | Task |
|---|---|---|
| Direct access without sign-in · cross-role access | Tested: every admin route against each role's permissions | T-060 (after the role merge) |
| Cross-client portal access | Tested on the client pages of quotations and invoices and on payment claims (another client's record is refused). The project portal is switched off | T-112 |
| Mass assignment | **No explicit test** | T-160 |
| CSRF, CORS, session | CSRF: a form without its token gets 419 (tested, and checked on the live address). CORS and session: no explicit test | T-160 |
| Rate limits on public forms and sign-in | Public forms tested; sign-in limit set (5 a minute), not tested | T-160 |
| Upload type and content | Tested on logos (HTML and SVG refused), lead files, category images, media | — |
| Webhook signature and replay | Tested (WhatsApp, Telegram, Google) | — |
| SQL injection and XSS | The framework's query builder and Blade escaping; one test on escaped preview content. No test on stored rich text | T-160 |
| Sensitive data in logs and errors | Tested per integration (redacted provider answers, hashed keys); no general test | T-160 |
| Puck HTML or script injection | No Puck | T-115 |
| Direct object references | Tested: a sales person cannot open another's lead; notifications are private. No sweep over every record screen | T-161 |

**Experience and accessibility tests (§5).** Automated counts exist (labels, names, target sizes, contrast, phone
widths). Missing as tests: sidebar by keyboard, the command palette's open-search-select-Escape, focus in
dialogs and drawers, error announcements — T-054.

**Performance checks (§6).** Lists paginate (37 calls in 28 components). Five test files bound a query count. Not
done: a query budget per list screen, a run with representative volumes, an index review, the shared host's
limits — T-168, T-139.

**Release gates (§7).**

| Gate | State | Closed by |
|---|---|---|
| A — design foundation | Tokens, shell and theme are built and tested; **not approved by the owner**; no component reference screen; accessibility reviewed by tools only | T-005, T-050, T-054 |
| B — module readiness | No module has passed (see §8) | per wave |
| C — Puck readiness | Not started | T-115 |
| D — Hostinger readiness | Not started; the staging live test has failed twice (13 November) | T-130 to T-139 |
| E — production cutover | Not started | T-121, T-181 |

**Severity and evidence (§8, §9).** The programme has no defect register with severities, and its reports
follow document 08 §11 without the additions of §9 here (device coverage, defects by severity, reconciliation,
backup status, performance, owner approval) — T-169, T-170.

---

## 10. Document 11 — roadmap (new)

| Phase | Master-plan wave | State | Open deliverables | Task |
|---|---|---|---|---|
| 0 Discovery and baseline | Wave 0 and the audits | Done, except two items | Hostinger capability checklist **verified on the plan**; the feature register exists (Feature status) | T-006 |
| 1 Product and design foundation | Wave 1 | Mostly done | **Branding cleanup from Interior labels**; **the owner approves sidebar, tokens and shell** | T-030 to T-035, T-005 |
| 2 Dashboard shell | Wave 1 | Sidebar, top bar, palette, drawer, role-aware menu done | **Favourites and recent screens; dashboard widget framework**; keyboard tests | T-046, T-044, T-054 |
| 3 Core sales vertical slice: lead → contact → estimate → quotation → approval → invoice or order | Waves 3 and 4 | Every step but the estimate (off) and the order (missing) exists and is tested in isolation | **The slice proven end to end on staging; one writer** | T-080, T-100 to T-103 |
| 4 Commerce and operations | Waves 5 to 8 | Catalogue screens built; the rest missing | Everything from orders to production | T-104 to T-111 |
| 5 Finance and projects | Wave 9 | Built, not reviewed | The finance owner validates; client isolation tests | T-013, T-112 to T-114 |
| 6 Storefront reimagination | Outside the admin | The shop is a React application | Tracked in `docs/furniture-2030/` | — |
| 7 Puck prototype | Wave 10, first half | Not started | All of it | T-115 |
| 8 Website Studio rollout | Wave 10 | Not started | All of it | T-116, T-117 |
| 9 Automation, inbox, integrations | Wave 10 | Built for Interior, off | Furniture adaptation; consent; dry run | T-118 to T-120 |
| 10 Hostinger production migration | Waves S and 11 | Not started | All of it | T-130 to T-142, T-121 |

**Priority model.** P0: security, data integrity, sign-in, deployment viability, the core sales flow. P1:
commerce, procurement, stock, projects, finance. P2: Puck rollout, automation, marketing, advanced reports.
P3: convenience. The to-do list carries a priority on every line.

---

## 11. Document 12 — the owner's decision log (new), reconciled

**Confirmed decisions.**

| ID | The owner's log | Programme | Note |
|---|---|---|---|
| D-001 | One WOODEX Agency OS for the furniture business | Same | — |
| D-002 | Reuse generic Laravel modules, remove Interior dependencies, rebuild what is missing | Same (DL-001) | Interior removal is incomplete: T-030 to T-035 |
| D-003 | Keep the React storefront and dashboard; reimagine both | **Changed for the dashboard** on 16 November (DL-001, DL-005) | X-1; T-001 |
| D-004 | Production database: MySQL on Hostinger | Same | — |
| D-005 | Hostinger shared hosting | Same; OD-3 names the plan: Business | T-006 |
| D-006 | Puck for the page builder; technical gate pending | Same (wave 10) | T-115 |
| D-007 | Laravel owns page persistence, permissions and publishing | Same | — |
| D-008 | No Interior branding, data or workflows | Same in intent; **not yet true of the screens** | T-030 to T-035 |
| D-009 | Storefront and dashboard stay separate applications | Changed for the dashboard, as D-003 | T-001 |

**Findings F-001 to F-010** agree with the master plan §3. F-008 (the React sidebar says "Woodex Interiors")
has its counterpart in the Laravel screens (T-030 to T-035).

**Provisional assumptions.**

| ID | Assumption | What the code does today | Task |
|---|---|---|---|
| P-001 | Currency is PKR | Assumes PKR | T-008 |
| P-002 | Tax rules are configurable | One rate per document; no rules | T-008, T-064 |
| P-003 | Payments recorded by hand at first | Yes; OD-4 says cash on delivery, no gateway | answered |
| P-004 | Client portal included | Built, switched off | T-015 |
| P-005 | Quotation approval by role | Yes: a discount limit per role and a minimum margin | T-004 (the numbers) |
| P-006 | One organisation | Yes | answered |
| P-007 | English admin | Yes | answered |
| P-008 | Keep the domain and addresses | A redirects screen exists, off | T-016 |

**Open decisions.**

| ID | Question | Known so far | Task |
|---|---|---|---|
| O-001 | Hostinger plan and limits | "Business" (OD-3). Limits read from Hostinger's documentation on 16 November (`docs/furniture-2030/04-HOSTINGER-BUSINESS-PREFLIGHT.md`): 120 processes, requests stopped after 120 s, no background workers, MySQL included, mail capped. Not verified on the account | T-006 |
| O-002 | PHP, extensions, SSH, Composer, cron | PHP 8.4.1 or newer by the owner's report; an SSH check job exists and has never run, for want of two secrets | T-006 |
| O-003 | Domains for shop, admin, API | Open. The admin can run on its own host name (`ADMIN_DOMAIN`) | T-007 |
| O-004 | Online payment at checkout | No at launch (OD-4) | answered |
| O-005 | Delivery and installation as separate workflows | Open | T-010 |
| O-006 | Variants, options, stock policy | Open. Variants exist as structured data in a product draft | T-011 |
| O-007 | Warehouses and branches | Open | T-012 |
| O-008 | Accounting policy, chart of accounts | A generic chart is installed; OD-5 limits launch to invoices, receipts and payables | T-013 |
| O-009 | WhatsApp and Telegram providers | A Furniture-only number and e-mail (OD-6); no credentials | T-014 |
| O-010 | Who approves quotations, discounts, expenses, purchase orders, publishing | Open. Quotations: by permission, with a discount limit per role | T-004 |
| O-011 | "The actual AI agent failure example and task" | Open; a question to the owner, not a build task | T-024 |
| O-012 | A staging account for visual checks | Yes for the Laravel admin: the demo, with one account per role | answered |

**The change procedure** of the owner's log (date, old and new decision, affected documents, approval, one
reviewable change) is what the programme log already does row by row. The owner's file is stored unchanged;
changes are recorded in [`12-DECISION-LOG.md`](12-DECISION-LOG.md), which names the owner's ID it changes.

---

## 12. Documents 08 and 09 — working rules and migration

**Document 08.** Followed: read before edit; smallest safe change; tests before the claim; the report format of
§11; no production action without approval; one writer. Three gaps:

| Rule | Gap | Task |
|---|---|---|
| §2 The PRD is the second source of truth | Not supplied | T-023 |
| §4 No Interior content (with D-008) | Present in open screens | T-030 to T-035 |
| §10 Browser smoke and visual checks "where available" | Available, run by hand; not in CI | T-167 |

**Document 09.**

| Section | State | Task |
|---|---|---|
| §3 Reuse, refactor, rebuild, **exclude** | Reuse and refactor under way; the exclusion of Interior is incomplete | T-030 to T-035 |
| §4 Ten migration rules | Kept so far, because nothing has moved: no module has switched its writer | T-100 onwards |
| §5 Sequence | The waves follow it, with stock before production | — |
| §6 Per-module cutover checklist (14 points) | Not yet used; becomes the last section of each wave report | T-170 |
| §7 Reconciliation: counts, statuses, relations, duplicates, totals, balances | No report built | T-179 |
| §8 CMS migration | Not started | T-117 |
| §9 Host migration, 13 steps | Step 1 half done (plan named); steps 2 to 13 open | T-130 to T-142, T-121 |
| §10 Rollback | Stated in the plan; to be written per cutover | T-121 |
