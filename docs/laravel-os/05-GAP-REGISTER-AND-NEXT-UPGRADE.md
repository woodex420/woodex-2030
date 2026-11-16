# Agency OS / 2030 → Laravel WOODEX OS: gaps and next upgrade

**2026-11-11 — repository-backed review, not a claim of feature parity.** This extends the [reuse master plan](00-REUSE-PROPOSAL-AND-ADMIN-MASTER-PLAN.md), not a replacement roadmap. “2023 Agency OS” in the request is interpreted as the current Agency OS/2030 checkout; no separate 2023 source was provided or inspected.

## 1. Why sections were missing; what changed

The imported admin deliberately filtered out module-switched-off destinations. The owner now wants the roadmap visible. Authorized users therefore see disabled **Soon** items for closed modules, including Inbox, Automation, Marketing, Pages & builder, Content and Website. Content and Pages & builder display “frontend reimagine”. Workspace adds planned preferences and onboarding upgrades; operational Settings/Access & team remain under System rather than duplicating live screens.

- Soon means **navigation placeholder only**, not a functioning screen or completed integration.
- It has no link and is `aria-disabled`; route middleware still rejects direct access with 404.
- Permission checks still apply. The owner sees the complete roadmap; sales staff do not see administrative placeholders they cannot use.
- Existing commerce/procurement destinations retain **2030** badges: separate React screens, usable only after `LEGACY_DASHBOARD_URL` is verified/configured. They are not native Laravel screens.
- Existing Interior HTML builder must not be represented as the future Furniture builder. Do not enable the website module merely to make its menu visible.

## 2. Cross-application gap register

Evidence: `apps/dashboard/src/App.tsx`, `apps/storefront/src/lib/runtime.ts`, `apps/api/server.mjs`, `platform/routes/{admin,api}.php`, `platform/app/Support/AdminNavigation.php`, Agency OS docs, and the earlier reuse classification. “Available” below means implemented in the synthetic Laravel foundation, not accepted for production. No production database was inspected in this review.

| Area / current React screens | Laravel status and missing work | Planned stage |
|---|---|---|
| Dashboard `/`, analytics `/analytics` | Native dashboard/reports available; commerce KPIs, cross-module Business Analytics and operational reconciliation missing | 2, then 4–9 |
| CRM `/crm`, `/clients`, `/companies`, `/tasks`, `/meetings` | Native leads, contacts/companies and task/visit hubs; Furniture terminology, meetings mapping, migration and duplicate-resolution rules still need completion | 2 / migration gate |
| Quotations `/quotations`, new | Native quote workflow available; product IDs, owner commercial policy, provenance and template review missing | 2–3 |
| Invoices `/invoices`, payment schedules `/payment-schedules` | Native invoices/receipts and allocations; 2030 payment schedules/credit logic and reconciliation not merged | 2, 9 |
| Commerce `/ecommerce`, `/orders` + detail | Existing React only; Laravel order states, returns, reservation and invoice authority missing | 4 |
| Catalogue `/catalog`, new/detail/edit | Existing 148-product source catalogue remains Node-owned; Laravel products/categories/materials/services/variants/images and import checks missing | 3 (next native module) |
| Delivery `/deliveries` | Dispatch, proof, installation/installed assets not ported | 8 |
| Support `/support` | Tickets, warranty policy, claims and visits not ported | 10 |
| Operations `/operations`, stage | End-to-end order/production/delivery board not ported | 4–8 |
| Suppliers `/suppliers` | Native vendor master exists; match/merge rules and supplier-specific fields not migrated | 6 |
| Purchasing `/purchase-orders`, `/goods-receipts`, `/supplier-invoices` | Purchase orders, stock receipts and three-way match missing; native bills must become one payable authority | 6 |
| Production `/production` | BOM, job cards, material issue, QC and override approval controls missing | 7 |
| Stock `/stock-ledger`, `/warehouses`, `/stock-transfers`, `/stock-reservations` | No native Furniture stock authority; append-only movement and concurrency invariants must be ported/tested | 6 |
| Finance `/ledger`, `/expenses`, `/credit-notes` | Native books/expenses available; credit notes, minor-unit reconciliation and historical migration incomplete. Accountant now has read access, not ledger management | 2, 9 |
| Projects `/projects` | Native projects/board/costs exist; merge BOQ, installation workflows and handover semantics; public portal remains closed | 8 |
| Inbox `/omnichannel` | Imported conversations/chat exist but switched off; Furniture consent, channel identities, shared inbox mapping and provider credentials unconfigured | 11; Soon |
| Automation | Imported sequences exist but off; Furniture copy, consent/quiet-hours/retry/deduplication and owner approvals required | 11; Soon |
| Marketing `/marketing` | Imported marketing is off; campaign/landing attribution and analytics semantics not merged | 11; Soon |
| Workspace `/settings` | Native Settings/System access available; preferences, onboarding, role-extension and login-lock view `/login-locks` not merged | 2; planned upgrades Soon |
| Pages `/website`, `/website/edit/:id` | Existing typed-block React editor is still authority; imported HTML-file builder is not a compatible replacement. Puck evaluation and adapter needed | 11, or a separately bounded earlier editor spike |
| Theme `/website/theme` | React theme contract and Laravel tokens not unified | 11 |
| Content/blog/portfolio/forms | Furniture content models, publish pipeline, SEO URLs and enquiry form mapping incomplete | 11; Soon |
| Media, SEO, redirects, site health | Imported tools off with website module; must target Furniture assets/pages, never Interior files or redirects | 3 media; 11 remainder |
| UI components `/ui-components` | React reference gallery only; not a business module requiring a second Laravel implementation | design reference |
| Login/session/2FA/login locks | Native Fortify/role checks available; Node sessions are separate, no SSO or credential migration. Login-lock consolidation missing | 2 / cutover |
| Integrations / audit / health / recycle bin | Native foundations exist; Furniture credentials disconnected; independent deployment, monitoring, retention and restore acceptance remain | 2, S, P |

### Cross-cutting missing work (not hidden by the new sidebar)

UTC storage conversion remains unimplemented; settings and document-default review remains owner-blocked; no authenticated staff `/api/v1`, Sanctum, scopes or token lifecycle exists yet; source/target ID mapping and migration rollback are not built; no real data migration, Hostinger staging, production cutover, unified session or Node retirement has occurred. The sidebar is not evidence of backend parity. Full screen/state/mobile/server-validation acceptance is still required per the original brief.

## 3. Puck assessment — proposed, not installed

Reviewed the public [Puck repository](https://github.com/puckeditor/puck) metadata and README at `eef68a2eea6391d8e8adbd1a642108e702dff271` on 2026-11-11. It describes a React visual editor using registered application components and data owned by the application; metadata/README identify the MIT license. No dependency has been added and compatibility has not been proven.

Recommended architecture: embed a separately built React editor inside the Laravel-authenticated admin, reusing the existing storefront React block renderer. Laravel owns versioned page JSON, authorization, drafts, publish history and assets. Puck must not write arbitrary Blade/PHP, arbitrary HTML scripts or Tailwind classes supplied by untrusted content. Keep one component registry and schema, not separate editor/storefront renderers.

Before selecting it: pin a release and inspect its actual dependency/React peer graph (avoid duplicate React); prototype adapters for the existing block JSON and theme tokens; validate server-side allowed components/props and asset references; prove round-trip editing, revision restore, media selection, nested sections, responsive output and published storefront rendering. Preview/draft content must not become public through a guessed URL. No Puck rollout or migration is claimed here.

## 4. `/api/v1` foundation started

`POST /api/v1/leads` reuses the existing stateless request/controller/capture action, module gate, spam protection and shared IP throttle (5/minute, 50/day across old and new endpoints). It defaults to **Furniture** and rejects explicit non-Furniture business lines. GET answers 405 with `Allow: POST`; there is no public lead-list API. Existing `/api/public/leads` remains unchanged.

This is a **public capture endpoint**, not staff API authentication. Do not issue staff tokens or claim Sanctum support. CORS includes only the exact new lead path, uses the existing configured origin allowlist and does not allow credentials. Configure production Turnstile/origin settings before public cutover; blank anti-spam provider configuration is not production hardening.

### Initial request and response contract

- Required: `name` (2–160 characters) and at least one valid `phone` or `email`.
- Optional: `message` (up to 5,000), `city`, `area`, `service`, enum `source`, `form`, attribution/UTM fields, boolean `consent`, `language`, `turnstile_token`, `form_started_at`.
- `business_line` may be omitted or `furniture`; public ownership/stage/assignment cannot be chosen by this input.
- Success 201 (new) / 200 (merged): `{ok, reference, message, whatsapp_url}`. Honeypot/too-fast 202: `{ok:true, reference:null}`, no persistence. Invalid input/anti-spam 422; throttled 429; module off 404.
- An enquiry is not an order or issued quotation. No payment is collected, catalogue changed or document issued by this endpoint.

### Compatibility work before changing the React storefront

| Existing storefront expectation | Required adapter/change — not implemented yet |
|---|---|
| Free-text `source` such as `Contact Form` | Map to validated enum plus `form` detail |
| `contact` combined phone/email string | Submit separate validated `phone` / `email`; decide legacy parse policy |
| `interest`, `note`, `contactConsent`, `company`, `preferredChannel` | Explicit field mapping; current endpoint uses `service`, `message`, `consent`, and does not yet persist all old extras |
| Response `id`/`leadId`, `ref`, `clientId` | Current response is public `reference`, not Node numeric IDs; never invent or reuse IDs across databases |
| `POST /api/leads/:id/whatsapp-handoff` | Design an unguessable public capability / signed token and replay rules, not an unrestricted numeric-ID endpoint |
| `/api/quotes` product basket | Versioned quote-request contract, authoritative product lookup, validation, spam/consent and persistence still needed |
| Success/failure/loading UI and attribution | Contract tests across Contact, Quotation and PublishedPage, then browser acceptance |

The live storefront still calls the Node endpoints. Simply changing its base URL now would break capture/handoff; it has deliberately **not** been done.

## 5. Execution sequence and decisions

1. **This slice:** visible permission-filtered Soon roadmap; initial v1 Furniture capture; regression tests.
2. **Next:** finalize storefront lead adapter and quote-request schema, public handoff capability, contract tests. Keep Node live until acceptance and a deliberate cutover.
3. Authenticated API design: decide Laravel session/CSRF vs Sanctum tokens for each client; no shared Node/Laravel session assumption. Gate every staff endpoint by active user, 2FA policy and permission.
4. Catalogue first native rebuild (Phase 3), including 148-product import rehearsal and image/variant parity; then commerce/stock stages in the original plan.
5. Frontend reimagine/Puck: bounded technical prototype only before scheduling an earlier Phase 11 implementation. Migration/publish acceptance is mandatory.

Owner input still required: Furniture commercial defaults/signers, data migration scope, verified legacy dashboard URL, production hosting constraints and whether page-builder implementation should be brought forward. None is invented by this plan. The document issuance safeguard remains closed.

## 6. Evidence for this slice

Targeted checks: 35 navigation/API/module/settings-route tests passed (283 assertions), followed by 66 API/roadmap/sidebar/unit-navigation tests (224 assertions), including shared throttle and failed Turnstile checks. These runs overlap; do not add their test counts. Vite build succeeded with the existing chunk-size warning. Full-suite/MySQL validation of this revision remains pending. No external provider was contacted by the tests.

Final validation follow-up: full local suite **2,045 passed, 34 skipped, 9 known environment failures**, 10,745 assertions; cause-based summary exit 0, no unexplained failures. Direct Chromium **18/18**, zero JavaScript exceptions, only expected wrong-password 422 and disabled-inbox 404. Preview synchronized without resetting synthetic data/sign-ins. MySQL CI for this revision remains pending (the prior permission revision was still queued when checked).
