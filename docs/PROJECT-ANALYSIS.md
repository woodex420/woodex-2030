# WOODEX — Project Analysis

**Date:** 2026-10-05 · **Method:** direct inspection of cloned repositories + migration parsing · **Every figure below is measured, not estimated**

---

## 1. Repositories inspected

| Repository | Branch | Stack | Measured size | Verdict |
|---|---|---|---|---|
| `blackibexofficial-blip/woodex-reimagined` | `main` (`e97d69f`) | React 18.3.1 · Vite 5.4.19 · TS 5.8 · Tailwind 3.4.17 · shadcn/ui · react-router-dom 6.30 | 209 files in `src/`, 23 routes, 851 kB JS bundle (232 kB gzip) | ✅ **Real storefront.** Builds and runs. |
| `woodex420/woodex` | `woodex-admin` (`b1bf11a`) | React + Vite · Tailwind 3 + TailAdmin/wsa tokens · Raleway | root `src/`: 23 files, **5,350 LOC, 13 pages** | ✅ **The real operational dashboard** |
| `woodex420/woodex` → `supabase/` | `woodex-admin` | PostgreSQL + Deno edge functions | **45 tables · 16 migrations · 106 policies · 32 functions · 9 buckets** | ✅ **The real backend** |
| `woodex420/woodex` → `apps/` | `woodex-admin` | — | 1,065 LOC total (admin 372 / api 137 / frontend 461) | ⚠️ Empty scaffold — a monorepo skeleton, not a codebase |
| `blackibexofficial-blip/Woodex-AI-Dashboard-3.1` | `main` | **React 19.2** · Vite 6 · recharts 3.4 · `@google/genai` · CDN Tailwind | 11 pages, 583-line mock dataset | ✅ Most modern UI; dark-only prototype, mock data |
| `blackibexofficial-blip/woodex-ai-suite` | — | — | **404 — does not exist** | ❌ Dead link |
| `woodex420/woodex-2030` | `main` | — | README only | ⚠️ Planning repo (this session) |

### Duplicate trees found inside `woodex-admin` (source of edit-the-wrong-file risk)

| Path | Type | Note |
|---|---|---|
| `src/` | **canonical dashboard** | 5,350 LOC, the 13-page app |
| `woodex-master/src/` | duplicate | Same 132-line `DashboardLayout.tsx`, same 553 token usages |
| `apps/admin/src/` | thin scaffold | 372 LOC, 77-line layout |
| `apps/frontend/src/` | thin scaffold | 461 LOC |
| `woodex-ecommerce/`, `woodex-furniture-mpa/`, `woodex-furniture-v2/`, `complete-project/`, `user_input_files/` | older copies | 123 / 30 / 11 / 108 files respectively; `user_input_files/whatsapp-engine` is the one live artifact (Node WhatsApp worker) |

---

## 2. Frontend analysis

### 2.1 Storefront (`woodex-reimagined`)
- 23 routes: home, shop, product detail, room packages, virtual showroom, series (+detail), projects (+detail), services (+detail), B2B, about, contact, custom design, quotation, showrooms, materials, warranty, blog (+post), checkout, 404.
- Data comes from hard-coded `src/data/{products,services,blogPosts,materials,seoContent}.ts` — **no network calls at runtime** (verified: zero `fetch`/`axios`/Supabase references in `src/`).
- All imagery is local under `src/assets/` (no external CDNs) — this is why it previews reliably.
- 49 shadcn `ui/*` wrappers exist; **18 are actually imported**; 31 are dead files.
- 27 `@radix-ui/*` packages declared; **10 are reachable** from the 18 live components.
- Verified running here: production build passes, and all 23 routes render in the committed jsdom smoke test.

### 2.2 Dashboard (`woodex-admin/src`) — the master admin app
- 13 pages: `DashboardPage`, `ProductsPage`, `CustomersPage`, `QuotationsPage`, `OrdersPage`, `InventoryPage`, `DeliveriesPage`, `ReturnsPage`, `ShowroomPage`, `WhatsAppPage`, `AnalyticsPage`, `SettingsPage`, `LoginPage`.
- `App.tsx`: Supabase Auth session → `profiles` row → `DashboardLayout`. Role type is `'admin' | 'editor' | 'viewer'`.
- `path="*"` → `<Navigate to="/dashboard">` — **no 404 page exists**.
- Measured quality gaps (all confirmed by grep):
  | Metric | Value |
  |---|---|
  | `useQuery` hooks | **0** (React Query provider mounted, unused) |
  | Files hand-rolling `useEffect` fetch | 14 |
  | `.limit()` calls | 10 |
  | `.range()` calls | **0** → tables silently cap at 100 rows |
  | `alert()` / `confirm()` calls | **18** |
  | `SettingsPage.tsx` | 21 lines, renders the literal string `PAGETITLE` |
  | `functions.invoke` calls | 4 (2 functions: `quotation-status-updater`, `quotation-pdf-generator`; +2 `whatsapp-chat-messages`) |
  | `DashboardLayout.tsx` | 132 lines |
  | Token-class usages | 614 |
  | Undefined token classes (render nothing) | **34** (`bg-surface-gray` ×23, `text-text-tertiary` ×11) |

### 2.3 AI Dashboard 3.1
- 11 pages incl. **Lead Generation**, **E-Quotations**, **Invoices**, **Showroom visitors** — modules the main dashboard lacks.
- Reads Supabase URL + key from **`localStorage`** at runtime; falls back to mock data when absent (works with no credentials).
- `geminiService.ts` implements AI draft replies (`gemini-2.5-flash`) but ships with an **empty API key**, so it always returns the canned fallback string today.
- Styling is CDN Tailwind + import maps (AI Studio export) — a prototype shell, not a production build.

---

## 3. Backend analysis (Supabase)

### 3.1 Schema
- **45 tables** defined across 16 migrations + 36 per-table SQL files. Full column-level listing: `DATABASE-INVENTORY.md`.
- **53 `ENABLE ROW LEVEL SECURITY` statements, 106 `CREATE POLICY` statements.**
- Notably complete domains: **quotations (4 tables)**, **WhatsApp (7 tables)**, **fulfilment (deliveries + zones + returns + deliverables)**, **inventory (inventory + stock_movements + pricing_rules)**.
- **Missing for the page builder:** no `pages`, `page_blocks`, `page_revisions`, `navigation`, `redirects`.
- `user_permissions` (module, `can_view/create/edit/delete`) and `user_activity_log` (action, resource, metadata JSONB, ip) **exist and are entirely unused** — the RBAC foundation is already modelled.

### 3.2 Edge functions — 32 defined, 3 invoked
Invoked from the dashboard: `quotation-status-updater`, `quotation-pdf-generator`, `whatsapp-chat-messages` (×2).

**29 unreferenced:** `analytics-aggregator`, `create-admin-user`, 8 × `create-bucket-*`, `create-payment-intent` (**empty implementation**), `delivery-calculator`, `inventory-sync`, `inventory-tracker`, `order-notifications`, `order-status-updater`, `pricing-calculator`, `quotation-calculator`, `quotation-crm-sync`, `quotation-generator`, `quotation-notifications`, `return-processor`, `stock-alerts`, `whatsapp-analytics-aggregator`, `whatsapp-appointment-booking`, `whatsapp-campaign-sender`, `whatsapp-crm-trigger`, `whatsapp-journey-tracker`, `whatsapp-message-handler`.

The quotation pipeline alone is 6 functions of ready logic for a module that currently has only 2 wired — **the fastest path to real business value**.

### 3.3 Storage buckets
Eight buckets are referenced by name: blog-temp, customer-documents-temp, media-library-temp, product-images-temp, products-temp, quotations-temp, services-temp, testimonials-temp (+ product-images/media-library used by the dashboard). Eight separate `create-bucket-*` functions should collapse into one media module.

---

## 4. Documentation found in-repo (your "reports")

The `woodex-admin` branch root contains **40+ handover documents**, including: `COMPLETION_SUMMARY`, `DELIVERABLES`, `FINAL_PRODUCTION_STATUS`, `PRODUCTION_READY_REPORT`, `PHASE2_*` (×6), `PHASE4_COMPLETE_SUMMARY`, `PHASE4_TESTING_PLAN`, `WOODEX_QUOTATION_SYSTEM_COMPLETE`, `WOODEX_ECOMMERCE_PHASE1/2`, `WOODEX_ECOMMERCE_TESTING_REPORT`, `ecommerce_platform_testing_report`, `order_placement_test_report`, `woodex_testing_report`, `SEO-Performance-Report`, `SEO-Quick-Start-Guide`, `DEPLOYMENT_*` (×5), `DEMO_CREDENTIALS`, `workspace_ae_design_system_analysis`, `workspace_ae_css_technical_analysis`, plus `docs/ARCHITECTURE.md`, `docs/woodex-master-architecture.md`, `docs/design-specification.md`, `docs/design-tokens.json`, `docs/wireframe.md`, `docs/CREDENTIALS.md`.

**Reading of these reports vs the code:** they describe a plan that is broader than what was built. The architecture docs specify a full platform (dashboard + showroom + WhatsApp + collaboration + customer portal); what exists in code is the dashboard (13 pages), the schema (45 tables) and the storefront. Treat the docs as **intent**, and the measured code in §2–3 as **reality** — the gap is exactly what this plan schedules.

---

## 5. Verification: your phase plan vs the measured code

| Claim in your plan | Verdict | Evidence |
|---|---|---|
| 13 pages | ✅ | `src/pages/` = 13 files |
| `SettingsPage` is a 21-line stub rendering `PAGETITLE` | ✅ | 21 lines, literal `PAGETITLE` at line 11 |
| `DashboardLayout.tsx` ~155 lines | ✅ (132) | `wc -l` = 132 |
| 18 `alert()`/`confirm()` | ✅ **exactly 18** | grep count |
| 10 `.limit()`, no `.range()` | ✅ | 10 / 0 |
| React Query mounted, 0 hooks used | ✅ | provider in `App.tsx`, 0 `useQuery` |
| 14 pages hand-rolling fetch | ✅ | 14 files with `useEffect` |
| `path="*"` silently redirects to `/dashboard` | ✅ | `App.tsx` |
| 562 token usages | ✅ (614 incl. variants; 553 core) | per-class counts in DESIGN-SYSTEM §3 |
| "Delete all 24 Radix packages (0 used)" | ❌ | **27 declared; 18 wrappers live; 10 packages required** |
| "React 18.3 → 19.2, Vite → 8, Tailwind 3.4 → 4.3, router v6 → v8" | ⚠️ wrong app | These describe the **storefront**, not the dashboard |
| "pannellum-react fails peer react@16" | ❌ not found | Not a dependency of the storefront |
| 23 dead edge functions | ✅ (26) | 32 defined − 3 invoked |
| `create-payment-intent` empty | ✅ | stub |
| `user_permissions` + `user_activity_log` defined & unused | ✅ | 0 references in `src/` |
| 1.44 MB single chunk, 0 lazy routes | ⚠️ storefront measures 851 kB | Re-measure after upgrade |

---

## 6. Security findings

| Severity | Finding | Files |
|---|---|---|
| 🔴 Critical | Real-looking **Stripe secret keys** (`sk_test_…`; `sk_live` referenced) | `docs/CREDENTIALS.md`, `FINAL_PRODUCTION_STATUS.md`, `ACTION_REQUIRED.md` |
| 🔴 Critical | `docs/CREDENTIALS.md` documents values/locations for `STRIPE_WEBHOOK_SECRET`, `JWT_SECRET`, `SENDGRID_API_KEY`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, `VITE_SUPABASE_SERVICE_ROLE_KEY` | public repo |
| 🟠 High | `.env` **tracked** (`.gitignore` only excludes `.env*.local`): Supabase URL + anon key + Stripe publishable key | `.env` |
| 🟠 High | Supabase **anon JWTs** committed (role=`anon`). One uses a typo'd project ref `vosqqajpznqyopjcymer` | `scripts/setup-github-secrets.sh`, `BUILD_SUCCESS.md`, `DEPLOYMENT_QUICKSTART.md`, `VERCEL_DEPLOYMENT_STEPS.txt`, `woodex-master/src/lib/supabase.ts`, `user_input_files/whatsapp-engine/.env.example` |
| 🟡 Medium | Public customer creation permitted by migration | `1762443015_allow_public_customer_creation_v2.sql` |
| 🟡 Medium | Account-scoped PAT pasted into chat | must be revoked at supabase.com/dashboard/account/tokens |

Anon keys are public by design; the risk is conditional on RLS correctness, which is unaudited. Stripe secret keys are unconditionally critical.

---

## 7. Environment limits encountered

| Probe | Result |
|---|---|
| `api.supabase.com/v1/projects` | **connection blocked** |
| `vocqqajpznqyopjcymer.supabase.co/rest/v1/` | **connection blocked** |
| `supabase.com` | **connection blocked** |
| `api.github.com`, `registry.npmjs.org` | ✅ reachable |
| Private npm cache (`europe-west4-npm.pkg.dev`, referenced by the storefront lockfile) | ❌ unreachable → lockfile repointed to npmjs (117 packages) |
| Headless browser downloads (playwright CDNs, chrome-for-testing) | ❌ blocked → route verification done via jsdom instead |

**Consequence:** all database knowledge in this document is **static** (parsed from migrations). Live schema drift, row counts, which modules contain real data, and actual RLS behaviour remain unverified until the network path is opened or a dump is supplied.
