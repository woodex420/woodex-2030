> **This is not a document of this product.** It is the requirements document of another system: the Interior studio's
> Laravel application, [woodex420/woodexinterior](https://github.com/woodex420/woodexinterior), from which the code
> of this admin was taken. The file is `docs/02-MASTER-PRD.md` of that repository at commit
> `603428cca1e45f9da2ffd9c4a0d88eb741285339` (3 November 2026), kept here unchanged so that a requirement the code names, such as "QUO-006",
> can be read where the code is. Checksum (sha256) of the file there, and of everything below this note:
> `3991d9fdcaff205109488965eb4ab0d47b4efdf19810fc42ac54ad19a642611d`.
>
> **Where this admin differs.** A requirement here says what a screen was built to do for the studio. It is not
> a promise of this product: which of the studio's modules Woodex Furniture uses, which are switched off and
> what is still to build is in [document 25](../../woodex-os/25-INTERIOR-ADMIN-REUSE-AND-UPGRADE-PLAN.md) and in
> the [master list](../../woodex-os/14-PRODUCTION-TODO.md). The business, its services, its prices and the people
> named are the studio's. Every decision taken for this admin is a row of
> [docs/woodex-os/12-DECISION-LOG.md](../../woodex-os/12-DECISION-LOG.md), and where the two differ, that log
> holds.
>
> The people, telephone numbers, addresses and web addresses in it are the studio's, as the studio published
> them; no password or key is in it. `scripts/verify-code-references.mjs` fails when anything below is changed.

<!-- The studio's document follows, unchanged. -->
# Woodex OS: Master Product Requirements Document (PRD)

**Version:** 1.0 · **Date:** 15 October 2026 · **Owner:** Woodex Interior (Director: Imtiaz Ahmad · Marketing
Manager: Nabeel Afzal)
**Status:** Approved for build (P0 starts) · **Related:** `01-MASTER-PLAN.md`, `04-DATABASE-SCHEMA.md`,
`05-DESIGN-SYSTEM.md`, `07-DECISIONS.md`

**How to read the requirement tables**

| Column | Meaning |
|---|---|
| **ID** | Stable requirement ID. Tests and commits reference it (e.g. `CRM-012`). |
| **Pri** | **M** = Must (release blocker) · **S** = Should (planned, can slip one phase) · **C** = Could (nice to have) |
| **Ph** | Phase that delivers it (P0–P9, see the Master Plan) |

---

## 1. Product overview

### 1.1 Problem

- **Leads are lost.** The current studio site's contact form is a demo that captures nothing.
- **The old admin cannot be trusted.** The v2.1/v2.5 platform keeps money in JSON blobs, has no data
  integrity and leaked credentials.
- **Follow-ups depend on memory.** There is no SLA and no single view of the client across WhatsApp,
  phone, website and social.
- **Website changes need a developer.** This puts the 142-page SEO footprint at risk with every edit.

### 1.2 Vision

> **One system where every enquiry becomes a tracked opportunity, every quotation and invoice is
> professional and traceable, every conversation is in one inbox, the owner runs the business from
> Telegram, and the team can restyle and grow the website without a developer.**

### 1.3 Scope summary

| Release | Phases | Contents |
|---|---|---|
| **R1 Sales OS** | P0–P3 | Foundation, CRM, lead capture, quotations, invoices, payments, v2.1 import, Telegram alerts |
| **R2 Conversations** | P4–P5 | WhatsApp Cloud API, unified inbox, website chat + AI bot, sequences, real-time, Telegram command centre |
| **R3 Website** | P6 | New Preline/Studio website, 142 URLs, theme builder, page builder, SEO, estimator, booking |
| **R4 Social** | P7 | Facebook + Instagram inbox/comments/Lead Ads/scheduler, LinkedIn posting + lead sync |
| **R5 Client experience** | P8–P9 | Projects, client portal, reviews, hardening, training |

### 1.4 Non-goals (v1)

- Full accounting / general ledger and tax returns. Woodex OS exports to the accountant.
- HR/payroll, inventory/warehouse, procurement purchase orders. PO tracking could come later.
- LinkedIn direct messages (no public API).
- A free-form drag-and-drop website canvas (D-03 chose blocks + tokens).
- Native mobile apps. The admin is mobile-responsive, and Telegram covers mobile control.

---

## 2. Users and personas

| Persona | Role | Goals | Main screens |
|---|---|---|---|
| **Imtiaz, Director/Owner** | `owner` | See pipeline, cash and team performance live; approve discounts and refunds; sign quotations | Dashboard, Approvals, Reports, Telegram |
| **Nabeel, Marketing & Sales Manager** | `sales_manager` | Run the funnel, assign leads, build quotes, manage social and website content | All CRM/Sales, Inbox, Marketing, Website |
| **Sales executive** | `sales` | Contact assigned leads fast, book visits, draft quotes, follow up | My Day, Leads, Inbox, Quotes |
| **Designer / Project manager** | `designer_pm` | Record measurements, deliver designs, post project updates with photos | Site Visits, Projects, Client Updates |
| **Accountant** | `accountant` | Issue invoices, verify payments, issue receipts, chase receivables | Invoices, Payments, Receivables, Reports |
| **Content editor** | `editor` | Edit pages, insights and portfolio; prepare social posts | Website, Media, Social Planner |
| **Support agent** | `support` | Answer chats and WhatsApp, hand qualified leads to sales | Inbox |
| **Admin (technical)** | `admin` | Users, settings, integrations, imports, health | System |
| **Client** | external | Clear quote, easy acceptance and payment, visible progress | Quote link, invoice link, client portal, WhatsApp |
| **Website visitor** | external | Understand services and costs in their city, see real work, book a visit quickly | Website, estimator, booking, chat |

---

## 3. Success metrics (KPIs)

| KPI | Definition | Target |
|---|---|---|
| Capture rate | Stored enquiries ÷ submitted enquiries | 100% |
| First response time | Median minutes from lead created → first outbound contact (business hours) | < 15 min |
| SLA breaches | Leads not contacted within SLA | < 5% per week |
| Visit rate | Leads reaching "site visit" ÷ qualified leads | Baseline → +20% |
| Quote turnaround | Median hours visit done → quote sent | ≤ 48 h |
| Win rate | Won ÷ (won + lost) by line | Baseline → +20% |
| Collection | Receivables > 30 days | −50% in 3 months |
| Website CWV | p75 mobile LCP / INP / CLS | < 2.5 s / < 200 ms / < 0.1 |
| SEO retention | Organic clicks 8 weeks after cut-over vs before | ≥ 95% |
| Bot containment | Conversations resolved or qualified by bot without human | ≥ 40% (with correct hand-off) |

---

## 4. Global requirements (all modules)

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| GLB-001 | Admin uses the TailAdmin layout: collapsible sidebar grouped as in Master Plan §4, header with global search, quick-add, notifications and profile; dark mode toggle. | M | P0 |
| GLB-002 | **Global search (Ctrl/⌘ K)** finds contacts, leads, quotes, invoices, projects and conversations by name, company, phone (any format: `0322…`, `+92322…`, `322…`) or document number (`WI-10123`). Results appear in < 500 ms for 50k records. | M | P1 |
| GLB-003 | Every list screen supports: search, filters, sort, pagination (25/50/100), saved views per user, column chooser, CSV export (permission `export`), bulk actions where relevant. | M | P1 |
| GLB-004 | Record details open in a **drawer** or full page. A unified **activity timeline** shows calls, WhatsApp, emails, notes, stage changes, quotes, invoices, payments and system events. | M | P1 |
| GLB-005 | Notes support **@mentions**, which notify the mentioned user (in-app + Telegram if linked). | S | P1 |
| GLB-006 | Attachments (images, PDF, DWG/DXF, XLSX, DOCX up to 20 MB) via the media library, with image thumbnails and WebP conversion. | M | P1 |
| GLB-007 | Tags on contacts and leads (colour, filterable). | S | P1 |
| GLB-008 | Notification centre (bell): unread count, mark read, deep links. It updates in real time when broadcasting is configured; otherwise it polls every 30 s while visible. | M | P1 |
| GLB-009 | **Audit log** for create/update/delete/status change of business records (who, when, IP, old→new values), plus logins, 2FA changes, settings and role changes. | M | P0 |
| GLB-010 | Soft delete + restore for business records. Permanent erase only by `owner` (privacy request), which anonymises personal data and keeps the financial totals. | M | P1 |
| GLB-011 | Phone input is normalised to E.164 (`+923224000768`). Click-to-call (`tel:`) and click-to-WhatsApp actions appear next to every phone number. | M | P1 |
| GLB-012 | Formats: time zone `Asia/Karachi`; date `15 Oct 2026`; time `3:45 pm`; currency `Rs 450,000` (option: lakh grouping `Rs 4,50,000`); amount in words on invoices ("Rupees Four Lakh Fifty Thousand Only"). | M | P0 |
| GLB-013 | Every screen has designed **empty, loading (skeleton), error and success (toast)** states. Destructive actions need a confirmation modal. | M | P0 |
| GLB-014 | Admin is fully usable on a phone (≥ 360 px wide): lists collapse to cards and key actions remain reachable. | M | P1 |
| GLB-015 | Client-facing texts (WhatsApp templates, emails, PDFs, portal) exist in **English and Urdu**. The contact's preferred language picks the version. | S | P4 |
| GLB-016 | Keyboard shortcuts: `N` new lead, `Q` new quote, `/` search, `G then D` dashboard. | C | P9 |
| GLB-017 | Installable admin (web app manifest + icons) for the home screen on Android/iOS. | C | P5 |
| GLB-018 | All outbound integrations log request/response metadata (no secrets, PII masked) in `integration_logs` with retry status. | M | P1 |

---

## 5. Module requirements

### 5.1 CAP: Lead capture (all channels → one pipeline)

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| CAP-001 | Public endpoint `POST /api/public/leads` (JSON) accepts name, phone, email, city/area, service, business line, property type, area size, budget band, timeline, message, page URL, referrer, UTM parameters, `gclid`/`fbclid`, consent flag and Turnstile token. | M | P1 |
| CAP-002 | Protection: rate limit (5/min/IP, 20/day/phone), honeypot field, minimum fill time (3 s), Cloudflare Turnstile verification when configured, CORS allow-list (`woodex.com.pk`, `www`, admin domain). | M | P1 |
| CAP-003 | **Lead Capture Service** (one class used by every channel): normalise phone → find or create contact (match phone, then email) → create lead (or attach to an open lead for the same contact + line within 30 days) → store source + attribution → score → assign → start SLA → fire `LeadCaptured` event. | M | P1 |
| CAP-004 | Sources (enum, extensible in Settings): website form, estimator, booking, website chat, WhatsApp, Facebook Messenger, Instagram DM, Facebook Lead Ad, Instagram Lead Ad, LinkedIn Lead Gen, LinkedIn comment, phone call, walk-in, referral, returning client, import, manual. | M | P1 |
| CAP-005 | First-touch and last-touch attribution: landing page, referrer, UTM source/medium/campaign/content/term, click IDs, device. They are stored on the lead and shown on the timeline. | M | P1 |
| CAP-006 | Auto-reply to the client: WhatsApp template "enquiry received" (P4; before P4, an email if an email was given) in the client's language, with studio hours and the next step. | M | P4 |
| CAP-007 | **Bridge mode:** the endpoint accepts posts from the current live site (P1–P6) with a per-origin API key, so live-site enquiries reach the new CRM before the website rebuild. | S | P1 |
| CAP-008 | Manual quick-add (+ Lead) with duplicate warning while typing the phone ("Contact exists: Ali Raza, 2 open leads"). | M | P1 |
| CAP-009 | CSV import of leads/contacts with column mapping, preview, dedupe report and rollback of the batch. | S | P1 |
| CAP-010 | Public success response returns a reference (`L-2026-000123`) and the WhatsApp deep link for the visitor to continue on WhatsApp. | S | P1 |

**Acceptance: CAP-003 (critical path)**

- *Given* a visitor submits the website form with phone `0322-4000768`, *when* the request is valid,
  *then* a contact exists with phone `+923224000768`, a lead with stage **New**, source **website form**
  and UTM data is created, the assignee receives a Telegram + in-app alert within **60 s**, and the API
  responds `201` with a reference in **< 800 ms**.
- *Given* the same phone submits again within 30 days for the same line, *then* no new lead is created.
  The enquiry is added to the existing open lead's timeline and the owner is notified as "returning
  enquiry".
- *Given* Turnstile is configured and the token is invalid, *then* the API returns `422` and nothing is
  stored except a rate-limited spam log entry.

### 5.2 CRM: Leads, contacts, pipeline, tasks, visits

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| CRM-001 | **Contacts**: person record (name, phones (multiple, one primary + WhatsApp flag), emails, city, area, address, preferred language, client type: individual/company/developer/architect, tags, consent flags, notes). Optional link to a **company**. | M | P1 |
| CRM-002 | **Companies**: name, type, NTN (optional), phones, emails, address, website, contacts list, all leads/quotes/invoices. | S | P1 |
| CRM-003 | **Leads (opportunities)**: contact, business line (Interior / Furniture / Project), service, property type, location, area (sq ft), budget band, timeline, lead type (new / returning / referral + referrer), priority (low/normal/high), stage, owner, next action + due, expected value, probability, lost reason, source/attribution. | M | P1 |
| CRM-004 | **Pipeline stages** (configurable, ordered, typed open/won/lost, with probability): **New → Contacted → Site Visit → Quotation → Negotiation → On Hold → Won / Lost**. Moving to Lost requires a reason (Settings list). Moving to Won requires an accepted quote or an owner override. | M | P1 |
| CRM-005 | **Pipeline board** (Kanban, drag and drop between stages; columns show count and total expected value; filters by owner, line, source, date). **List view** with the same filters. Switching between them keeps the filters. | M | P1 |
| CRM-006 | **Next action** is mandatory on open leads: call / WhatsApp / site visit / meeting / email + due date-time. Overdue next actions appear in red on My Day and trigger reminders. | M | P1 |
| CRM-007 | **Tasks**: title, type, due, assignee, related record, priority, status; recurring option; reminders (in-app/Telegram). | M | P1 |
| CRM-008 | **Site visits**: date/time slot, address + Google Maps link, assigned staff, client confirmation status, reminders (client WhatsApp D-1 and 2 h before; staff Telegram), outcome form (measurements, photos, notes, next step). Calendar view (day/week/month) and conflict warning. | M | P1 |
| CRM-009 | **Assignment rules**: by line, service, city/area and source; round-robin among active users of a role; working-hours aware; manual reassign with reason; workload cap per user. | M | P1 |
| CRM-010 | **SLA timers**: first response target per source and priority (default 15 min in hours; outside hours, the next business day at 10:30). Breach → escalate to sales manager (Telegram) → owner after a second threshold. | M | P1 |
| CRM-011 | **Lead scoring** (0–100) from rules in Settings: budget, area, service, city, source, engagement (quote viewed, replies). Shown as a badge; used for sorting and alerts ("hot lead"). | S | P1 |
| CRM-012 | **Duplicate detection and merge** for contacts (same normalised phone or email, similar name). The merge moves all related records and keeps an audit entry. | M | P1 |
| CRM-013 | **My Day**: today's visits, overdue and due-today next actions, new leads assigned to me, quotes awaiting follow-up, conversations waiting for me. | M | P1 |
| CRM-014 | **Segments**: saved contact filters (e.g., "Clients in DHA", "Lost due to budget, last 6 months") usable for broadcasts and exports. | S | P4 |
| CRM-015 | Contact 360 page: profile, open/closed leads, quotes, invoices, payments, projects, conversations (all channels), files and the full timeline. | M | P1 |
| CRM-016 | Calls: log call outcome quickly (connected / no answer / busy / wrong number / call back) from list, drawer or Telegram. Three "no answer" outcomes in a row propose a WhatsApp template. | M | P1 |

**Acceptance: CRM-005 / CRM-004**

- Dragging a card from *Contacted* to *Site Visit* without a scheduled visit opens the
  "Schedule visit" modal. Cancelling the modal returns the card to its column.
- Dragging to *Lost* opens a required "Lost reason" select. The stage change, reason and user are
  written to the timeline and audit log.
- The board with 500 open leads renders in < 1.5 s. Drag-drop saves in < 300 ms (optimistic UI, rollback
  on error).

### 5.3 QUO: Quotations

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| QUO-001 | **Rate book**: catalogue items (category, description, unit (sft, sqmt, rft, nos, each, set, point, job, lumpsum, views), rate, finish level essential/standard/premium, line Interior/Furniture, active flag). Seeded from the real Woodex catalogue (22 items + decor library + 6 estimator services). | M | P2 |
| QUO-002 | **Quote templates**: named sets of sections + items + default terms + payment-milestone plan + notes. 16 templates are seeded from real Woodex BOQs (Renovation, Civil Work, Interior Designing, Interior, Fit-out, Architecture, 3D Visualization, Furniture, Design Consultancy, and the 7 "Scope of Work" templates). | M | P2 |
| QUO-003 | **Builder**: create from template, from blank or by duplicating a quote. Sections can be added, renamed and reordered (drag). Items are added from the rate book (search) or as free text. Each line has qty (3 decimals), unit, rate, line discount (%/amount), optional/included flag and internal cost (hidden from the client). Inline editing; keyboard navigation (Enter = new line). | M | P2 |
| QUO-004 | **Totals computed on the server** in paisa: line = round(qty × rate) − line discount; section subtotals; quote discount (%/amount); taxes (configurable rates per line or quote, default none, with an "exclusive of taxes" note); rounding rule; grand total; **margin %** visible to staff only (from internal costs). | M | P2 |
| QUO-005 | **Payment milestones** per quote from a plan: execution **50/40/10** (advance / civil+wood completion / handover), design **75/25** (advance / approval), or custom. Percentages must total 100%. | M | P2 |
| QUO-006 | **Numbering**: Interior/Project **WI-10100+**, Furniture **WF-10050+** (from Settings). The number is assigned when the quote is first saved. Revisions keep the number and add `R1`, `R2`. | M | P2 |
| QUO-007 | **Approval**: if the discount exceeds the user's limit (Settings per role, e.g. sales 5%, manager 10%) or the margin falls below the minimum, sending is blocked until an owner/manager approves (admin or Telegram inline button). | M | P2 |
| QUO-008 | **Send**: generate the PDF; create an immutable **version snapshot**; send via WhatsApp template "quotation" with a signed link button (P4; before P4, a copyable link + email); record `sent` event. | M | P2 |
| QUO-009 | **Client quote page** (signed, expiring URL, no login): Studio-branded view with sections, totals, terms, milestones, validity countdown, PDF download, **Accept** (name + phone OTP via WhatsApp/SMS when available, or typed name + checkbox), **Request changes** (comment) and **Ask a question** (opens WhatsApp). | M | P2 |
| QUO-010 | **Tracking**: events for created, sent, delivered (WhatsApp status), viewed (each view with time, device, duration), downloaded, accepted, changes requested, expired, declined. Owner gets Telegram alerts: first view, repeat views (≥ 3 in 24 h = "hot"), acceptance, change request. | M | P2 |
| QUO-011 | **Acceptance automation**: lead → Won, contact → client, project created (from line/template), advance invoice drafted (or issued, per Settings) and sent, thank-you message with payment details for the correct bank (Interior → Bank Alfalah "WOODEX INTERIOR"; Furniture → Meezan "WOODEX FURNITURE"; wallets per Settings). | M | P2/P3 |
| QUO-012 | **Validity and reminders**: validity days (default 15). Follow-up sequence on day 2, 5 and 12 if not accepted (stops on reply/accept/decline). Auto-expire after validity; one-click "re-issue with new validity". | M | P2 |
| QUO-013 | **Revisions**: editing a sent quote creates a new revision. The client link always shows the latest revision; older revisions are viewable by staff with a diff summary (items/totals changed). | M | P2 |
| QUO-014 | **PDF**: A4, Studio letterhead (logo, address, NTN optional), client block, scope summary, sections table, totals, amount in words, milestones, terms, bank details, signatory blocks (**Imtiaz Ahmad, Director** · **Nabeel Afzal, Marketing Manager**, from Settings), page numbers, quote number on each page. Optional cover page + project images. | M | P2 |
| QUO-015 | **Scope of work** text blocks per template/section (rich text, sanitised) printed before the BOQ. | S | P2 |
| QUO-016 | Quotes list: status chips (draft, pending approval, sent, viewed, changes requested, accepted, declined, expired), value, age, owner, last activity; filters; "needs follow-up" view. | M | P2 |
| QUO-017 | Quick estimate → quote: website estimator results (service × area × finish) can be converted into a draft quote. | S | P6 |
| QUO-018 | Urdu PDF variant of terms and headings (body items stay English). | C | P9 |
| QUO-019 | **Quote options**: alternative proposals under the same number (e.g. `WI-10123 · Option A / Option B`, different finishes) shown side by side on the client page; accepting one closes the others. (v2.1 already used options.) | S | P2 |
| QUO-020 | **Other charges** line (rent, transport, scaffolding) added after the subtotal and before tax, as in the v2.1 documents. | M | P2 |

**Acceptance: QUO-004 / QUO-008 / QUO-011**

- A quote with 40 lines (fractional quantities such as `121.125 sft × Rs 1,200`) yields totals equal to
  the reference calculation **to the paisa**. Unit tests cover rounding, discounts, taxes and milestones.
- After sending, editing any line in the builder prompts "Create revision R1?". The original snapshot and
  its PDF stay unchanged and downloadable.
- Accepting on the client page (valid link, not expired) marks the quote *accepted*, moves the lead to
  *Won*, creates a project and a 50% advance invoice for an execution template, and notifies the owner
  on Telegram. **The whole chain is one database transaction plus queued notifications.**

### 5.4 INV: Invoices, payments, receivables

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| INV-001 | **Invoices from milestones**: an accepted quote's milestones become invoices (draft or issued, per Settings) as each milestone is reached. Number = quote number + sequence (`WI-10123-1`, `-2`, `-3`, configurable). | M | P3 |
| INV-002 | **Manual invoices** (items, taxes, discount) for extras/variations, linked to a project or contact. Variation orders are marked as such. | M | P3 |
| INV-003 | Invoice lifecycle: draft → issued → partially paid → paid; overdue (computed); void (reason, owner only); credit note for reversals. Issued invoices are immutable (snapshot + PDF). | M | P3 |
| INV-004 | **Bank routing**: invoice shows the bank account for its business line (Settings: accounts with title, bank, IBAN/account no., branch, line). Wallets (JazzCash/Easypaisa) optional. **Account numbers never in code.** | M | P3 |
| INV-005 | **Payments**: record amount, date, method (bank transfer, cash, cheque, JazzCash, Easypaisa, card/gateway), reference, received-into account, proof attachment. **Allocate** to one or more invoices; unallocated = client credit. | M | P3 |
| INV-006 | **Client payment proof upload** from the invoice link or portal (image/PDF). It creates a *pending verification* payment → accountant verifies or rejects with a reason → client notified. | M | P3 |
| INV-007 | **Receipts** (`RC-2026-0001`) PDF + WhatsApp/email delivery on verification. | M | P3 |
| INV-008 | **Reminders**: due-date reminders (D-3, D0, D+3, D+7, D+14) via WhatsApp/email, with polite EN/UR templates; stop when paid; owner escalation after D+14. | M | P3/P4 |
| INV-009 | **Receivables aging**: current, 1–15, 16–30, 31–60, 60+ days by client/project/line, with totals and export. | M | P3 |
| INV-010 | Reports: invoiced vs collected per month, by line and by bank account; payments ledger; client statement PDF. | M | P3 |
| INV-011 | Client **invoice page** (signed link) with payment instructions, upload proof, PDF download, and online payment button when a gateway is enabled (later). | M | P3 |
| INV-012 | Export for accountant (CSV/XLSX: invoices, payments, receipts by date range). | S | P3 |

**Acceptance: INV-005 / INV-006**

- A payment of Rs 300,000 against two invoices (Rs 200,000 + Rs 250,000) can be allocated as 200,000 +
  100,000. The first invoice becomes *paid*, the second *partially paid* (balance Rs 150,000). The aging
  report updates immediately.
- A client uploads a proof image → accountant gets a Telegram alert with **Verify / Reject** buttons →
  *Verify* creates the receipt, sends it on WhatsApp and writes the audit entry.

### 5.5 PRJ: Projects and client portal

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| PRJ-001 | Project created from an accepted quote (or manually): code (= quote number), client, line, site address, start/target dates, PM, team, value, stage. | M | P3 |
| PRJ-002 | Stages: **planning → design → procurement → execution → finishing → handover → completed** (configurable). Stage changes notify the client (template per stage, optional). | M | P3 |
| PRJ-003 | Milestones linked to payment milestones (e.g., "civil + wood work complete" triggers the 40% invoice draft). | M | P3 |
| PRJ-004 | **Client updates**: PM posts text + photos from a phone → optional approval → sent to client via WhatsApp template "project update" and shown in the portal. | M | P8 |
| PRJ-005 | Project board (Kanban by stage) and list with progress %, next milestone, overdue flags, amount invoiced / collected. | M | P8 |
| PRJ-006 | **Client portal** (magic link via WhatsApp/email or OTP; no password): quotes, invoices, payments, receipts, project timeline and photos, documents (drawings, 3D views), messages. | M | P8 |
| PRJ-007 | Handover checklist (configurable items, photos, client signature/OK) → completed → **review request** (Google review link) after 3 days. | S | P8 |
| PRJ-008 | Case study draft: with client consent, the project photos + facts create a draft portfolio page in the CMS. | C | P8 |
| PRJ-009 | Simple cost tracking per project (purchases/expenses lines vs quote internal cost) for margin reporting. | C | P9 |

### 5.6 CNV: Unified inbox

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| CNV-001 | One inbox for website chat, WhatsApp, Messenger, Instagram DMs and comments (FB/IG), LinkedIn comments (when available). Filters by channel, status (open/pending/snoozed/closed), assignee, unread, label. | M | P4/P7 |
| CNV-002 | Conversation view: messages with delivery/read ticks, media preview, contact panel (lead, quotes, invoices, last activity), quick actions (create lead, schedule visit, send quote link, assign, label, snooze, close). | M | P4 |
| CNV-003 | Assignment (manual, rules, round-robin). Internal notes (not sent) and @mentions. Collision warning when two agents type at once. | M | P4 |
| CNV-004 | **Canned replies** with variables (`{first_name}`, `{studio_hours}`, `{quote_link}`), EN/UR versions, shortcut `/`. | M | P4 |
| CNV-005 | 24-hour window awareness for WhatsApp/Messenger/IG: outside the window the composer only offers approved templates; a countdown shows the window time left. | M | P4 |
| CNV-006 | Real-time updates (new message, typing, status) via broadcasting; fallback polling 5 s on open conversation, 30 s list. | M | P4 |
| CNV-007 | Conversation SLA (first reply, next reply) with escalation to Telegram. | M | P4 |
| CNV-008 | Media: receive and send images, PDFs, voice notes (play in browser), location pins (map link). | M | P4 |
| CNV-009 | AI assist: suggested reply, summarise conversation, translate EN↔UR/Roman Urdu (staff can edit before sending). | S | P4 |
| CNV-010 | Comment moderation (FB/IG): reply publicly, reply privately (DM), hide, delete, convert to lead. | M | P7 |

### 5.7 WAP: WhatsApp Cloud API

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| WAP-001 | Connect WhatsApp Business Account (WABA ID, phone number ID, permanent system-user token, app secret, webhook verify token) in Integrations, with **Test connection**. | M | P4 |
| WAP-002 | Webhook `GET` verification + `POST` with **X-Hub-Signature-256** validation; idempotent processing (`webhook_events` unique on message ID); fast `200` response, processing on the queue. | M | P4 |
| WAP-003 | Receive text, image, document, audio, video, location, contacts, interactive replies (buttons/lists), reactions; download media to storage via the Graph media endpoint. | M | P4 |
| WAP-004 | Send free-form messages inside the window; send **templates** outside it; track `sent/delivered/read/failed` statuses with error codes. | M | P4 |
| WAP-005 | **Template manager**: sync templates from Meta, create/submit new templates (category, language EN/UR, variables, buttons), see approval status; map templates to system events (enquiry received, visit confirmation, visit reminder, quotation, quote follow-up, invoice issued, payment reminder, payment received, project update, review request). | M | P4 |
| WAP-006 | **Opt-in / opt-out**: consent captured on forms; keywords `STOP`, `UNSUBSCRIBE`, `بند کریں` set opt-out; marketing templates are blocked for opted-out contacts; utility messages respect legal rules. | M | P4 |
| WAP-007 | **Broadcasts** (marketing) to segments with opt-in, scheduled send, throttling, per-recipient status and cost estimate before sending. | S | P4 |
| WAP-008 | **Usage and cost meter**: monthly counts by category (marketing, utility, authentication, service, free-entry-point) × rate table (Settings), with threshold alerts to Telegram. | M | P4 |
| WAP-009 | Click-to-WhatsApp links on the website carry a reference code (`#WX1234` page/campaign), so the first inbound message is attributed to the page/campaign. | S | P6 |
| WAP-010 | Quality monitoring: phone number quality rating and messaging limit tier shown on the Integrations page; alert on downgrade. | S | P4 |

### 5.8 BOT: AI chat bot (website + WhatsApp)

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| BOT-001 | Website chat widget (vanilla JS + Alpine, Preline-styled, < 35 KB gzipped, lazy-loaded): greeting by page context, quick replies (Get a quote · Book a visit · See projects · Talk to a person), file/photo upload, persistent session (visitor token), offline mode. | M | P4 |
| BOT-002 | Bot engine with provider abstraction (Claude default; OpenAI/Gemini) and the **system prompt in `03-MASTER-SYSTEM-PROMPT.md` Appendix A**, editable per section in Settings (persona, rules, FAQs). | M | P4 |
| BOT-003 | **Knowledge base**: auto-built from services, areas, FAQs, process, hours, policies and portfolio pages (CMS), plus manual Q&A entries; retrieval by MySQL FULLTEXT top-k (no vector DB needed); sources shown to staff in transcripts. | M | P4 |
| BOT-004 | Qualification: collect service, property type, location, area, budget band, timeline, name and WhatsApp number; create or update the lead through the **Lead Capture Service** (source: website chat / WhatsApp). | M | P4 |
| BOT-005 | Booking: offer next available site-visit slots (from Settings calendar rules) and confirm. | S | P4 |
| BOT-006 | **Hand-off** rules: user asks for a human; complaint or angry sentiment; price negotiation or discount request; low confidence; more than N turns; VIP/returning client. The hand-off notifies the assignee and the bot stays silent until released. | M | P4 |
| BOT-007 | **Guardrails**: never invent prices beyond the estimator's published ranges; never promise dates/discounts; never share other clients' data; reply in the user's language (English, Urdu, Roman Urdu); keep WhatsApp replies short and combined (cost). | M | P4 |
| BOT-008 | Cost control: daily token/cost cap, per-conversation turn limit, model choice per channel; when the cap is reached, switch to "human will reply" mode + Telegram alert. | M | P4 |
| BOT-009 | Analytics: conversations, containment, hand-offs (reason), leads created, top unanswered questions (to improve the knowledge base). | S | P4 |
| BOT-010 | Staff "bot mode" switch per channel and schedule (e.g., bot only outside studio hours). | M | P4 |

### 5.9 AUT: Automation (sequences, rules, reminders)

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| AUT-001 | **Follow-up sequences**: ordered steps (wait X hours/days within business hours → send WhatsApp template/email → create task → change stage). Enrol automatically by trigger or manually. Exit when a condition is met (client replied, quote accepted, lead lost, opt-out). | M | P4 |
| AUT-002 | Built-in sequences (editable): New lead not reached; Quote not viewed; Quote viewed not accepted; Invoice reminders; Post-visit follow-up; Lost-lead nurture (60/120 days); Review request. | M | P4 |
| AUT-003 | **Alert rules** (event → condition → recipients (user, role, assignee, group chat) → channels (in-app, Telegram, email) → quiet hours → priority). | M | P1/P5 |
| AUT-004 | Scheduler runs every minute (cron) with business-hours awareness and holidays list (Settings). | M | P1 |
| AUT-005 | Execution log per enrolment (step, time, result, error) and pause/resume/cancel controls. | M | P4 |
| AUT-006 | Safe limits: max messages per contact per day (e.g. 2), never between 21:00 and 09:00 local time unless the client wrote first. | M | P4 |

### 5.10 TGM: Telegram command centre

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| TGM-001 | Bot connection (token, webhook with secret header), **Test**; staff link their account via a one-time code from their profile (`/start ABC123`); group chats (e.g., "Woodex Sales") can be linked for team alerts. | M | P1 |
| TGM-002 | **Alert catalogue** (each on/off per user/role in Alert Rules): new lead (with score, source, inline *Assign to me / Call / WhatsApp / Open*); unassigned lead > 5 min; SLA breach; returning client enquiry; chat waiting for human; WhatsApp message unanswered > N min; quote viewed (first / hot); quote accepted / changes requested / declined; approval needed (discount/void/refund); invoice overdue; payment proof uploaded (*Verify / Reject*); payment received; site visit tomorrow / in 2 h; project stage change; Lead Ad lead; social comment needing reply; **system**: cron heartbeat missed, failed jobs, queue backlog, backup failed/succeeded, disk > 80%, integration token expiring/failed, webhook silent > 24 h, login from a new device, role/settings changed, deploy finished. | M | P1/P5 |
| TGM-003 | **Digests**: daily 09:00 (today's visits, due follow-ups, yesterday's leads/quotes/payments, cash collected), weekly Monday 09:00 (funnel, win rate, top sources, receivables), monthly 1st 09:00. | M | P5 |
| TGM-004 | **Commands**: `/help` `/today` `/leads [new|mine|unassigned]` `/lead <id|phone>` `/find <text>` `/quotes [pending|viewed]` `/quote <no>` `/due` (invoices due/overdue) `/visits [today|tomorrow]` `/report [day|week|month]` `/health` `/approvals` `/approve <id>` `/assign <lead> <user>` `/note <lead> <text>` `/idea <text>` `/issue <text>` `/mute <hours>`. | M | P5 |
| TGM-005 | **Inline actions** perform the action in Woodex OS with the linked user's permissions; every action is audit-logged ("via Telegram"). Sensitive actions (void, refund, role change) are never available from Telegram. | M | P5 |
| TGM-006 | **Change requests from mobile**: `/idea` or `/issue` (text, photo or voice note) → `change_requests` record (type, priority guess, screenshot) → visible in System → Change Requests with status (new, accepted, planned (phase), done, rejected) → requester notified on status change. | M | P5 |
| TGM-007 | Security: only linked, active users; role checks per command; rate limits; inline buttons expire after 24 h; unlinking on user deactivation. | M | P1 |
| TGM-008 | Quiet hours and priority: non-critical alerts batched during quiet hours; critical ones always delivered. | S | P5 |

### 5.11 SOC: Social media (Facebook, Instagram, LinkedIn)

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| SOC-001 | Connect Facebook Pages + linked Instagram Business accounts via Facebook Login for Business; store long-lived page tokens encrypted; show token health and expiry. | M | P7 |
| SOC-002 | Messenger + Instagram DMs flow into the unified inbox (send/receive text, images; 24 h window rules; human-agent tag where permitted). | M | P7 |
| SOC-003 | FB + IG comments on posts/ads flow into the inbox as threads; reply publicly or privately; hide/delete; sentiment flag; convert to lead. | M | P7 |
| SOC-004 | **Lead Ads**: `leadgen` webhook → fetch lead → map form fields → Lead Capture Service (source FB/IG Lead Ad + campaign/ad/form IDs) → instant Telegram alert + WhatsApp template to the lead. Field mapping UI per form. | M | P7 |
| SOC-005 | **Composer**: one post → multiple targets (FB Page, IG, LinkedIn Page) with per-network text, media (images, carousel, video/reel where supported), link, first comment, hashtags library; previews per network. | M | P7 |
| SOC-006 | **Scheduler + content calendar** (month/week, drag to reschedule); statuses draft → in review → approved → scheduled → published / failed (with error + retry). Approval step for editor-created posts. | M | P7 |
| SOC-007 | **LinkedIn**: company page posting (text, image, link) via Community Management API; read and reply to comments where approved; Lead Gen forms via Lead Sync API when approved, otherwise CSV import / webhook bridge. | M | P7 |
| SOC-008 | Basic insights per post and per account (reach, engagement, clicks where the APIs allow) in Reports → Social. | S | P7 |
| SOC-009 | AI assist: draft captions EN/UR from a project's photos/facts; suggest hashtags; repurpose an insight article into posts. | C | P7 |
| SOC-010 | Asset library shared with the website media (tags: project, room type, style). | S | P7 |

### 5.12 WEB: Public website

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| WEB-001 | All **142 live URLs** answer 200 with the same path and a trailing slash; non-slash and legacy `.html` paths 301 to the canonical slash URL. **CI parity test** lists all 142 paths. | M | P6 |
| WEB-002 | Page types (each a template of blocks with typed fields): Home, Service (65), City/Area (20), Insight article (48 + index), Project case study (6 + index), Landing page, Contact, About, Legal, Estimator, Book a visit, Thank-you, 404. | M | P6 |
| WEB-003 | Studio design (Nohemi + Inter, navy/beige, pill buttons with arrow, generous whitespace) implemented with Preline blocks and Woodex tokens; responsive 360 px → 1920 px; dark sections. | M | P6 |
| WEB-004 | Global header (mega-menu for services and areas), footer, sticky mobile CTA bar (Call · WhatsApp · Get quote), breadcrumbs, related services/areas/insights. | M | P6 |
| WEB-005 | Forms: enquiry (short + detailed), callback, booking, estimator lead step: all post to `/api/public/leads` with Turnstile + consent; inline validation; success state with reference + WhatsApp continue. | M | P6 |
| WEB-006 | **Estimator**: service × area × finish → indicative range from the rate book (Settings), "this is an estimate" note, lead capture before showing the detailed breakdown (configurable). | M | P6 |
| WEB-007 | **Book a visit**: available slots by area/day from Settings, confirmation on WhatsApp, reminders. | M | P6 |
| WEB-008 | Chat widget on all pages (BOT-001), respecting page cache (stateless API). | M | P6 |
| WEB-009 | Portfolio/projects with real photos (replace stock), filters (residential/commercial/bespoke), facts (area, location, duration, budget band), gallery lightbox, client quote, CTA. | M | P6 |
| WEB-010 | Insights (blog): categories, author, reading time, table of contents, related posts, share buttons, schema `Article`. | M | P6 |
| WEB-011 | Performance: LiteSpeed cache, critical CSS inline for the hero, WebP/AVIF responsive images with dimensions, fonts preloaded + `font-display: swap`, JS < 60 KB gzipped on content pages, no layout shift. | M | P6 |
| WEB-012 | Accessibility WCAG 2.2 AA: contrast, focus visible, keyboard menus, alt text required in the builder, reduced-motion support. | M | P6 |
| WEB-013 | Analytics: GA4 + events (form start/submit, WhatsApp click, call click, estimator complete, booking), optional Meta Pixel + Conversions API (server-side Lead events), consent banner. | S | P6 |
| WEB-014 | Urdu landing pages (RTL support in blocks) for campaigns. | C | P9 |

### 5.13 CMS: Theme builder, page builder, content

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| CMS-001 | **Block library** (code-defined, Preline-based, Studio look): hero (photo carousel, split with form, minimal), disciplines strip, intro/story, services grid, split feature + accordion, project grid, process steps, stats, testimonials, client logos, FAQ, CTA band, contact block, gallery, pricing/estimate teaser, rich text, image, video, map, insight list, area list, team, form, spacer/divider. Each block has a **schema** (typed fields), **variants**, and design options (background token, spacing, alignment, anchor id, visibility on mobile/desktop). | M | P6 |
| CMS-002 | **Global blocks** (header, footer, CTA band, trust strip, contact strip) edited once and used on every page; per-page overrides allowed where the schema permits. | M | P6 |
| CMS-003 | **Page builder**: add block (gallery with thumbnails), reorder (drag), duplicate, hide, delete; edit fields in a side panel; live preview in an iframe (desktop/tablet/mobile toggles); undo within session; autosave drafts. | M | P6 |
| CMS-004 | **Versions**: every publish stores a version; compare and restore; scheduled publish/unpublish. | M | P6 |
| CMS-005 | **SEO panel** per page: title, meta description, slug (locked for the 142 imported pages unless owner overrides with a 301), canonical, robots, OG image, schema type, FAQ schema from FAQ block; checks (one H1, length limits, alt text, internal links, duplicate titles). | M | P6 |
| CMS-006 | **Theme builder (design tokens)**: brand colours (primary, secondary, accent, ink, body, surface, soft, beige, line), dark section colours, fonts (from an allow-list of self-hosted fonts: Nohemi, Inter, Plus Jakarta Sans, Manrope, DM Sans, Playfair Display, Noto Nastaliq Urdu), type scale, radius, button style (pill/rounded/square, arrow on/off), spacing scale, container width, shadow level, header style (transparent over hero / solid), logo variants, favicon. **Contrast checker** blocks publishing unreadable combinations. | M | P6 |
| CMS-007 | Theme preview (signed preview URL, side-by-side with live) → publish writes a versioned CSS variables file (`/theme/{hash}.css`) and purges the cache. **No npm/Vite build on the server.** Rollback to any previous theme. | M | P6 |
| CMS-008 | **Collections** (structured content types): Services, Areas/Cities, Insights, Projects, Testimonials, Team, FAQs, Client logos, Awards/press. Pages pull from collections (e.g., "services grid" block lists services by category). | M | P6 |
| CMS-009 | **Menus** editor (header mega-menu, footer columns, mobile menu) with drag ordering and nesting. | M | P6 |
| CMS-010 | **Media library**: upload, folders/tags, alt text required, focal point, automatic WebP + responsive sizes, usage tracking ("used on 4 pages"), replace file keeping URL. | M | P6 |
| CMS-011 | **Redirects** manager (301/302, regex optional, hit counter) + **404 log** with "create redirect" suggestion. | M | P6 |
| CMS-012 | **Forms builder** (fields, validation, consent, routing rules to line/owner, success message, WhatsApp follow-up template). | S | P6 |
| CMS-013 | Content import tool for the live site HTML (142 pages) → page types/blocks with a review queue. | M | P6 |
| CMS-014 | Editor roles: editors can draft; publish needs `cms.publish` (manager/owner) when "review required" is on. | M | P6 |
| CMS-015 | HTML sanitising for rich text (allow-list), no raw script injection by editors; a "custom code" block exists only for `owner`. | M | P6 |

### 5.14 RPT: Dashboard and reports

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| RPT-001 | **Dashboard** (role-aware): KPI cards (new leads today/week, response time, open pipeline value, quotes sent/accepted this month, invoiced, collected, overdue), "needs attention" list (SLA breaches, hot quotes, overdue invoices, unanswered chats, failing integrations), pipeline funnel chart, revenue chart, upcoming visits, recent activity. Live-updating. | M | P1/P3 |
| RPT-002 | **Funnel**: stage conversion by period/line/source/owner with drop-off reasons. | M | P3 |
| RPT-003 | **Sources & ROI**: leads, won value per source/campaign; manual ad spend entry per month to compute cost per lead and cost per won deal. | S | P3 |
| RPT-004 | **Team performance**: leads handled, response time, visits, quotes, win rate, revenue per user. | M | P3 |
| RPT-005 | **Revenue & collections**: quoted, accepted, invoiced, collected per month; by line and bank account; receivables aging. | M | P3 |
| RPT-006 | **Response times**: first response and conversation reply SLAs by channel and agent. | S | P4 |
| RPT-007 | **Website & conversion**: GA4 sessions, top pages, form conversion rate, top landing pages for leads, Search Console clicks/impressions/queries. | S | P6 |
| RPT-008 | **Social**: posts published, engagement, comments handled, Lead Ads leads and cost per lead (spend entry). | S | P7 |
| RPT-009 | Every report: date range presets, comparison to previous period, CSV export, print-friendly layout. | M | P3 |

### 5.15 SYS: System, settings, security, import

| ID | Requirement | Pri | Ph |
|---|---|---|---|
| SYS-001 | **Users**: invite by email (set-password link), deactivate (keeps history), roles, Telegram link status, 2FA status, last login, sessions list with remote logout. | M | P0 |
| SYS-002 | **Roles & permissions** (spatie): default roles from D-21 with a permission matrix (§7); owner can create custom roles. | M | P0 |
| SYS-003 | **2FA (TOTP)** with recovery codes; mandatory for owner/admin/accountant; login throttling; password rules; optional IP allow-list for admin. | M | P0 |
| SYS-004 | **Settings** (typed groups): Business (legal name, brand names, NTN, address, phones, emails, hours, social links, map), Sales (numbering, validity, terms, milestone plans, discount limits, lost reasons, sources, scoring, SLA), Banks & wallets, Taxes, Notifications (alert rules defaults, quiet hours, digests), Booking (slots, areas, holidays), Bot (provider, model, caps, persona), WhatsApp rates (for the cost meter), Website (analytics IDs, consent text), Privacy (retention). | M | P0+ |
| SYS-005 | **Integrations hub**: cards per integration with status, last success, last error, test button, docs link; secrets write-only (masked after save). | M | P1+ |
| SYS-006 | **Data import v2.1** wizard + command: dry-run report, run, re-run (idempotent), per-entity counts, money checksums, warnings CSV, import log. | M | P1–P3 |
| SYS-007 | **Audit log** viewer with filters (user, model, action, date) and export. | M | P0 |
| SYS-008 | **Health**: cron heartbeat, queue size/failed jobs (retry/forget), disk usage, DB size, last backup, mail test, integrations status, PHP/Laravel versions, scheduled tasks list with last run. | M | P0/P5 |
| SYS-009 | **Backups**: daily DB, weekly files, retention policy, off-site destination (Google Drive / S3-compatible), download (owner only), restore instructions; Telegram alert on failure. | M | P0 |
| SYS-010 | **Change requests** board (from Telegram or admin), statuses, link to phase, notification to requester. | M | P5 |
| SYS-011 | API tokens (Sanctum) for future integrations/mobile; scoped abilities; revocable. | C | P9 |
| SYS-012 | Maintenance mode with a secret bypass and a branded page. | S | P0 |
| SYS-013 | Privacy tools: export a contact's data (JSON/PDF), erase/anonymise on request, retention jobs (e.g., delete spam leads after 30 days, chat transcripts of non-leads after 12 months). | S | P9 |

---

## 6. Non-functional requirements

| ID | Area | Requirement |
|---|---|---|
| NFR-001 | Hosting | Runs on Hostinger shared/cloud hosting with PHP 8.4, MySQL 8/MariaDB 10.6+, cron every minute, no daemons, no Node at runtime. |
| NFR-002 | Performance (admin) | Typical screen server time < 300 ms; lists with 10k rows paginate < 500 ms; no N+1 queries (enforced with `Model::preventLazyLoading()` in non-production). |
| NFR-003 | Performance (public) | Cached page TTFB < 200 ms; uncached < 600 ms; Core Web Vitals per WEB-011. |
| NFR-004 | Availability | 99.5% monthly (host-bound); webhook endpoints answer < 2 s and queue the heavy work; integration outages never block saving data. |
| NFR-005 | Security | OWASP ASVS L2 practices: CSRF on admin, signed + expiring client URLs, rate limits, CSP and security headers, HTML sanitising, file-type validation + private storage for documents, encrypted secrets, 2FA, least privilege, dependency audit in CI. |
| NFR-006 | Privacy | Consent capture, opt-out, data export/erase, PII masking in logs, retention jobs; compliant with Pakistan's PECA 2016 and Meta/WhatsApp policies. |
| NFR-007 | Reliability | Jobs idempotent (unique keys), retries with backoff, dead-letter view (failed jobs), webhook replay from `webhook_events`. |
| NFR-008 | Data integrity | Foreign keys everywhere, money in integer paisa, DB transactions for multi-record actions, row locks for numbering, immutable sent documents. |
| NFR-009 | Maintainability | Modular structure, typed PHP 8.4, Larastan level 6, Pint, ≥ 80% coverage on Domain services, docs updated per PR. |
| NFR-010 | Compatibility | Admin: last 2 versions of Chrome, Edge, Safari, Firefox; Android Chrome; iOS Safari. Website: same + graceful degradation. |
| NFR-011 | Observability | Structured logs (daily files, 14-day retention), error alerts to Telegram (rate-limited), health screen, integration logs. |
| NFR-012 | Localisation | English UI for staff; client-facing EN + UR; RTL-safe components for Urdu content. |
| NFR-013 | Scalability | Designed for 20 staff, 50k contacts, 200k messages/year on shared hosting. Moving to Hostinger Cloud/VPS needs no code change (switch to Redis + Reverb when available). |

---

## 7. Permission matrix (default roles)

Legend: ✅ full · 👁 view · ✍ own/assigned only · — none

| Capability | owner | admin | sales_manager | sales | designer_pm | accountant | editor | support |
|---|---|---|---|---|---|---|---|---|
| Dashboard (all KPIs) | ✅ | ✅ | ✅ | ✍ | ✍ | 👁 finance | 👁 web | ✍ |
| Leads / contacts | ✅ | ✅ | ✅ | ✍ | 👁 | 👁 | — | ✍ create |
| Assign / reassign leads | ✅ | ✅ | ✅ | — | — | — | — | — |
| Quotes: create/edit/send | ✅ | ✅ | ✅ | ✍ | ✍ design | 👁 | — | — |
| Approve discounts | ✅ | — | ✅ (up to limit) | — | — | — | — | — |
| Invoices / payments | ✅ | 👁 | 👁 | 👁 own | — | ✅ | — | — |
| Void invoice / refund | ✅ | — | — | — | — | request | — | — |
| Projects / client updates | ✅ | ✅ | ✅ | 👁 | ✅ | 👁 | — | — |
| Inbox / conversations | ✅ | ✅ | ✅ | ✍ | ✍ | — | — | ✅ |
| Automations / templates | ✅ | ✅ | ✅ | — | — | — | — | — |
| Website / theme / pages | ✅ | ✅ | ✅ | — | — | — | ✍ draft (+publish if allowed) | — |
| Social planner | ✅ | ✅ | ✅ | — | — | — | ✍ draft | 👁 |
| Reports | ✅ | ✅ | ✅ | ✍ | ✍ | ✅ finance | 👁 web | — |
| Settings / integrations | ✅ | ✅ | 👁 sales settings | — | — | 👁 banks | — | — |
| Users & roles | ✅ | ✅ (not owner) | — | — | — | — | — | — |
| Data import / backups / audit | ✅ | ✅ | — | — | — | — | — | — |
| Export CSV | ✅ | ✅ | ✅ | — | — | ✅ | — | — |

---

## 8. Notification matrix (defaults; editable in Alert Rules)

| Event | Client (WhatsApp/email) | Assignee | Manager | Owner | Accountant | Channel(s) |
|---|---|---|---|---|---|---|
| New lead | Enquiry received | ✅ | ✅ (if unassigned) | high-score only | — | Telegram + in-app |
| SLA breach | — | ✅ | ✅ | 2nd breach | — | Telegram |
| Site visit booked / D-1 / 2 h | Confirmation + reminders | ✅ | — | — | — | WhatsApp, Telegram |
| Quote sent | Quotation link | — | — | — | — | WhatsApp + email |
| Quote viewed (first / hot) | — | ✅ | hot | hot | — | Telegram |
| Approval needed | — | — | ✅ | ✅ | — | Telegram (inline) |
| Quote accepted | Thank you + payment details | ✅ | ✅ | ✅ | ✅ | Telegram + in-app |
| Invoice issued / reminders | Invoice link / reminders | — | — | — | ✅ (overdue) | WhatsApp + email |
| Payment proof uploaded | "Received, verifying" | — | — | — | ✅ (inline) | Telegram |
| Payment verified | Receipt | ✅ | — | ✅ | — | WhatsApp + Telegram |
| Project stage / update | Update + photos | — | — | — | — | WhatsApp + portal |
| Chat hand-off / unanswered | — | ✅ | after N min | — | — | Telegram + in-app |
| Lead Ad / social comment | Auto-reply (template) | ✅ | ✅ | — | — | Telegram + inbox |
| System health issues | — | — | — | ✅ | — | Telegram (admin) |
| Daily / weekly digest | — | own | team | all | finance | Telegram |

---

## 9. Key end-to-end acceptance scenarios (release gates)

1. **R1 gate: "Enquiry to receipt".** A web enquiry → Telegram alert → assign → call logged → visit
   booked + reminder → visit outcome with measurements → quote from the Renovation template → discount
   approval via Telegram → sent link → viewed alert → accepted → project + 50% invoice → client uploads
   proof → accountant verifies → receipt sent. Every step appears on the contact timeline, and all money
   values reconcile.
2. **R1 gate: "Migration".** Importing v2.1 yields equal counts (± merged duplicates listed) and equal
   totals for quotes, invoices and payments. Staff log in with new passwords.
3. **R2 gate: "WhatsApp day".** Inbound WhatsApp from an unknown number → bot qualifies → lead created →
   hand-off → agent replies in window → outside the window only templates are allowed → quote link
   delivered with read receipt → usage meter counts the messages.
4. **R3 gate: "SEO-safe launch".** 142/142 URLs green in the parity test on staging, CWV targets met, theme
   change published and rolled back without a deploy, 404 log empty after 48 h of production traffic
   (or redirected).
5. **R4 gate: "Social lead".** Facebook Lead Ad test lead appears in the CRM in < 1 min with campaign info
   and an automatic WhatsApp template. A scheduled IG + FB + LinkedIn post publishes on time.

---

## 10. Glossary

| Term | Meaning |
|---|---|
| **Lead** | An opportunity (deal) for a contact in one business line |
| **Contact** | A person (client or prospect). Becomes a *client* after the first accepted quote. |
| **Line** | Business line: Interior, Furniture or Project (turnkey) |
| **BOQ** | Bill of quantities: the sectioned line items of a quotation |
| **Milestone** | A payment stage (e.g., 50% advance) linked to project progress |
| **Window** | WhatsApp/Messenger 24-hour customer service window for free-form replies |
| **Template** | A Meta-approved WhatsApp message format, used outside the window |
| **Parity test** | Automated check that all 142 legacy URLs still work |
| **Token (design)** | A named design value (colour, font, radius) used by the theme builder |
