# Woodex Reimagined → Agency OS Feature-Gap Matrix

**Checkpoint:** 2026-10-31
**Purpose:** Phase 1 code-level comparison. `apps/storefront` remains the Agency OS integration target; the complete reference project is kept as an isolated review preview, not a production replacement.
**Reference:** `blackibexofficial-blip/woodex-reimagined`, `main` at `e97d69f10e6537c84712d9c97abd52b50cd29015`; source copied to `apps/woodex-reimagined-preview`, preview port `:5175`.
**Rights:** On 2026-10-31, the user stated they are a Woodex founder and authorized complete reuse of source, assets and content. The upstream repository remains untouched.

## Evidence and limits

The Agency OS side was checked against `apps/storefront/src/App.tsx`, route components, cart/quote contexts, `src/lib/runtime.ts`, and the API handlers in `apps/api/server.mjs`. A static route comparison now confirms all 23 reference patterns (22 named paths plus `*`) exist in Agency OS; Agency OS adds `/order-status` and `/p/:slug`. This proves route-pattern coverage only, not link correctness, visual behavior or interaction acceptance. HTTP smoke tests and builds do not prove browser-level acceptance.

| Reference feature family | Agency OS implementation observed | Phase 1 status / remaining action |
|---|---|---|
| Home, navigation, footer | `/` (`Index`), shared `Header` and `Footer`; wildcard `NotFound` route | Present in the router. Verify every internal link, browser back, direct reload, mobile menu and 404 in Chromium. |
| Catalog, search, filters, sorting | `/shop` (`Shop`); runtime catalog sync and price/stock overrides through `/api/products` and `/api/products/overrides` | Present at source level. Verify filtering, sorting, empty/offline states, stale-price handling and responsive behavior in browser tests. |
| Product detail, specifications and finishes | `/shop/:productId` (`ProductDetail`) with product data and runtime catalog revision | Present at source level. Confirm a missing product state, image alt text, gallery keyboard behavior and server-current price before any order submission. |
| Room packages / configurator / virtual showroom | `/room-packages` and `/virtual-showroom` | Both routes exist. Exact parity with reference configurator behaviors and state persistence is not yet verified; run mobile/desktop interaction tests and confirm content/assets. |
| Furniture series | `/series` and `/series/:seriesId` | Routes exist; content is code-defined. Verify all links and approve business claims/images. |
| Projects / case studies | `/projects` and `/projects/:projectId` | Routes exist; content is code-defined. Confirm project/client names, imagery and any performance claims before publication. |
| Services, B2B and custom design | `/services`, `/services/:slug`, `/b2b`, `/custom-design` | Routes exist. Verify forms and API submission behavior; content and commercial promises need owner approval. |
| Contact / lead intake | `/contact`; runtime exposes `POST /api/leads` | API-backed path is present in the app. Browser-test validation, consent, server failure/retry, attribution and truthful confirmation states. |
| Quotation builder | `/quotation`; quote basket uses `localStorage`; page posts to `/api/quotes` or `/api/leads` | Present. Required “Final payment terms will be included in formal quotation.” wording is present. Verify server totals, price snapshots, failure/retry, persistence and manual WhatsApp handoff; no live delivery claim. |
| Showrooms, materials and warranty | `/showrooms`, `/materials`, `/warranty` | Routes exist; pages are largely code-defined. Confirm locations, phone numbers, hours, warranty and material claims with the owner. |
| Blog / editorial content | `/blog`, `/blog/:postId` | Routes exist; content is code-defined. Approve authorship, dates, factual claims and images before publication. |
| Cart / checkout | `/checkout`; cart state uses `localStorage`; storefront submits to `POST /api/orders` | The API reloads current catalog prices and stock, recomputes the total, and returns a saved order reference plus a high-entropy private tracking code. Client-supplied prices/totals are ignored. Delivery address/payment preference are not yet persisted and no payment is captured, so this remains an **order-request flow, not production checkout**. Browser journeys are still pending. |
| Order status / tracking | `/order-status`; API `POST /api/orders/lookup` with reference + private code | The one-time code is stored as a SHA-256 digest; lookup requires both values and returns only progress/stage fields, not customer, item, invoice, payment, return, or address data. SQLite/PGlite API QA covers valid, invalid, and reference-only access; browser acceptance remains pending. |
| Published CMS pages | `/p/:slug`; reads `/api/pages/:slug/public`; draft preview query parameters are supported | Agency OS CMS extension. Published-page FAQ hook ordering and product-grid theme propagation were corrected. Verify signed draft privacy, direct reload, missing-page/error states, CTA behavior and page SEO in a browser. |
| Not-found/deep links | Catch-all route renders `NotFound` | Present in route source. Direct-route fallback, browser back and unknown parameter cases remain unverified without browser E2E. |

## Summary and gate

- Static route comparison verifies all 23 reference patterns are present in Agency OS; `/order-status` and `/p/:slug` are two Agency OS additions. Browser behavior and full feature parity remain unproven. The full reference project is imported and previewed separately; keep `apps/storefront` as the Agency OS integration target pending the Phase 2 decision.
- The imported reference build and TypeScript check pass; lint passes with 10 warnings and no errors after local hygiene fixes. Route-level code splitting brings the main chunk below 500 kB. Install reported 31 dependency vulnerabilities; no fixes were applied. Browser testing remains blocked because no browser binary is installed.
- Phase 1 implementation has begun in both the isolated reference preview and Agency OS storefront. Remaining blockers include browser acceptance, approval of business content/trust claims, the Agency OS dependency/typecheck mismatch, and checkout address/payment-preference persistence plus payment-provider policy. The API pricing and private order-tracking controls are implemented and covered by local SQLite/PGlite tests; hosted PostgreSQL and browser behavior remain unverified.
- Complete source/assets/content reuse is authorized by the user; keep the upstream repository untouched and keep the imported copy isolated until integration is explicitly chosen.
- Do not use real customer data in the current preview. Do not present checkout, payment, WhatsApp or public order tracking as production-ready.

**Related execution plan:** [`AGENCY-OS-WOODEX-REIMAGINED-TWO-PHASE-PLAN.md`](AGENCY-OS-WOODEX-REIMAGINED-TWO-PHASE-PLAN.md).
