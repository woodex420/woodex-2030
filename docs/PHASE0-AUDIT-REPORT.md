# PHASE 0 — DISCOVERY & AUDIT REPORT (PRD §27 output)

**Date:** 2026-10-05 · **Method:** live clones (this session), GitHub API metadata, site fetches. Nothing invented — inaccessible resources are marked `AUDIT STATUS: BLOCKED` per §03.

## 1. Resource inventory

| Resource | Access | Status |
|---|---|---|
| `marketingwoodex-cloud/-marketingwoodex` @ `arena/01a0ec23` (Woodex **Interiors** product) | cloned 175 MB, 1,772 files | ✅ COMPLETE |
| `blackibexofficial-blip/woodex-reimagined` | previously cloned + now our `apps/storefront` (209 src files, 23 routes, React+TS+Vite+Tailwind) | ✅ COMPLETE |
| `woodex420/woodex` (Minimax) | repo public but contains committed credential patterns + prompt-injected README | ⛔ **AUDIT STATUS: BLOCKED** — will not read/clone in this environment (secrets policy). Request: sanitized export (source-only ZIP, no `.env`/credentials) or per-path fetches approved by owner |
| `blackibexofficial-blip/woodex-ai-suite` | GitHub API | ⛔ **BLOCKED** — 404 (4th confirmation). Needs corrected URL or ZIP |
| Vireo sales dashboard | fetched live | ✅ COMPLETE (findings §7) |
| TailAdmin demo + prior clone | design.md §32 already applied (patterns only, original UI shipped) | ✅ COMPLETE |
| Preline repo | cloned v5.0.0 | ✅ COMPLETE |
| OSS research: `open crm`, `topic:page-builder`, `topic:landing-page-builder` | GitHub search API | ✅ COMPLETE |
| **This project** `woodex-2030` | live dev servers :3001/:5173/:5174 | ✅ runtime-connected monorepo (verified e2e 5 Oct) |

## 2. Repository health (Interiors primary ref)
Actively pushed (same-day commits during P19 round), docs-heavy (40 MD plans/audits), 22 committed ZIPs, duplicated admin (`wf-admin/` legacy alongside `frontend-v1/admin/`), no automated tests found, no CI configs. Health: **functional but debt-heavy; single-maintainer cadence.**

## 3. Technology stack (observed)
Interiors: static multi-route site (87+ pages, ~60 service/city routes) + **vanilla-JS SPA admin** (30 `admin-*.js` modules) + custom `api/router.mjs` (Node) + Netlify functions (legacy) + **PHP 8 + MySQL target** (their ADMIN-V2 decision) + AdminLTE vendor (MIT) + Supabase SQL drafts. No framework, no package manager at root; builder is plain JS + one CSS file. Our furniture stack: React 19 + Vite + Tailwind v4 (dashboard, storefront), Express + node:sqlite (api).

## 4. Architecture map
`Browser → static HTML (SEO) ∥ /admin SPA (hash routes) → /api/*.php or router.mjs → MySQL; builder operates on page files + section JSON; publish = file writes + backups` — their v1 chain (Netlify+Supabase+GitHub) documented as failed; v2 = one DB + static export. **Adopted as our rule A1.** Our current map: two SPAs → one API proxy → one DB — already v2-shaped.

## 5. Feature map (from live code + pasted menu)
Sales: Enquiries&leads · Pipeline · Bookings · Clients · Quotations · Invoices · Transactions · Quote templates · Projects · WhatsApp offers · WhatsApp automation. Support: Inbox · Client updates · **Train AI**. Website: All pages · Page builder · Section library · Header&footer · Redirects · Blog · Page templates · Estimator · Forms · Portfolio · Service pages · City pages · FAQ groups · Testimonials · Team · Media. Marketing: SEO · Speed · Site health. Settings: Integrations&APIs · Business info · Users&roles · Activity log · Backups · Maintenance&error pages · File manager · Database · System check · My security.
**Furniture translation:** Bookings→Showroom visits/Design consults · Estimator(kanal/marla)→Room/workspace configurator (already in storefront) · Service/City pages→Category/Showroom/SEO pages · Interior BOQ→Production material schedule (Business Pack §15 Production).

## 6. Database map
SQLite (ours): `products(148) materials(16) services(7) leads quotes orders meta` — live, e2e-tested. Interiors SQL drafts: 25 tables incl. clients, enquiries, estimator_leads, page_versions, redirects, media, site_settings, testimonials, activity — **model inspiration, zero reuse (no license)**. Missing both sides: invoices/payments/returns, conversations, pages CMS tables, tenancy/RBAC, automation, analytics rollups.

## 7. Dashboard map
Vireo sales.html KPI hierarchy (studied live): greeting+period narrative → primary CTA pair → **metric row: Target-hit, Deals won/open, Revenue, Customers, Products, Transactions, AOV, Refund-rate** → revenue chart w/ period toggle → balance card → device sessions → **Top selling products list** → traffic source → recent transactions table → activity feed → command palette. Adopted for our Overview/Sales page (Furniture: AOV, quote win-rate, production WIP, on-time delivery, return-rate in place of finance balance card).
Our dashboard today: 9 pages, live API wiring done for Catalog/CRM/Quotes/Operations/KPIs; shell lacks command palette, notifications, role switcher (Phase 3 scope).

## 8. Builder map (the “Elementor-style with UI kit” evidence)
`frontend-v1/builder/`: **blocks.js 148 LOC** (`WX_BLOCKS = {id,name,icon,html}` — section library using **v1.css classes only**), uikit.js 283, builder.js 1,775, templates-v26.js 353 → **whole editor ≈ 2.6k LOC vanilla** and it serves their real site edits (P15–P19 iterations). Lessons: HTML-snippet blocks bounded to a design-system class allowlist make validation trivial; global header/footer editing; auto thumbnails; JSON import/export; no-delete/no-rename rule; builder inside admin with SSO.
Implication for us: a typed-block builder is feasible in ~1–2k LOC (our Phase 8 risk drops); GrapesJS/Puck remain optionality, not necessity.

## 9. Reusable components/services (safe: patterns + licenses)
AdminLTE (MIT) — skip, we have a design system. Preline v5 plugins (MIT+FairUse) — patterns only per §25 gate; templates/dashboards/cms-admin as IA reference. GrapesJS (BSD-3) comparator. Puck (MIT, daily commits) React-mode candidate. Krayin Laravel-CRM + trycompai/crm (MIT) — CRM schema reading lists. Instatic/Builder.io (MIT) — watch.

## 10. Reusable services (ours, already built)
`apps/api` REST (products/overrides polling bridge, quotes/leads/orders CRUD + PATCH transitions, stats rollup), dashboard `lib/api.ts` hooks (useApi/mutate/fmtPKR/timeAgo), storefront runtime sync + typed submit flows, DataTable/Modal/Toast/KPI components, esbuild data-extractor seeding. All generic enough to rename into `packages/*` in Phase 2 without rewriting logic.

## 11. Authentication
Ours: mock sign-in only (per approved demo policy). Interiors: shared-password gate + hashed passwords + session tokens in v2 plan (A1) — same direction as our Phase 2. Supabase Auth deferred per §03 gate. No MFA yet anywhere; planned Phase 2.

## 12. Integrations
Observed: Turnstile (forms), Hostinger SMTP, WhatsApp Cloud API (theirs), Google (admin-google.js), Netlify/Vercel deploy configs. Our target abstraction: `providers/` adapters (WhatsApp Meta-Cloud + 3P, email, storage) behind server-side secrets — PRD §16; none wired yet (Phase 10).

## 13. AI/MCP
Theirs: `mcp-server/server.mjs` (+ duplicate in wf-admin), Train-AI page, live-chat AI with fallback auto-reply (P19 B1). Us: AI Draft button on quotation builder (local, prompt-based) — safe. Plan: `AI Agent → MCP gateway → Permission engine → tools` per §17; theirs is a pattern reference; **their MCP file contains secrets → never copy.**

## 14. Technical debt (their repo, to avoid in ours)
Committed ZIPs; duplicated admin trees; 230 hardcoded WhatsApp numbers (their own finding); date-field confusion (created vs activity); cache-busting missing on media replace; admin vendor dir checked in. Ours today: Express→NestJS pending (Phase 2), no auth, no tests beyond manual curl — acknowledged, scheduled.

## 15. Security issues
🔴 Interiors public repo: key-shaped secrets in 8 files (`netlify/functions/_supabase.mjs`, mcp-server files, MASTER-PLAN docs) — **owner must rotate**; we never displayed values. 🔴 woodex420/woodex credential exposure (prior finding) — rotate + audit. 🟠 P19: admin password posted in chat — change after go-live. Ours: none found; add pre-commit secret scan in Phase 2; §23 “never expose secrets” enforced from here on (scan runs every commit).

## 16. Licensing risks
Interiors repo: **NO LICENSE** → patterns/data-model only (locked in master plan §1). Preline: dual MIT+Fair-Use → no Pro assets, wrappers + NOTICE, clearance question open. TailAdmin: patterns only (§32 — shipped compliance). GrapesJS BSD-3, Puck MIT, Craft MIT, Vvveb Apache-2 — all safe if adopted as deps, none copied. GPL/AGPL OSS CRMs (ERPNext, idurar, Dolibarr, Gauzy, h5-Dooring, luban): reference-only, excluded as deps. Twenty/NocoBase NOASSERTION → excluded from code reuse, UX study allowed.

## 17. Migration opportunities
Keep/refactor/replace/deprecate per §20: KEEP apps/api shape+data (→Postgres in P2), KEEP dashboard components, REFACTOR apps→packages layout, RE-USE (ideas) Interiors: publish/version/redirect model, P19 QA list, settings-tabs IA. DEPRECATE: none of our live code. Supabase cutover = driver swap after preflight (§27 of v2.0 doc). Data imports (products CSV, MW leads) only with written permission from Interiors owner — separate project, not this repo.

## 18. Missing capabilities (gap register → phases)
P2: auth/RBAC/tenancy/audit-log/CI+tests · P3: command palette, notifications, role simulation, Vireo-KPI home · P4: contacts/companies/opportunities/tasks/scoring/dedupe(“single client record”) · P5: invoices/payments/quote-statuses(Sent/Viewed/Negotiation/Expired)+PDF+balance/receivables · P6: cart/coupons/refunds/reviews/wishlist/abandoned-cart (storefront has cart; rest open) · P7: themes/SEO/redirects/media-mgmt/nav · P8: builder canvas+DnD+uikit+reusable sections · P9: campaigns/calendar/UTM/social · P10: unified inbox/chat widget/WhatsApp adapters · P11: automation engine · P12: AI governance+MCP · P13: production orders/QC/dispatch/delivery sign-off/BOQ-style materials schedule · P14: backups/observability/a11y sweep.

## 19. Target architecture recommendation (for approval)
Keep the working monorepo; grow it into the PRD layout without blind rewrite:
`apps/{dashboard,storefront} (Vite today → Next.js web only when CMS needs SSR) · apps/api → NestJS · packages/{design-system→ui, builder-core, database, auth, crm, ecommerce, theme-engine, ai, integrations, shared}`. Data: **PostgreSQL 16 (+RLS, LISTEN/NOTIFY) · Redis · S3-compat storage**, Docker Compose local → VPS/cloud; static-HTML export for marketing SEO (Interiors lesson). Builder: original **typed-block + class-allowlist editor** (their 2.6k-LOC proof de-risks custom), GrapesJS/Puck bounded comparators. UI kit: our design system + Preline-pattern wrappers (license gate honored). Realtime: SSE now, NOTIFY in P2. Supabase = optional host once ref+anon key+preflight JSON arrive.

## 20. Roadmap recommendation
Adopt **PRD §22 phases verbatim** as the official roadmap (supersedes my interim numbering in plan v3 §9): status after this audit — P0 ✅ complete (this doc); P1 = architecture approval (recommend §19); P2–P3 partially prepaid by existing monorepo (foundation exists, design system shipped; auth/RBAC/packages missing); **recommend next build order after approval: P2→P3 polish→P4→P5** (money loop first, matching the live-data strength), builder P7–P8 as the flagship after CRM/sales stabilize, P13 Business Pack extends P5/P8 naturally. Each phase ends with the §26 acceptance standard + A–G phase structure in its doc.

---
**END OF PHASE 0.** Per §27, awaiting: *“Which recommendation would you like to approve before I begin Phase 1?”*
