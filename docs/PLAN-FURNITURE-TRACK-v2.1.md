# Woodex FURNITURE — Plan v2.1 (corrected scope)

**Supersedes scope drift in Phase-0 plan v2. Date: 2026-10-05. Gate: recommendation → approval → build.**

## 0. Boundary correction (user directive, 5 Oct)

> “Do not match with Woodex Interiors project — it is a Woodex **Furniture** project.”

- `marketingwoodex-cloud/-marketingwoodex` = **Woodex Interiors** sister brand (kanal/marla plots, fit-out services, interior pages). It is **NOT** this product.
- Its audit value here = **engineering lessons only** (static-SEO publishing, single client record, section-library workflow, P19 bug checklist). **No content, data, tenant, or migration of interiors** into woodex-2030.
- Old plan-v2 items that crossed the line → **removed**: “migrate MarketingWoodex as tenant #2”, republishing MW pages, sharing their clients/leads tables. Multi-tenancy stays in the architecture (Agency OS core), but **tenant #1 and only active tenant = Woodex Furniture**.
- The 3 pre-existing planning branches (interiors master plans) remain untouched elsewhere; nothing from them is merged here.

## 1. Where the furniture project stands (live, verified)

| Surface | State |
|---|---|
| `apps/storefront` :5174 | woodex-reimagined, 148 SKUs PKR, configurator, quote basket, contact, checkout → all write to shared API ✓ |
| `apps/dashboard` :5173 | design-system shell; Catalog / CRM kanban / Quotation pipeline / Operations board / KPIs live on API ✓ |
| `apps/api` :3001 | Express + node:sqlite; tables: products, materials, services, leads, quotes, orders, meta ✓ |
| **Gaps** | no invoices/payments, no returns/RMA, no customer order tracking, no CMS/pages tables, no builder, no PDFs, auth = mock |

## 2. Three candidate tracks (from the PRD gates) — analysis

### Track A — Sales loop completion: quotes → **invoices** → payments → **returns**
*PRD §orders→invoice/return flows. Finishes the revenue cycle on data we already have.*
- New tables: `invoices` (from quote or order; PKR totals, tax lines, paid/partial/due), `payments` (method: bank/COD/bank-transfer, reference), `returns` (RMA ref, reason, condition, refund_amount, state machine: Requested→Inspect→Refund/Replace/Closed).
- APIs: `POST /api/quotes/:id/invoice`, `POST /api/orders/:id/return`, `PATCH /api/invoices/:id/pay`, stats rollups (revenue, outstanding, return-rate).
- Dashboard: **Invoices** page (list + DataTable + status badges, PDF print view), **Returns** queue in Operations; Orders get invoice/return chips.
- Storefront: `/order-status` lookup by ref (WX-4308) showing stage timeline + invoice + RMA state — reduces WhatsApp “where is my order?” noise.
- PDF: print-optimized HTML invoice (browser → PDF; later server-side). PKR labels locked; no currency ambiguity.
- Effort: 1 focused build session · Risk: low (additive) · Unlocks: finance ops, deposits for custom furniture orders (production needs 50% advance rule in PRD).

### Track B — Page builder foundation §30: `pages`, `page_blocks` (furniture marketing only)
- Tables: `pages` (slug, title, status draft/published, seo_title/desc/og, theme tokens override), `page_blocks` (page_id, type, props JSON, sort, published flag), `page_versions` (snapshot on publish — Interiors lesson, our own implementation).
- Block registry v1 (furniture-specific, renders with our storefront components): hero, product-grid (live catalog binding), product-feature, materials-craft, showroom/CTA, testimonials, FAQ, contact-form → **posts /api/leads on submit (CRM already wired)**.
- Dashboard: Pages list (status, preview link, edit) + v1 editor = ordered block list with typed prop forms (no drag yet; Puck/drag = phase 7 decision) + publish → version row.
- Storefront: `/p/:slug` server-rendered-from-JSON page route (published blocks only). Landing pages for campaigns: “Ramadan Workspace Sale”, “Ergonomic chairs under 50k”.
- Effort: 1–2 sessions · Risk: medium (new surface) · Unlocks: marketing autonomy, campaign landers with real product data.
- **Explicit non-goals**: nothing from interiors site; no GrapesJS yet (engine decision deferred to P5/P7 after B proves the block schema).

### Track C — Supabase cutover
- Needs from you: project **ref + anon key** (public role only, never service-role), and confirmation the project is this furniture DB's home.
- Sandbox first: `curl https://<ref>.supabase.co/rest/v1/` reachability + `auth/v1/health`; if blocked (as in past sessions) → stays paused, API remains the bridge.
- Then: generate `woodex_furniture` schema SQL mirroring our 7 tables + RLS (anon: insert leads/quotes/orders + read published products; authenticated dashboard role for CRUD), seed script from SQLite, swap `apps/api` driver or retire it with PostgREST direct.
- Effort: half session + network luck · Risk: external-dependency · Unlocks: hosted persistence, auth, edge functions per PRD §13.

## 3. Recommendation

**A first, then B; C in parallel only if credentials arrive.** Rationale: A completes money-flow on live data with zero external dependency; B's pages should showcase furniture sales (invoiced quotes, order-status links) that exist only after A; C is a swap, not a feature, and stays safe because the API boundary already isolates storage.

## 4. After-approval sequencing (once you pick)

1. Schema migration + endpoints → 2. Dashboard/Storefront UI → 3. e2e curl suite + build checks → 4. commit/push → 5. demo walkthrough notes. Each track gates the next.

> Superseded as planning authority by `docs/AGENCY-OS-MASTER-PLAN-v3.md` (same day); tracks A/B here are now phases 4–5 of v3.
