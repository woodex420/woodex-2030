# Dashboard information architecture and Sales CRM plan

**Prepared:** 2026-10-05
**Updated:** 2026-10-06
**Status:** Phase 0 navigation approved/completed; Phase 1 storefront-intake slice delivered with follow-up work open; Phase 2 persistent e-quotation foundation in progress (not accepted complete). API regression baseline is 84/84 on SQLite and 84/84 on PGlite; browser-driven quote acceptance remains unverified.
**Scope:** Dashboard routes and maturity, proposed business-funnel navigation, and the priority furniture sales flow from enquiry through quote, invoice, payment, and fulfilment.
**Consolidated next-step checklist:** `docs/product/NEXT-PHASE-DELIVERY-PLAN-2026-10-06.md`.

## 1. Recommendation

Keep the existing Woodex-2030 design system and current product identity. Reorganize the navigation around the customer journey rather than expanding the current long **Sales** list. Improve the sales path in this order:

1. **Capture and identify** an enquiry, its source, contact details, and consent; attach it to one client record.
2. **Work the relationship** in the shared inbox and CRM; preserve returning-client history, assign an owner, schedule follow-ups, and book site/showroom visits.
3. **Prepare and track a complete e-quotation** with structured furniture options, a reusable template, revisions, approvals, and a client-ready document.
4. **Convert an accepted quotation to an invoice**, record payments and balances in PKR, then link the sale to an order and fulfilment stages.
5. After the sales loop is accepted, continue through the other dashboard groups and prototypes.

The navigation regrouping was approved and delivered; Phase 1 intake work began on 2026-10-06. At the user's direction, Phase 2 persistent e-quotation work began on 2026-10-06 while Phase 1 follow-up items remain open. This starts Phase 2 only; it does not mark its acceptance gate complete or imply hosted production integrations or real WhatsApp send/receive.

## 2. Reference review — read-only

### `woodex420/woodex`

The public repo's root React app has a straightforward furniture-admin menu: Dashboard, Products, Customers, Quotations, Orders, Inventory, Deliveries, Returns, Showroom, WhatsApp, Analytics, and Settings. Its route tree and page source provide useful **feature and grouping patterns**:

- Quote list/detail actions read quotation records and line items, update status through a named Supabase Edge Function, and request a PDF through another named function.
- The WhatsApp page has conversation/campaign/analytics/journey tabs and calls a Supabase Edge Function to load and send messages.
- Orders, deliveries, returns, and customers have separate management screens.
- The repo's root `SettingsPage` is a development placeholder, despite Settings appearing in navigation.
- The showroom page reads products, but its current “Add to Quote” control has no action handler, and the “360° View” shown in that component is an image, not evidence of a functioning 360° viewer.
- The accompanying architecture document describes a broader target schema (product variants/customizations, customer interaction history, quote line items, message records, etc.). That document is a design reference, not proof that every integration is deployed.

**Verification limit:** this was source inspection only. A Supabase connection, Edge Function deployment, WhatsApp provider/webhook, live database, and real message delivery were not tested. Credential/configuration files were not opened. No code or records were copied into Woodex-2030.

### `woodex420/e-quotation-` and its public preview

The repo is useful as a visual/functional reference for a quotation builder, line items, templates, invoices, and printing. Its source stores most products, clients, quotes, templates, and invoice state in browser `localStorage`; saving a quote also attempts a `/api/quotes` request, but that alone does not prove a durable shared server database. The hosted preview's dashboard metrics/status claims are unverified sample display values, not Woodex-2030 production data.

**Plan implication:** use the workflow as a checklist only. Woodex-2030 must keep its own API/database as the source of truth; do not copy client data or assume the demo's “API/database/backup” labels mean production persistence.

### Woodex Interiors public site and GitHub reference

The [1](https://woodex.com.pk/) public Woodex site, including its [contact page](https://woodex.com.pk/contact/) and [estimator](https://woodex.com.pk/estimator/), presents a useful intake sequence: brief/enquiry → site visit or drawings review → concept/quantities → build/handover. Its contact/estimate experience asks for details such as name, phone, email, project type, and space, and offers call/WhatsApp contact. The public surface does **not** establish where a submitted enquiry is stored or whether it reaches this CRM. The `woodexinterior` GitHub source inspected for this audit is a static marketing site; its form handler is unconfigured and falls back to a local enquiry-brief download.

**Plan implication:** borrow the *funnel idea*—not site text, code, customer records, or integrations. Treat the interiors product as a separate sister product. Connect a site to this CRM only through a separately verified, approved API/webhook integration.

## 3. Current dashboard: navigation and page inventory

### Existing sidebar groups

| Current group | Current links |
|---|---|
| Workspace | Overview |
| Sales | CRM, Clients, Sales & Quotations, Invoices & Payments, Ecommerce, Products, Orders, Website / CMS, Theme Studio, Marketing, Omnichannel |
| Delivery | Tasks, Projects, Production, Delivery |
| Insights | Analytics, UI Components, Settings |

The Sales group currently mixes lead handling, communication, commerce, finance, and website tooling. Delivery mixes follow-up tasks with fulfilment. There are also editor/detail routes that do not appear in the sidebar. The proposed map in §4 separates these journeys without changing the visual theme.

### Route-by-route completeness

**Status terms:**
- **API-backed in this workspace** means the page calls this repo's `/api` and has a working application workflow; it does not mean a hosted production database or external provider has been verified.
- **Mixed** means a useful live/API path exists alongside mock or local-only elements.
- **Prototype/mock** means key data or actions are sample/local UI rather than a completed shared workflow.
- **Placeholder** means the route is intentionally not implemented yet.

| Route / page | Current placement | Current status and what is present | Main gap / next step |
|---|---|---|---|
| `/` — Overview | Workspace | **Mixed.** `/api/stats` and recent leads are read from the workspace API; some revenue series/summary and trend values are imported from mock data. | Replace all demo trend data with reconciled API-derived figures; label any remaining prototype metric. |
| `/crm` — CRM / lead board | Sales | **API-backed.** Leads can be created and moved through the current `New → Qualified → Meeting → Quotation → Won/Lost` stages. | Add the agreed `Contacted` and optional site-visit states, next follow-up, contact/consent/source completeness, and returning-client context. |
| `/clients` — client list and 360 | Sales | **API-backed, partial.** Search, client creation, a 360 timeline, quote/order/invoice/task context, and a human merge-review queue exist. | No distinct company/account workflow; the “Keep separate” duplicate-review action is currently only a toast, not a saved decision. Add preferred contact/consent and new-versus-returning lifecycle. |
| `/omnichannel` — shared inbox (`?c=` selects a thread) | Sales | **API-backed, manual-channel workflow.** Threads, messages, templates, assignment, disposition, priority, and manual inbound logging exist. `WA →` records a handoff and opens `wa.me`. | This is **not** a WhatsApp Business send/receive integration and is not a public website live-chat widget. Keep replies marked manually logged until a provider/webhook is configured. |
| `/tasks` — follow-ups | Delivery | **API-backed.** List, open/done board, due-date calendar, owner/priority, and client-linked tasks exist. | The task UI does not deliver reminder notifications or model visits as scheduled appointments. Projects includes a mock Site Visit tab, but it is not an API-backed appointment workflow. Add a visit record/calendar workflow; retain tasks for follow-ups. |
| `/quotations` — quote list | Sales | **API-backed, Phase 2 foundation.** Lists saved quotes; quote references open the persistent record and its version history. | Add search/filtering, templates, and a complete approval/client-response event trail. |
| `/quotations/new` — e-quotation builder | Hidden child route | **API-backed, Phase 2 in progress.** Saves and reloads structured customer/site data, lead links, line options/dimensions, dates, PKR pricing components, terms, server-calculated totals, revisions/audit history, and an HTML preview/print flow. New drafts are blank and start without unconfirmed pricing or terms. | Add approved templates, line-level discount/manual override audit, full approver/sent/viewed/client-acceptance events, and validate a client-ready PDF/share flow. Confirm business tax/pricing/terms rules before enabling policy defaults; Phase 2 acceptance is not yet complete. |
| `/invoices` — invoices and payments | Sales | **API-backed, partial.** Issue from quote, invoice details, manual payment recording, paid/outstanding balance, status filters, and print/PDF are present. | No payment gateway or bank reconciliation is verified. The print layout contains sample company/contact/bank/tax details that must be replaced before client use. Build transaction history, due/aging, and monthly PKR reporting on reconciled data. |
| `/ecommerce` — commerce overview | Sales | **API-backed.** Reads product, order, and invoice data; vendors/purchasing are explicitly marked unconfigured. | Keep vendor/purchasing as prototype until its own data model is approved; avoid presenting it as operational. |
| `/catalog` — products/materials | Sales | **API-backed.** Product list and material data use the workspace API; product CRUD is part of the current project work. | Keep the catalog as the source for quote options, series, variants, and pricing rules; add structured customization data only after its schema is agreed. |
| `/catalog/new` — create product | Hidden child route | **API-backed editor route.** | Verify full field validation and media/product persistence as part of the existing catalog QA; not a separate sidebar item. |
| `/catalog/:id` — product detail | Hidden child route | **API-backed detail route.** | Keep detail linked from catalog and commerce; no new top-level link needed. |
| `/catalog/:id/edit` — edit product | Hidden child route | **API-backed editor route.** | Preserve a stable route and complete edit/save/error handling. |
| `/orders` — order list | Sales | **API-backed.** Lists storefront/workspace orders. | Improve as the downstream result of accepted quotes; keep quote/client/payment links visible. |
| `/orders/:id` — order detail | Hidden child route | **API-backed.** Order detail can show/update order information and related activity. | Connect approved quote → invoice → order snapshots without creating duplicate or orphaned records. |
| `/operations` — full board | Delivery | **API-backed with mixed metrics.** Five order stages are present: production, QC, dispatch, delivery, installation; returns are surfaced in the operations area. Some completion/QC KPI cards are static. | Use API-derived metrics only; make site visit/project planning distinct from order production. |
| `/operations/:stage` — stage-filtered board | Delivery | **API-backed.** Same board filtered by stage; current sidebar directly links to production and delivery, while other stages are reachable in the board. | Treat these as filters/child views, not separate modules; retain all five stages in one operations workflow. |
| `/projects` — projects | Delivery | **Prototype/mock.** Uses imported mock data; the New Project action reports that the wizard is planned. | Keep marked prototype or out of production navigation until project records and actions persist to the API. |
| `/support` — Support & After-Sales | Not in sidebar | **Placeholder.** The route renders a planned “Support & After-Sales” screen. | Later add warranty claims, complaints, service tickets, and return links; do not present it as complete today. |
| `/website` — Website Studio | Sales | **API-backed CMS v1, not a full site CMS.** Manages campaign landing pages (`/p/:slug`) and typed blocks. | The public site's main routes/content remain code-defined. The separate CMS audit records the draft/live safety, publishing/history, media, route, and SEO gaps. See `docs/architecture/CMS-GAP-AUDIT-2026-10-05.md`. |
| `/website/edit/:id` — page editor | Hidden child route | **API-backed editor route.** | Fix the CMS audit's P0 draft/live and save/publish/rollback issues before relying on it for production edits. |
| `/website/theme` — Theme Studio | Sales | **API-backed.** Site-wide theme tokens and per-page landing theme are available. | Preserve current visual identity; clarify global/page theme precedence and do not treat this as the missing full website content manager. |
| `/marketing` — landing analytics | Sales | **API-backed.** First-party landing views, CTA/UTM/referrer summaries and landing leads are read from `/api/marketing/stats`. | This is landing-page analytics, not a complete paid-ad/campaign publishing tool. Reconcile its lead attribution with the CRM. |
| `/analytics` — business analytics | Insights | **Prototype/mock.** KPI cards, chart series, lead-source split, and campaign rows are imported from mock data; the range selector does not fetch a corresponding date window. | Rebuild from the same invoice/payment/order/lead API read models before using for business decisions. |
| `/settings` — settings/team | Insights | **Mixed, mostly sample/local.** The page shows sample workspace/team/approval settings; controls update local React state and toast, not saved settings in this page. | Keep under Admin; persist settings only through explicit workspace APIs and show their source/state. |
| `/ui-components` — component showcase | Insights | **Developer/design reference, not a business workflow.** | Move to a developer-only/admin location or keep out of the everyday business sidebar. |

The app also has a login/auth gate outside the business sidebar, redirects for legacy paths (`/login`, `/signin`, `/dashboard`, `/home`, `/overview`), and a not-found route. Keep the simple `admin` / `admin` login requirement; this audit does not claim browser sign-in is verified or propose a new demo-account list.

### Data and production-confidence note

The repo's architecture describes SQLite as the current workspace database and PostgreSQL as a later/cutover target. API-backed pages are therefore a stronger foundation than mock pages, but should not be called “production live” until the deployed database, backups, permissions, and public-site integrations are verified. No hosted PostgreSQL URL, public-site form submission, WhatsApp handoff, or external message delivery was verified in this read-only pass.

## 4. Proposed dashboard map — funnel-first

Keep existing URLs where possible and group them as follows. New concepts are marked **planned**; they are not current routes.

```text
WORKSPACE
└── Overview                                  /

CAPTURE & RELATIONSHIPS
├── Shared Inbox / Enquiries                  /omnichannel
├── Leads & Pipeline                          /crm
├── Clients & Companies (Customer 360)        /clients
└── Follow-ups & Site Visits                  /tasks + planned visit calendar/record

QUOTE & COLLECT
├── Quotations                                /quotations
│   ├── Builder                               /quotations/new
│   └── Templates                             planned tab/child view
└── Invoices & Payments                       /invoices
    └── Transaction ledger                    planned section/tab

COMMERCE & FULFILMENT
├── Commerce Overview                         /ecommerce
├── Product Catalog & Materials               /catalog (+ create/detail/edit routes)
├── Orders                                    /orders (+ order detail)
├── Operations                                /operations (production → QC → dispatch → delivery → installation)
├── Projects                                  /projects (prototype until backed)
└── Returns & Support                         returns panel now; support route planned

WEBSITE & GROWTH
├── Website / CMS                             /website (+ page editor)
├── Theme Studio                              /website/theme
└── Landing Analytics / Marketing             /marketing

INSIGHTS & ADMIN
├── Business Analytics                        /analytics
├── Workspace, Team & Preferences             /settings
└── UI Components                             developer-only; not a normal business destination
```

**Why this map:** an employee can follow an enquiry from capture to client, quote, invoice, and fulfilment without hunting through a Sales group that also contains the CMS and theme editor. It also keeps quotes/templates and invoice/transaction work together, while keeping technical tools out of the everyday business menu. The map does not change or replace the visual theme.

## 5. Priority sales flow

### A. Capture enquiries and resolve identity

Intake sources should be explicit and attributable: Woodex-2030 storefront forms, published landing-page forms, a future website chat widget, showroom/phone/manual entry, and a WhatsApp handoff. For the separate public Woodex Interiors site, integration must be confirmed rather than assumed.

**Phase 1 storefront-capture slice (implemented 2026-10-06):** Contact and e-quotation forms now use named fields, submit the entered name/company/email/phone/project details, capture a preferred contact method and explicit enquiry-contact consent, and show success only after the API confirms the write. A quote request with basket items creates a quotation plus linked lead/client; without basket items it still creates a lead. The API stores consent state/server timestamp/version, source, landing path/referrer/UTM, normalized email/phone identity, a CRM lead classification (`New`/`Returning`), and an inbox system message plus next-day follow-up task. Conflicting email/phone matches are kept as a separate record, flagged for review, and scheduled as a high-priority identity task rather than silently attached. A SQLite upgrade and the PostgreSQL schema mirror cover the new lead fields. Full API QA verifies new, returning, and conflicting fictional identities on both database drivers.

**Still open in Phase 1:** no public website chat widget is implemented; the quote page's direct `wa.me` action still does not create a logged handoff event; the existing dashboard WhatsApp handoff remains manual and is not live send/receive. Site-visit appointments/reminders and browser-driven submission testing are not part of this slice. Continue to avoid claiming provider delivery or verified public-site integration.

**Phase 2 progress (started 2026-10-06):** the quote API now has a detail/reload endpoint, a structured persisted quote snapshot, server-validated arithmetic, direct client/lead links, immutable numbered content revisions, status audit, and explicit protection against editing an issued quote without first opening a `Revised` status. The dashboard builder can reopen and revise saved quotes, preserve item options/dates/currency/terms, and preview/print the current draft. SQLite and PostgreSQL schemas/migrations are aligned. API QA exercises save → reload → revise and checks that invoice conversion retains the saved quote totals. The dashboard preview is implemented, but its rendered/print/PDF path has not had browser-driven acceptance testing.

**Remaining Phase 2 work:** approved templates, line-level discount/manual override audit, full approval and sent/viewed/client-response events, a verified share/PDF flow, and business approval of any pricing/tax/legal/payment defaults. Phase 2 is in progress, not complete.

For a visitor who asks for a quote or visit but has not shared contact details, ask for a phone/WhatsApp number and preferred contact method. Record the consent state, server timestamp, source, landing page/referrer/UTM where available, initial message, and handoff outcome. Keep an anonymous website conversation anonymous until the visitor provides enough information to identify or contact them.

Normalize phone numbers (including Pakistan country code) and match by phone/email. A confident match attaches the new enquiry to the existing client and preserves old conversations, quotes, invoices, and orders. An uncertain match goes to the existing human merge-review workflow; never silently merge. Label the person **new** or **returning** based on their linked history, while each new enquiry remains its own lead/opportunity.

### B. Shared inbox, lead pipeline, and follow-up

Create or attach one conversation to the client and lead. Give it a source/channel, assigned owner, priority, disposition, and full message/note timeline. A live webchat widget is a **new** customer-facing capability; the existing shared inbox is staff-side and does not itself prove webchat exists.

Use distinct lead and quote lifecycles so the two records do not disagree:

- **Lead/opportunity:** `New → Contacted → Qualified → Site visit booked → Site visit completed → Quotation → Follow-up → Won/Lost`. Site visits are optional for ready-made products and expected only where measurement/design is needed. Re-open/reactivate a returning client's opportunity without replacing their client history.
- **Quotation:** `Draft → Internal review/approval → Sent → Viewed → Negotiation/Revised → Accepted/Rejected/Expired`.

Keep the existing tasks system for next actions. Add a first-class site-visit appointment with date/time, owner, address, confirmation status, notes, visit outcome, and linked client/lead; generate reminders from its schedule. Do not pretend a due-date task is a confirmed appointment.

### C. E-quotation builder and templates

Build one server-persisted quote record with revisions and a snapshot of its terms at issue time. A quotation line should support:

- series/product and variant, or a clearly labelled custom item;
- width/height/depth and unit where relevant;
- material, finish, colour/fabric and customization notes;
- quantity, unit price, line discount, and auditable manual price overrides;
- delivery and installation charges, optional taxes, quote discount, totals, currency, and calculation breakdown;
- issue date, editable validity/expiry, payment milestones, warranty/production terms, and client/site details.

Provide reusable, brand-approved templates (for example ready-made product quote, custom furniture quote, and measured/site-visit quote), editable line order/terms, a live preview, and a client-ready print/PDF or secure quote view. Track each version, approver, sent/viewed event, client response, and follow-up date. A change after sending should create a revision, not silently rewrite the copy the client already received.

Keep all calculations server-validated and use PKR as the primary currency. The current builder's displayed 5% discount, 18% tax, shipping amount, USD approximation, and sample terms/contact are demo defaults—not confirmed company pricing or legal terms. Make policy values configurable and verify the final business header, tax details, bank details, warranty, and payment milestones before issuing real documents.

### D. Quote acceptance → invoice → payment → order

On accepted quotation, issue one invoice from an immutable quote snapshot. Preserve the link to its client and quote; prevent accidental duplicate conversion. Store each payment as an append-only transaction with amount, date, method, reference, recorder, and invoice link. Derive `paid`, `balance`, `partially paid`, `overdue`, and `paid in full` from invoice totals and payment records, with a visible audit trail.

Show monthly invoiced/collected/outstanding sales in PKR; distinguish booked revenue from cash actually collected. The current app can record manual payments, but no online payment gateway or bank reconciliation has been verified. The current project docs use a 50% advance convention for bespoke orders; keep payment milestones configurable per quote rather than hard-coding one policy for every customer. Link the accepted sale to an order and then to the existing production/QC/dispatch/delivery/installation stages.

## 6. Example end-to-end scenario (illustrative, not a real customer)

A **fictional** Lahore customer asks the Woodex Furniture storefront about a made-to-order dining set. The site captures the message, source page, campaign tags, phone/WhatsApp preference, and consent. If that phone already belongs to a client, the new enquiry is added as a returning-client opportunity; otherwise a new client is created. The staff inbox shows the thread and the CRM assigns an owner.

The salesperson uses the current **manual WhatsApp handoff**: the workspace records the drafted handoff and opens WhatsApp with `wa.me`. The workspace does not claim the message was delivered or that a reply was synced. When the customer replies, the salesperson logs the inbound reply manually until a real WhatsApp Business provider/webhook is configured.

The salesperson qualifies the need and books a site/showroom visit if measurements or finish selection are required. After that visit, they build a quote with the selected series/variant, custom dimensions, material/finish, quantity, delivery/installation, any approved discount, expiry, and agreed payment terms. The saved revision is previewed and issued to the client. The salesperson tracks viewed/revised/accepted status and schedules a follow-up. On acceptance, the system creates the linked invoice, records the deposit and later payments as transactions, calculates the remaining balance, and advances the linked order through production to installation. If the same customer returns later, their history stays on the original client record.

## 7. Suggested implementation sequence after approval

| Phase | Scope | Exit check before moving on |
|---|---|---|
| 0 — Map and sign-off (**this document**) | Approve the navigation, page-status inventory, sales defaults, and boundaries. | User approval received; navigation regrouping delivered without changing routes or customer records. |
| 1 — CRM capture and client lifecycle | Connect approved Woodex-2030 intake sources to leads/clients; capture contact, consent/source, conversation; deduplicate; add owner/next-follow-up, visit appointments, and returning-client history. Keep WhatsApp handoff manual/logged. | Test a new and returning fictional client end-to-end; both retain source/consent and conversation/lead linkage; no silent duplicate merge; visit and reminder appear correctly. |
| 2 — Persistent e-quotation system | Complete quote schema/persistence, product options/custom dimensions, templates, calculations, review/approval, versioning, and PDF/client preview. | Save → reload → revise → preview a quote; every agreed field remains; totals validate; client/lead links and audit history remain correct. |
| 3 — Invoices, transactions, and sales reporting | Convert accepted quotes, prevent duplicate invoices, record manual payments, calculate balances/aging, and report monthly PKR invoiced vs collected. | Reconcile quote, invoice, deposit, balance, and monthly totals; test partial/full payment and overdue states. |
| 4 — Complete the remaining dashboard groups | Revisit prototypes and other gaps in Commerce/Fulfilment, Analytics, Projects, and CMS according to this inventory and the separate CMS audit. | Each page labelled API-backed/prototype/placeholder honestly; acceptance test per module. |
| Optional provider gate — real WhatsApp API | Only after choosing/configuring an approved provider, server-side secrets, webhook verification, consent/opt-out handling, templates, and delivery-status mapping. | Synthetic inbound/outbound tests and delivery states verified without claiming until the provider connection is live. |

## 8. Boundaries and acceptance rules

- Preserve the Woodex-2030 visual theme; reorganize labels/groups, do not replace the identity with a third-party admin template.
- Do not implement before this map/plan is approved. Do not change live CRM, storefront, CMS, quote, invoice, or customer records during planning.
- API-backed in this repo is not the same as publicly deployed, hosted, backed up, or externally integrated. Mark those states honestly.
- Manual `wa.me` is a handoff, not live WhatsApp messaging. Until a provider/webhook exists, inbound replies are manually logged.
- Use the `woodex`, `woodexinterior`, and `e-quotation-` repos/pages as patterns only. Do not copy code/content or migrate customer/business data between products.
- Keep login simple (`admin` / `admin`); do not add a demo-account list or claim browser sign-in is fixed without user verification.
- Once the sales flow is accepted, proceed through the other navigation groups in the planned sequence rather than starting unrelated modules in parallel.

## References

- Current route/nav source: `apps/dashboard/src/App.tsx`, `apps/dashboard/src/components/layout/Sidebar.tsx`.
- Current sales and API source: `apps/dashboard/src/pages/Crm.tsx`, `Clients.tsx`, `Inbox.tsx`, `Quotations.tsx`, `Invoices.tsx`, `Tasks.tsx`, `apps/api/server.mjs`.
- Storefront intake source reviewed: `apps/storefront/src/pages/Contact.tsx`, `Quotation.tsx`, and `apps/storefront/src/lib/runtime.ts`.
- Current CMS findings: `docs/architecture/CMS-GAP-AUDIT-2026-10-05.md`.
- Existing architecture and finance invariants: `docs/architecture/ARCHITECTURE.md`, canonical plan `docs/product/AGENCY-OS-MASTER-PLAN-V2.md`.
- Public references reviewed read-only: [1](https://woodex.com.pk/) ([contact](https://woodex.com.pk/contact/), [estimator](https://woodex.com.pk/estimator/), [projects](https://woodex.com.pk/projects/)); [`woodex420/woodex`](https://github.com/woodex420/woodex); [`woodex420/woodexinterior`](https://github.com/woodex420/woodexinterior); [`woodex420/e-quotation-`](https://github.com/woodex420/e-quotation-); [e-quotation preview](https://e-quotation.vercel.app/).
