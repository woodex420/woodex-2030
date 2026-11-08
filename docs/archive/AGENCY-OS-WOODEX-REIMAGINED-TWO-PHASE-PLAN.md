# Woodex Reimagined Frontend → Agency OS
## Two-Phase Implementation Master Plan

**Status:** Phase 1 in progress. The user confirmed complete reference reuse rights; the full source tree is isolated at `apps/woodex-reimagined-preview` with a live preview on `:5175`, and Agency OS ports remain unchanged. The reference build, lint and typecheck pass; Agency OS build/lint pass with warnings. Browser acceptance is blocked by the missing browser binary, and API/privacy/content gates remain open. This is not a deployment, provider or production approval.
**Prepared:** 2026-10-30
**Updated:** 2026-10-31
**Parent authority:** [`AGENCY-OS-MASTER-PLAN-V2.md`](../product/AGENCY-OS-MASTER-PLAN-V2.md) and the requirements PRD `AGENCY_OS_MASTER_SYSTEM_PRD_PROMPT.md` (not committed to this repository; superseded by [`docs/product/PRD-AGENCY-OS.md`](../product/PRD-AGENCY-OS.md)). This plan maps work to the PRD’s P0–P14 roadmap; it does not replace or renumber it.
**Reference:** `blackibexofficial-blip/woodex-reimagined`, default branch `main`, commit `e97d69f10e6537c84712d9c97abd52b50cd29015` (2026-10-03). The original audit was read-only/static; after the user authorized complete reuse, an isolated full-tree copy was built and previewed locally on 2026-10-31. The upstream repository was not modified.

---

## 1. Goal and product boundary

### Phase 1 execution evidence — 2026-10-31

- Preview mappings are active: Dashboard `:3001`, API `:3002`, Agency OS Storefront `:5174`, isolated imported reference `:5175`.
- HTTP smoke checks returned `200` for the dashboard and API health proxy, and for home/shop/quotation/contact/checkout plus `App.tsx`/`ImageGallery.tsx` transforms on both storefronts. This verifies response availability only; it is not browser-level acceptance.
- Root `npm run build` passes. The malformed dynamic rotate CSS was fixed and both storefront apps now lazy-load route pages; the dashboard still emits one chunk-over-500-kB warning. The imported reference build and typecheck pass; only its stale Browserslist-data warning remains.
- `npm -w @woodex/storefront run lint` now passes with 13 warnings. The initial lint errors were corrected, including a conditional FAQ hook and an unbound theme reference in published-page product grids.
- Checkout now shows success only after the API returns an order reference, keeps the cart on failure, and labels payment selection as an unrecorded preview preference. Order-status copy no longer claims live updates, an unapproved advance percentage or an unverified phone number.
- The feature-gap matrix is saved at [`WOODEX-REIMAGINED-FEATURE-GAP-MATRIX.md`](WOODEX-REIMAGINED-FEATURE-GAP-MATRIX.md). Static route comparison confirms all 23 reference patterns exist in Agency OS, with `/order-status` and `/p/:slug` as two Agency OS additions. The matrix records order-lookup privacy and checkout API gaps (address/payment preference not persisted; client total trusted); do not use real customer data or treat these flows as production-ready.
- A required storefront/CRM Playwright run was attempted but stopped before test execution because no Chromium/Firefox browser binary is available. Browser acceptance remains blocked, not passed.
- An optional storefront TypeScript check remains blocked by the installed React 19 / `@types/react` 19 dependency tree conflicting with the storefront's declared React 18 stack, and by the unresolved `@lovable.dev/mcp-js` module. No dependency alignment was applied.
- The user stated they are a Woodex founder and authorized complete reuse. The full source/assets/config tree was imported from the exact audited commit into `apps/woodex-reimagined-preview`; only local preview/network adjustments were made, and the upstream repository was not changed. The isolated preview is live on `:5175`.
- The imported reference lint passes with 10 warnings and no errors after local lint hygiene fixes; `npx tsc --noEmit -p tsconfig.app.json` passes. `npm ci` reported 31 dependency vulnerabilities (10 moderate, 21 high); no audit fixes were applied. No browser E2E ran because no browser binary is installed.

Complete a polished, responsive **Woodex Furniture public storefront** in the existing Agency OS project, then connect its customer journeys to the existing Agency OS API and operational modules. The public experience should support product discovery, B2B quote requests, B2C order requests, service/project discovery and approved CMS content, while Agency OS remains the system of record for business activity.

The existing `apps/storefront` already uses the same major frontend family as the reference—React 18, Vite 5 and Tailwind 3—and already contains a broad public-site route set plus API-backed catalog, lead, quote, order and CMS paths. Therefore this plan starts with a **feature-parity and gap audit**, not an assumption that a second storefront must be built. Do not create duplicate storefronts, cart systems, CMSs or APIs without an approved architecture decision.

### Boundaries and gates

- The reference repository has no LICENSE file. On 2026-10-31, the user stated they are a Woodex founder and authorized complete reuse of its source, assets and content. The full project tree is imported at `apps/woodex-reimagined-preview` from commit `e97d69f10e6537c84712d9c97abd52b50cd29015` for an isolated `:5175` preview; the upstream repository remains untouched.
- Do not move customers, leads, quotes, orders, credentials, database dumps or production data between the reference product and Agency OS.
- Keep the existing Woodex Dashboard theme unchanged. The fully imported reference storefront is an isolated review app and does not replace or overwrite the Agency OS storefront; any production integration/selection remains a Phase 2 decision.
- Do not claim live payments, WhatsApp delivery, production deployment, production PostgreSQL, tenant isolation or a completed phase without evidence.
- Preserve the accepted quotation rules: show the estimated total and **“Final payment terms will be included in formal quotation.”** Do not add a percentage advance split or client-facing share URL.

---

## 2. Evidence baseline

### Reference frontend (`woodex-reimagined`, as audited)

- React 18.3 + TypeScript 5.8 + Vite 5 + Tailwind 3, React Router 6, shadcn/Radix UI and Lucide icons.
- 23 route patterns covering home, catalog, product detail, room packages/configurator, series, projects, services, B2B, contact, custom design, quotation, showrooms, materials, warranty, blog and checkout.
- Static source data and local assets; cart/quote/configurator state uses browser storage.
- Contact and quote forms show local success state; checkout shows a local confirmation and clears the cart. These flows do not establish backend persistence.
- No business API/database migrations or automated test/CI configuration were found in the reviewed tree. The only Supabase function found is MCP tooling, not the lead/order business API described in its planning docs.
- The report and source differ on some planned routes/features. Resolve against code and approved requirements, not documentation labels alone.

### Agency OS destination

- `apps/storefront`: React 18/Vite 5/Tailwind 3; existing public pages, runtime catalog sync and API-backed lead/quote/order/CMS features are present in part.
- `apps/dashboard`: separate operator app; keep its React 19/Vite 8/Tailwind 4 stack isolated unless a separately approved ADR justifies shared-package or version convergence.
- `apps/api`: Express API; SQLite is the development default, with a PostgreSQL adapter and PGlite rehearsal. Existing API QA/rehearsal is not a production database sign-off.
- Preview mapping: Dashboard `:3001`, API `:3002`, Agency OS Storefront `:5174`, isolated imported reference preview `:5175`. Keep the Agency OS API proxy same-origin from the browser.
- Core API surfaces already include catalog, leads/CRM, quotes/revisions/templates, orders/lookup, invoices/manual payments, returns, CMS publishing/preview, theme, tracking/analytics and manual/logged WhatsApp handoff. Not every reference page has an API-managed content entity today.

---

# Phase 1 — Complete the Frontend and Run a Safe Preview

**Maps mainly to:** P0, P3, P6, P7 and P8.
**Objective:** finish one complete customer-facing storefront in `apps/storefront`, reconcile reference feature coverage with what Agency OS already has, and verify the UI against safe local/demo data before broad backend expansion.

## Phase 1 workstreams

### 1.1 Lock scope, rights and the source snapshot

1. Record the exact reference commit and keep the upstream branch untouched.
2. **Complete — 2026-10-31:** the user stated they are a Woodex founder and authorized complete source/asset/content reuse. The exact audited project commit has been copied to the isolated preview app; the upstream repository remains untouched.
3. **Route crosswalk complete — 2026-10-31:** static comparison confirms all 23 reference route patterns are present in `apps/storefront`; `/order-status` and `/p/:slug` are Agency OS additions. The working feature matrix is saved at [`WOODEX-REIMAGINED-FEATURE-GAP-MATRIX.md`](WOODEX-REIMAGINED-FEATURE-GAP-MATRIX.md); behavior-level parity still needs browser checks.
4. Classify remaining feature details as **already present**, **gap**, **needs business approval**, **reference-only**, or **out of scope**. Do not treat mock values as business truth.

**Deliverables:** working gap matrix ([`WOODEX-REIMAGINED-FEATURE-GAP-MATRIX.md`](WOODEX-REIMAGINED-FEATURE-GAP-MATRIX.md); owner review pending), route map, reuse/rights decision (complete reuse confirmed by the user on 2026-10-31), and source-of-truth owners for content and product data.

### 1.2 Complete the public information architecture

Reconcile and verify these journeys within the existing storefront rather than adding a parallel site:

- Home and primary navigation, category browsing and search.
- Shop, product detail, filters/sort, images, specifications, materials and finishes.
- Series, room packages and any approved room/configurator experience.
- Projects, services, B2B/custom-design and showroom discovery.
- Contact and quote-request forms.
- Quote basket versus B2C cart/checkout; clarify where each flow starts and ends.
- Order status/tracking and published CMS pages where supported by Agency OS APIs.
- About, warranty, materials and blog/content pages.

Check that navigation, footer, breadcrumbs, empty states, 404, browser back, deep links and direct reloads work. Resolve documentation/source route mismatches explicitly.

### 1.3 Finish responsive UI and interaction states

- Preserve the current Woodex storefront visual language; use original implementation and approved assets.
- Test key routes at mobile (390px), tablet (768px) and desktop widths; verify menus, drawers, dialogs, product galleries, configurator interactions and quote/cart states.
- Provide loading, offline, empty, validation, permission/error and retry states. Never show success for a failed or non-persisted submission.
- Audit keyboard navigation, focus handling, semantic headings/labels, contrast, reduced motion, alt text and screen-reader names.
- Verify static trust claims, phones, addresses, showroom data, testimonials, pricing, warranty and payment text with the business owner before publication.

### 1.4 Safe data and preview behavior

- Use the Agency OS API for data already supported. For frontend-only gaps, use clearly marked synthetic fixtures until the API contract is approved.
- Do not point an isolated reference preview at production systems or external providers.
- Preview the integrated project with Dashboard `:3001`, API `:3002` and Storefront `:5174`; Vite services must bind to `0.0.0.0` and allow the Arena preview host.
- The user-authorized full reference copy is previewed separately on `:5175`, bound to `0.0.0.0` with Arena host access. It has no Agency OS API proxy/shared database configured; keep it isolated, do not use production data, and do not take over the Dashboard `:3001`, API `:3002` or Agency OS Storefront `:5174` ports.

### 1.5 Phase 1 acceptance gate

Phase 1 is complete only when:

- One route/feature matrix is reconciled with the actual `apps/storefront` implementation.
- Every approved public route renders and deep-links correctly; primary navigation has no dead links.
- Approved product/catalog UI works with safe data and refreshes without stale-price errors.
- Quote/cart/contact/checkout UI has truthful states and retains the quotation wording constraints.
- Responsive, keyboard/accessibility and visual checks pass on the agreed device matrix.
- Build, lint and browser suites execute and pass; skipped browser tests remain blockers.
- Preview topology is documented, upstream is unchanged, and no production/provider/data-migration claim is made.

**Phase 1 does not authorize:** production content migration, external messaging, online payment, a framework rewrite, a second CMS, or cross-product data transfer.

---

# Phase 2 — Complete API Integration and Upgrade Existing Features

**Maps mainly to:** P2, P4, P5, P6, P7, P9, P10, P13 and P14.
**Objective:** make the approved storefront journeys persist and reconcile through the Agency OS API, then add only the backend capabilities that the completed frontend genuinely requires.

## Phase 2 workstreams

### 2.1 Establish API contracts and ownership

Create a versioned mapping from each frontend action to the existing API, payload schema, permissions, owner, idempotency behavior, audit event and user-visible status. Use the existing API as the single write authority; do not add direct Supabase/browser writes or a parallel JSON/serverless database.

| Storefront capability | Existing Agency OS surface / planned treatment |
|---|---|
| Catalog and price/stock | `GET /api/products`, `/api/products/overrides`; server remains authoritative for price/availability. |
| Materials and services | Existing read endpoints; decide whether reference-only categories remain static or require managed entities. |
| Contact/custom-design enquiry | `POST /api/leads`; persist consent, preferred channel and attribution; link to CRM/client identity safely. |
| B2B quotation | `POST /api/quotes`; calculate totals on the server, snapshot items/template, link lead/client and preserve immutable revisions. |
| B2C order | `POST /api/orders`; validate current catalog and totals, persist order state and link invoice/task as approved. |
| Order tracking | Existing public lookup by reference; define allowed fields and honest stage mapping, without exposing private customer data. |
| Published pages/theme | Existing public snapshot, signed preview and theme endpoints; drafts must remain private. |
| Analytics | Existing first-party tracking endpoint; define consent, source, event names, retention and demo-data exclusion. |
| WhatsApp | Existing manual/logged handoff only; no “sent/delivered” status without an approved provider and verified delivery evidence. |

**Potential API gaps to decide before building:** API-backed series, project, blog, showroom and configurable room data; media lifecycle; newsletter; search/filter pagination; content redirect/SEO metadata. Add only the entities/endpoints accepted by the owner, with migrations, RBAC, validation, rate limits, audit and tests.

### 2.2 Integrate workflows incrementally

1. **Catalog:** reconcile static fallback with API records; preserve stable IDs; handle loading/offline/stale data; recalculate price server-side at submit time.
2. **Lead intake:** replace fake success with server response, consent and attribution; validate on client and server; show a stable enquiry reference and retry safely.
3. **Quotation:** persist quote and line-item snapshots, link the CRM lead/client, retain revisions and formal wording, and distinguish a request estimate from an issued commercial offer.
4. **Checkout/order:** persist orders and current status; make payment method explicitly manual/unconfigured until a provider is approved; do not clear a cart on a failed request.
5. **Tracking:** expose only approved, non-sensitive order status; test unknown refs, expired refs, rate limits and privacy boundaries.
6. **CMS and content:** use the existing pages API/published snapshots for approved managed routes; retain code-defined content only where the owner chooses it.
7. **Analytics/inbox:** connect attribution and manual handoffs to the CRM and internal inbox; keep provider delivery status truthful.

### 2.3 Data, security and platform readiness

- Keep SQLite as development default until a production database ADR and real PostgreSQL canary are approved; PGlite is rehearsal only.
- Test public/signed-out and role-restricted API behavior directly. Add tenant isolation only after a tenant model is approved; do not infer multi-tenant readiness from a workspace selector.
- Make changes transactional and idempotent where retries could create duplicate leads, quotes or orders.
- Define consent, privacy notice, retention, deletion, webhook verification and provider-secret handling before enabling external channels.
- Add migrations, data reconciliation, backup/restore, monitoring, incident ownership and rollback before any production cutover.

### 2.4 Phase 2 acceptance gate

Phase 2 is complete only when:

- Every approved storefront write path has an API contract and server validation.
- A lead can be traced from storefront intake through CRM; a quote through revision/approval; and an order through its approved operational status.
- Server totals and immutable snapshots reconcile; duplicate retries and failure rollback are tested.
- Draft/public content, RBAC, PII boundaries and provider statuses pass security/privacy checks.
- SQLite and PostgreSQL rehearsal suites pass; a real database/production canary is separately approved and verified before claiming production readiness.
- Browser E2E, API QA, migration/recovery tests and release evidence are recorded; no suite is skipped for acceptance.

**Phase 2 does not automatically approve:** live payment, live WhatsApp/email/social delivery, a second tenant, imported customer records, or go-live. Those require separate owner decisions and release gates.

---

## 3. Cross-phase quick task sequence

1. **Complete — rights/boundary:** user authorized complete reuse; the full project is imported in an isolated preview, with upstream untouched.
2. **Run the feature gap matrix** against current `apps/storefront` so Phase 1 improves the existing app instead of creating a duplicate.
3. **Verify real business content** and remove/demo-label any unapproved contact, payment, warranty, testimonial or delivery claim.
4. **Complete the Phase 1 responsive frontend** and run Chromium browser acceptance in CI.
5. **Sign off API contracts and business policies** before Phase 2 writes or external integrations.
6. **Connect catalog → lead/quote/order → CRM/operations** one workflow at a time, with rollback and audit tests.
7. **Hold P14 production go/no-go** until the deployment owner, real database, secrets, provider state, backups and recovery evidence are approved.

## 4. Recorded owner decision and remaining approvals

- **Resolved 2026-10-31:** the user stated they are a Woodex founder and authorized complete reuse of the reference repository’s source, design assets and content. The full project is imported only in the isolated preview; the upstream repository was not changed.
- Which reference pages/behaviors are product requirements versus examples to redesign?
- Which content and product datasets are approved first-party Agency OS data, and who approves changes?
- What are the commercial rules for quote validity, discounts, delivery, warranty, returns, taxes and payment?
- Which content entities remain code-defined and which become CMS-managed?
- What hosting, real database and operational owner are approved for production?
- Are any external messaging/payment providers in scope, and what consent/webhook policy applies?

**This is a planning document only. Update phase status only when owner decisions, implementation and test evidence support the change.**
