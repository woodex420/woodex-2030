# Woodex Agency OS — Master Plan V2 (Canonical)

**Prepared:** 2026-10-15 · **Product:** Agency OS Core + Woodex Furniture Business Pack · **Operating market:** Lahore, Pakistan · **Currency:** PKR
**Status:** Canonical planning baseline. This document does **not** declare a phase complete, authorize production deployment, activate an external provider, or approve a data migration.
**Supersedes:** `docs/archive/AGENCY-OS-MASTER-PLAN-v3.md` as planning authority; the previous file is retained as historical context.
**Requirements source:** `docs/product/PRD-AGENCY-OS.md` remains authoritative for the approved product requirements and the official P0–P14 phase names.
**Version note:** V2 is the user-requested re-baseline and replacement of the prior v3.1 planning file; it is not a release number or a claim that the product has regressed or shipped.

---

## 1. Purpose and decision summary

Woodex Agency OS is the intended reusable platform. The current deliverable is not a generic, fully deployed SaaS product: it is a working monorepo with a growing **Woodex Furniture** business pack. The platform should keep a generic core—identity, permissions, audit, settings, integrations, content and workflow primitives—and put furniture-specific catalog, quotation, order and delivery behavior in a separable pack.

The plan adopts these principles:

1. **One product boundary per release.** Agency OS Core is the platform direction; Woodex Furniture is the first live business pack. The separate Woodex Interior / MarketingWoodex product remains a separate system. No code, content, customer records, credentials, SQL dumps, archives, or production data are copied or migrated between sister products under this plan.
2. **One business-data authority.** Keep one API and one authoritative database path for each environment. Do not create a second backend or synchronize hidden copies of business records. Exports and published snapshots are derived artifacts, not a second editable source of truth.
3. **Keep the working product; evolve it incrementally.** Continue improving the existing Dashboard, Storefront, API, CMS and quote flow. No blind rewrite, framework migration, new provider, or production cutover without a recorded decision and compatibility plan.
4. **Treat status honestly.** A local demo, PGlite rehearsal, mocked card, configured URL, logged handoff, skipped test, or UI badge is not evidence of a production integration or deployment.
5. **Preserve user-approved UX and commercial constraints.** Keep the existing Woodex Dashboard theme (do not replace it with TailAdmin). Keep the simple `admin` / `admin` development sign-in; do not re-add a demo-account list or claim browser sign-in is fixed without browser verification. In the quotation slice, retain the estimated total, omit a 75%/25% split, print **“Final payment terms will be included in formal quotation.”**, and do not add a client-facing share URL.
6. **Approval gates remain real.** Production hosting, tenant boundaries, commercial rules, provider activation, and external data migration remain blocked until the relevant owner decisions and acceptance evidence exist.

### The recommendation

Use the existing React/Vite Dashboard and Storefront plus the single `/api` service as the near-term implementation base. The current API uses SQLite by default and has a PostgreSQL-compatible data layer and PGlite QA/rehearsal path. Treat this as the **current development architecture**, not the final production architecture. The PRD’s broader target (Next.js, NestJS, PostgreSQL, Redis and object storage) remains a future architecture decision to be validated and delivered incrementally; it is not permission to start a rewrite.

The next execution order is: **(a)** close browser-verification gaps with a Chromium-capable run, **(b)** record the release boundary and architecture decision, **(c)** finish the approved CRM/sales workflows and their policy gates, **(d)** finish CMS/builder safety and acceptance, **(e)** expand operational, marketing and communication capabilities only as they become API-backed and approved, and **(f)** enter production hardening only after an explicitly approved target environment exists.

---

## 2. Source hierarchy and evidence standard

Apply the source-of-truth order in the PRD without silently replacing requirements:

1. User-approved decisions and standing constraints.
2. Current application code, database schema/migrations, and tests.
3. The official PRD at `docs/product/PRD-AGENCY-OS.md`.
4. Current architecture, QA and delivery documents in this repository.
5. Supplied external reports, used only within their stated scope and confidence.
6. Official technical documentation and carefully license-checked reference material.

Every status in this plan is one of **implemented and verified**, **implemented but browser-unverified**, **partial**, **not started**, or **blocked/approval required**. “Planned” is never a synonym for “working.” A test that skips because Chromium or a service is unavailable is explicitly **unverified**, not passed.

The attached `p23.md` analysis is a static audit of a different repository and product: **Woodex Interior / MarketingWoodex**. Its counts, branches, deployment tree, secrets, PHP/MySQL behavior, live host assumptions and release recommendation belong to that audited subject only. It did not inspect this Agency OS runtime or its production environment, and it cannot establish this repository’s production state.

---

## 3. Analysis of the supplied P23 report

### 3.1 What the report says about its own subject

The attached report is dated 2026-10-13 and identifies its subject as the external Woodex Interior repository at `main` revision `782b50b`, with additional branch and release-tree analysis. Its inventory snapshot is approximately **314 MB / 2,674 tracked files**, **150 HTML documents**, **364 PHP actions**, **20 MySQL tables / 161 columns**, 13 branches and 16 release trees. Its Postgres/Supabase table count is not internally stable (17 in the headline summary versus 18 distinct tables later), so that number needs reconciliation before anyone uses it for migration planning. None of these figures describe Agency OS.

The report describes overlapping site/admin release trees, a PHP/MySQL operational path alongside a serverless/Postgres path, JSON-file stores, deployment archives, and an unclear authoritative build. It reports critical credential exposure in that **other repository**, possible non-atomic JSON writes, incomplete schema dumps, private-path protection dependent on unverified web-server rules, misleading integration indicators, and a release-readiness recommendation of **BLOCK**. Its own method section says runtime behavior was **not executed**: no PHP runtime, MySQL, live host or browser was available. It also says the live validity of the reported credentials is unknown and that the correct deployed tree cannot be proven from the repository alone.

Those statements are treated as the supplied auditor’s evidence about its audited repository, not as independently verified facts about this codebase. The external **BLOCK** applies to that report's subject; it neither proves that Agency OS is blocked nor proves that Agency OS is production-ready. No credential values are reproduced here.

### 3.2 Transferable engineering lessons, applied to Agency OS

| P23 report signal (external product) | Agency OS control adopted in this plan | Boundary / verification needed |
|---|---|---|
| Multiple overlapping release trees made the deployed version ambiguous. | Maintain one source tree and a release manifest that identifies commit, build, migrations, QA results and rollback artifact. | Verify against this repository and its eventual deployment target; do not deploy any external tree. |
| Two backend paths and several stores could diverge. | Keep a single API contract and database authority per environment; add migrations and parity tests before changing the driver. | Current SQLite/PGlite evidence is not a real hosted-PostgreSQL canary. |
| Tracked credentials and public exposure created critical risk. | Run secret scanning in CI; keep runtime secrets outside Git, browser bundles and chat; document rotation/remediation without publishing values. | The external report’s keys belong to its owner and source repository. Their revocation is not performed by this plan. |
| Private file-backed stores relied on unverified web-server rules for access protection. | Keep secrets/business data out of web-served paths; enforce authorization in the application and test denied requests from outside the process. | Do not assume that Apache/LiteSpeed rules or deployment topology apply to this Vite/API application. |
| JSON read/modify/write had a concurrency risk. | Persist business state transactionally; if a file-backed store is ever justified, specify locking, atomic replacement, recovery and tests first. | This is a design lesson, not a claim that the same defect exists in Agency OS. |
| An integrations screen could indicate “connected” without making a provider request. | Use truthful states: `not configured`, `demo`, `manual handoff`, `connected`, `degraded`, `disconnected`; reserve `connected` for a successful server-side health check or approved canary. | No external messaging or payment provider is currently claimed live. |
| Static analysis and stubbed tests did not prove production behavior. | Separate static/build/API/browser/staging/production evidence. Require browser E2E to execute (not skip) before its acceptance gate. | Current browser suites were skipped locally because Chromium is unavailable. |

### 3.3 What is explicitly not transferred

The external repository’s PHP stack, deployment choice, branch, release tree, menu text, builder implementation, schema, SQL, archives, site pages, customer data, credentials, logos, or other assets are **not** adopted or imported. The report can inform risk controls and questions; it does not authorize a second Woodex product to become an Agency OS tenant, nor does it prove shared ownership, consent, license, or data-export rights. Any future cross-product data move would require its own written scope, owner approval, privacy/legal review, field mapping, backup, deduplication plan and rollback procedure. Until then, it is out of scope.

---

## 4. Product model and scope boundary

### 4.1 Agency OS Core

The long-term generic platform layer is responsible for:

- organizations, tenant/workspace boundaries, sites/domains and environment identity;
- users, teams, server-side roles/capabilities, authentication and session lifecycle;
- audit history, notifications, feature flags and workspace settings;
- API contracts, provider adapters, webhook validation and integration-health state;
- shared design system, accessible forms/tables, navigation, error handling and test harness;
- generic workflow primitives that do not embed Furniture-only policy.

These primitives are not all complete today. In particular, production-grade tenant isolation, production auth, integrations and operational observability are not claimed complete.

### 4.2 Woodex Furniture Business Pack (first real pack)

Furniture-specific behavior belongs behind a pack boundary:

- catalog, categories, materials, finishes, variants and availability;
- enquiries/leads, client identity review, quotations and immutable quote revisions;
- sales orders, invoices, append-only payments, returns and customer status lookup;
- approved site visits, production milestones, quality checks, dispatch, delivery, installation and after-sales as they are implemented;
- furniture landing-page blocks and content bindings, without hard-coding those rules into generic Agency OS primitives.

Only the modules and statuses backed by the current API may be shown as working. Project/production workflows that remain prototype or partial must remain labelled accordingly.

### 4.3 Product separation

Woodex Furniture and Woodex Interior / MarketingWoodex remain separate product experiences and data domains. This plan does not provision a second tenant for the sister product and does not share CRM identities, website content, media, provider credentials, database records, or release artifacts across them. A future multi-tenant Agency OS must demonstrate isolation with synthetic test tenants before it can be considered ready for multiple businesses.

### 4.4 In-scope versus gated

| In scope for the plan | Gated; not implied by this document |
|---|---|
| Improve and verify existing Dashboard, Storefront, API, CRM, quotation, finance, CMS and builder capabilities. | Production deployment, production-domain cutover, production database or live credential creation. |
| Build the reusable Agency OS core and Furniture pack in small, testable increments. | Live WhatsApp, email, social, payment, Google, or other external provider delivery. |
| Complete API-backed workflows with persisted state, honest status and browser tests. | Multi-tenant launch or sister-product data migration without isolation and owner approval. |
| Record explicit policy decisions and keep unsigned business terms configurable/blank. | Inventing tax, price, discount, payment milestone, warranty, refund or delivery terms. |
| Keep AI drafts human-reviewed and permissions server-enforced. | Autonomous publishing, outbound messaging, financial actions, permission changes or destructive operations. |

---

## 5. Current baseline (2026-10-15)

This is a code-and-test baseline, not a live production audit.

### 5.1 Runtime and data

| Layer | Current implementation | Evidence / limitation |
|---|---|---|
| Dashboard | React + Vite in `apps/dashboard`; existing Woodex design system. | Production build and strict TypeScript build pass. Browser interaction remains subject to executed E2E. |
| Storefront | React/Vite in `apps/storefront`. | Production build passes; no claim of a production host or real payment gateway. |
| API | Express in `apps/api`, one `/api` contract; SQLite is the local default. | Server owns persistence, validation, quote calculations and permissions. |
| PostgreSQL path | PostgreSQL-compatible adapter, schema/migration support and PGlite rehearsal. | QA demonstrates semantic parity in rehearsal; PGlite is not production PostgreSQL and no live cutover has been approved. |
| Development preview | Default local layout: Dashboard `:5173`, API `:3001`, Storefront `:5174`. Arena preview layout: Dashboard `:3001`, API `:3002`, Storefront `:5174`. | Port remapping is a development-preview arrangement, not the production topology. |
| System of record | Current API database. | Do not add a parallel JSON, serverless or client-side source of truth for business records. |

### 5.2 Capability status

| Capability | Baseline | Open acceptance / limitation |
|---|---|---|
| Authentication and permissions | Simple `admin` / `admin` demo sign-in; API sessions and role/capability checks have API QA coverage. | Do not treat demo credentials as production auth. Do not claim the full browser login path fixed unless the Chromium E2E actually runs. Production identity policy is gated. |
| CRM | Leads, client records, identity-review queue, client history/tasks and API-backed intake exist. | Complete browser journeys and persistent human resolution for ambiguous/keep-separate identity cases; do not silently merge people. |
| Quotations | Quote persistence, revisions, calculated totals, snapshots and print/preview foundation exist; reusable template designer was added. | Browser E2E is pending; approved commercial policy, lifecycle events and production document identity remain gated. Preserve the accepted note and omit the 75%/25% breakdown. |
| Finance/operations | Invoices, manual payments, returns, orders and workflow/task links exist in the development application. | No payment gateway, bank reconciliation or production accounting sign-off. A recorded payment is not a gateway settlement. |
| CMS/theme | Typed page blocks, draft/published snapshots, signed preview, versions/rollback, reusable sections and theme tooling exist. | No production `siteUrl`, full managed-site coverage, approved static export/SSR target or complete publish governance. |
| Visual builder | Existing `/website` CMS supports add/edit/remove, reorder, navigator and signed preview. A responsive preview with desktop/tablet/mobile modes was added. | Build/API QA pass; browser E2E is currently skipped for lack of Chromium. A skip is not an acceptance pass. |
| Marketing analytics | First-party landing analytics and basic attribution capabilities exist. | Do not claim complete campaigns, social publishing, email delivery or reconciled company-wide analytics. |
| Omnichannel | Internal inbox and manual/logged `wa.me` handoff flow exist. | WhatsApp remains **manual and logged**, not sent/delivered by an API. No live provider/webhook is claimed. |
| Automation / AI / MCP | Follow-up tasks and constrained draft behaviors are not a full automation platform. | Trigger engines, external actions, autonomous agents and MCP writes remain future, permission-gated work. |
| Production hardening | Development builds, SQLite QA and PostgreSQL/PGlite QA exist. | No production host, production migration, backup/restore drill, incident runbook, monitoring or go-live sign-off has been verified. |

### 5.3 Verification snapshot — updated 2026-11-06

- Latest SQLite API/security QA: **92/92 passed**; the suite uses an isolated rehearsal database and synthetic rows.
- Latest PostgreSQL/PGlite rehearsal QA: **92/92 passed**. This verifies PostgreSQL semantics, not a hosted database.
- Dashboard, Storefront and combined Vercel production builds: **passed**. Bundle-size/CSS warnings remain technical debt.
- Browser E2E journeys: **not verified locally**. The full CI command stopped before browser tests because Chromium is not installed in this sandbox. Require an actual GitHub PR check before merging.
- The linked single-project Vercel target has its build settings configured, but no database provider/project or Vercel environment variables have been selected/configured; no new deployment has been made.
- Production deployment, live integration delivery, tenant isolation, backup/restore and recovery: **not verified**.

---

## 6. Target architecture and migration guardrails

### 6.1 Current operating shape

```text
Dashboard (React/Vite) ─┐
                         ├── same-origin /api proxy ── Express API ── SQLite (development default)
Storefront (React/Vite) ─┘                              └────────────── PostgreSQL-compatible adapter
                                                                └── PGlite rehearsal / QA
```

### 6.2 PRD target, reached through a decision gate

The PRD describes a future stack with Next.js, NestJS, PostgreSQL, Redis and S3-compatible object storage. Preserve that as the target to evaluate, but migrate only after an architecture decision record (ADR) proves the need, compatibility, cost, operating owner and rollback path. A preferred path is:

1. Stabilize current API contracts, migrations, auth/capabilities, audit and regression suites.
2. Select and provision a real database target; prove backup, restore, migration, row counts, digests and rollback before switching any live traffic.
3. Introduce tenant IDs and server-side row scoping with cross-tenant denial tests; do not rely on a UI workspace selector.
4. Add queue/cache/object storage only for an accepted use case, with service boundaries and health checks. Redis/S3 are not assumed to exist today.
5. If NestJS or Next.js is approved, migrate one bounded context at a time behind stable HTTP contracts; retain the current app until parity and rollback pass.

A modular monolith is the default implementation style until a measured scale/team need justifies service extraction. Keep one API gateway and one authoritative write path; do not build a second backend in parallel.

### 6.3 Data and state rules

- Persist business state in the authoritative database; use transactions for multi-record changes.
- Preserve issued quote/invoice snapshots. Amendments create revisions, not silent overwrites.
- Keep public content on its last successfully published snapshot; previews are signed/authorized and drafts are not public.
- Keep identifiers stable across CRM, quotation, invoice, order, payment, return and conversation records; define deletion/retention as soft-delete/archive where needed.
- Make migrations explicit, replayable and tested on a copy. No live import from the P23 project or any other sister product is authorized.
- Treat metrics as read models with named definitions, currency, time window and source. Demo/chart samples must be labelled and excluded from business decisions.

---

## 7. Official P0–P14 roadmap and status register

The phase names and top-level gates below preserve PRD §22. Status is as of **2026-10-15**. A phase can contain shipped slices without being complete. The sequence is an execution recommendation, not a renumbering of the PRD.

| PRD phase | Current status | Next deliverable | Exit gate (must be evidenced) |
|---|---|---|---|
| **P0 — Discovery & Audit** | **Documented; production evidence incomplete.** This V2 integrates the supplied report within its boundary. | Finish the source-code, route, data, security and acceptance inventory for this repository; attach decisions to the log. | Findings are scoped to the right product, risks have owners, and the next architecture recommendation is approved. |
| **P1 — Product Architecture** | **Partially documented; open decisions remain.** | Approve near-term release boundary, tenant target, database/hosting and migration strategy. | Signed ADRs; no conflict with PRD requirements; rollback path and ownership are explicit. |
| **P2 — Foundation** | **Partial.** Monorepo, API proxy, SQLite baseline, PostgreSQL-compatible data layer, QA and some auth/RBAC exist. | Establish environment/secrets, migration discipline, audit/tenant model and reproducible test pipeline. | Build + API contracts pass; migrations/rollback pass; roles and tenant-denial tests prove server enforcement. |
| **P3 — Dashboard + Design System** | **Partial; existing Woodex theme is retained.** | Reconcile remaining mocked analytics/settings and finish navigation, notification, empty/error/accessibility behavior. | Usability review; all live figures trace to API sources; permission and accessibility checks pass. |
| **P4 — CRM** | **Partial; core lead/client workflows exist.** | Complete identity-review outcomes, ownership/follow-up and cross-app journey acceptance. | New, returning, ambiguous and keep-separate cases pass with an auditable client timeline and no silent merges. |
| **P5 — Sales + Finance** | **Core slices implemented; phase not signed off.** | Complete the approved quote→revision→invoice→manual payment→order flow and its document/browser checks. | Immutable snapshots and totals reconcile in SQLite and PostgreSQL rehearsal; policies are explicitly approved; no gateway claim. |
| **P6 — Ecommerce** | **Partial.** Storefront/catalog/order paths exist. | Define and verify inventory, fulfillment and payment scope; keep unconfigured features out of the live path. | Product→cart/quote/checkout→order state reconciles; external payment behavior is only claimed after sandbox/live verification. |
| **P7 — CMS + Theme Engine** | **Core exists; site-wide content and release gates remain.** | Complete page lifecycle recovery, managed-route/media/domain policy and publish checks. | Draft/public isolation, revisions, rollback, SEO metadata and full acceptance pass on the approved host profile. |
| **P8 — Visual Builder** | **Scoped typed-block builder exists; browser gate open.** | Run the add/edit/reorder/remove/responsive-preview suite in Chromium; close dirty-state, keyboard and error recovery gaps. | Non-developer completes the full editing/preview/publish/rollback journey; no public draft leakage; tests execute, not skip. |
| **P9 — Marketing + Social** | **Partial.** First-party landing analytics exists. | Define campaigns and provider adapters; build approval/scheduling only after account and content policy approval. | Idea→approval→schedule→publish→analytics is evidenced against a real authorized test account; no mock status. |
| **P10 — Omnichannel** | **Partial; manual handoff only.** | Keep inbox, consent, opt-out and audit state accurate; define provider and webhook before any send/receive implementation. | Synthetic inbound/outbound provider test and delivery mapping pass; until then the UI says manual/not sent. |
| **P11 — Automation** | **Not started as a workflow engine.** | Design trigger/condition/action schema, simulator, dry-run, idempotency, retries, quiet hours and kill switch. | Approved automations pass replay/failure tests and have a clear operator stop control. |
| **P12 — AI + MCP** | **Not production-ready; controlled drafts only.** | Define tool allowlist, permission checks, human approvals, data minimization and audit. | Security review plus read-only first; write actions require explicit role, confirmation and traceable audit ID. |
| **P13 — Woodex Business Pack** | **Partial.** Catalog/sales/order/returns foundations exist. | Specify and implement only approved production, QC, dispatch, delivery, installation and after-sales workflows. | End-to-end furniture transaction passes with real approved policy values and reliable audit/history. |
| **P14 — Production Hardening** | **Pending; deployment is not approved.** | Provision approved host/secrets/DB, monitor, backup/restore and release candidate with rollback. | Security, performance, accessibility, browser, backup/restore, smoke and owner go/no-go gates all pass. |

### Recommended work waves (mapped to, not replacing, the PRD phases)

1. **Wave A — Evidence and decisions:** update code-level inventory, record the release/tenant/architecture decisions, run the browser suites in Chromium, and make skipped tests visible as blockers.
2. **Wave B — Stable foundation and core workflows:** finish the selected P2/P3 prerequisites, P4 identity review, and P5 quote-to-cash acceptance. Keep business rules and invoice identity user-approved.
3. **Wave C — Public web editing:** finish P7/P8 CMS lifecycle and responsive preview acceptance; do not replace the current typed-block builder without a separately approved ADR.
4. **Wave D — Operations and growth:** extend P6/P13 only for approved inventory, production and delivery requirements; wire P9/P10 only with real provider/account approval.
5. **Wave E — Controlled platform expansion:** add P11 automations and P12 AI/MCP after permissions, audit, dry-run and rollback foundations.
6. **Wave F — Go-live:** P14 remains a distinct review after an environment, responsible owner and rollback operator are designated. A successful development QA run alone never opens this gate.

---

## 8. Cross-cutting release and acceptance gates

### 8.1 Definition of done

A phase or release slice is accepted only when all applicable items are true:

- requirements and data ownership are clear; no unresolved cross-product import is hidden in the implementation;
- user flows work in the existing Woodex theme with responsive, keyboard-accessible controls;
- server-side authorization is tested directly, not just hidden in the UI;
- data persists, reloads and reconciles across the Dashboard, API and Storefront;
- draft/public state, financial snapshots, audit history and failure recovery are correct;
- build, API QA, DB/migration tests and browser E2E execute and pass; skipped suites remain open;
- error, empty, loading, offline, denied and retry states are truthful;
- dependencies, licenses, secrets, backups and rollback have been reviewed for the changed scope;
- documentation and `docs/architecture/DECISION-LOG.md` are updated; user approval is obtained for gated decisions.

### 8.2 Security and privacy

- Never place production credentials in Git, client bundles, documents, URLs or chat. Use an approved secret store and rotation runbook when production is authorized.
- Run secret scan in CI and before release; a clean scan does not replace credential rotation or provider-side verification.
- Keep server-side RBAC authoritative. Before multi-tenancy, test at least two synthetic tenants plus signed-out and role-restricted users against every sensitive endpoint.
- Sign/authorize preview links; do not let `?draft=1` alone expose draft content.
- Verify webhook signatures, replay protection, rate limits, file validation, consent, retention, and opt-out behavior before enabling external channels.
- External P23 secret findings remain the responsibility of that repository’s owner; this document neither includes the values nor authorizes access, rotation on behalf of that owner, or deployment of that product.

### 8.3 Messaging, payments and AI truthfulness

- WhatsApp is a **manual/logged handoff**. Do not display “sent,” “delivered,” or “replied” without provider evidence. Do not enable outbound provider delivery without configuration, consent/opt-out, webhook verification and owner approval.
- Payment entries are internal records unless a real gateway/bank source confirms settlement. Do not call a manual payment record a gateway transaction.
- AI-generated copy/quotes/replies are drafts. Publishing, outbound communication, discounts, refunds, financial updates, access-control changes and destructive operations require the applicable human approval and server-side permission.

### 8.4 Quotation and CMS non-regression requirements

- Quote preview/print retains the estimated total and the exact sentence **“Final payment terms will be included in formal quotation.”**
- Do not introduce a 75%/25% payment breakdown in the approved quotation slice; do not add a client-facing share URL to that slice.
- Quote templates and issued quotes are snapshots; changing a reusable template must not rewrite an existing quote.
- Page-builder work extends the existing Website / Pages CMS. The slice remains typed-block add/edit/reorder/remove, with a real signed preview and device widths; it is not a second CMS, a new app module or a raw HTML/JS execution surface.

### 8.5 Production go/no-go checklist

No production release until an owner approves and evidence exists for: target host/domain and TLS; database provisioning and migration/rollback; secret provisioning/rotation; tenant and RBAC tests; privacy/legal/commercial policy; provider sandbox and delivery checks (if any); backups and successful restore; monitoring/incident contacts; executed browser suites; accessibility/performance review; release manifest; and a rollback rehearsal. Unknown values remain blank or blocked rather than guessed.

---

## 9. Risk register

| Risk | Impact | Current treatment | Gate to close |
|---|---|---|---|
| External P23 findings could be mistakenly attributed to this product. | Wrong remediation, unsafe migration, cross-product contamination. | Scope label on all borrowed evidence; separate-product rule. | Review each proposed reuse/import against written ownership and approval. |
| Old plans and stale QA counts conflict with current code. | False phase-complete or release claims. | V2 becomes canonical; older plans are explicitly historical; cite date and test artifact. | Refresh audit/decision log at each release candidate. |
| Browser E2E skips locally without Chromium. | UI/auth/preview regressions remain unseen. | CI installs Chromium and `E2E_REQUIRED=1`; local skip is labelled. | Obtain an executed CI run and retain artifacts/logs. |
| SQLite/PGlite rehearsal is mistaken for production DB validation. | Migration, concurrency, backup or hosting failures. | Keep local/rehearsal labels; preserve digest and rollback checks. | Approved real PostgreSQL canary plus restore test. |
| Demo auth is treated as production security. | Account compromise or incorrect deployment assumptions. | Simple demo login retained only for development; production auth is a separate gate. | Approve and test production identity/session design. |
| API, provider or analytics status is shown as “connected” without a real check. | Operators make decisions from false delivery or KPI states. | Explicit integration mode and source labels. | Provider health/canary plus failure-mode tests. |
| Incomplete financial, tax, payment or delivery rules are filled with assumptions. | Incorrect quotations, invoices or customer commitments. | Keep policies configurable/blank; retain accepted quote wording. | Written business-owner approval and regression tests. |
| Multi-tenant scope is claimed before tenant isolation exists. | Data disclosure across customers/businesses. | One pack/test tenant only; no cross-sister-product data. | Cross-tenant API denial suite and owner sign-off. |
| One-person/operator development causes test/documentation drift. | Unsafe release and stale source-of-truth docs. | Release manifest, CI, decision log and phase gates. | Assign release owner and backup/rollback operator. |

---

## 10. Decisions still requiring owner approval

The plan is canonical, but these decisions are **not** silently decided by writing it:

| Decision | Recommendation for discussion | Why it stays gated |
|---|---|---|
| First supported release boundary | Finish the Woodex Furniture workflow first while keeping the Agency OS core generic. | A Woodex-only release is not automatically a full multi-tenant Agency OS release under PRD §06. |
| Current stack vs PRD re-platform | Stabilize current Vite/Express/SQLite + PostgreSQL-compatible path; approve an ADR before Next.js/NestJS/Redis/object-storage migration. | Migration changes operations, interfaces, cost, security and rollback. |
| Tenant model and production hosting | Define tenant/site identifiers, auth provider, host, database and operations owner before production work. | Current local/PGlite state does not establish a secure multi-tenant deployment. |
| Finance/commercial policies | Approve company identity, tax, discounts, payment schedule, invoice wording, warranty, returns and delivery terms. | No legal/financial defaults are inferred from the attachment or old plans. |
| External messaging/payment providers | Name providers, environments, webhook owners, consent rules and delivery states. | Current WhatsApp behavior is manual/logged; no provider is claimed live. |
| CMS content migration | Decide which routes are database-managed versus code-defined and who approves publishes. | No cross-repository content import or broad URL migration is included. |
| Sister-product relationship | Keep separate until an explicit legal/data/tenant plan is approved. | Ownership of two repositories does not equal permission to merge their records or code. |
| AI/MCP authority | Start read-only and human-approved if/when P12 is approved. | Secrets, permissions and customer/financial data require a threat model and audit. |

---

## 11. Operating process for future phases

Every major phase follows the PRD’s A–G shape: **Findings → Research → Recommendation → Alternatives → Decision required → Implementation → Verification**. The recurring loop is **RESEARCH → INSPECT → RECOMMEND → APPROVE → BUILD → TEST → DOCUMENT → REPEAT**.

Before implementation: inspect the relevant route, API, schema, permissions, tests and current user constraints. Research only the decision at hand; check official documentation, current maintenance and license. Present trade-offs for architecture, external services, data migration or commercial rules. Ask before proceeding where the decision changes architecture, tenants, external delivery, production, or business policy.

After implementation: run targeted tests and the full QA appropriate to the scope, run browser E2E with Chromium when the interaction is browser-visible, update this plan’s status only with evidence, append a decision-log entry for decisions, and state what remains unverified. Never treat a preview server, local seed data or CI build as deployment.

---

## 12. Canonical documents and traceability

- **Requirements:** `docs/product/PRD-AGENCY-OS.md` — preserved as the requirements source.
- **Canonical plan:** `docs/product/AGENCY-OS-MASTER-PLAN-V2.md` — this document.
- **Two-phase frontend-to-platform execution plan:** `docs/archive/AGENCY-OS-WOODEX-REIMAGINED-TWO-PHASE-PLAN.md` — subordinate implementation sequence for the storefront and Agency OS API; does not change the PRD’s P0–P14 roadmap or authorize source import/production.
- **Architecture:** `docs/architecture/ARCHITECTURE.md` — current bounded contexts and data invariants; update when an ADR changes them.
- **Current implementation backlog:** `docs/product/NEXT-PHASE-DELIVERY-PLAN-2026-10-06.md` — detailed workflow/page gaps; refresh its verification counts and statuses when touched.
- **External-source audit record:** `docs/audits/PHASE0-AUDIT-REPORT-2026-10-05.md` and supplied P23 report — historical/reference evidence, not proof of this product’s runtime or production readiness.
- **Decision log:** `docs/architecture/DECISION-LOG.md` — append approved changes; do not edit away historical decisions.
- **Historical master plan:** `docs/archive/AGENCY-OS-MASTER-PLAN-v3.md` — retained, marked superseded, not a second source of truth.

**This plan is a living plan, not a deployment approval. Update status only when tests, owner decisions and acceptance artifacts support the change.**
