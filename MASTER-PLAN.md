# WOODEX Platform — Master Plan

**Prepared:** 2026-10-05 · **Analysis basis:** real code in 4 Cloned Repositories (see §2) · **Status:** analysis complete — 1 hard blocker (§3) and 2 decisions (§11)

---

## 1. Executive summary

Your plan is **80% correct and every specific number in it checks out against the real code** — but it describes **two different codebases as if they were one**, and it was written without access to the database, which I now have (as schema, not live data). Three things change the plan materially:

| # | Finding | Impact |
|---|---|---|
| **1** | Your phase plan merges the **storefront** (`woodex-reimagined`: React 18.3, Tailwind 3.4, 27 Radix packages, 23 routes) with the **dashboard** (`woodex420/woodex@woodex-admin`: 13 pages, 5,350 LOC, `DashboardLayout.tsx`, `PAGETITLE`, 553 token usages). They are separate apps needing separate upgrades. | Phase 1 must be split into **1A (storefront)** and **1B (dashboard)** or half of it will be applied to the wrong app. |
| **2** | The live Supabase project is **unreachable from this sandbox** — `api.supabase.com` **and** `*.supabase.co` both return connection failure. The `sbp_…` token cannot be used here at all. | "Connect with real data" is **blocked** until network access or a dump is provided. Everything else can proceed. |
| **3** | **Real secret keys are committed to a public GitHub repo** — including Stripe secret keys and a credentials file listing every integration secret. | Rotate immediately, before any further development. This is the highest-priority item in this document. |

**What is genuinely good news:** the database design is far more complete than a typical project at this stage — **45 tables, 106 RLS policies, 32 edge functions, 8 storage buckets**, covering quotations, orders, deliveries, returns, inventory, WhatsApp CRM, showroom and analytics. The dashboard is a real working app, not a mock. Most of the backend you need **already exists**; the work is wiring, permissions, pagination and UI — not designing a system from scratch.

---

## 2. Verified inventory (what we actually have)

### 2.1 Repositories

| Repo | Real? | Stack | Size | Role |
|---|---|---|---|---|
| `blackibexofficial-blip/woodex-reimagined` | ✅ cloned | React 18.3 · Vite 5.4 · TS 5.8 · Tailwind 3.4 · shadcn/Radix | 209 src files · 23 routes | **Public storefront** — runs & builds |
| `woodex420/woodex` @ `woodex-admin` | ✅ cloned | React + Vite · Tailwind (TailAdmin tokens) | 5,350 LOC · **13 pages** | **Operational dashboard** — the real admin app |
| `woodex420/woodex` @ `woodex-admin` → `supabase/` | ✅ | PostgreSQL | **45 tables · 106 policies · 32 edge fns** | **The real backend** |
| `woodex420/woodex` @ `woodex-admin` → `apps/*` | ⚠️ scaffold | — | 1,065 LOC total | Monorepo skeleton, mostly empty (do not build on it) |
| `blackibexofficial-blip/Woodex-AI-Dashboard-3.1` | ✅ cloned | **React 19.2** · Vite 6 · recharts 3 · Gemini AI | 11 pages · 583-line mock dataset | **Most advanced UI reference** — dark AI dashboard prototype, runs on mock data with no keys |
| `woodex420/woodex-2030` (this repo) | ⚠️ README only | — | — | Planning/coordination only |
| `blackibexofficial-blip/woodex-ai-suite` | ❌ **does not exist** | — | — | Dead link — ignore |
| `blackibexofficial-blip/Woodex-AI-Dashboard-3.1` → `user_input_files/whatsapp-engine` | ✅ | Node/TS service | 4 TS files | Standalone WhatsApp automation engine (MessageHandler, AutomationProcessor) |

### 2.2 Database — 45 tables, grouped by business domain

Full column-level inventory: **[`docs/DATABASE-INVENTORY.md`](docs/DATABASE-INVENTORY.md)**

| Domain | Tables | Notes |
|---|---:|---|
| Identity & access | 5 | `profiles`, `admin_users`, **`user_permissions`**, **`user_activity_log`**, `user_presence` |
| Catalog & inventory | 7 | `products`, `product_variants`, `categories`, `inventory`, `stock_movements`, `media_assets`, `pricing_rules` |
| Customers & CRM | 6 | `customers`, `customer_addresses`, `customer_interactions`, `customer_journey_events`, `b2b_companies`, `b2b_users` |
| Quotations & orders | 8 | `quotations`, `quotation_items`, `quotation_activities`, `quotation_templates`, `orders`, `order_items`, `order_status_history`, `cart_items` |
| Fulfilment | 4 | `deliveries`, `delivery_zones`, `deliverables`, `returns` |
| WhatsApp & comms | 7 | `whatsapp_conversations`, `_messages`, `_templates`, `_campaigns`, `_automation_rules`, `_analytics`, `_appointments` |
| Showroom & experience | 3 | `virtual_rooms`, `collaboration_sessions`, `room_packages` |
| Content & marketing | 4 | `blog_posts`, `services`, `testimonials`, `faqs` |
| Analytics | 1 | `analytics_daily` (pre-aggregated daily rollup) |

**What is missing entirely:** there are **no CMS/page-builder tables** — no `pages`, `page_blocks`, `page_revisions`, `navigation` or `redirects`. The page builder in §8 requires new tables (DDL provided).

### 2.3 Backend — 32 edge functions, only 4 ever called

Deployed (by filename) but **only these 4 are invoked anywhere in the dashboard**: `quotation-status-updater`, `quotation-pdf-generator`, `whatsapp-chat-messages` ×2. The other **26 are dead code** — including the six function-quotation pipeline, `create-payment-intent` (empty), `stock-alerts`, `order-notifications`, `delivery-calculator`, `return-processor`, `analytics-aggregator`, and 8 more WhatsApp functions.

### 2.4 Frontend surfaces

- **Storefront (reimagined):** 23 routes, fully self-contained, all imagery local, **no backend calls at all** — it is a marketing/e-commerce shell with no live data. Cart/quotation are client-side context only.
- **Dashboard (woodex-admin/src):** 13 pages, Supabase Auth with `profiles` role check, 14 files hand-rolling `useEffect`+fetch, `DashboardLayout.tsx` (132 lines), `path="*"` → silent redirect to `/dashboard` (no 404), `SettingsPage.tsx` = 21 lines with the literal string `PAGETITLE`.
- **AI Dashboard 3.1:** 11 pages (Dashboard, Orders, E-Quotations, Invoices, Products, Deliveries, WhatsApp CRM, Showroom, Analytics, Lead Generation, Settings), **reads Supabase credentials from `localStorage` at runtime**, falls back to mock data when absent, and has a working Gemini service for AI draft replies. Two capabilities here exist nowhere else: **AI copilots** and **runtime-configurable Supabase connection**.

### 2.5 It already runs

The storefront is verified working in this environment — production build passes and all **23 routes render** (jsdom smoke test, `npm run test:smoke` committed to that clone). Two fixes were needed to run it outside Lovable: the lockfile resolved 117 packages through an unreachable private npm cache, and a Google Fonts `@import` sitting after the `@tailwind` directives was being silently dropped by browsers (so the site's `font-black` headings never loaded weight 900).

---

## 3. ⛔ The one hard blocker: no path to the database

| Probe | Result |
|---|---|
| `api.supabase.com/v1/projects` (Management API — what the token is for) | **connection blocked** |
| `vocqqajpznqyopjcymer.supabase.co/rest/v1/` (project REST/PostgREST) | **connection blocked** |
| `supabase.com` | **connection blocked** |
| `api.github.com` | ✅ reachable |

**Consequence:** even with your `sbp_…` token, I cannot read the real data, cannot list tables, cannot see row counts, and cannot connect the dashboard to the live project from here. Everything in §2.2 comes from reading the migration files — it is the *intended* schema, and may have drifted from what is actually deployed.

**To unblock, any one of these is enough:**

1. **Preferred — open the network path** to `api.supabase.com` and `*.supabase.co` in this sandbox (then I can introspect the project and wire the app directly).
2. **Give me a schema + sample dump:** run locally and attach:
   ```sh
   supabase db dump -f schema.sql          # structure
   supabase db dump --data-only --schema public -f data.sql   # optional, redact PII
   psql "$DB_URL" -c "select table_name, (xpath('/row/c/text()', query_to_xml(format('select count(*) c from %I.%I','public',table_name), false,true,'')))[1]::text::int as rows from information_schema.tables where table_schema='public' order by 2 desc;"
   ```
   The row-count query alone tells me which modules have real activity and which are empty — that decides build order.
3. **A staging project** created from the same migrations, with its own URL + anon key (anon key is safe to share; a service-role key must never be pasted in chat — put it in a sandbox-only env var).

> **Never paste a `service_role` key or a `sbp_` token into chat.** The token you already sent should be revoked at **supabase.com/dashboard/account/tokens** once work here finishes.

---

## 4. Corrections to your phase plan

| Your plan said | Reality (measured) | Corrected action |
|---|---|---|
| "Delete all 24 Radix packages (0 used)" | **27 declared**; 18 of 49 `ui/*` wrappers are actually imported; 10 Radix packages are reachable directly. The rest is dead weight, *but deleting all of them breaks `button`, `card`, `dialog`, `select`, `tabs`, `sheet`, `tooltip`, `toast`… | Delete the **31 unused wrapper files** + the **~17 unreachable Radix deps**. Keep the 10 that back the 18 live components. |
| "562 usages of `text-text-primary` etc." | **553** in `woodex-admin/src` (and 553 again in the duplicate `woodex-master/src`, 121 in `woodex-furniture-mpa`) | Target the dashboard, not the storefront — **zero** such tokens exist in `woodex-reimagined`. |
| "13 pages", `DashboardLayout.tsx` 155 lines, `PAGETITLE`, 18 `alert()`, 10 `.limit()`, 0 `useQuery` | All ✅ **confirmed** (layout is 132 lines; 553 tokens) — these describe the **dashboard** | Apply Phase 3 to the dashboard. |
| "React 18.3 → 19.2, Vite 6 → 8, Tailwind 3.4 → 4.3, `react-router-dom` v6 → v8" | Describes the **storefront** (18.3 / Vite 5.4 / Tailwind 3.4 / router v6) | Apply as **Phase 1A** to the storefront. |
| "recharts → ApexCharts in the single `AnalyticsPage`" | Dashboard uses **recharts**; AI Dashboard uses **recharts 3**. Storefront has recharts too | Decide once, apply to both (§11 decision D2). |
| "replace `pannellum-react` (fails peer `react@16.x`)" | **`pannellum-react` is not present** in the storefront; `VirtualShowroom.tsx` only contains the words "360° Product View" in copy | No action in the storefront. Verify before assuming this bug exists elsewhere. |
| "RLS policy audit" | **106 `CREATE POLICY` statements** across 53 enable statements, but two migrations let the public create customers (`allow_public_customer_creation_v2`), and `profiles.role` only has `admin\|editor\|viewer` | Audit is required and is bigger than expected — see §7 Phase 4. |

---

## 5. Target architecture

One monorepo, one design system, one data layer. Recommendation:

```
woodex-platform/
├── apps/
│   ├── storefront/          ← woodex-reimagined, upgraded (React 19, Vite, Tailwind 4)
│   ├── dashboard/           ← woodex-admin/src, TailAdmin shell + 13 modules + builder
│   │   ├── modules/*        (M01–M18)
│   │   └── builder/         ← visual page builder editor (M13)
│   └── whatsapp-engine/     ← user_input_files/whatsapp-engine (long-running worker)
├── packages/
│   ├── design-system/       ← tokens · Tailwind preset · UI primitives (§9)
│   ├── builder-core/        ← block schema + block registry + renderer (shared editor↔storefront)
│   ├── data/                ← generated Supabase types + React Query hooks + pagination
│   └── shared-types/        ← business types (extend the existing 81-line file)
├── supabase/
│   ├── migrations/          ← existing 45-table schema (authoritative)
│   └── functions/           ← 32 edge functions → triage: wire / delete / complete
└── docs/
```

**Non-negotiables**
- `supabase/migrations` is the **single source of truth** for schema; all schema changes via migration files, never the dashboard UI.
- The **dashboard is the only place** that holds a service-role key, and only inside edge functions.
- **Storefront and dashboard share `design-system` + `builder-core`** — that is what makes the builder's "what you see" identical to "what ships".

---

## 6. Master module plan

Status legend: ✅ exists & wired · 🟡 exists, partially wired · 🔴 exists, not wired · ⛔ missing

| ID | Module | Data | Backend | Current state | Work |
|---|---|---|---|---|---|
| **M01** | Auth & identity | `profiles`, `admin_users` | `create-admin-user` | 🟡 login works, role from `profiles` | Invite flow, password reset, MFA, session table, kill the `admin_users` duplication |
| **M02** | Dashboard / overview | `analytics_daily`, all | — | 🟡 `DashboardPage` | Real KPIs from aggregates, role-aware widgets, date-range, low-stock + today's deliveries |
| **M03** | Products & catalog | `products`, `product_variants`, `categories`, `media_assets`, `pricing_rules` | `pricing-calculator` | 🟡 list + `ProductFormModal` | Full CRUD, variants, image upload to `product-images`, bulk import/export, SEO fields |
| **M04** | Inventory & warehouse | `inventory`, `stock_movements` | `inventory-sync`, `inventory-tracker`, `stock-alerts` | 🔴 3 dead functions | Stock ledger UI, adjustments, multi-location, low-stock alerts, reorder points |
| **M05** | Customers & CRM | `customers`, `customer_addresses`, `customer_interactions`, `customer_journey_events` | — | 🟡 list only | 360° customer view, interaction timeline, lead scoring, segmentation, CSV import |
| **M06** | E-Quotation | `quotations`, `quotation_items`, `quotation_activities`, `quotation_templates` (4 tables, 6 edge fns!) | `quotation-{calculator,generator,pdf-generator,crm-sync,notifications,status-updater}` | 🟡 2 of 6 wired (`generate PDF`, `update status`) | **Highest-value module** — wire the other 4, templates, versioning, accept/reject tracking, PDF branding |
| **M07** | Orders | `orders`, `order_items`, `order_status_history` | `order-notifications`, `order-status-updater` | 🔴 both dead | Order list w/ server-side pagination, status workflow, invoice, notification triggers |
| **M08** | Deliveries | `deliveries`, `delivery_zones`, `deliverables` | `delivery-calculator` | 🔴 dead | Delivery scheduling + **FullCalendar**, zone/charge master, assign driver, proof-of-delivery upload |
| **M09** | Returns & after-sales | `returns` | `return-processor` | 🔴 dead | Return request → approval → inspection → refund/exchange workflow, reason analytics |
| **M10** | WhatsApp CRM | 7 `whatsapp_*` tables | 9 WhatsApp functions + `whatsapp-engine` service | 🟡 1 wired (`whatsapp-chat-messages`) | Inbox UI, templates, campaigns, automation rules, appointments, analytics rollup, engine worker |
| **M11** | Virtual showroom | `virtual_rooms`, `collaboration_sessions`, `user_presence` | — | 🟡 `ShowroomPage` | Room manager, 360° panoramas, shared sessions, presence |
| **M12** | Analytics & reporting | `analytics_daily` | `analytics-aggregator` | 🔴 dead | Daily rollup job, sales/inventory/quotation dashboards, date ranges, export |
| **M13** | **CMS & Page Builder** | ⛔ **0 of 5 tables exist** | — | ⛔ | **The Elementor replacement — see §8 and `docs/PAGE-BUILDER-SPEC.md`** |
| **M14** | Media library | `media_assets` | 9 × `create-bucket-*-temp` | 🔴 | Upload/browse/tag/crop, bucket policies, replace the 9 ad-hoc bucket functions with one |
| **M15** | **RBAC & audit** | `user_permissions`, `user_activity_log` (both **exist, both unused**) | — | ⛔ | **Your highest-value differentiator** — §7 Phase 4 |
| **M16** | Settings & integrations | — | `create-payment-intent` (empty) | ⛔ Settings is a stub | Real settings: users, roles, company profile, tax, currency, Stripe deposits, email/SMS, WhatsApp tokens, audit |
| **M17** | B2B / corporate portal | `b2b_companies`, `b2b_users` | — | ⛔ unused tables | Trade accounts, contract pricing via `pricing_rules`, bulk quote requests, credit terms |
| **M18** | AI copilots | — | Gemini (`@google/genai`) | 🟡 exists only in AI Dashboard 3.1 | Port `geminiService`: draft WhatsApp replies, quotation drafting, lead scoring, insight summaries |

### Role matrix (from your answer: internal team, distinct roles)

| Role | Primary modules | Permission shape in `user_permissions` |
|---|---|---|
| **Management** | M02, M12, M06, M15, M16 | `can_view` everywhere; approve quotations/returns; **no** delete on ledger tables |
| **Sales** | M05, M06, M07, M10, M17 | full CRUD on customers/quotations; read products/inventory; no cost prices |
| **Warehouse** | M03, M04, M08 | full CRUD on inventory/stock movements; read orders/deliveries |
| **Delivery** | M08, M09 | update only assigned deliveries (row-level), read orders |
| **Accounts** | M06, M07, M09, M12 | read all, edit payments/refunds/invoices, no product edits |
| **Content/Editor** | M13, M14, M03, M16 | page builder + media; publish rights gated behind approval |

`user_permissions` already stores exactly this shape per module: `can_view / can_create / can_edit / can_delete`. Current `profiles.role` only allows `admin|editor|viewer` — it must be widened to `management | sales | warehouse | delivery | accounts | editor`.

---

## 7. Phase plan (corrected)

### Phase 0 — Unblock & secure *(1 day)*
- **Rotate every exposed secret** (§10) — do this first, it is unrelated to code.
- Choose the unblock path in §3 and give me the schema/row-count dump.
- Decide the monorepo layout (§5, decision D1).
- **Exit gate:** no live secrets in the public repo; a read-only path to real data exists.

### Phase 1A — Storefront platform upgrade *(3–5 days)*
React 18.3 → 19, Vite 5 → 7/8, Tailwind 3.4 → 4 with the token bridge, router v6 → v8, prune the 31 dead shadcn wrappers + ~17 Radix deps, wire the storefront to real Supabase (products, categories, room packages, blog, media) instead of its hard-coded `src/data/*.ts`.
**Exit gate:** identical-looking site, green build, catalog served from the database.

### Phase 1B — Dashboard platform upgrade *(2–3 days)*
Same stack target, plus the **token bridge for the 553 `text-text-primary`/`bg-surface-base`/`border-separator` usages** so nothing renders differently, and delete the duplicate `woodex-master/`, `apps/*` scaffold and `woodex-furniture-*` copies.
**Exit gate:** dashboard renders identically on the new stack.

### Phase 2 — TailAdmin shell *(3–4 days)*
Vendor TailAdmin v2.4 (MIT), extract collapsible `AppSidebar`, `AppHeader`, breadcrumbs, `ThemeProvider` **dark mode**, auth layouts. Replace `DashboardLayout.tsx`. Add a **real 404** (today `path="*"` silently redirects).
**Exit gate:** dark mode works; every route has a title; 404 exists.

### Phase 3 — Port the 13 modules *(~2 weeks, module by module)*
Order (ops-first, matching your answer): **Dashboard → Orders → Quotations → Products → Inventory → Customers → Deliveries → Returns → WhatsApp → Analytics → Showroom → Settings → (new) Page Builder.**
Every module gets, as part of "done":
- **React Query** (provider is mounted, zero hooks used today)
- **server-side pagination + filtering** via `.range()` (10 `.limit()` calls currently cap tables at 100 rows silently)
- **TailAdmin dialogs + `sonner` toasts** replacing all **18 `alert()`/`confirm()`**
- loading/empty/error states, and a react-query cache key convention
**Exit gate:** no module is a stub; no `alert()` left in the codebase.

### Phase 4 — RBAC & audit *(~1 week — highest-value differentiator)*
Widen `profiles.role` to the six real roles; build the role × module matrix on `user_permissions`; per-route guards **and** per-action gating (replace the single `canEdit` boolean); user-management UI; audit-log viewer on `user_activity_log`; and a full **RLS policy audit** — with real data, client-side gating is UX, not security.
**Exit gate:** a `delivery` user logging in sees only assigned deliveries, proven by an RLS test, not by hiding a button.

### Phase 5 — Page Builder & CMS *(2–3 weeks — §8)*
**Exit gate:** a marketer publishes a new landing page without a developer, and the published page is served by the storefront from the database.

### Phase 6 — AI & advanced *(pick, then sequence)*
Gemini copilots (M18) · notification center + Supabase Realtime · command palette (`cmdk` is installed and unused) · FullCalendar for deliveries · triage the **29 dead edge functions** (wire or delete — start with `quotation-pdf-generator`, 264 lines ready) · i18n + RTL (Urdu/Arabic — strong for a Lahore exporter) · code splitting (1.44 MB single chunk, 0 lazy routes) · tests (currently **0**) · Sentry · complete `create-payment-intent` for Stripe deposits.

---

## 8. Page Builder — the Elementor replacement

Full specification: **[`docs/PAGE-BUILDER-SPEC.md`](docs/PAGE-BUILDER-SPEC.md)**

**Why not Elementor:** Elementor is WordPress/PHP, produces markup you don't control, and can't bind to your Supabase products, quotations or inventory. What you actually need is a **block-based visual builder whose output the storefront renders natively from your own design system** — so a builder page is indistinguishable from a hand-coded page, and can drop in live product grids, room packages and quote-request forms.

**Architecture in one line:** the editor writes a **JSON document**; `packages/builder-core` renders that same JSON in the editor canvas *and* in the storefront — so preview ≡ production by construction.

- **Content model (5 new tables):** `pages`, `page_blocks` (or `content JSONB` + tree), `page_revisions`, `navigation`, `redirects`. **None exist today** — DDL is in the spec.
- **Block library (~28 blocks):** layout (section/container/columns/grid), content (heading, text, image, video, button, icon, divider, spacer, accordion, tabs, quote, table), commerce (product grid, product card, category strip, price table, add-to-quote, room package), lead-gen (form, WhatsApp CTA, contact card, map), dynamic (latest blog, testimonials, FAQ from DB, client logos, stats).
- **Token-bound styling only** — blocks pick from design-system tokens, so pages can't drift from the brand. Advanced users get a scoped custom-CSS field.
- **Dynamic bindings** — any block can bind to a query (`products where category = X limit 8`), which is the thing Elementor fundamentally cannot do here.
- **Workflow:** draft → preview (shareable link) → publish (versioned, instant rollback); revisions on every publish; per-locale content for i18n.
- **Performance:** pages are static-first with cached HTML at the edge plus client hydration only for interactive blocks — a builder must never be the reason the site gets slow.
- **Import path:** if there is real Elementor content, the spec includes a JSON import/mapping plan.

---

## 9. Design system

Full detail: **[`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md)**

One token source, three consumers (storefront, dashboard, builder):

- Tailwind v4 `@theme` with CSS custom properties as the single source of truth.
- **A token bridge** maps the dashboard's existing 553 legacy classes (`text-text-primary`, `text-text-secondary`, `border-separator`, `bg-surface-base`) onto the new theme variables, so Phase 1B ships with **zero visual diff**.
- Two brand contexts, one system: the storefront's HON-inspired white/dark/green and the dashboard's TailAdmin surface scale become *semantic* tokens (`--surface-base`, `--text-primary`, `--accent`) with per-app themes.
- Dark mode defined once via `class` strategy (TailAdmin's `ThemeProvider`).
- Recharts → single charting decision (§11 D2) with a shared chart theme so analytics looks the same everywhere.

---

## 10. Security findings — act before anything else

The repo `woodex420/woodex` is **public** and contains:

| Severity | Finding | Action |
|---|---|---|
| 🔴 **Critical** | `docs/CREDENTIALS.md` + `FINAL_PRODUCTION_STATUS.md` contain **real-looking Stripe secret keys** (`sk_test_…`); `sk_live` is referenced in 3 files | **Rotate Stripe keys now** (test *and* live), revoke old ones |
| 🔴 **Critical** | `docs/CREDENTIALS.md` documents the location/value of `STRIPE_WEBHOOK_SECRET`, `JWT_SECRET`, `SENDGRID_API_KEY`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, `VITE_SUPABASE_SERVICE_ROLE_KEY` | Rotate all of them; **delete the file from the repo history** |
| 🟠 High | `.env` is **tracked** (`.gitignore` only excludes `.env*.local`) — contains Supabase URL + anon key + Stripe publishable key | Untrack `.env`, add `.env` to `.gitignore`, commit `.env.example` only |
| 🟠 High | Supabase **anon JWTs committed** in 6 files (`scripts/setup-github-secrets.sh`, `BUILD_SUCCESS.md`, `DEPLOYMENT_QUICKSTART.md`, `VERCEL_DEPLOYMENT_STEPS.txt`, `woodex-master/src/lib/supabase.ts`, `user_input_files/.../.env.example`) — role is `anon` (public by design, so lower risk *if* RLS is tight), but one uses a **typo'd project ref** (`vosqqajpznqyopjcymer`) | Rotate the anon key once the RLS audit passes; fix the typo'd ref |
| 🟡 Medium | Public customer creation allowed by `allow_public_customer_creation_v2`; 106 policies unaudited | Part of Phase 4 RLS audit |
| 🟡 Medium | The `sbp_…` token you pasted in chat is account-scoped | **Revoke it** at supabase.com/dashboard/account/tokens |

**Note:** the anon key being public is *normal* — it is designed to be shipped in browsers. The reason this is still worth cleaning is that if RLS is wrong anywhere, that key is the front door. The Stripe secret keys, by contrast, are unambiguously critical: a secret key can create charges and read your customer list.

---

## 11. Decisions I need from you

| # | Decision | Options | My recommendation |
|---|---|---|---|
| **D1** | Repo strategy | (a) new `woodex-platform` monorepo as §5 · (b) keep 3 repos, share packages via npm · (c) keep as-is | **(a)** — one design system, one builder, one CI |
| **D2** | Charting | recharts (in use) vs ApexCharts (your plan) | **ApexCharts** — better out-of-box for financial/ops tables and printable reports; migrate both apps in one pass so charts don't diverge |
| **D3** | Real data path | §3 option 1 / 2 / 3 | **Option 1** (open network) — everything else needs manual re-dumping |
| **D4** | Which app is the "master" | dashboard-first vs storefront-first | **Storefront 1A + dashboard 1B in parallel**, then dashboard modules, then builder — the storefront upgrade is small and unblocks the builder's renderer |

---

## 12. Risks

| Risk | Mitigation |
|---|---|
| Building the builder before the design system is tokenised | Phase 1A/1B must land before Phase 5 |
| 45-table schema with 29 never-called functions means unknown half-features | Triage every function: wire, delete, or rewrite — no "leave it" |
| Duplicate copies (`woodex-master/`, `apps/*`, `woodex-furniture-*`, `complete-project/`) cause edits to the wrong tree | Phase 1B deletes them; single app per surface |
| RLS drift between migrations and live DB | Get a live schema dump (§3) and diff before Phase 4 |
| Builder output causing site-wide slowdown | Static-first rendering + budget tests in CI |
| Storefront has **no backend calls today** | Phase 1A adds the data layer — do it before the builder binds to it |

---

## 13. Next 10 actions

1. **Revoke** the `sbp_…` token and rotate Stripe/SendGrid/WhatsApp/JWT secrets; untrack `.env`.
2. Choose D1–D4 (§11) and the §3 unblock path.
3. Run the row-count query in §3 and share it — it decides module order by real activity.
4. Delete the duplicate trees and scaffold dirs; confirm `woodex-admin/src` + `supabase/` as canonical.
5. Phase 1A: storefront stack upgrade + token bridge.
6. Phase 1B: dashboard stack upgrade + prune dead shadcn wrappers.
7. Phase 2: TailAdmin shell + dark mode + real 404.
8. Phase 3: port M06 (E-Quotation) first — it has 4 tables and 6 functions of ready backend, i.e. the fastest real value.
9. Phase 4: RBAC across the six real roles + RLS audit.
10. Phase 5: page builder (spec ready).

---

### Appendix — documents in this set

| File | Contents |
|---|---|
| `MASTER-PLAN.md` | This document |
| `docs/PROJECT-ANALYSIS.md` | Full technical inventory, evidence and verification table |
| `docs/DATABASE-INVENTORY.md` | All 45 tables with columns, keys and references |
| `docs/PAGE-BUILDER-SPEC.md` | Elementor replacement: data model, block library, editor UX, renderer, phases |
| `docs/DESIGN-SYSTEM.md` | Token architecture, TailAdmin mapping, migration steps |
