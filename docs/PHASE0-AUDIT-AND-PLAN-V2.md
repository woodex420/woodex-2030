# Phase 0 — Discovery & Audit + PRD Plan v2

**Date:** 2026-10-05 · **Status: RESEARCH → INSPECT → RECOMMEND complete — DO NOT CODE YET (awaiting approval gate)**
**Auditor:** Arena agent · **Inputs:** live clones + GitHub API metadata (this session)

---

## 1. What was audited (real evidence, not assumptions)

| Target | Result | Key facts |
|---|---|---|
| `marketingwoodex-cloud/-marketingwoodex` @ `arena/01a0ec23` | ✅ cloned (175 MB, 1,772 files) | Public marketing site (87 static HTML pages, ~60 service/city routes), `frontend-v1/admin/` (29-screen JS admin: `admin-crm.js`, `admin-chat.js`, `admin-dash.js`…), `builder-p17/18` JS, `api/router.mjs`, `mcp-server/`, `supabase/*.sql`, `wf-admin/` (deprecated parallel copy). **NO LICENSE file.** 40 MD plan docs incl. ADMIN-V2 + P19 bug round. |
| `blackibexofficial-blip/woodex-ai-suite` | ❌ **still 404** | Dead link — remove from all planning docs (third session confirming). |
| `htmlstreamofficial/preline` | ✅ cloned | v5.0.0. DOM-first plugins (collapse/carousel/accordion/dropzone/datatables/vanilla-calendar + ApexCharts). **Dual license: MIT + "Preline Fair Use"** (no template resale). `templates/dashboards/cms-admin` = CRM/admin reference set (520 KB). No React component library — React usage = copy-paste wrappers. |
| Open-CRM top-10 (GitHub search) | ✅ | Twenty ★58k (license NOASSERTION ⚠), ERPNext ★40k (GPL-3), NocoBase ★24k (⚠ dual), **Krayin Laravel-CRM ★24k (MIT)**, trycompai/crm ★11k (MIT), idurar (AGPL), Gauzy (AGPL), Dolibarr (GPL). |
| Page-builder top-10 (topic search) | ✅ | **GrapesJS ★26k** (BSD-3/dual), **Puck ★13k (MIT, active daily)**, Builder.io ★9k (MIT), **Instatic ★9k (MIT**, Webflow-like, TS), Craft.js ★9k (MIT, dormant since 2025-02), **VvvebJs ★9k (Apache-2)**, Plasmic ★7k (MIT). |
| Our platform (this repo) | ✅ | `apps/dashboard` (design-system UI), `apps/storefront` (woodex-reimagined), `apps/api` (Express + node:sqlite) — runtime-connected, e2e verified this session. |

## 2. Security findings — immediate, ungated

| Sev | Finding | Action |
|---|---|---|
| 🔴 | **MarketingWoodex is PUBLIC and 8 files contain key-shaped secrets** (`netlify/functions/_supabase.mjs`, `mcp-server/server.mjs`, `MASTER-PLAN.md`, copies in `wf-admin/`): Supabase service keys + likely `sbp_` PATs | Rotate/revoke Supabase keys + PAT at supabase.com **now**; rewrite git history (`git filter-repo`) or delete repo; values never reproduced in this report |
| 🔴 | P19 doc: "admin login was posted in chat" | Change that admin password post-go-live (their note) |
| 🟠 | Our prior session: `woodex420/woodex` public repo carries Stripe test/live keys + credentials file | Already flagged in old MASTER-PLAN §10 — still un-rotated as of this audit date: **rotate** |
| 🟡 | MarketingWoodex `.zip` bundles committed (22 files) — inspect before any migration import for embedded secrets | Scan during P13 |

**Rule adopted from evidence:** no repo in this family is license-clean for code reuse. Treat all as *pattern reference* unless ownership+license is explicitly confirmed in writing.

## 3. Reuse-first matrix

| Candidate | License | Verdict for our build |
|---|---|---|
| MarketingWoodex **data model** (25 tables: clients, enquiries, estimator_leads, projects, page_versions, redirects, media, site_settings, testimonials, activity log) | no license (their own) | ✅ **Absorb concepts** into our Postgres schema (same brand family — confirm ownership); import their live SQL for parity |
| MarketingWoodex **builder v17/18 JS + static-HTML output idea** | ⚠ | Reference architecture only (SEO-safe static publishing + MySQL-backed sections) — do not copy code yet |
| MarketingWoodex **P19 priorities** (single client DB matched by phone/email; Settings-one-page; WhatsApp-one-page; menu groups) | ⚠ | ✅ Adopt as requirements — these are proven user-feedback items |
| **Puck** (MIT, active) | ✅ | **Recommended editor core** for React pages (typed blocks ↔ our block renderer, JSON document model) |
| **GrapesJS** (BSD-3) | ✅ | **Recommended HTML template mode** when MarketingWoodex-style static/SEO output is required (elementor-like canvas, exports raw HTML/CSS) |
| VvvebJs / Craft.js / h5-Dooring / luban | ⚠/❌ | Rejected as core (dormant / GPL viral / editor UX mismatch); patterns noted |
| **Krayin (MIT)** or trycompai/crm (MIT) | ✅ | Only as module reference; **do not fork** — our Agency OS already has CRM/quote tables and needs tenancy/RBAC they lack |
| Twenty / NocoBase / ERPNext | ⚠ dual/GPL | **Not** as platform (license + stack clash); mine Twenty's UX patterns for pipeline/board views |
| **Preline** templates (dashboards, cms-admin) | MIT+FairUse | ✅ Use patterns + copy-allowed MIT scope with attribution note in `NOTICE`; never resell as template product. Prefer our tokens-first design system; import only where it saves real time (e.g. datatables behaviors) |
| AdminLTE (vendored in MW) | MIT | Not needed (we have a design system) |
| TailAdmin (already replaced) | MIT | Remains **reference-only** per design.md §32 — no regressions |

## 4. Stack decisions — recommendations

**D1. App architecture.** MW's own postmortem (their wf-admin "v1"): 3-service publish chain (Netlify functions + Supabase + GitHub) caused sign-in bounces, failed publishes, data split. Lesson → **one DB, one API, static-HTML as an export artifact.**
- **Recommended (Option A):** keep Vite SPAs; replace `apps/api` Express with **NestJS** (monorepo `apps/api`), move SQLite → **PostgreSQL 16 + Redis** (Docker Compose, same API shape — our pages are already proxy-based so the swap is contained), publish builder output as **static HTML** to `assets/`/CDN for MarketingWoodex-grade SEO. Next.js deferred until CMS requires SSR (P6).
- Option B (full PRD re-platform to Next.js + NestJS now): +3–4 weeks before any user-visible feature; not recommended pre-PMF.
- Option C (their Hostinger PHP/MySQL path): hosting-cheapest, but caps automation/AI/MCP ambitions; keep only as deployment target for the static export (their live site already runs on shared hosting).

**D2. Multi-tenancy.** Postgres `tenants` + `tenant_id` FK everywhere + RLS policies (Supabase-compatible), workspace switcher already in dashboard header. Serves *Agency OS core*; Woodex = tenant #1, MarketingWoodex = tenant #2 (sister brand: same CRM + builder, own theme/pack).

**D3. Builder.** Two render modes, one block schema (17 block types we already define in shared types):
- *React mode*: **Puck** editor + our typed renderer (app pages, campaign landers)
- *HTML mode*: **GrapesJS** with our Woodex section library → static `.html` publish + version table (MarketingWoodex parity, SEO-safe)
Global header/footer = section refs (edit once, fan out on publish) — exactly MW's A3 lesson.

**D4. CRM.** Self-build continued on our leads/quotes/orders tables + **Single Client Record** (phone/email match, P19 requirement): client = timeline of chats, WhatsApp, enquiries, quotes, invoices, payments, notes, projects. Kanban exists. Import MW's `clients/enquiries/estimator_leads` SQL shapes.

**D5. Data bridge (live access).** All three apps already speak REST via Vite proxies; new tables ride the same client. Runtime-access modules in priority: leads → clients → quotes/invoices → pages/sections/media → analytics rollups.

## 5. Improved 14-phase plan (gated; replaces old §34 ordering)

| Ph | Deliverable | Exit gate (user approval required) |
|---|---|---|
| 0 | This audit ✅ | **you approve plan v2 (this doc)** |
| 1 | Platform core: NestJS api + Postgres + Redis + Docker Compose; migrations for tenants/users/clients/leads; API-parity tests on existing SQLite endpoints | green e2e: both apps on Postgres, zero UI change |
| 2 | Auth+RBAC (Owner/Admin/Editor/Sales/Support), sessions, CSRF, rate-limit, activity log | pen-test checklist pass |
| 3 | CRM core: Single Client Record + timeline, pipeline, assignment, follow-ups, CSV import/export | import 500 live MW leads without duplicates |
| 4 | Sales: quotations (exists) → **PDF**, invoices, payments, returns; quote→invoice→delivery chain | finance walk-through with real numbers |
| 5 | Website/CMS v1: pages, posts, media, redirects, versions, publish queue — **GrapesJS HTML mode** + section library + global header/footer | republish 3 real MW pages without visual diff |
| 6 | Theme Studio: tokens per tenant (color/type/spacing), presets, preview, rollback | Woodex + MarketingWoodex theming from one core |
| 7 | Builder v2: **Puck React mode**, typed data bindings (lead forms ↔ CRM), responsive/RTL, landing-page packs | new landing page ships < 15 min |
| 8 | Marketing: campaign + social publishing adapters (Meta first), content calendar | publish to 1 real test account |
| 9 | Omnichannel: website chat + **WhatsApp Cloud API** with provider abstraction (3rd-party swappable), inbox→lead | live chat round-trip + template send |
| 10 | Automation engine: triggers→actions, dry-run, quiet hours, idempotency, dead-letter | 3 automations in production behind kill switch |
| 11 | Controlled AI: drafts, suggested replies, lead classification — **review-gated UI only**, audit trail, no autonomous sends | §26 acceptance checklist |
| 12 | MCP/tool gateway (reuse MW `mcp-server` pattern, clean-room), agent-rules doc | read-only tools first; write tools behind role+confirm |
| 13 | Migration & go-live of MarketingWoodex tenant: static export → Hostinger/CDN, data import, redirects, 404/coming-soon parity (P19 bugs fixed by design) | live-site parity audit vs current prod |
| 14 | Analytics warehouse: daily rollups (their `analytics_daily` idea), lead source attribution, ops KPIs | exec dashboard with real 30-day data |

## 6. Open risks

1. License/ownership for MW code reuse is **unconfirmed** — plan assumes patterns+data-model only.
2. Supabase project ref for *Woodex* side still unknown — Postgres now, Supabase-on-request later (D5 keeps the door open).
3. Preline Fair-Use — never ship their templates verbatim as a product; wrappers + NOTICE file only.
4. P19 shows their bug backlog touches chat/coming-soon/PDF blocks — import their QA list into our P5/P9 acceptance tests.

> **Update (same day):** scope corrected for Woodex Furniture — see `docs/PLAN-FURNITURE-TRACK-v2.1.md`; interiors-brand migration items in §5 P13 are OUT of this product.
