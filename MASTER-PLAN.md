# WOODEX Platform — Master Plan

**Prepared:** 2026-10-05 · **Analysis basis:** real code in 4 cloned repositories (see §2) · **Status:** analysis complete; implementation checkpoint below (live Supabase remains unverified)

---

## 1. Executive summary

The verified inventory below is a **migration-derived snapshot, not a live database introspection**. The two application surfaces—the public storefront and the operational dashboard—remain distinct, but should share their design system, builder renderer and typed data contracts. The user's updated direction promotes the no-code page builder and global Theme Studio to a core product pillar, with multi-industry starter packs and an end-to-end CRM plan. The full, approval-gated v2 is in [`docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md`](docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md).

| # | Finding | Impact |
|---|---|---|
| **1** | Your phase plan merges the **storefront** (`woodex-reimagined`: React 18.3, Tailwind 3.4, 27 Radix packages, 23 routes) with the **dashboard** (`woodex420/woodex@woodex-admin`: 13 pages, 5,350 LOC, `DashboardLayout.tsx`, `PAGETITLE`, 553 token usages). They are separate apps needing separate upgrades. | Phase 1 must be split into **1A (storefront)** and **1B (dashboard)** or half of it will be applied to the wrong app. |
| **2** | The live Supabase project is **unreachable from this sandbox** — `api.supabase.com` **and** `*.supabase.co` both return connection failure. The `sbp_…` token cannot be used here at all. | "Connect with real data" is **blocked** until network access or a dump is provided. Everything else can proceed. |
| **3** | **Real secret keys are committed to a public GitHub repo** — including Stripe secret keys and a credentials file listing every integration secret. | Rotate immediately, before any further development. This is the highest-priority item in this document. |

**What is genuinely good news:** the database design is far more complete than a typical project at this stage — **45 tables, 106 RLS policies, 32 edge functions, 8 storage buckets**, covering quotations, orders, deliveries, returns, inventory, WhatsApp CRM, showroom and analytics. The dashboard is a real working app, not a mock. Most of the backend you need **already exists**; the work is wiring, permissions, pagination and UI — not designing a system from scratch.

---

## Implementation checkpoint — 2026-10-05

Build work is now underway in the local monorepo at `/home/user/woodex-platform` (the code is not yet
published to a remote repository):

| Delivered locally | Details |
|---|---|
| Dashboard platform + shell | React 19 / Vite 8 / Tailwind 4; all 13 production dashboard pages ported; grouped nav, role guards, theme, real 404 |
| Storefront port | All 23 original routes and assets, shared design system, Supabase-first catalogue with the original curated data as fallback |
| Shared builder core | 17 block types, same renderer in dashboard and storefront, token-only styles, validation, rich-text sanitiser, immutable tree operations |
| Page editor v1 | Create/edit, block palette + outline, schema-driven inspector, up/down reorder, duplicate/delete, undo/redo, desktop/tablet/mobile canvas, draft save, validate/publish |
| CMS schema draft | 5-table migration with RLS, role checks, revisions and atomic save/publish RPC; not yet applied to any project |
| Lead intake | Storefront form → edge function (service-role key server-side); mock mode writes a CRM lead |
| Verification | 55 tests pass across core/dashboard/storefront; both production builds and all four source-graph typechecks pass |

**Still blocked/unverified:** the user's Supabase project is different from the placeholder ref found in
the repo, and this sandbox cannot reach Supabase. The migration has not been applied, the real schema and
RLS have not been compared against it, the edge function is not deployed, and there is no live-data
verification. Request project ref, anon key and the safe read-only query results using
`docs/SUPABASE-SETUP.md` before pointing the apps at a real project. Never use a service-role key in a
browser build.

**Dashboard refresh:** the WOODEX overview, shell, and catalogue were refreshed in the local monorepo
(commit `b716816`, still local-only) with the charcoal/leaf/timber palette, seeded furniture imagery,
PKR labels, and an explicit demo-data mode. The preview login is mock-only. This is a UI foundation,
not approval to deploy or connect to a live database.

**User-confirmed planning direction (2026-10-05):** plan first and wait for approval before coding;
provide both mock demo sign-in and a later Supabase Auth path; plan all-industry UI/page packs from v1;
launch website live chat + WhatsApp first. The exact boundary between all-industry template coverage
and production backend parity remains an approval question in the v2 plan.

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
| **M13** | **CMS & Page Builder** | ⛔ **No CMS tables in the parsed repo migrations; live project schema unknown** | `wx_save_page_document` in pending migration | 🟡 **Builder core + editor v1 built locally; no live schema/deploy verification** | **The Elementor replacement — see §8 and `docs/PAGE-BUILDER-SPEC.md`** |
| **M14** | Media library | `media_assets` | 9 × `create-bucket-*-temp` | 🔴 | Upload/browse/tag/crop, bucket policies, replace the 9 ad-hoc bucket functions with one |
| **M15** | **RBAC & audit** | `user_permissions`, `user_activity_log` (both **exist, both unused**) | — | ⛔ | **Your highest-value differentiator** — §7 Phase 4 |
| **M16** | Settings & integrations | — | `create-payment-intent` (empty) | ⛔ Settings is a stub | Real settings: users, roles, company profile, tax, currency, Stripe deposits, email/SMS, WhatsApp tokens, audit |
| **M17** | B2B / corporate portal | `b2b_companies`, `b2b_users` | — | ⛔ unused tables | Trade accounts, contract pricing via `pricing_rules`, bulk quote requests, credit terms |
| **M18** | AI copilots | — | Gemini (`@google/genai`) | 🟡 exists only in AI Dashboard 3.1 | Port `geminiService`: draft WhatsApp replies, quotation drafting, lead scoring, insight summaries |
| **M19** | **Theme Studio** | Proposed `sites`, `site_themes`, `theme_revisions` | — | ⛔ not implemented | Global design tokens, header/footer, responsive/light/dark/RTL previews, presets and rollback; schema subject to preflight |
| **M20** | **Unified inbox & support** | Existing WhatsApp tables + proposed channel/conversation records | Website chat + WhatsApp adapters | ⛔ no provider-neutral inbox | Start with website chat + WhatsApp; keep existing WhatsApp records behind a non-destructive adapter |
| **M21** | **No-code automation studio** | Proposed workflow definitions/versions/runs/outbox | Worker/Edge Functions not yet approved | ⛔ not implemented | Visual trigger-condition-action workflows with dry-run, permission checks, consent, idempotency, audit and emergency stop |
| **M22** | **Industry starter packs** | Vertical manifests + approved typed adapters | — | ⛔ not implemented | Shared UI/page packs and synthetic demo states for all requested verticals; live connectors are separately gated |

### Initial role matrix (existing WOODEX roles; v2 extensions in the detailed plan)

| Role | Primary modules | Permission shape in `user_permissions` |
|---|---|---|
| **Management** | M02, M12, M06, M15, M16 | `can_view` everywhere; approve quotations/returns; **no** delete on ledger tables |
| **Sales** | M05, M06, M07, M10, M17 | full CRUD on customers/quotations; read products/inventory; no cost prices |
| **Warehouse** | M03, M04, M08 | full CRUD on inventory/stock movements; read orders/deliveries |
| **Delivery** | M08, M09 | update only assigned deliveries (row-level), read orders |
| **Accounts** | M06, M07, M09, M12 | read all, edit payments/refunds/invoices, no product edits |
| **Content/Editor** | M13, M14, M03, M16 | page builder + media; publish rights gated behind approval |

`user_permissions` currently stores coarse per-module `can_view / can_create / can_edit / can_delete` flags. The known profile role set is limited and may differ from the live project. The v2 plan adds support, marketer, owner, and capability/resource scopes; reconcile all role and membership changes against live schema/RLS before migration.

---

## 7. Phased plan — builder and Theme Studio are core pillars

The old sequence treated the builder as a late add-on. That is superseded by the user's 2026-10-05 direction. The detailed plan, page inventory, data model, CRM flows, acceptance gates, and open scope decisions are in **[`docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md`](docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md)**.

### Phase 0 — Approve scope, rights, and safe preflight
- Get approval of the plan and confirm what “all industries from v1” means: starter UI/page packs for every vertical vs full production backend parity.
- Confirm tenant/workspace scope, permitted Preline use/attribution, and any rights to reuse the user-supplied interior reference repo.
- Obtain the exact safe Supabase preflight JSON and compare it with migrations. Do **not** apply the draft page-builder migration before complete schema/RLS review and explicit approval.
- **Exit:** approved product scope, source/license boundaries, workspace/role model, and verified schema/RLS map.

### Phase 1 — Agency-grade shell, design system, and reusable app patterns
- Design the shared app shell, role-aware navigation, workspace/site context, global search, page headers, forms, tables, responsive states, and design-token contract.
- Evaluate Preline selectively under its current license; keep existing WOODEX tokens authoritative, support light/dark and tested RTL, and retain the shared chart adapter while chart-library choice is deferred.
- **Exit:** representative desktop/tablet/mobile pages pass accessibility, dark/light, keyboard, and RTL review.

### Phase 2 — Theme Studio + visual builder canvas
- Build the visual no-code editor, real pointer and keyboard drag/drop, nested outline, inspector, responsive/RTL preview, reusable blocks/templates, undo/redo and recovery.
- Add global site identity, colours, typography, component defaults, spacing/layout, header/footer, and theme version/rollback controls.
- **Exit:** a non-developer can create a branded landing page and see a global theme change propagate without writing code.

### Phase 3 — CMS workflow, media, and safe publishing
- Implement page/theme revisions, review/approve/publish/rollback, navigation, redirects, media library, SEO/accessibility checks, and typed data bindings.
- Apply a reviewed migration only after the user approves the complete preflight diff; connect live data only after RLS tests.
- **Exit:** authorized users can publish and roll back; public access is limited to published content; editor output matches storefront output.

### Phase 4 — CRM customer 360 and WOODEX lifecycle
- Connect intake, identity resolution, assignment/queues, SLA, qualification, tasks, quotations, orders, delivery, returns, support, and analytics through typed frontend services.
- **Exit:** a synthetic lead can be followed from capture to after-sales with assignment and audit history.

### Phase 5 — Website chat + WhatsApp unified inbox and automation
- Deliver provider-neutral conversation views, website chat, WhatsApp adapter, assignment/SLA/saved replies, consent/opt-out, and a no-code trigger/condition/action automation studio.
- Add logs, approvals, idempotency, retry limits, quiet hours and emergency stop before production outbound messaging.
- **Exit:** synthetic website chat and test WhatsApp events appear in the same customer timeline; demo mode cannot send real messages.

### Phase 6 — Multi-industry starter packs and ready-page library
- Provide the shared shell, page catalogue, component library, manifests, and synthetic examples for SaaS, CRM, ERP, commerce, finance/banking, healthcare, education, HRM, AI/analytics, project management, SEO, and WOODEX.
- **Exit:** each pack is clearly labelled `template-only`, `synthetic-demo`, or `live-connected`; no template implies unbuilt integrations or compliance.

### Phase 7 — Later channels and advanced capabilities
- Add Instagram/Facebook DMs and email after business-account/app approvals, webhook validation and consent are ready; then expand campaigns, analytics, AI-assistive features, and vertical-specific live connectors.

---

## 8. Page Builder + Theme Studio — the Elementor-style product pillar

The existing `packages/builder-core` and editor v1 are the starting point, not the finished product. The present foundation already has a typed block registry, shared editor/storefront renderer, schema-driven inspector, outline, undo/redo, device preview, draft save, validation, and publish path. The main gaps are **true drag-and-drop**, reusable global sections/templates, a full site-wide Theme Studio, review/revision/rollback UI, and hardened live data bindings.

**The user's requirement:** a real no-code visual builder, with global control of colours, typography, component defaults, spacing/layout, breakpoints, header/footer, responsive modes, and RTL. Normal page creation must not require source code. Use validated blocks and token controls; do not expose arbitrary JavaScript, SQL, or unsafe HTML.

**Architecture:** keep the typed JSON document and shared renderer as the default. The editor and published storefront must use the same schema/renderer. A bounded alternative-engine comparison may inform drag/drop interaction, but no engine switch is approved unless it preserves typed data bindings, safe rendering, accessibility, and existing document compatibility.

- **Theme Studio:** site identity; semantic light/dark palettes; typography roles; global styles for headings, buttons, forms, cards, links and tables; layout/grid/breakpoints; header/footer/menu; theme presets; staged preview; version history and rollback.
- **Builder UX:** block library/search; pointer + keyboard drag/drop; tree/navigator; inline editing; inspector tabs; device/locale/RTL preview; undo/redo; autosave/recovery; reusable blocks/sections/page templates; media, SEO and accessibility tools.
- **Publishing:** draft → review → approve → publish → monitor → rollback; signed expiring preview links; immutable versions; role/RLS enforcement; audit events.
- **Dynamic content:** typed allowlisted data sources; user-configured filters/sort/limits; safe fallback content; no user-authored SQL.
- **Data gate:** the five-table CMS migration is a local draft only. Compare it against the exact live project schema, grants, RLS, functions, triggers and storage policies before any application. It is not applied.

Detailed scope and acceptance criteria: **[`docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md`](docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md) §5** and the existing technical draft **[`docs/PAGE-BUILDER-SPEC.md`](docs/PAGE-BUILDER-SPEC.md)**.

---

## 9. Design system and component strategy

Detailed target: **[`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md)** and **[`docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md`](docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md) §4**.

- Keep `packages/design-system` as the single semantic token source for dashboard, storefront, and builder. Extend it with Theme Studio-editable site-level tokens rather than introducing Preline's defaults as a second brand system.
- Evaluate Preline v5 as a selective component/pattern source. It is DOM-driven in React and has dual MIT + Fair Use terms; verify integration lifecycle, attribution, and commercial derivative conditions before copying code. Do not include Pro assets without their license.
- Build WOODEX React wrappers for shared forms, tables, navigation, overlays, charts, calendars, chat, file upload, Kanban and builder controls. Keep the codebase's existing chart wrapper; chart-library selection remains deferred.
- Support light/dark, responsive desktop/tablet/mobile, semantic states, reduced motion, WCAG-oriented testing, and logical properties for RTL/Urdu/Arabic.
- Preline MCP/Agent Skills/AI prompts remain optional developer tooling and are not runtime product features; never feed them customer data or secrets.

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

## 11. Decisions confirmed for planning and remaining gates

| Topic | Current decision/state |
|---|---|
| Monorepo | New local `woodex-platform` monorepo is the selected build target; it is not currently published to a remote. |
| Planning/implementation order | Deliver the master plan first; wait for the user's approval before implementation. |
| Sign-in | Both modes: safe seeded mock demo now; Supabase Auth after the user's exact project is confirmed and reviewed. |
| Page builder | A core project pillar: no-code drag/drop with site-wide Theme Studio and controlled publishing. |
| Industry scope | All-industry UI/page packs are in the plan; confirm whether the user expects production backend parity for every vertical or WOODEX live data plus reusable starter packs. |
| First communication channels | Website live chat + WhatsApp. Instagram/Facebook DMs and email are later adapters. |
| Charting | Deferred by the user; maintain one shared chart wrapper so the underlying library can change in one place. |
| Live Supabase | Use seeded mock data until the exact project/ref and safe preflight JSON are available; never assume the placeholder ref and never use a service-role key in the browser. |
| Marketingwoodex reference repo | No source/content/data reuse until repository ownership and reuse permission are confirmed; current GitHub metadata declares no license. |
| Preline | Use as a selective reference only after license/attribution review; do not copy Pro assets. |

Open gates and detailed recommendations are tracked in **[`docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md`](docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md) §11**.

---

## 12. Risks

| Risk | Mitigation |
|---|---|
| Global Theme Studio is built before the semantic token contract is stable | Complete the token and schema contract first; Theme Studio edits versioned tokens, not ad-hoc page CSS |
| 45-table migration inventory differs from the user's live project | Safe preflight + schema/RLS diff before any migration, provider wiring, or live data binding |
| Duplicate source trees make it easy to edit the wrong app | Keep `/home/user/woodex-platform` as the selected local monorepo; this repo remains planning/coordination |
| Builder output causes slow or inconsistent pages | Use the shared typed renderer, lazy interactive blocks, performance budgets; decide prerender/edge only after hosting is confirmed |
| “All industries” expands into unbounded backend scope | Ship shared UI/page packs; label each as template, synthetic demo, or live-connected; approve each production domain separately |
| Preline's dual Fair Use terms affect a commercial builder derivative | License/attribution review before copying or bundling implementation code; avoid Pro assets without a license |
| Reference repo rights are unclear | No code/assets/content/data reuse until ownership and permission are confirmed |
| WhatsApp-only schema is forced into a universal conversation model too early | Use a non-destructive channel adapter first; normalize after live schema and migration review |
| No-code automation accidentally sends messages or changes money/data | Consent/quiet-hour limits, dry-run, role checks, human approvals, idempotency, audit log, retries/dead-letter queue, and emergency stop |
| Finance/healthcare/banking demo pages are mistaken for compliant live products | Explicit data-mode/feature labels; domain-specific security, privacy, and compliance review before any live connector |

---

## 13. Next actions — planning gates before implementation

1. Review **[`docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md`](docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md)** and approve or request changes. No dashboard code or migration work starts before approval.
2. Confirm whether all-industry v1 means UI/page packs or full production backend parity for every industry.
3. Confirm the initial workspace/tenant model and role/capability hierarchy.
4. Confirm legal/reuse rights for the marketingwoodex reference; the plan does not reuse its code or content.
5. Review Preline's current dual license/attribution terms before any implementation code is copied.
6. Provide the exact safe Supabase preflight requested in `docs/SUPABASE-SETUP.md` when ready; do not send service-role keys, PATs, customer records, or unredacted dumps.
7. After approval, implement the design-system/shell foundations and Theme Studio + builder canvas as the first major workstream.
8. Then implement CMS publishing/RLS, customer lifecycle CRM, website chat + WhatsApp inbox/automation, and all-industry page packs by the exit gates in the detailed plan.

---

### Appendix — documents in this set

| File | Contents |
|---|---|
| `MASTER-PLAN.md` | This document |
| `docs/PROJECT-ANALYSIS.md` | Full technical inventory, evidence and verification table |
| `docs/DATABASE-INVENTORY.md` | All 45 tables with columns, keys and references |
| `docs/PAGE-BUILDER-SPEC.md` | Elementor replacement: data model, block library, editor UX, renderer, phases |
| `docs/DESIGN-SYSTEM.md` | Token architecture, TailAdmin mapping, migration steps |
| `docs/AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md` | User-approved planning scope, agency-level dashboard UX, component/page catalogue, no-code builder/Theme Studio, CRM flows, frontend data model, security and phase gates |
