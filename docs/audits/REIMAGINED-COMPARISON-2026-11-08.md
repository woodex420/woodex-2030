# WOODEX — Reimagined Storefront vs. Current Platform
## Verification & Comparison Report

**Date:** 2026-11-08
**Repo examined:** `github.com/blackibexofficial-blip/woodex-reimagined` (main, `b2eb949`, 2026-11-08 11:18 +0500)
**Compared against:** `woodex420/woodex-2030` @ `1d4d5a2` (branch `arena/41856005-woodex-2030`)
**Method:** cloned, installed (623 pkgs), typechecked, linted, built, rendered in jsdom, and ran the repo's own test suites.

---

## 1. Verdict

**Yes — it is a real, working storefront. It is not a mock or a screenshot.**

It is also not a *replacement* for this platform. The two are different products that share a name and an ancestry.

| | Reimagined | This platform |
|---|---|---|
| What it is | Storefront-first CMS + commerce demo | Operations platform + storefront |
| Where data lives | Browser `localStorage` | SQLite/Postgres server + REST API |
| Readiness | Impressive prototype, not deployed | Running, tested, deployable |
| Code | 56,685 LOC, 331 files | 31,120 LOC |

**The one-line answer:** Reimagined is the better *website builder and content experience*. This platform is the better *business system*. Neither can absorb the other without real work, and the report below shows exactly which parts are worth taking.

### Proven, not assumed

| Check | Result |
|---|---|
| `npm install --legacy-peer-deps` | exit 0, 623 packages |
| `tsc --noEmit -p tsconfig.app.json` | **exit 0**, zero errors |
| `eslint .` | **0 errors**, 33 warnings (all fast-refresh hints) |
| `vite build` | **exit 0**, 26.8 s, `dist/` = 12 MB |
| jsdom render of real `main.tsx` | **3 root children, 2,594,004 chars, 0 errors** |
| Repo's own E2E suite | **59/59 checks passed** |
| Repo's own axe a11y scan | **0 violations across 47 routes** |

That last pair is the important one. Their suite tests things this platform does not test at all: JSON-LD schema shape, canonical URLs, breadcrumbs, the page builder inserting blocks and rendering `/lab/home`, kanban drag, BOQ recalculation. It passes honestly.

---

## 2. Provenance — what this repo actually is

Worth stating plainly because it changes how the code should be read:

1. **It is our code, descended.** Its `package.json` dependencies are near-identical to our storefront's (same Radix set, same shadcn/ui, same `tailwindcss-animate`, same `vaul`, same `next-themes`, same `input-otp`). It carries `components.json` — the shadcn generator file we deleted in Phase 2, and it still has `.lovable/`, `LOVABLE` traces, and `@lovable.dev/mcp-js`.
2. **It is one squashed snapshot.** `git log` contains exactly **one** commit (a merge of PR #21). There is no development history to review — 22 `arena/*` branches exist upstream, so real history was flattened before publishing.
3. **Our Phase-2 deletion was correct.** In Phase 2 we deleted `apps/woodex-reimagined-preview` (12 MB) as a dead fork. This repo is that same lineage, but it has since grown ~6×: 40-section page builder with Puck, Drizzle schema, Supabase edge functions, 47-route admin. It was dead *in our tree*; it was not dead *as a project*. Both statements are true.
4. **It renders the same storefront.** Its `/` output opens with `Quote Basket / Showrooms / Material and Colors / WOODEX / Make your space work` — the same chrome and routes as ours.

**Branches:** `main` plus 21 `arena/*` branches. No deploy workflow, no `vercel.app` URL, no release. It has never been published anywhere.

---

## 3. The headline gap — page builder

This is the part worth acting on.

### Reimagined
- **40 section blocks** across 10 categories + **17 elements**, asserted at import time (`if (SECTION_COUNT !== 40) throw`)
- **Puck** editor (`@puckeditor/core` 0.24) — a real drag-and-drop page builder, not a form
- Inspector with per-block styling, linked styles (`hm-*` tokens), CSS cascade islands
- Layer controls: hide on storefront / editing lock, enforced by Puck permissions
- Rich text, thumbnails, insert library, undo
- **Live preview at `/lab/:slug`** — renders any page, published *or draft*, `noindex`, `Puck <Render>` against the *same* config the editor uses
- 2,490 LOC in `src/pages-builder/` alone

### This platform
- **11 block types** (`hero`, `text-section`, `product-grid`, `product-feature`, `materials`, `gallery`, `testimonials`, `faq`, `cta-band`, `global-section`, `lead-form`) — defined server-side in `BLOCKS` in `apps/api/server.mjs:2197`
- Block **forms** in a 584-line `Website.tsx` — no drag-and-drop, no layers, no inline styling
- Preview = an `<iframe>` at `/p/:slug` (works, is signed for drafts, but re-renders a whole page per save)
- `BlockPreview` is a **mini renderer** — a second, simplified implementation of the storefront renderer, so the admin preview and the real page can drift apart

### What I verified actually works on our side

Because the honest answer matters more than the comparison table, I tested our own loop end-to-end rather than asserting it:

- `/api/pages` → 1 page (`ramadan-workspace-sale`, Published, 8 blocks)
- `/api/pages/ramadan-workspace-sale/public` → **200**, 8 blocks with a live `global-section` fan-out
- `/p/ramadan-workspace-sale` on the storefront → **renders, 63,761 chars, `Ramadan` content present, live `Rs` prices present, 0 errors**

So our builder is real and it does publish. It is just narrow (11 blocks), clunky (forms not canvas), and it duplicates the renderer.

> **Investigation note, recorded so nobody repeats it:** an earlier probe made our page look broken ("Nothing published here / Tracking service unreachable"). That was **my harness**, not the app. Node's native `fetch` rejects relative URLs, and my first shim recursed infinitely. Re-run with a correct shim, the page renders perfectly. Do not "fix" `PublishedPage.tsx` on the strength of a jsdom probe alone.

**Verdict:** adopt their **section catalogue + Puck** as the block model. Do not adopt their renderer wholesale — we would inherit 40 blocks of `src/assets` coupling and lose the server-side block registry that our API currently owns.

---

## 4. Real-time — the biggest structural difference

The word "real time" needs splitting into three different things. They are not the same, and we only have one of them.

### (a) Server → client event push

**This platform:** genuinely built, genuinely live, and **not connected**.

- `apps/api/server.mjs:1525` defines an SSE bus: `sseClients` Set + `emit()` + `GET /api/events`
- **39 `emit()` call sites** across the API
- I tested it live: opened `GET /api/events` with a token, created a task → received `event: tasks` **1.5 s later**, correctly formed

```
event: tasks
data: {"type":"tasks","at":"2026-11-08T06:59:46.559Z","title":"Task added: SSE probe B","who":"You"}
```

- **And the dashboard never opens it.** `grep -rn "EventSource" apps/dashboard/src` → **zero hits**
- `initRealtime()` (`apps/dashboard/src/lib/api.ts:298`) just starts a `setInterval` that fires an event of type `"poll"` — which `useRealtime` then *filters out* (`detail.type !== "poll"`). **The bus dispatches nothing, and nothing listens.**

**Reimagined:** no real-time at all. `VITE_API_MODE=demo` means `localStorage`. Two browser tabs do not see each other's changes without a manual reset. Its `/admin` is single-user by construction.

**Verdict:** this is our win, and it is one line of wiring away from being visible. The backend is done; only the client is missing.

### (b) Live data without rebuild

| | Reimagined | This platform |
|---|---|---|
| Product price/stock | seeded into `localStorage` | `GET /api/products/overrides`, polled **60 s** |
| Theme | static config | `GET /api/theme`, polled **60 s** |
| Storefront reactions | none | `woodex:runtime` CustomEvent → `useRuntimeRevision` → re-render |

Ours is real (verified: the storefront's `syncCatalog()` mutates the in-memory `products` array and dispatches `woodex:runtime`). Theirs is not. **Ours wins.**

### (c) Live preview in the editor

**Theirs wins decisively.** `/lab/:slug` renders draft and published pages through the same Puck config as the canvas, so *what you see is what the component tree is*.

Ours re-renders a whole `iframe` per save and maintains a separate `BlockPreview` mini-renderer that can drift.

---

## 5. Front-end breakdown

| Dimension | Reimagined | This platform | Winner |
|---|---|---|---|
| Storefront routes | 25 | 25 (near-identical) | tie |
| Admin routes | **47** | 25 | **theirs** |
| React | **18.3.1** | **19.3.0** | **ours** |
| Vite | 5.4 | **8.3** | **ours** |
| Tailwind | 3.4 | 3.4 (store) / **4.3** (dash) | mixed |
| Typecheck | clean | clean | tie |
| Bundle (largest chunk) | 771 KB + 706 KB admin + 690 KB builder | 330 KB | **ours** |
| `dist/` size | 12 MB | 12 MB | tie |
| Images | 93 `.webp` (77 unique files) bundled in `src/assets` | 92 `.jpg`, one copy at `/img` | **ours** |
| Charts | Recharts, 422 KB chunk | Recharts | tie |
| Design system | shadcn/ui + `components.json` | hand-built components | theirs (more standard) |

### Where their front end is genuinely better
- **Six additional commerce surfaces we have no equivalent for:** `series` (product families), `showrooms`, `virtual-showroom`, `room-packages`, `warranty`, `custom-design`, `b2b`, `blog`. We have the routes; theirs have more depth behind them.
- **Admin breadth:** `bulk-editor`, `price-lists`, `sales-channels`, `purchase-orders`, `transfers`, `suppliers`, `warehouse` inventory, `exchanges`/`returns`/`claims`, `bundles`, `collections`, `brands`, `options`, `media`, `drafts`, `client-merge`, `invoice-document`. We have 25 pages against their 47.
- **SEO engine:** `src/lib/seo/analyse.ts` + verified JSON-LD (`Organization`, `WebSite` + `SearchAction`, `FurnitureStore`/LocalBusiness, `Product` with PKR offer + `aggregateRating`, `BreadcrumbList`, `FAQPage`, `BlogPosting`). Their E2E asserts all of it. **We have no equivalent SEO test coverage.**

### Where our front end is genuinely better
- **React 19 / Vite 8.** They are on 18.3.1 / Vite 5.4 — the exact combination that caused our white screen. If they ever hoist a React 19 dependency, they get the same blank page.
- **Smaller initial payload.** Their `index` chunk is 771 KB (218 KB gzip) before admin or builder loads.
- **Real API instead of `localStorage`.**

---

## 6. Data layer breakdown

This is the deepest difference and the reason the two cannot simply merge.

### Reimagined — three interchangeable adapters
```
src/api/
├── demo/db.ts      5,425 LOC  localStorage, 210 seeded records, 28 SKUs
├── bagisto/admin.ts          REST adapter → Bagisto (Laravel)
└── supabase.ts     1,685 LOC  RPC surface
```
- `VITE_API_MODE` selects `demo | static | bagisto | supabase`
- `src/config/env.ts` centralises **every** env read — nothing else touches `import.meta.env`
- `drizzle/schema.ts` + `drizzle/migrations/` exist (a real schema, unused in demo mode)
- `backend/supabase/` — 89 files: tables, migrations, 5 edge functions (`admin-bootstrap`, `lead-capture`, `order-submit`, `order-tracking`, `mcp`)
- `supabase/seed.sql` + 64 `.sql` files total

### This platform — one real backend
- `apps/api/server.mjs` — 2,959 LOC, SQLite **and** Postgres via `db-adapters.mjs`
- Verified live counts: **148 products**, 14 clients, 8 orders, 7 leads, 8 tasks, 2 invoices, 1 page
- 96/96 QA on SQLite *and* 96/96 on PGlite
- Auth (sessions, roles, `page.manage`/`theme.manage` capabilities), rate limits, CORS, security headers, transactional writes

### The honest comparison

| | Reimagined | This platform |
|---|---|---|
| Persistence | browser only | server DB |
| Multi-user | no | yes |
| Survives cache clear | **no** | yes |
| Real orders/leads | written to `localStorage` | written to DB, visible in CRM |
| Data volume | 210 records / 28 SKUs | 148 products, real transactions |
| Backend flexibility | 4 adapters | 2 drivers |
| Schema artefacts | Drizzle + 64 SQL + 5 edge fns | `schema.postgres.sql` + adapters |

**The catch, stated plainly:** their "storefront end-to-end loop" — checkout → `/admin/orders`, quotation → `/admin/leads` — works entirely inside one browser's `localStorage`. It is a *demonstration* of the loop, not the loop. A customer placing an order on their `/checkout` would not put an order in front of a staff member on another machine. **Our loop is real; theirs is a simulation.**

Conversely, their adapter pattern (one config switch, three backends) is better *design* than our two hardcoded drivers — and it is the reason they can claim "adapter-ready for Bagisto" credibly.

---

## 7. Security — an actual finding

**The repo commits a `.env` to a public repository, and it is not a placeholder.**

```
VITE_SUPABASE_PROJECT_ID     = ewbflgbshdstfxqretay          (22 chars, real)
VITE_SUPABASE_PUBLISHABLE_KEY= sb_publishable_…              (48 chars, real)
VITE_SUPABASE_URL            = https://c--e1dec3e5-…-prod.lovable.cloud  (68 chars, real)
```

Assessment:
- The key is a **`sb_publishable_`** key — by Supabase design that is client-safe *if* RLS is correct. It is not a `sb_secret_`/service-role key, so this is **not** a "someone can own your database" incident.
- But it exposes the **live project ref and backend endpoint** of a production Supabase instance to anyone who clones. That is free reconnaissance: it tells an attacker exactly which project to probe and which RLS policies to test.
- The host name (`…-prod.lovable.cloud`) is a third-party proxy — a dependency nobody is monitoring.

**Recommendation:** treat the Supabase project as exposed. Rotate the publishable key, move `.env` to ignore, verify RLS on every table before trusting it. Given we are running on SQLite/Postgres and never used Supabase, the cheapest correct move is **revoke/delete that project**.

Also noted: their demo admin credentials (`admin@woodex.pk` / `woodex-admin`) are printed in the README. They document it as a demo gate, not an auth boundary — which is honest, but it means `/admin` on any public deploy is open to anyone who reads the repo.

---

## 8. What I would take, and what I would not

### Take — high value, low risk
1. **The 40-section catalogue as a block inventory.** Port the *list and props* into our server-side `BLOCKS` registry. We go 11 → ~40 blocks without touching our renderer architecture.
2. **Puck as the editing surface.** `@puckeditor/core` is MIT, React-native, and their integration is proven (their E2E drives it). Replaces our form-based `Website.tsx` and kills the duplicate `BlockPreview` renderer.
3. **Their SEO test suite.** JSON-LD/canonical/breadcrumb assertions are the single most valuable test asset in that repo, and we have none of it.
4. **The adapter pattern** from `src/config/env.ts` + `src/api/index.ts` — a cleaner seam than our hardcoded driver switch.
5. **The a11y harness** (`e2e/a11y.mjs`, axe, 47 routes, fails CI on critical/serious). We have no accessibility gate at all.

### Take with care
6. **The extra admin screens** (price-lists, transfers, purchase-orders, bulk-editor). Great backlog, but each needs a real API behind it — their versions are `localStorage` demos. Porting a screen without its backend produces 20 convincing pages that do nothing.

### Do not take
7. **React 18.3.1 / Vite 5.4.** This is the exact configuration that produced our white screen. Any port must land on React 19 / Vite 8.
8. **`localStorage` as the demo DB.** It cannot show a real order to a real operator.
9. **`src/assets` image coupling.** 93 `.webp` inside the bundle — the thing we just spent Phase 2 undoing in favour of one `/img` copy.
10. **The committed `.env`.**
11. **`.lovable/` and `@lovable.dev/mcp-js`.** Still present; still unwanted.

---

## 9. Problems, ranked

| # | Problem | Where | Severity | Fix |
|---|---|---|---|---|
| P1 | SSE bus exists (39 emits) but dashboard has **zero** subscribers | `apps/dashboard/src/lib/api.ts:298` | **High** — feature is paid for and invisible | Replace `initRealtime` poll stub with `new EventSource("/api/events?token=…")` |
| P2 | `useRealtime` **filters out** the only event it receives (`"poll"`) | same file, line 314 | **High** | Delete the filter; real events never carry `type:"poll"` |
| P3 | Committed live `.env` (project ref + endpoint) | reimagined repo | **High** | Rotate key, delete project if unused, block `.env` in `.gitignore` |
| P4 | Page builder is 11 blocks / forms / duplicate renderer | `Website.tsx`, `server.mjs:2197` | **Medium** | Adopt Puck + 40-section catalogue |
| P5 | No SEO regression tests despite rich JSON-LD | our repo | **Medium** | Port their `seo` E2E block |
| P6 | No accessibility gate | our repo | **Medium** | Port `e2e/a11y.mjs` |
| P7 | `PublishedPage.tsx` catch-all reports "Tracking service unreachable" for *any* error | `PublishedPage.tsx:278` | **Low** — misleading, cost me 20 min | Narrow the catch; log the real error |
| P8 | Duplicate React/Vite risk on any port | both repos | **High if ignored** | Land on React 19 / Vite 8 only |
| P9 | Their admin breadth is demo-only | reimagined | Informational | Backlog, not a port target |

---

## 10. Bottom line

**Is it a real reimagined storefront?** Yes. It installs, typechecks clean, lints clean, builds in 27 s, renders 2.59 MB of real DOM with zero errors, passes its own 59-check E2E suite and a 47-route axe scan with zero violations. Nothing about it is fake.

**Should it replace what we have?** No. It is a **single-user, browser-local prototype** of a commerce CMS. What runs today is a **multi-user operations platform** with a real database, real transactions, and 96/96 tests on two database drivers. The reimagined repo's entire business loop lives in `localStorage` — it cannot take an order from a customer and show it to staff.

**Should we take from it?** Yes, selectively, and the list is short: **the 40-section catalogue, Puck, the SEO tests, and the a11y harness.** Those four close real gaps in what we ship. Everything else is either inferior to what we have (React 18, bundle size, image handling, persistence) or unusable without a backend we would have to build anyway (their extra 26 admin screens).

**The highest-value single action is not a port.** It is **P1 — wiring the SSE bus we already built and already emits on.** The backend is finished and proven; the frontend is one `EventSource` away. That is a half-day of work that turns "real-time" from a claim into a visible product feature, in the app the user actually opens.

---

*Verification artefacts: clone and test run in `/tmp/wxr` (not committed). Live servers: API `:3001`, dashboard `:5173`, storefront `:5174`.*
