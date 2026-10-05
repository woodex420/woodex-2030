# WOODEX Agency OS — Master System Plan **v3.1**

**Product: Woodex FURNITURE** (Lahore, PKR) · Prepared 2026-10-05 · **Status: v3.1 APPROVED (5 Oct, “start as you recommend”) — Phase 1/2 docs shipped; P5 finance loop LIVE (invoices/payments/returns/order-tracker, commit 8500e42); next: **P3 realtime/SSE + command palette + Vireo KPI home SHIPPED (this wave)** **P4 CRM shipped · P7 CMS + P8 Builder v1 shipped (typed registry, publish/versions/rollback, /p/:slug renderer w/ live catalog bindings + landing lead-forms)** → **P8-v2 SHIPPED: DnD reorder+locks, navigator, global-section fan-out, 409 source guard; P6 starter: per-page theme tokens live in /p/:slug renderer** — next: **P6 SHIPPED: site-wide Theme Engine (token CSS injection + .dark + SSE live-retheme + studio at /website/theme)**, P9+, foundation: auth/RBAC + Postgres/Redis when decided.; remaining foundation: auth/RBAC + Postgres/Redis when decided.**
Supersedes v2.0 dashboard plan and this doc's v3.0 draft. Companion evidence: `docs/AGENCY_OS_MASTER_SYSTEM_PRD_PROMPT.md` (**official PRD, saved verbatim — source-of-truth #1**), `docs/PHASE0-AUDIT-REPORT.md` (PRD §27 20-item output), `docs/PLAN-FURNITURE-TRACK-v2.1.md` (boundary fix), `docs/PHASE0-AUDIT-AND-PLAN-V2.md` (initial audit).
**v3.1 changelog:** ① PRD §22’s 14 phases replace my interim numbering (§9 rewritten; nothing silently replaced per §02). ② Interiors admin dashboard (WoodexAdmin v2, user-pasted + code-verified) adopted as the **proven IA blueprint**, furniture-translated (§5). ③ Vireo sales.html studied live → KPI hierarchy spec for Overview/Sales (§5). ④ Builder feasibility evidence: their whole Elementor-style editor = **2.6k LOC vanilla** with a 148-line CSS-class-allowlisted block registry → custom typed-block builder confirmed viable; comparator risk downgraded (§4.5). ⑤ Resource audit statuses recorded incl. two `BLOCKED` refs (woodex420/woodex = secrets policy; woodex-ai-suite = 404) with the corrected-request protocol.

## 0. Changelog vs v2.0

| # | Change | Source |
|---|---|---|
| 1 | **Furniture re-anchor**: removed “interior repository” framing, `room_packages` bindings, and references to a `packages/builder-core` monorepo that does not exist here. Reality = this repo: `apps/api · apps/dashboard · apps/storefront`, already runtime-connected (7 live tables). | User directive 5 Oct |
| 2 | **Audit absorbed as plan improvements** (§2): the interiors repo is now a *pattern+data-model reference only* — its ADMIN-V2 postmortem, A1–A8 roadmap, and P19 bug round became explicit requirements/QA gates. | Phase-0 clone |
| 3 | **Preline verdict updated from live clone**: v5.0.0 has no React component package; `templates/dashboards/cms-admin` is the reference set; dual MIT+Fair-Use license gate confirmed → patterns + original wrappers, NOTICE file, never resale. | Phase-0 clone |
| 4 | **Builder decision resolved with evidence**: extend our typed-block renderer for Elementor-style UX with a UI kit; GrapesJS = bounded comparator spike only; Puck MIT = optional React-mode engine; Craft.js rejected (dormant), VvvebJs noted. `woodex-ai-suite` removed (dead link, 3× confirmed). | Research sets + v2.0 §5 |
| 5 | **Real-time data flow formalized** (§4.6): existing poll-sync bridge upgrades to SSE, then Postgres `LISTEN/NOTIFY` — “realtime access” applies to CRM, sales, and builder previews. | Current build state |
| 6 | **14 phases** replace v2.0’s 8 (keeps all exit gates + acceptance criteria; phase 0 now has partial evidence done). | User’s PRD item list |

## 1. Positioning & hard boundaries

- **Agency OS core + Woodex Business Pack**: one platform (tenancy, RBAC, builder, CRM, automation); the Furniture pack (catalog→quote→invoice→production→delivery→returns) is tenant #1 and **the only production data model**.
- **Woodex Interiors (MarketingWoodex repo) is a separate sister product.** In this plan: engineering lessons and data-model inspiration only — no code, content, customer data, or migrations cross over. Ownership/license of that repo is still **unconfirmed (NO LICENSE)**; that never blocks us because we copy nothing from it.
- UI kit: **our design system is the source of truth**; Preline/TailAdmin/Vireo are reference patterns (§32 compliance stands).
- Charting deferred (single adapter); sign-in = mock demo now (`management@woodex.pk` / `woodex-demo`, demo-only), Supabase Auth after project gate; PKR everywhere.

## 2. Audit → concrete plan upgrades

| Reference evidence | What we adopt | Where it lands |
|---|---|---|
| Their “why not wf-admin” postmortem: 3-service publish chain (Netlify+Supabase+GitHub) → sign-in bounces, split data | **One DB + one API; static HTML is an export artifact, not the source of truth** | §3 architecture rule A1 |
| MySQL/`pages`+versions+section-library design (A2/A3) | Page docs + immutable revisions + global header/footer fan-out + auto-thumbnail sections + JSON import/export | §4 builder spec |
| A4/A5: forms→lead→kanban→client; quote→PDF→invoice→payment | Sales chain tables & flow (matches our live Track-A gap) | §6 |
| P19: **one central client record** (phone/email match), Settings-as-one-tabbed-page, WhatsApp-as-one-tabbed-page, menu groups Dashboard·Sales·Support·Website·Content·Marketing·Settings | IA + CRM dedupe requirement; nav restructure of dashboard | §5, §6 |
| P19 bug list (chat fallback, drafts on closed modal, live preview wiring, cache-busting `?v=` on replaced media, coming-soon guard) | Promoted into **§9 acceptance QA checklist** (our own implementations, not their code) | §9 |
| Builder rules: no delete/rename from builder (admin-only + forced redirect); header/footer edit updates all pages | Publishing guardrails | §4.4 |
| `mcp-server/server.mjs` pattern | Clean-room MCP gateway design later (read-only first) | P11 |
| 🔴 8 secret-bearing files in a **public** repo | Standing rule for *our* repos: pre-commit secret scan; rotation advice only — handled by owner outside this repo | §8 |
| Open-CRM search (24h metadata): Twenty ★58k dual, Krayin ★24k MIT, trycompai ★11k MIT, ERPNext GPL… | No fork/adopt — stack+license mismatch. Krayin/trycompai as schema/UX reading lists | §6 |
| Page-builder topic search: GrapesJS ★26k (BSD-3), **Puck ★13k MIT active**, Builder.io MIT, Instatic MIT, Craft.js dormant, VvvebJs Apache | §4.5 engine decision ladder | §4 |

## 3. Architecture (target, portable)

```
apps/dashboard   Vite React SPA  ─┐                      ┌─ Postgres 16 (RLS + LISTEN/NOTIFY)
apps/storefront  Vite React SPA  ─┼─ /api (proxy today) ─┤   Redis (cache, queues, rate-limit)
exported static HTML + assets  ◀──┘   NestJS api (goal) └─   Object storage (media, backups)
```

- **A1 One truth:** business data lives in the DB; pages publish *both* JSON docs and generated static HTML (SEO-safe, cache-friendly — the Interiors lesson, original implementation).
- Today's Express+SQLite remains the working bridge; NestJS + Postgres migration keeps the REST shape (both apps already proxy `/api` — the swap is contained). Supabase is an optional *host* of the same Postgres schema when the user provides ref + anon key + preflight JSON (v2.0 §7 checklist unchanged).
- Portability: Docker Compose dev = VPS/cloud prod parity; no platform lock (no Netlify-function chains).
- Realtime: request→response now; **SSE push for board/KPI/builder sync** in P3; Postgres NOTIFY behind NestJS.

## 4. Elementor-style Page Builder + UI kit (primary pillar)

### 4.1 Editor shell (Preline-inspired patterns, WOODEX original components)
Top bar (page title/slug/status, save-state, undo/redo, device+RTL+locale preview, validation, review→publish, history) · left **Library drawer** (searchable, grouped, favorites, “saved sections” with auto thumbnails) · center **canvas** = the real renderer at chosen viewport, inline text/image editing, visible drop targets, snap guides, lock/hidden toggles · right **Inspector** tabs Content · Style · Advanced(=layout/motion/conditions) · Data(bindings) · SEO/Alt · bottom **Navigator tree** + responsive breakpoint bar. Empty/loading/error states everywhere; keyboard alternative for every drag.

### 4.2 Site Settings panel (Elementor parity UX)
Global palette, typography scale, layout width/spacing, radius/shadow/motion, buttons/forms/cards defaults, header/footer chrome editor, logo/favicon/OG — one panel, staged apply, live preview, compare, restore. Values = versioned token JSON only; **no raw CSS/JS/HTML injection** until a separately approved sandbox.

### 4.3 Block taxonomy (Furniture v1)
Structure: section, container, columns, grid, spacer, divider, tabs, accordion · Content: heading, text, image, gallery, video, icon, button, quote, table, logo-strip, testimonial, FAQ, stats, timeline · **Commerce (live-bound)**: product-grid, product-feature, price-table, series-strip, materials-craft, quote-basket, order-status mini · Lead-gen: validated form → `POST /api/leads`, quote-request, WhatsApp CTA, appointment, consent notice · Global: header, footer, announcement bar, reusable sections · Packs: starter landing packs (workspace sale, ergonomic chair promo) — `template-only` until a pack declares live connectors.

### 4.4 Publish pipeline & data tables
`pages(slug,status,seo…) · page_blocks(page_id,type,props,sort,published) · page_revisions(immutable snapshot) · navigation_menus · redirects` (exactly v2.0’s five-table draft, applied locally first, Supabase-compatible SQL). Editing a published page clones to draft; publish = validation (slug collision, alt text, heading order, contrast, bindings resolve, form consent) + audit row + cache invalidation + optional static export. **No delete/rename inside builder** (admin-only, forced redirect — Interiors rule). Unpublish is explicit.

### 4.5 Engine decision (resolved)
1. Ship v1 editor on our **typed block renderer + native DnD spike** (acceptance: pointer + keyboard reorder, schema validation, draft recovery, same renderer preview/live).
2. If the spike stalls > 2 days of effort, evaluate **GrapesJS** as canvas layer over the same block schema (its Storage pattern maps to `page_blocks`); never accept arbitrary-HTML as canonical format.
3. **Puck (MIT)** stays the candidate for React-app-page mode in P7. Craft.js rejected (dormant since 2025); Dooring/luban GPL excluded; Instatic/Builder.io watched.

### 4.6 Real-time data access
Blocks bound to typed read models (`products`, `categories/series`, `materials`, `services`, `projects`, `testimonials`, `faqs`) — allowlist, server-scoped, never raw SQL. Dashboard & storefront share today’s 15 s override polling; SSE replaces polling in P3; builder canvas refreshes < 1 s on data edit via same channel.

## 5. Dashboard IA — modeled on the proven Interiors admin (WoodexAdmin v2), furniture-translated
Menu skeleton verified against their live code (30 `admin-*.js` modules) and the user-pasted menu — the structure works in production for an agency; we rebuild it original (no code reuse):
- **Dashboard** — Vireo-spec KPI home: greeting+period narrative + CTA pair (`Create invoice`/`View pipeline` equivalents) → metric row **Target-hit · Deals won/open · Revenue · Customers · AOV · Refund-rate** → revenue chart w/ week-month-year toggle → **Top selling products** → lead-source traffic mix → recent transactions → activity feed → command palette (`⌘K`, esc to close, ↑↓↵).
- **Sales** — Enquiries & leads · Pipeline · **Bookings → Showroom visits / Design consults** · Clients (single client record + timeline) · Quotations · Invoices · **Transactions** (payment ledger) · Quote templates (block-order + PDF preview wired live).
- **Projects & WhatsApp** — Projects (production stages) · WhatsApp offers · WhatsApp automation — one WhatsApp hub page with tabs (Inbox · Offers · Automation · Templates · Connection).
- **Support** — Inbox · Client updates (order-status notifications = our `/order-status`) · **Train AI** (knowledge/tones editor; drafts stay human-approved).
- **Website → Pages & builder**: All pages · Page builder · Section library · Header & footer · Redirects. **Content**: Blog & insights · Page templates · **Estimator → furniture configurator quote-estimator** · Forms · **Portfolio → Projects lookbook** · **Service pages → Categories/collections** · **City pages → Showroom & delivery-zone pages (Lahore, Karachi…)** · FAQ groups · Testimonials · Team. + Media library (WebP, unused finder, alt, cache-bust `?v=`).
- **Marketing** — SEO (bulk audit) · Speed · Site health (broken links, image weight, 404 log).
- **Settings** — ONE tabbed page: General · Integrations & APIs · Business info · Users & roles · Activity log · Backups · Maintenance & error pages (coming-soon with staff bypass) · File manager · Database · System check · + My security (separate sidebar item).
Global: workspace/site switcher, role simulation (mock), density, light/dark, RTL, notification bell. All groups permission-aware via capability names (`invoice.issue`, `page.publish`, `wa.send`…).

## 6. CRM & Sales (Furniture)
- **Single Client Record**: identity resolution by normalized phone/email over leads, chats, WhatsApp, quotes, invoices, orders, returns, projects; ambiguous matches → human merge queue, never silent merge (Interiors P19 lesson + v2.0 §6.2).
- Lifecycle: form/chat/WhatsApp → consent+source → dedupe → lead → queue/owner → qualify (need, budget band, room/product focus) → quote → follow-up → won → order → production stages → delivery/POD → support/return → review/repeat. Stages, owners, follow-up dates, notes, CSV import/export.
- Sales chain tables (Track-A spec): `invoices` (from quote/order, PKR, tax, due), `payments` (bank/COD/transfer, reference, 50% advance default rule for bespoke), `returns` (RMA states Requested→Inspect→Refund/Replace/Closed); printable **invoice PDF via print-CSS** first, server-render later. Quote PDF block-order switches reflect in live preview instantly (their P19 bug → our acceptance test).
- Inbox: unified conversation model; website chat + WhatsApp adapter behind `providers` abstraction (3rd-party swappable); secrets server-side only; demo mode cannot send real messages.

## 7. Data plan (progression, one source)
Live: `products·materials·services·leads·quotes·orders·meta` → **P4 adds** `invoices·payments·returns` → **P5 adds** CMS five tables + `media_assets` → **P3 adds** `clients` (single-record) + `activities` → conversations/automation tables (P8/P9) → `tenants/roles/capabilities` (P2, capability names like `page.publish`, `order.refund`). Postgres = Supabase-compatible SQL, RLS per table, seeds from SQLite; never a second copy of business records.

## 8. Security (unchanged from v2.0 + audit addendum)
No service-role keys in browser/commit/chat; preflight JSON review before ANY Supabase apply; anon-key-only frontend; cross-tenant denial tests with ≥2 users + signed-out visitor; **pre-commit secret scan enforced on all Woodex repos**; rotate exposed keys in the sister brand’s public repo (owner action, tracked here, not ours to perform).

## 9. 14 phases × acceptance gates
**Official roadmap = PRD §22 verbatim** (P0 Discovery&Audit → P1 Product Architecture → P2 Foundation → P3 Dashboard+Design-System → P4 CRM → P5 Sales+Finance → P6 Ecommerce → P7 CMS+Theme-Engine → P8 Visual Builder → P9 Marketing+Social → P10 Omnichannel → P11 Automation → P12 AI+MCP → P13 Woodex Business Pack → P14 Production Hardening; gate per phase as written in the PRD). Status after Phase 0: **P0 ✅** (audit report); P1 = approval of master-plan §3/§19 architecture; P2/P3 **prepaid in part** (monorepo, proxy API, tokens design system shipped; missing: NestJS/Postgres/Redis, auth, RBAC, packages layout, CI/tests, command palette, Vireo-KPI home); P4 partially prepaid (leads kanban + stats live; missing: contacts/companies/opportunities/tasks/scoring/dedupe).
The build sequence below is **an execution order mapped onto PRD phases** — it does not redefine them: P2→(P3 polish)→P4→P5 run as the first wave (money loop on live data), then P7→P8 (CMS + builder flagship), then P9–P14. Every phase follows the PRD §01 A–G doc structure and §26 acceptance standard.
Mapping for this doc's original table rows: sales-loop = **P5**, CMS/builder v1 = **P7+P8**, Theme Studio = **P7**, inbox/WhatsApp = **P10**, automation = **P11**, AI = **P12**, MCP = **P12**, packs = **P13–P6**, analytics = **P9/P14**, realtime = cross-cutting **P3+P5**, infra = **P2**.

<details><summary>Interim execution detail (v3.0 table, kept for continuity — numbering follows the mapping above)</summary>

| Ph | Scope | Exit gate |
|---|---|---|
| **0** | Discovery & audit | ✅ done — v3 = its output; you approve |
| **1** | Infra: Docker Compose, Postgres 16 + Redis, NestJS api over same REST, parity tests vs SQLite | both apps green on Postgres, UI untouched |
| **2** | Tenancy + auth + RBAC (Owner/Admin/Editor/Sales/Support/Warehouse/Accounts/Analyst), capabilities, audit log, CSRF/rate-limit | pen-checklist + cross-tenant denials |
| **3** | Realtime layer (SSE), global search, notifications, nav restructure §5, Settings tabbed page | board+KPI updates < 2 s without refresh |
| **4** | **Sales loop (Track A)**: invoices/payments/returns + PDF print + order-status page + 50% advance rule | finance walk-through with real numbers; P19-derived QA list passes |
| **5** | **CMS + Builder v1 (Track B)**: five tables, block registry §4.3, editor §4.1–4.2 (no drag yet: list reorder), publish/validation/versions, `/p/:slug` | non-developer creates & publishes a furniture landing page from template in < 30 min |
| **6** | **Theme Studio** full: token contracts, presets, apply/compare/rollback, dark/RTL propagation | one token change visibly updates all inheriting blocks/pages |
| **7** | Builder v2: true DnD (+ §4.5 ladder), navigator tree, global sections + header/footer fan-out, section library with thumbnails + JSON import/export, Puck evaluation for React mode | drag→publish→rollback demo; template edit doesn’t mutate the shared template |
| **8** | Unified inbox: website chat + WhatsApp Cloud API adapter (sandbox), templates, opt-out/consent, SLA, assignment | synthetic inbound chat + test WA event on one client timeline |
| **9** | Automation studio: trigger→condition→action graph, simulator, dry-run, quiet hours, idempotency, dead-letter, kill switch | 3 automations live behind review |
| **10** | Controlled AI: drafts (quotes, replies, page copy), lead scoring w/ explanation, summaries — **human-approved sends only** | §11 AI-rules checklist; zero autonomous outbound |
| **11** | MCP/tool gateway (clean-room): read-only tools → role+confirm write tools; agent rules doc | external agent demo without secrets |
| **12** | Industry starter packs (SaaS/CRM/e-commerce/finance/healthcare/education/HRM…): manifests + template packs, data-mode labels | each pack honestly labelled template-only vs live |
| **13** | Analytics warehouse: `analytics_daily` rollups, source attribution, funnel/SLA/win-rate/return-rate, exports | 30-day real-data exec dashboard |
| **14** | Hardening & go-live: backups/restore, media cache-busting, 404/coming-soon/maintenance, perf budgets in CI, docs site | full acceptance suite §10 green |

</details>

**v3.1 amendment to §4.5 (from Builder-map evidence):** the Interiors team ships a real Elementor-style editor at ~2.6k LOC vanilla (148-line block registry, CSS-class allowlist) — our typed-block custom path is de-risked; GrapesJS/Puck move from “decision” to **bounded comparator only**.

## 10. Acceptance baseline
v2.0 §10 **in full** (UI/UX bar, Builder, CRM/channels, Data/security) **plus** the P19-derived QA list: modal drafts persist; toggles reflect in preview instantly; replaced media cache-busted; coming-soon bypass for logged-in staff; stale-date fields use created vs last-activity; AI down → fallback auto-reply state shown; every list has real empty/error/stale states.

## 11. AI coding-agent rules (project standing)
Research→inspect→recommend→**approval**→build→test→document · never copy/commit credentials; reference-only for unlicensed/TailAdmin assets · no service-role keys browser-side · PKR labels · improve existing modules, don’t overwrite (their P19 rule — adopted) · mock sign-in stays isolated from live auth · each phase ends with demo + exit-criteria review.

## 12. Decisions needed from you before “start” (PRD §27 gate)
1. **Approve v3.1 + §19 target architecture** (official roadmap = PRD §22 phases; first wave P2→P3→P4→P5) — reply “approve” or mark edits.
2. Preline posture: **A** patterns + original wrappers (zero risk, default) or **B** written license clearance before any code-level use?
3. Interiors (MarketingWoodex) repo: **patterns/data-model only** confirmed — their admin menu (§5 blueprint) adopted as structure, no code/content/data crosses over? And their public-repo leaked keys: rotated by their owner?
4. Blocked resources (per §03, no invented findings): ① `woodex420/woodex` — provide a **sanitized source-only ZIP** (no .env/credentials) or approve selective read-only fetches of specific paths; ② `woodex-ai-suite` — corrected URL or ZIP, else struck from PRD references.
5. “All industries v1” = starter UI packs with Woodex Furniture the only live data integration — yes/no?
6. Supabase: send project ref + anon key + preflight JSON now, or run Phase 2 on local Docker Postgres and cut over later?

*Answers 1–6 = Phase-0 sign-off. On receipt I begin Phase 1/2 immediately — sales loop (P5: invoices/payments/returns) first visible feature, builder (P7/P8) next. RESEARCH → INSPECT → RECOMMEND → **APPROVE** → BUILD → TEST → DOCUMENT → REPEAT.*
