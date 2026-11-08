# Next-phase delivery plan — frontend/backend completion and deployment readiness

**Prepared:** 2026-10-06
**Status:** First slice and selected browser-coverage spec are implemented. CMS acceptance and API regression coverage pass; site-visit/manual-handoff work is implemented; browser execution is unverified because the Chromium binary is unavailable in this workspace.
**Scope:** Close skipped and pending work across the Woodex storefront, dashboard, API, CRM, quotation flow, CMS, and deployment foundation.

> **Verification update — 2026-10-06:** SQLite QA passes **88/88** and PGlite/PostgreSQL-semantics QA passes **88/88**, including CMS draft/live isolation, quote lifecycle, manual WhatsApp handoff, and site-visit persistence. Dashboard and storefront production builds pass. `npm run e2e:storefront-crm` now covers no-basket enquiry and basket quote paths, consented manual handoffs, CRM/quotation visibility, and a booked/confirmed site visit with an internal reminder; it was **skipped**, not passed, because Playwright could not find a Chromium executable. The storefront build reports CSS-interpolation and >500 kB chunk warnings; the dashboard reports a >500 kB chunk warning. This is not deployment readiness. WhatsApp remains manual/not sent, and the 75%/25% payment split has been removed from the quotation summary and print output; final terms are deferred to the formal quotation.
**Source of truth:** `docs/AGENCY_OS_MASTER_SYSTEM_PRD_PROMPT.md`, current application/API code, `docs/DASHBOARD-IA-AND-SALES-CRM-PLAN-2026-10-05.md`, `docs/CMS-GAP-AUDIT-2026-10-05.md`, and `docs/POSTGRES-CUTOVER.md`.

## 1. Goal and guardrails

The goal is a verified Woodex workflow where the storefront, dashboard, and API show the same persisted state, the remaining business pages are either completed or honestly labelled, and a deployment candidate passes security, browser, database, and recovery checks.

This plan does **not** authorize a production deployment, external provider activation, architecture rewrite, or unconfirmed business policy. Preserve the existing Woodex theme and simple sign-in experience. Keep WhatsApp as a manually logged handoff until a provider/webhook is separately selected, configured, and approved. Do not invent pricing, tax, warranty, legal, payment, or delivery defaults. Do not copy code/content or move records from the separate Woodex sister product.

### Approval gates to resolve before implementation widens

- [ ] **Release scope:** confirm whether the next release is a Woodex single-workspace launch or the full multi-tenant Agency OS. The PRD requires tenant isolation; the current schema has a tenancy/RLS placeholder. Do not claim multi-tenant readiness without isolation tests, or silently waive that requirement.
- [ ] **Architecture:** confirm whether to finish the current Vite + Express + SQLite/PostgreSQL-compatible architecture or start an approved re-platform. The PRD describes a broader NestJS/PostgreSQL/Redis platform; current code is not that architecture. No rewrite or new infrastructure without approval.
- [ ] **Production environment:** choose the real host, domain, PostgreSQL instance, secret manager, backup target, and rollback owner. PGlite is a rehearsal engine, not a production database.
- [ ] **Business rules:** approve any quote tax/pricing formula, invoice header/bank/tax details, discounts, payment milestones, warranty/legal terms, and delivery/installation policies before using them as defaults or issuing real documents.
- [ ] **External channels:** select and approve any chat/WhatsApp/email provider and delivery behavior before enabling live messaging. Until then, user-facing language must say “handoff logged/manual,” never “message sent/delivered.”

## 2. Verified baseline and known gaps

| Area | Current verified state | Still pending / caution |
|---|---|---|
| API and database | SQLite is the local default. PostgreSQL compatibility and PGlite rehearsal are implemented. Latest API QA: **88/88** on SQLite and **88/88** on PGlite. | Real hosted-Postgres migration/canary, backup/restore drill, production secrets, and tenant isolation are not verified. |
| Dashboard/storefront | Dashboard and storefront production builds pass; same-origin API proxies exist. CRM now includes lead stages and site-visit controls. | `npm run e2e:storefront-crm` is implemented but skipped because the Chromium binary is unavailable. A skipped test is not browser acceptance. |
| E-quotations | Phase 2 persistence foundation: detail/reload, structured quote data, server totals, client/lead link, numbered revisions, audit, preview/print UI, and consented manual handoff. | Phase 2 is not complete: approved templates, line discounts/override audit, approval and client-response events, verified share/PDF behavior, browser acceptance, and business-policy sign-off remain. |
| CRM/intake | Leads/clients, identity review, client timeline, tasks, inbox, Contacted/site-visit stages, first-class visit records, and manual WhatsApp handoff logging exist. Visits link a staff reminder task; no customer notification is sent. | Browser journey is unverified; website chat and durable “keep separate” decision remain open. Generic service/showroom WhatsApp links are separate, direct links and are not the logged quote-enquiry flow. |
| Website/CMS | Typed campaign landing pages, theme tools, and visual builder exist. **CMS draft/live acceptance passed:** draft edits stay private, signed preview works, version snapshots and rollback retain published output. | Main site routes remain mostly code-defined; broader page/content/media/SEO management and atomic editor/recovery tests remain. |
| Other business pages | Catalog, orders, invoices, operations, tasks, marketing landing analytics, and clients have API-backed workflows. | Overview/Analytics include mock data; Projects is mock; Settings has local/sample controls; Support is a placeholder; vendors/purchasing are unconfigured; invoice print content still needs approved business identity details. |
| Deployment confidence | Local SQLite and PGlite QA pass; dashboard/storefront builds pass. | No production host, live database canary, executed browser journey, recovery evidence, or production-readiness review has been completed. |

## 3. PRD phase status register

This register maps remaining work to the existing PRD roadmap; it does not rename or silently complete phases.

| PRD phase | Status in this workspace | Work still required for the next release / full platform |
|---|---|---|
| P0 — Discovery & Audit | **Documented; partial code-level refresh** | CMS draft/live behavior is now regression-tested. Complete the remaining route/API/schema audit and confirm the deployment target only after approval. |
| P1 — Product Architecture | **Partially decided** | Resolve the single-workspace vs multi-tenant release boundary and current-stack vs re-platform decision. |
| P2 — Foundation | **Partial** | Real database cutover, environment/secrets, tenant/RLS decision and tests, restore drill, production observability, and CI/browser test runner. Redis/object storage are not present; adopt only if approved/needed for the selected scope. |
| P3 — Dashboard + Design System | **Partial** | Keep the theme; replace or label mock business metrics; persist settings; verify navigation, permissions, error/loading/empty states, and browser accessibility. |
| P4 — CRM | **Partial; site-visit slice implemented** | Lead stages and appointment UI/API are present. Identity-review decisions, ownership/follow-up policy, live-chat linkage, and executed browser acceptance remain. |
| P5 — Sales + Finance | **In progress** | Continue Phase 2 quotation acceptance, approved template/lifecycle rules, invoice print/header configuration, transaction reporting, and browser checks across quote → invoice → payment → order. No gateway/bank reconciliation is verified. |
| P6 — Ecommerce | **Partial** | Validate product → cart → checkout → order consistency; inventory/vendor/purchasing/payment gateway capabilities need a defined scope and data model. |
| P7 — CMS + Theme Engine | **Partial; draft/live acceptance verified** | QA proves draft isolation, signed preview, and published-output retention through rollback. Managed navigation/content routes, media, configured canonical domain, and browser acceptance remain. |
| P8 — Visual Builder | **Partial** | Complete responsive preview and route/content customization needed for the release; validate blocks and published output in a browser. Do not replace the current builder without approval. |
| P9 — Marketing + Social | **Partial** | Landing analytics exists. Campaign management, email/social scheduling and publishing are not complete; provider-backed publishing stays gated. |
| P10 — Omnichannel | **Partial; manual handoff logged** | Quote enquiry and builder handoffs require consent, log a not-sent inbox event, and open `wa.me` manually. Public chat and provider-backed send/receive remain unavailable. |
| P11 — Automation | **Not started as a workflow engine** | Current follow-up tasks are not a trigger/condition/action automation system. Design, dry-run, idempotency, approval, and kill-switch gates remain. |
| P12 — AI + MCP | **Not started / gated** | No autonomous business actions. Any AI/MCP work requires explicit permission, security review, audit, and human-approval rules. |
| P13 — Woodex Business Pack | **Partial** | Complete only the approved furniture workflow (site visits, quote options, production/QC/dispatch/delivery/installation, after-sales) needed for the chosen release. |
| P14 — Production Hardening | **Pending** | Real-host canary, security review, backups/restore, performance/accessibility, monitoring, rollback, and final acceptance review. |

## 4. Pending and incomplete page register

Every route must end in one of two honest states: **working and API-backed for its stated purpose**, or **clearly labelled prototype/placeholder and excluded from launch navigation**.

| Page / route | Current state | TODO to close |
|---|---|---|
| `/` — Overview | Mixed: API figures plus mock revenue/trend content. | Replace mock metrics with reconciled API read models; show the date window, currency, and source of each metric. |
| `/crm` — Lead pipeline | API-backed; Contacted/site-visit stages and appointment booking, rescheduling, status, outcome, and internal reminder controls are present. | Execute the browser journey; add approved next-action/ownership rules and complete lead-history/identity-review workflow. |
| `/clients` — Customer 360 | API-backed, partial. | Persist identity-review decisions (including keep-separate), make links/history consistent after merge, and test link integrity across lead/quote/order/invoice/task/conversation. |
| `/omnichannel` — Staff inbox | API-backed manual workflow. | Connect public-chat sessions and lead attribution; preserve manual message state; display no false delivery claims. |
| Storefront live-chat widget | **Not built.** | Add anonymous visitor session, consent/identity handoff, rate limiting/spam controls, accessible widget, API persistence, inbox thread, and CRM linking. |
| Storefront WhatsApp CTA | Quotation enquiry and basket-builder paths require follow-up consent, log a manual/not-sent inbox event, then open `wa.me`; a retry link remains available. | Run the cross-app browser test. Generic service/showroom/contact links are still direct `wa.me` links and are not this CRM-logged enquiry workflow. Do not send messages automatically. |
| `/tasks` and Site Visits | First-class visit API/schema and CRM UI support date/time/timezone, address, owner, notes/outcome, status, linked lead/client, reschedule audit, and an internal reminder task. | Run browser acceptance; reminders are staff tasks only, not notification delivery. Connect visit records to a dedicated calendar/appointment view if approved. |
| `/quotations` and `/quotations/new` | Phase 2 persistence foundation; 75%/25% terms removed from the storefront estimate/print view in favor of confirming final terms in the formal quotation. | Finish approved templates, line-level discount/override audit, approval/sent/view/client-response history, client-ready PDF/share, and browser acceptance. Test basket and no-basket enquiry paths. |
| `/invoices` | API-backed manual finance workflow; print view has sample business content. | Replace sample header/bank/tax/contact data only after approved business values; verify print/Save PDF, partial/full/overdue balances, and quote snapshot fidelity. |
| `/ecommerce` | Partial API-backed overview. Vendor/purchasing marked unconfigured. | Decide whether vendors, purchase orders, receiving, inventory, coupons, and payment gateways are in the release; otherwise keep clearly marked and out of the go-live path. |
| `/catalog` and product detail/edit routes | API-backed catalog. | Verify all product options and media survive create/edit/reload and appear consistently on storefront, quotes, and product pages. |
| `/orders`, `/orders/:id`, `/operations` | API-backed order/fulfilment paths, with remaining cross-link and KPI gaps. | Prove the accepted quote/invoice/order chain, status/audit snapshots, and API-derived production/QC/delivery/install metrics. |
| `/projects` | **Mock/prototype.** | Either implement persisted projects, tasks, BOQ, milestones, files, and links to client/order/site visits, or remove/hide production navigation and state the limitation. |
| `/support` | **Placeholder.** | Decide the minimal supported use case (ticket, complaint, warranty/return, status history); implement persisted ticket lifecycle or hide the route until approved. |
| `/website` and `/website/edit/:id` | Landing CMS/builder v1; SQLite and PGlite QA verify draft/live isolation, signed preview, version snapshots, and published-output retention through rollback. | Complete editor atomicity/error and browser tests; unpublish UI; route/menu/content management; authenticated media; and accessible responsive preview. |
| Storefront pages/content | Product and core commerce routes are code-defined; CMS manages campaign landings only. | Select and migrate approved routes/content such as home, about, services, projects/blog, FAQs, navigation/header/footer while preserving checkout/product behavior. |
| `/website/theme` | Theme engine available for current site/landing contract. | Verify theme changes synchronize in storefront and dashboard, document precedence, validate mobile/dark behavior, and keep brand identity. |
| `/marketing` | Landing analytics API-backed. | Reconcile attribution to CRM and define the scope for campaigns/forms; do not label unbuilt social/email publishing as live. |
| `/analytics` | **Prototype/mock.** | Replace mock series/sources/campaigns with reconciled API data and date-range queries, or clearly mark as demo and keep out of business decisions. |
| `/settings` | **Mixed/sample/local.** | Persist only approved workspace/team/approval settings through backend APIs; remove sample tenant/domain/tax/team values or label them as demo; ensure permission checks. |
| `/ui-components` | Developer showcase. | Keep out of normal business navigation; no production workflow claim. |

## 5. Prioritized execution checklist

Owners remain **Unassigned** until the user assigns them. “Blocked/approval” means do not invent a value or activate an external system to make the checkbox green.

### Priority and dependency map

- **P0 — release gate:** decisions, data integrity, safe CMS publication, complete scoped sales flow, authorization, browser acceptance, and verified production/recovery checks. P0 means a release cannot be called ready until its applicable acceptance gate passes.
- **P1 — scoped Woodex workflow:** customer-facing CRM, chat/visit intake, CMS customization, and API-backed core pages. If an item is explicitly excluded from the agreed launch, it must be labelled and removed from that release path rather than implied complete.
- **P2 — follow-on/full-platform scope:** optional pages/modules and PRD phases not required by the approved Woodex release boundary; do not treat as live capabilities.
- **Dependencies:** W0 remains the prerequisite for widening scope or claiming release readiness. The user-authorized first slice may proceed only within the existing architecture and the listed guardrails; it does not approve tenant, architecture, commercial-policy, production, or provider decisions. W1–W3 may otherwise proceed only where entities/contracts are agreed. W4 depends on the W0 API/page inventory and runs alongside workflow completion. W5 depends on the accepted parts of W1–W4. W6 depends on W5 plus approved production infrastructure, secrets, business rules, and provider decisions. P-levels do not override an approval/blocked status.

| Work package | Priority | Depends on | Exit / acceptance gate |
|---|---|---|---|
| W0 — Scope, baseline, contracts (NX-01–05) | P0 | None | Release boundary and architecture signed off; current gaps/contracts/business rules inventoried. |
| W1 — CRM, visit, chat, handoff (NX-10–17) | P1 | W0; entity/API contract for lead, client, conversation, appointment | Intake and follow-up browser scenarios pass; WhatsApp stays manual. |
| W2 — Quote-to-cash (NX-20–26) | P0 | W0; W1 client/lead links and approved commercial rules | Quote revision/issue/acceptance and invoice/payment links reconcile. |
| W3 — CMS/page customization (NX-30–36) | P0 for draft/live safety; P1 for expanded route/media/SEO scope | W0; publish/preview contract | Public output is isolated from drafts; editor/browser acceptance passes. |
| W4 — Remaining pages (NX-40–45) | P1 for core launch pages; P2 for explicitly deferred modules | W0; API/read-model decisions | Each visible page is accepted or explicitly excluded/labelled. |
| W5 — Cross-app sync/E2E (NX-50–55) | P0 | W0 plus implemented/accepted W1–W4 journeys | Full browser paths prove persisted state across apps and reloads. |
| W6 — Production readiness (NX-60–66) | P0 before any deployment | W5; approved host/database/secrets/business and provider decisions | Live canary, security/recovery evidence, and go/no-go approval. |


### W0 — Scope, baseline, and contract freeze

- [ ] **NX-01 — Confirm release boundary**: Woodex first launch vs complete Agency OS; decide whether single-tenant or PRD-mandated tenant isolation is the acceptance target.
- [ ] **NX-02 — Approve architecture direction**: record whether the current Vite/Express/dual-driver stack is the near-term target or a re-platform is approved. Preserve current screens/data during any approved migration.
- [ ] **NX-03 — Re-audit current state** *(partial)*: current SQLite/PGlite QA counts and CMS draft/live behavior are refreshed below; full route/API/schema inventory and remaining stale-doc sweep are pending. Mark complete, pending, blocked, or not in release.
- [ ] **NX-04 — API contract map**: document each page’s reads/writes, entity relationships, auth capability, loading/error/empty state, and reload behavior. Keep client/server types and API validation aligned.
- [ ] **NX-05 — Business-rule decisions** *(approval required)*: collect approved discount/tax/payment/warranty/legal/delivery values and quote/invoice wording; otherwise leave configurable/blank and prevent issuing sample documents.

**Gate W0:** release scope, architecture, tenant target, and business-rule decisions are recorded. No large migration or live provider work begins before its approval.

### W1 — Close CRM/intake and live-chat gaps

- [x] **NX-10 — Lead lifecycle (scoped stages)**: Contacted and site-visit booked/completed are represented in the CRM pipeline; API QA covers Contacted and visit transitions. Further lifecycle stages remain PRD-scoped work.
- [x] **NX-11 — Appointment backend/UI (first slice)**: separate visit records carry confirmation/status, owner, timezone-qualified time, location, notes/outcome, lead/client link, reminder task, and reschedule/cancel audit; CRM can book, edit, change status, and save an outcome. SQLite/PGlite QA passes.
- [ ] **NX-12 — Reminder service**: current reminders create internal staff tasks only. Idempotent delivery, quiet hours, failure handling, and any external notification channel remain unimplemented/gated; select a channel only after approval.
- [ ] **NX-13 — Live-chat widget**: build an accessible, responsive customer widget; persist anonymous sessions/messages; allow a visitor to identify/consent before creating or linking a lead; add abuse/rate-limit controls.
- [ ] **NX-14 — CRM/inbox bridge**: turn an identified chat into one client/lead/conversation, preserve anonymous history on handoff, and route assignment/status to the existing inbox.
- [x] **NX-15 — Storefront WhatsApp handoff log (quotation flows)**: the quotation enquiry and basket builder require consent, log the explicit attempt/source before opening `wa.me`, and state that the customer continues manually. No outbound API call. Generic direct WhatsApp links remain separate.
- [ ] **NX-16 — Identity-review decisions**: persist keep-separate/merge outcomes, actor, timestamp, and reason; recheck that links and conversations survive merges.
- [ ] **NX-17 — Intake browser acceptance**: journey script at `scripts/e2e-storefront-crm.mjs` now covers no-basket enquiry and basket quote → consented manual handoff → CRM/quotations plus site visit/reminder. Execution is pending because Chromium is unavailable in this workspace; Contact page and error/retry browser scenarios remain.

**Gate W1:** test new, returning, ambiguous, anonymous-chat-to-known-lead, no-basket, appointment, reminder, and manual handoff paths without silent merges or false messaging claims.

### W2 — Complete the quote-to-cash frontend/backend flow

- [ ] **NX-20 — Quote templates**: add approved ready-made/custom/measured-site templates; keep template terms editable and free of unapproved company policy.
- [ ] **NX-21 — Item pricing audit**: add line-level discount/override fields only after requirements are approved; record actor, old/new values, reason, and resulting version.
- [ ] **NX-22 — Lifecycle and approvals**: align list, builder, API, and permissions for review/approval/issue/revision; append events for sent/viewed/responded/accepted/rejected/expired. Do not claim an email/portal was sent or viewed without a real event source.
- [ ] **NX-23 — Client-ready preview/PDF**: verify formatting, pagination, terms, pricing, PKR, and print-to-PDF in a browser; replace sample invoice/quote identity/bank/tax text only with approved business data.
- [ ] **NX-24 — Quote conversion**: enforce one linked invoice per accepted quote unless a deliberate revision/reissue workflow is approved; preserve client/lead/order links and the issued quote snapshot.
- [ ] **NX-25 — Payment and reporting checks**: validate append-only payment entries, partial/full/overdue balance math, cancellations, and monthly invoiced-vs-collected reporting; no gateway or bank reconciliation claim.
- [ ] **NX-26 — Quote regression acceptance**: save → reload → revise → preview → issue/accept → invoice, across SQLite and PGlite; include a protected issued version, corrupted inputs, and idempotency cases.

**Gate W2:** every quote/invoice field and total survives reload, issued content is never silently replaced, and no policy default is enabled without approval.

### W3 — Complete CMS and page customization safely

- [x] **NX-30 — Draft/live release test**: current SQLite and PGlite QA proves draft edits stay private, signed preview works, and publish/version rollback preserves public output. This resolves the earlier audit suspicion that public reads directly used unsnapshotted draft blocks. Broader editor atomicity/recovery tests remain in NX-31.
- [ ] **NX-31 — Atomic editor lifecycle**: make block save transactional and ordered; a failed latest save must block publish; persist full title/slug/SEO/theme/blocks in version snapshots; rollback must restore a full editable draft; support explicit field clears.
- [ ] **NX-32 — Page operations**: add search/status filters, duplicate/archive, visible unpublish, safe slug/redirect decisions, approvals/scheduling only where approved, and audit history.
- [ ] **NX-33 — Main-site customization**: define which storefront routes become managed page/content records (home/about/services/projects/blog/FAQ/navigation/footer) while preserving product/cart/checkout application routes.
- [ ] **NX-34 — Media**: decide and implement authenticated uploads/asset IDs/validation/alt text/reference checks; do not allow arbitrary unsafe file URLs or orphan deletion.
- [ ] **NX-35 — SEO/domain**: configure an approved production `siteUrl`; include published route families in sitemap/robots/canonical metadata; test social metadata/prerendering with the chosen host.
- [ ] **NX-36 — Theme/builder browser tests**: verify page-specific vs site-wide precedence, responsive preview, keyboard-accessible reorder, images/alt text, forms, and cross-app theme updates.

**Gate W3:** a non-developer can safely edit, preview, publish, and rollback an approved page; public visitors only see the last approved publication.

### W4 — Remove false “complete” claims from remaining pages

- [ ] **NX-40 — Overview/Analytics**: replace mock revenue/trend/lead-source/campaign charts with API-derived read models and real date filters; reconcile definitions and currency.
- [ ] **NX-41 — Settings**: persist approved workspace settings through API, enforce owner permissions, remove sample workspace/domain/tax/team data, and retain a simple login flow without a demo-account selector.
- [ ] **NX-42 — Projects**: define a Woodex project data model and connect client/site visits/BOQ/milestones/files/production/order, or hide/label the prototype until approved.
- [ ] **NX-43 — Support**: define tickets/complaints/warranty/service workflow linked to client/order/return, or keep `/support` out of launch navigation until it exists.
- [ ] **NX-44 — Ecommerce/operations**: approve inventory/vendor/purchasing scope and data model; otherwise keep vendor/purchase UI marked unconfigured. Verify product/stock/order/fulfilment synchronization.
- [ ] **NX-45 — Cross-page data states**: every production page has API-backed loading, empty, error, permission, save-success, and retry behavior; no sample value silently appears as a real business record.

**Gate W4:** every visible business page is accepted as working for its stated purpose or clearly labelled as a prototype and excluded from production workflows.

### W5 — Shared frontend/backend synchronization

- [ ] **NX-50 — Product sync**: dashboard catalog edit → API → storefront product detail, search, landing blocks, and quote builder; verify cache/realtime refresh and stock/price consistency.
- [ ] **NX-51 — Lead/client sync**: storefront forms/chat → lead/client/inbox/task; CRM change → client timeline/inbox; verify consent/source and human review.
- [ ] **NX-52 — Sales sync**: quote revision → invoice snapshot → payments/balance → order → operations/status lookup, with stable identifiers and no orphan/duplicate records.
- [ ] **NX-53 — CMS/theme sync**: dashboard preview/publish/theme changes → public storefront at the configured host; verify draft privacy and rollback.
- [ ] **NX-54 — API/auth contract**: standardize request/response validation, capabilities, same-origin paths, error formats, and server-owned calculations; verify every write endpoint has the intended role gate.
- [ ] **NX-55 — Cross-app E2E suite**: `npm run e2e:storefront-crm` covers no-basket enquiry and basket quote, logged manual handoffs, CRM/quotation visibility, site-visit booking, and staff reminder. It skipped locally because no Chromium executable is installed. `.github/workflows/ci.yml` now provisions Chromium, starts the app servers, and invokes both browser suites with `E2E_REQUIRED=1` (missing browsers fail CI rather than skip green); remote CI execution is still pending a push and Actions run. Extend the suite for chat, invoice/payment, product sync, and CMS publish.

**Gate W5:** the same record and business state is visible in each app after mutation/reload; no route relies on unpersisted local-only state for a business operation.

### W6 — Production deployment readiness

- [ ] **NX-60 — Real PostgreSQL migration/canary**: provision the approved host; run the 1:1 migrator and `--pg-rehearsal` against the same real target; validate row counts, digests, ID continuity, transactions, and rollback. Keep SQLite rollback copy until sign-off.
- [ ] **NX-61 — Tenant and permission security**: implement and test the approved tenant boundary and server-side capabilities. Test signed-out, each role, cross-tenant access, public routes, and direct API calls.
- [ ] **NX-62 — Runtime secrets/auth**: define production credential provisioning/rotation out of chat/repository; prevent production use of development/demo seeds; keep the UI login simple and do not seed a demo-user list.
- [ ] **NX-63 — Environment and domain**: validate production URL, TLS/proxy/host allowlists, CORS policy if needed, API origins, file storage, email/chat integrations, and health/readiness endpoints.
- [ ] **NX-64 — Backup/restore and operations**: schedule backups; perform a restore drill; document migrations, rollback, incident contact, logs/monitoring, rate limits, and data retention.
- [ ] **NX-65 — Build/security/performance**: run dashboard/storefront/API builds, QA suites, secret scan, dependency review, accessibility checks, and performance budgets; split or justify the dashboard bundle above the current 500 kB advisory.
- [ ] **NX-66 — Release sign-off**: complete the acceptance matrix below, record known limitations, obtain user approval, then deploy only to the approved target.

**Gate W6:** production readiness review passes; actual deployment remains a separate user-approved action.

## 6. End-to-end acceptance matrix

| ID | Scenario | Pass condition |
|---|---|---|
| E2E-01 | Dashboard edits a catalog item | API persists change; storefront and quotation builder show the same approved price/stock after refresh. |
| E2E-02 | New/returning/ambiguous storefront enquiry | Correct client/lead links, consent/source, inbox event, follow-up and human-review behavior; no silent merge. |
| E2E-03 | Visitor chat to CRM | Anonymous message stays anonymous until identification/consent; handoff preserves the thread and creates/links one lead/client. |
| E2E-04 | WhatsApp handoff | Handoff attempt is logged and opens the manual channel; UI does not claim provider delivery or inbound sync. |
| E2E-05 | Site visit | Booking/reschedule/cancel/reminder/outcome is linked to lead/client and audited; reminders are idempotent. |
| E2E-06 | Quote through invoice | Save/reload/revise/preview; issued version retained; totals server-validated; client/lead link and invoice snapshot preserved. |
| E2E-07 | Payment/order | Partial/full payment updates balance; order and operations retain quote/client links; status survives reload. |
| E2E-08 | CMS edit/publish/rollback | Edit a published page; anonymous public output remains unchanged until publish; rollback restores the complete snapshot. |
| E2E-09 | Theme/page customization | Approved theme change appears on the correct public pages and does not leak into unrelated pages/tenants. |
| E2E-10 | RBAC/tenant | Direct API requests for forbidden actions/other tenants are denied; UI hiding is not the only control. |
| E2E-11 | Error/recovery | Failed save is visible, does not publish partial content, and can be retried without duplicate business records. |
| E2E-12 | Deploy/restore | Real-host smoke passes; backup can be restored; rollback steps are documented and verified. |

Run `npm run qa`, `npm run qa:pg`, both app builds (`npm run build`), `npm run e2e`, and `npm run e2e:storefront-crm` in a browser-capable CI environment. Current API QA passes **88/88** on SQLite and **88/88** on PGlite; preserve these gates and add tests for new features rather than replacing them.

## 7. Explicitly deferred or approval-gated scope

These are not silently assumed to be part of the first Woodex deployment. Keep them in the PRD roadmap and label clearly until separately accepted:

- Live WhatsApp Business send/receive, email delivery, payment gateways, social publishing, and third-party webhooks — require provider choice, secure runtime secrets, consent/opt-out behavior, test accounts, and delivery verification.
- Multi-tenant Agency OS launch — requires tenant-scoped data, RLS/capability tests, and cross-tenant denial tests; current placeholder is not enough.
- Redis, object storage, NestJS/Next.js migration, static export, and full package reorganization — architecture choices requiring explicit approval; do not undertake as “cleanup” without scope and migration plans.
- PRD P11 automation, P12 AI/MCP, broad P9 social publishing, and unapproved Woodex Business Pack modules — human review/security gates and product requirements first.
- Any tax, discount, payment, warranty, legal, or delivery default — wait for approved Woodex rules.

## 8. Recommended first execution slice — progress

The user authorized only this first slice before the wider W0 release-boundary decisions are resolved. Continue only within this scope and stop at any business, architecture, tenant, production, or provider approval gate.

1. **CMS draft/live acceptance — complete:** SQLite/PGlite QA proves draft isolation, signed preview, versioning, and rollback preservation. The browser editor test remains pending.
2. **Phase 2 quotation acceptance — partial:** API persistence, revision, server-total, acceptance/conversion, and invoice checks pass in the QA battery. Browser preview/print/share, full client-response events, approved templates/line overrides, and commercial-policy sign-off remain.
3. **Storefront-to-CRM browser flows — spec expanded, execution blocked:** `scripts/e2e-storefront-crm.mjs` covers no-basket enquiry and basket quote paths, consent, logged not-sent handoffs, CRM/quotation visibility, and site-visit/reminder follow-up. It skipped here because the Chromium executable is unavailable; do not call it passed.
4. **Site visits/manual handoff — implemented and API-tested:** first-class site-visit records and CRM booking/edit/status/outcome UI are present; reminders create internal staff tasks only. Quotation enquiry/builder handoffs require consent, log before opening `wa.me`, and never send automatically. Browser verification remains blocked.
5. **Overview/Analytics/Settings mock-value cleanup — not started in this slice.** Keep pending; do not broaden without authorization.

Then proceed to public live chat and CMS main-site customization only once their data, consent, routing, and approval rules are agreed.
