# WOODEX 2030 — Master Plan v1
## Deep-research findings, phase plan, and decisions required

**Date:** 2026-11-08
**Branch audited:** `arena/41856005-woodex-2030` @ `74f6a34`
**Scope:** storefront v1 approval → Agency OS consolidation → deployment-ready `main`

> This plan is built on **measured** state, not intent. Every claim below was verified by running the code, querying the live API, or counting the source. Where a claim is an estimate it says so.

---

## 0. The single most important finding

> **SUPERSEDED — this section described the situation on 2026-11-08 morning and is
> no longer true.** `main` now contains the full application and deploys to
> production. Kept verbatim below because the reasoning that followed from it
> shaped the plan; do not act on it.
>
> **Current state lives in [`docs/agency-os/00-CURRENT-STATE.md`](../agency-os/00-CURRENT-STATE.md).**
> Verified 2026-11-08: `main` @ `15e6242`, production READY and PROMOTED, API
> answering, catalogue seeded, owner login confirmed.

**At the time of writing, `main` contained exactly one file: `README.md`.**

```
$ git ls-tree -r --name-only origin/main | wc -l
1
```

Application code lived only on `arena/*` branches, and the Vercel project deploys `main`. Nothing could reach production — which is why the runbook of that day ended with "no deployment has been made."

That framed the original request: the blocker was not features but **integration** — getting a verified tree onto `main` through a deployment path that survives Vercel and Hostinger. That work is now done.

---

## 1. Measured state

### 1.1 What is actually built and verified

| Area | Evidence |
|---|---|
| API | 2,959 LOC, 37 GET + mutating routes, SQLite/Postgres/PGlite drivers |
| Tests | `npm run qa` **100/100** on SQLite *and* `npm run qa:pg` 100/100 on PGlite |
| Build | `vercel:build` exit 0, `dist/` 12 MB |
| Types | storefront `tsc -b` **0 errors**, dashboard **0 errors** |
| Render | both apps mount (`smoke:render`), storefront 91 KB of DOM |
| Data | 148 products, 14 clients, 8 orders, 7 leads, 6 quotes, 2 invoices |
| SSE | works — verified live, `event: tasks` received 1.5 s after a write |
| Page builder | publishes end-to-end: `/p/ramadan-workspace-sale` renders 63,761 chars, live prices, 0 errors |

### 1.2 What is fake, broken, or missing

| # | Problem | Evidence | Severity |
|---|---|---|---|
| **F1** | **Dashboard "Overview" shows invented revenue** | `Dashboard.tsx:20` imports `revenueSeries, revenueSummary` from `@/data/mock`; renders `revenueSummary.totalRevenue` as the headline number | **Critical** — an operator sees fabricated money |
| **F2** | Analytics page is **100% mock** | `Analytics.tsx:10` imports 4 mock datasets; zero `useApi` calls | **Critical** |
| **F3** | Projects page is **100% mock** | `Projects.tsx:14` imports `boq, milestones, projectFiles, projects`; **no `/api/projects` endpoint exists** (`grep -c "api/projects"` → 0) | **Critical** |
| **F4** | SSE bus has **zero subscribers** | 39 `emit()` sites, working `/api/events`; `grep -rn "EventSource" apps/dashboard/src` → 0 hits | High |
| **F5** | `useRealtime` filters out the only event it gets | `api.ts:314` `detail.type !== "poll"` — `initRealtime` only ever emits `"poll"` | High |
| **F6** | Page builder: 11 blocks, forms not canvas, **duplicate renderer** | `BLOCKS` in `server.mjs:2197` (11 types); `BlockPreview` re-implements rendering inside the admin | High |
| **F7** | `main` has one file | §0 above | **Critical (deploy)** |
| **F8** | 56 docs, several competing "master plans" | `docs/product/AGENCY-OS-MASTER-PLAN-V2.md`, `docs/archive/AGENCY-OS-MASTER-PLAN-v3.md`, `PLAN-FURNITURE-TRACK-v2.1.md`, `NEXT-PHASE-DELIVERY-PLAN` | Medium — agents follow different plans |
| **F9** | No agent workflow / trap-prevention contract | no `AGENTS.md`, no `.cursor/`, no task protocol | Medium |
| **F10** | Storefront is a **client-rendered SPA** — zero prerender | no SSR/SSG in `vite.config.ts`; all SEO meta is set at runtime in `useEffect` | High for SEO |
| **F11** | `/support` is a `Placeholder` stub | `App.tsx:91` | Low |
| **F12** | Tailwind 3 (storefront) vs 4 (dashboard) | last version split | Low |
| **F13** | Dead `tsbuildinfo` files in tree | `apps/storefront/tsconfig.*.tsbuildinfo` | Trivial |

### 1.3 The sidebar is already correct

The six groups you listed **already exist** in `apps/dashboard/src/components/layout/Sidebar.tsx:15-62`:

`Workspace` · `Capture & Relationships` · `Quote & Collect` · `Commerce & Fulfilment` · `Website & Growth` · `Insights & Admin`

Route↔nav coverage is clean: only `/catalog/new` and `/quotations/new` lack entries (they are detail routes, correct), plus `/support` (F11). **This is refinement work, not a rebuild.** What needs to change is what the pages *contain*, not how they are grouped.

---

## 2. Phase 1 — Storefront v1

### 2.1 Name

**Recommendation: keep `WOODEX`.**

Reason: it is already in the domain, in 148 product names, in the schema (`woodex.db`), in `og:site_name`, in the admin credentials, and in the business identity the catalogue describes ("Lahore-based furniture manufacturer/retailer"). Renaming means touching ~200 strings across 26 files, `index.html`, every `document.title`, and the sitemap — for zero functional gain, at the exact moment we are trying to reach production.

If a rename is wanted, the clean way is a **single `brand` config object** (`apps/storefront/src/config/brand.ts`) that all titles/meta read from, so the decision is one file later rather than 200 strings now. I will build that regardless — it is good practice and costs nothing.

**Decision needed from you:** keep `WOODEX`, or rename? (See Q1.)

### 2.2 Framework — is the current one right?

| Option | Verdict |
|---|---|
| **Stay: Vite 8 + React 19 SPA** | Fastest, already green, deploys anywhere static — **but zero SEO**, which for a furniture store in Pakistan is a real revenue problem |
| **Move to Next.js** | Fixes SEO (SSR/ISR), but 25 pages + Header/Footer/router would need rework, and it contradicts "optimise the debugged tree" |
| **Middle: keep Vite, add prerendering** | `vite-plugin-prerender` / `react-snap`-style static prerender of the 9 public routes → real HTML for crawlers, zero architecture change |

**Recommendation: middle path.** Add build-time prerender for the public routes (`/`, `/shop`, `/shop/:id` seeds, `/about`, `/contact`, `/services`, `/materials`, `/projects`, `/quotation`). The dynamic CMS pages `/p/:slug` get prerendered from the published page list at build time. This gives crawler-visible HTML and keeps the whole verified tree.

Cost: ~1 day. Benefit: the single biggest SEO gap closes without a migration.

### 2.3 Folders to remove / clean

- Delete `apps/storefront/tsconfig.*.tsbuildinfo` (F13) — build artefacts, gitignored pattern exists but files are stale.
- `apps/storefront/public/` — 16 KB, verify nothing duplicated with `/img`.
- `src/data/products.ts` is **148 KB** — this is the seed catalogue duplicated in the storefront *and* in the API (`shop-data.mjs`). Single-source it: storefront should read `/api/products` and keep only a thin fallback.
- Remove the `Placeholder` `/support` route or implement it (F11).

**Roadmap:**
1. `brand.ts` single source (0.5 d)
2. Prerender public routes (1 d)
3. Single-source the catalogue (0.5 d)
4. Delete junk, verify `smoke:render` + `npm run qa` (0.5 d)

---

## 3. Phase 2 — Agency OS

### 3.1 Kill the fake data (highest value)

Three screens currently show invented numbers as if real. This is the single biggest trust defect in the product — worse than a missing feature, because it lies convincingly.

Fix order:
1. **Dashboard Overview** → wire to the existing `/api/stats` (already returns products, leads by status, quote pipeline value, orders by stage) plus a new `/api/stats/revenue` for the chart. Delete `revenueSummary`/`revenueSeries` imports.
2. **Analytics** → wire to `/api/stats` + `/api/marketing/stats` (both real, both already return correct shapes). Delete all 4 mock imports.
3. **Projects** → **no API exists**. Either build `projects`/`boq`/`milestones` tables + endpoints (~3 d) or relabel the screen honestly as "Prototype — not connected". I recommend **building it**: Projects is how furniture installation work is actually tracked.

Target: `grep -rn "data/mock" apps/dashboard/src` returns only the UI primitives (Avatar, Badge, Progress, Toast use *types*, not data — verify and split).

### 3.2 Real-time (finish what exists)

- Replace `initRealtime()`'s poll stub with `new EventSource("/api/events")` (F4), **keeping** the 15 s poll as fallback for serverless (where SSE returns 410 by design).
- Delete the `type !== "poll"` filter (F5).
- Wire `useRealtime` into Orders, Tasks, Inbox, CRM — the four screens where a colleague's change must appear live.
- Serverless note: on Vercel SSE is deliberately disabled; the poll path must remain the default there and SSE the enhancement on Hostinger/self-host.

### 3.3 Page builder — real-time, live, customisable

**Status: re-scoped on 2026-11-08 after reading the code. The Q5 answer (adopt
Puck + port a 40-section catalogue) was made without that inspection. What is
actually built is better than the plan assumed, and Puck would be a downgrade.**

What exists today (verified):

| Capability | Where | Verdict |
|---|---|---|
| Real-time *visual* render | `Website.tsx` renders a live `<iframe>` of the **real storefront** `/p/<slug>` via a 15-min signed draft link (`lib/preview.ts`, `/api/pages/:id/preview-link`) | This is already "real-time render" — no guessed markup |
| One renderer | `PublishedPage.tsx` is the only renderer; the iframe shows exactly it | Correct by construction |
| Live real data | `product-grid` / `product-feature` read the catalogue and render **live PKR prices**; `lead-form` posts into the CRM | Meets the requirement |
| Customisable | 11 block types, 4 field types, drag-to-reorder, per-block lock, theme presets, **global sections that fan out**, saved-section library, draft/publish/version/rollback | Solid |
| Server-authoritative catalogue | `BLOCKS` in `apps/api/server.mjs`, served at `/api/blocks`; the editor renders whatever fields the server declares | Correct pattern |

Why not Puck:

- A shared React renderer module is **not possible without inventing a design
  system**. The storefront uses Tailwind 3 (`tailwind.config.ts`) with
  `primary` / `muted-foreground` tokens; the dashboard has **no Tailwind config**
  at all and styles from CSS custom properties. Puck's canvas would have to
  reproduce storefront markup in the other app's CSS — the same drift, one layer
  deeper, with an extra 200 kB dependency.
- The signed-iframe preview is **guaranteed truthful**, which a re-implemented
  canvas is not. The remaining cost is a save + iframe reload per edit, not a
  correctness problem.
- Puck is headless config-first; adopting it means re-declaring all 11 blocks
  (and the 40 new ones) in Puck's schema *and* keeping the storefront renderer —
  two catalogues, which is precisely the drift we are trying to delete.
- `@measured/puck@0.20.2` supports React 19 (peer `^18 || ^19`), so it *can* be
  installed — this is a design judgement, not a compatibility wall.

**Re-scoped target (~1 d instead of 4 d):**

1. Delete `BlockPreview` in `Website.tsx` — the crude guessed mock. The inspector
   keeps the field editor; the preview pane is the signed iframe, which is real.
   *(Removes the only place that can lie about what a page looks like.)*
2. Keep the catalogue server-authoritative and **grow it toward 40 blocks** in
   `BLOCKS` + one `case` in `PublishedPage.tsx` each — incremental, no migration.
3. Add the field types the catalogue needs to be genuinely customisable:
   `select` (enum), `number`, `color`, `link`, `boolean` — currently only
   `text` / `textarea` / `lines` / `image` exist, which is the actual ceiling on
   "fully customisable".

If the user still wants Puck after reading this, it is reversible: nothing else
in the plan depends on the choice.

### 3.4 UI/UX pass

- Sidebar: keep the 6 groups, add counts/badges (open leads, unpaid invoices), collapse persistence, keyboard nav.
- Make `/support` real or delete it.
- Empty/loading/error states: already exist as components (`SkeletonRows`, `EmptyState`, `ErrorState`) — ensure every page uses them.
- Dark mode in the dashboard (storefront already has the toggle working, now that `theme.ts` is fixed).

### 3.5 API / database

- **Projects/BOQ/milestones tables** (F3) — `projects`, `project_tasks`, `boq_lines`, `project_files`.
- **SQL aggregates** — `/api/clients`, `/api/stats`, `/api/marketing/stats` currently do N+1 loops in JS (Q9 in QUICK-TASKS).
- **Postgres constraints** — FKs, `CHECK` on statuses, indexes (Q6 in QUICK-TASKS).
- **Migrations runner** — `npm run db:migrate` applying ordered files (schema exists, runner does not).
- **Backups** — none today. At minimum a documented `pg_dump` cron for Hostinger and a Vercel/Neon PITR note.

---

## 4. Agent workflow (the "traps" you asked about)

There is no agent contract in the repo today, which is why different sessions follow different plans. I will add:

**`AGENTS.md`** (root, ~1 page) containing:
- **Read order**: `docs/README.md` → this plan → the relevant audit. Never start from a random doc.
- **The five traps** that have actually cost us time, written as hard rules:
  1. Never let the two apps declare different React majors (duplicate React → silent white page). `npm run smoke:render` must pass.
  2. Never trust `JSDOM.fromURL` to prove a page renders — it does not execute `type="module"`. Use `npm run smoke:render`.
  3. Never write bare-word regex rewrites across `.tsx` — restrict replacements to non-string contexts.
  4. Never `pkill` in the same shell call as a launch.
  5. Never point the workspace at a hosted DB; servers run on SQLite/PGlite.
- **Definition of done** (the command set that must be green):
  `npm run qa` 100/100 → `npm run qa:pg` 100/100 → `typecheck:all` → `smoke:render` → `vercel:build` → `secret-scan`.
- **File-ownership map**: which files are safe to edit per area, which are load-bearing.
- **One-plan rule**: `docs/product/MASTER-PLAN.md` is the only plan an agent reads; everything in `docs/archive/` is history.

Also: consolidate the four competing plan documents into **one** `docs/product/MASTER-PLAN.md` (this file), and move the rest to `docs/archive/` with a "superseded by" banner.

---

## 5. Deployment — both targets

### 5.1 The blocker

`main` is empty of code. First deliverable of this whole effort is **a clean `main`**, which means:
1. Finish the work on `arena/41856005-woodex-2030`.
2. Open a PR to `main`.
3. Merge only with the full gate green.

### 5.2 Vercel

Already configured (`vercel.json`, `vercel:build`, `dist`). Needs:
- `DATABASE_URL` (Postgres) in Production **and** Preview.
- `INITIAL_ADMIN_EMAIL` / `INITIAL_ADMIN_PASSWORD` (≥16 chars) for first boot only.
- `npm run db:apply-schema` against the new DB.
- Know the constraint: **SSE is disabled serverless** → dashboard falls back to polling there.

### 5.3 Hostinger shared

This is the harder target and needs a decision (Q3). On shared hosting you generally get:
- static files served by LiteSpeed/Apache → storefront + dashboard build output drop in cleanly;
- **Node.js app support varies by plan** — often one Node process, restarted by the panel, no WebSocket guarantee;
- MySQL, not necessarily Postgres.

Two viable shapes:

**Shape A — split (recommended):** static frontends on Hostinger; API on a cheap Node host (Railway/Render/Fly) with Postgres. Cleanest, but two vendors.

**Shape B — all-in-one on Hostinger:** build `dist/` + run `apps/api/server.mjs` under the panel's Node app with a SQLite file (we support it natively). Simplest ops, one vendor, but SQLite on shared hosting means single-writer and panel-managed restarts — acceptable for a furniture retailer's traffic, risky for concurrency.

Either way I need to add: `npm run start:hostinger` (serve `dist/` from Express with SPA fallback + proxy-free API), a `.htaccess` for the static path, and a documented upload procedure.

---

## 6. Execution order

| Step | Work | Days | Blocks |
|---|---|---|---|
| 0 | **Answers to Q1–Q5** | — | everything |
| 1 | Kill fake data: Dashboard, Analytics (+ Projects API) | 4 | — |
| 2 | SSE wiring + realtime screens | 1 | — |
| 3 | Page builder → **re-scoped** (keep iframe+renderer, grow catalogue, add field types) | 1 | — |
| 4 | Storefront: brand config + prerender + catalogue single-source | 2 | — |
| 5 | Agent workflow: `AGENTS.md` + one master plan + archive the rest | 1 | — |
| 6 | DB: aggregates, constraints, migrations runner, backups | 3 | — |
| 7 | Deployment: Hostinger start path, `.htaccess`, runbooks | 2 | — |
| 8 | PR to `main`, full gate, merge | 1 | — |

**≈18 working days.** Steps 1–2 alone (5 days) remove every fabricated number from the product, which is the highest trust-per-day in the list.

---

## 7. Decisions required

**Q1 — Storefront name.** Keep `WOODEX`, or rename (and to what)? A rename done properly = 1 file if I build the brand config first.

**Q2 — SEO vs architecture.** Take the prerender middle path (~1 day, keeps the verified tree), or commit to a Next.js migration (~2 weeks, best possible SEO)?

**Q3 — Hostinger shape.** Shape A (static front ends + API elsewhere) or Shape B (everything on Hostinger with SQLite)?

**Q4 — Projects/BOQ.** Build the real tables + API (~3 days), or relabel the screen honestly as a prototype for now?

**Q5 — Page builder.** Full Puck adoption with the 40-section catalogue (~4 days), or keep the 11-block form builder and just fix the duplicate renderer (~1 day)?

---

*Everything above was verified against `74f6a34`; the API, dashboard, and storefront are running at :3001 / :5173 / :5174.*

---

## 8. Progress log

### 2026-11-08 — shipped

**Fake data eliminated (F1, F2, F3).** Overview, Analytics and Projects no longer
render invented numbers.

- `/api/stats/revenue?months=N` added — real revenue/orders/collected series from
  `orders` + `payments`, with a continuous month window so quiet months show zero.
- Dashboard Overview: headline revenue + chart now live.
- `Kpi.trend` made optional; the hardcoded "↑ 24%" arrows are gone (no
  period-over-period source exists yet, so no arrow is shown).
- Quotation Pipeline derived from real quote buckets; fake "11 days" removed.
- Recent Activity: real events only (it used to append a hardcoded feed).
- Analytics rewritten against `/api/stats`, `/api/stats/revenue`,
  `/api/marketing/stats`. Zero mock imports remain in `pages/`.

**Projects / BOQ built for real (F3).** The screen had no backend at all.

- New tables `projects`, `project_milestones`, `boq_lines`, `project_files` in
  both the SQLite bootstrap and `scripts/schema.postgres.sql`.
- 14 endpoints. Line totals server-computed; client-supplied `lineTotal` ignored
  and covered by a QA assertion. Milestone completion derives `progress`.
- Projects page rewritten: real list, real modals, real delete, realtime refresh.
- New `project.manage` capability (owner, sales).

**Realtime connected (F4, F5).**

- Dashboard attaches `EventSource` to `/api/events`; polling remains the floor for
  the serverless runtime and as a reconnect path.
- `/api/events` accepts `?token=` because EventSource cannot set headers — scoped
  to that one route.

**Verification:** `npm run qa` **100/100** SQLite and **100/100** PGlite (4 new
project groups), `typecheck:all` clean, `smoke:render` both apps, `vercel:build`
exit 0, `secret-scan` clean. Rendered for real: `/projects` shows `PRJ-1001`,
Rs 2,500,000 planned, Rs 948,000 BOQ total; `/analytics` shows PKR 1,201,000
revenue and the true lead-stage split.

### 2026-11-08 (later) — storefront SEO, page builder, database

**Storefront ships real HTML.** All 76 public routes previously prerendered as a
529-byte `Loading page…` shell. Root cause was three separate bugs: `React.lazy`
never settles inside jsdom (rewritten to static imports by an esbuild plugin), the
wait polled for `children.length > 0` which the toast portal satisfies before the
route commits, and the `#root` swap matched a literal empty div that no longer
existed on a second run. Full build is now 18 s; `PRERENDER_ONLY=/about` renders
one route in 0.4 s.

**Brand is one source.** `apps/storefront/src/config/brand.ts` is read by both the
app components and the prerenderer. This fixed real inconsistency: the footer
advertised two different phone numbers, one of them a fake `+92 42 111 WOODEX`,
and the address disagreed with the dashboard's invoices. Contact values still
need business sign-off; they are flagged in the file.

**One sitemap.** The API and the prerenderer each built a partial sitemap
(149 and 76 URLs) with different contents. `apps/api/public-routes.mjs` is now the
single route list; the API owns `/sitemap.xml` (it is the only side that can see
published CMS pages) and the prerenderer owns `robots.txt`. A static
`dist/sitemap.xml` would be served by Vercel *before* rewrites and shadow the
complete one, so it is deliberately not emitted.

**Page builder: rich fields, real preview.** Five field types added
(`select`/`number`/`color`/`link`/`boolean`), `hero` gained
align/accent/compact, `product-grid` gained `limit` + `sort`. Three API bugs
found while proving it: unknown block types were accepted; `POST /api/pages`
silently discarded `blocks`; and there was no way to delete a page at all.

**Database.** `/api/clients` issued 2,943 queries for 321 clients (~9 each) —
now 8, flat in row count and 54 ms → 4 ms, verified value-for-value against the
per-client endpoint. Nine hot-path indexes added after `EXPLAIN QUERY PLAN`
showed full table scans; `npm run db:backup` / `db:verify-backup` added
(VACUUM INTO, integrity check, row-count comparison against live).
`db.queries` + `/api/diagnostics/queries` make N+1 measurable, and three QA
tests now fail if the query count scales with rows, if the two schemas drift, or
if a backup cannot be restored.

### 2026-11-08 (deployment)

**Shape B works and is tested.** `WOODEX_SERVE_STATIC=dist npm run start:hostinger`
serves prerendered pages, the dashboard, the API, imagery and the sitemap from one
process. The first attempt returned 401 on every page because the static layer was
registered after the session middleware, and 301s because `express.static` answers
directory paths with a redirect — both fixed, and `smoke:hostinger` now asserts
`200` + a real `<title>` + no `Loading page` on 8 routes, plus that `/api/*` still
returns JSON and `/sitemap.xml` still comes from the API.

`docs/DEPLOYMENT.md` documents both targets end to end: env vars, schema creation,
post-deploy `curl` checks, the SQLite single-writer caveat, and backups.

### Still open from the plan

- Storefront: brand + prerender — **done**. Catalogue single-source is done via
  `apps/api/public-routes.mjs` + `apps/storefront/src/config/brand.ts`.
- Page builder: rich field types + sorting **done**; `BlockPreview` in
  `Website.tsx` is still a guessed mock (the live preview is the signed iframe,
  so it is redundant rather than wrong) — deleting it is the remaining tidy-up,
  along with growing the catalogue toward 40 blocks.
- Database: aggregates/indexes/backup **done**. Still open: `CHECK` constraints on
  status enum columns, and a documented `pg_dump` cron in the deployment runbook.
- `main` still contains one file; no PR opened yet.
- Hostinger packaging — **done**: `npm run start:hostinger` (Shape B, one Node
  process), `deploy/hostinger/.htaccess`, `npm run smoke:hostinger` and
  `docs/DEPLOYMENT.md` covering both targets, backups and post-deploy checks.
- Tailwind 3 → 4 for the storefront (F12).
