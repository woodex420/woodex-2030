# WOODEX 2030 — FULL-STACK AUDIT & REIMAGINE REPORT

**Audit date:** 2026-11-08
**Audited tree:** `woodex420/woodex-2030` @ `arena/01a10a97-woodex-2030` (`6ad3f2f`, 2026-11-06) — 738 files
**Baseline (this branch):** `main` @ `e7b7d15` contains **only `README.md`**
**Method:** static audit of all non-image source + live local runtime (fresh SQLite DB, 92-check suite, 32 adversarial HTTP probes) + Vercel control-plane + build/typecheck evidence
**Verdict (as received):** Frontend ✅ near-complete · Backend ✅ functionally real · Database ⚠️ single-tenant, unconstrained · Stack ⛔ NO-GO for live traffic — one public write path was untrusted.

**Status after this audit's fixes (see §11):** that blocker is **closed and verified**, a **respond-before-commit race in 9 routes** is fixed, and regression coverage is now **96/96 on SQLite *and* 96/96 on PGlite**. Remaining NO-GO conditions are **operational** (no `DATABASE_URL` on Vercel, no backups, no rate limit on a hosted edge), not application-logic.

---

## 0. TL;DR — what you have, in one page

| Layer | State | Evidence |
|---|---|---|
| **Storefront** (`apps/storefront`) | ✅ Complete, 25 routes, React 18 + Vite 5 + Tailwind 3, shadcn/Radix, live-hydrated from API, 11 MB build | builds in 33.5 s, 64 chunks, themes/tokens runtime-injected |
| **Reimagine preview** (`apps/woodex-reimagined-preview`) | ⚠️ 96 % identical fork of the storefront with its **own untracked `supabase/functions/mcp`**, `bun.lock*`, and a duplicate 12 MB tree | `diff -rq` = 22 differences |
| **Admin dashboard** (`apps/dashboard`) | ✅ Complete, 27 routes, React 19 + Vite 8 + Tailwind 4, RBAC-aware shell, ⌘K palette, zero mock data except 2 screens | `tsc -b` **clean (0 errors)**, builds to 740 KB |
| **API** (`apps/api/server.mjs`) | ✅ Real and substantial — **87 routes, ~2.6 k LOC, 25 tables**, scrypt bearer auth, capability guard, quote versioning, transactional writes | **96/96 SQLite and 96/96 PGlite after the §11 fixes** (92/92 before) |
| **Database** | ⚠️ SQLite (dev) / Postgres 1:1 mirror (prod). Zero FKs, zero CHECKs, zero ENUMs, ISO-text timestamps, JSON-in-TEXT | 25 tables; `FOREIGN KEY` count = **0** |
| **Integrity of writes** | ✅ After §11.2 every mutating route commits **before** it responds | 9 routes converted, race reproduced then fixed |
| **Deploy** | ⚠️ Single Vercel project wired (`/`, `/dashboard`, `/api/*`), build passes locally, **env list is empty** → live deployments cannot boot | `list_env_vars` → `[]` |
| **Integrations** | ⛔ **None.** No payments, no email, no SMS, no WhatsApp API | Stripe/SendGrid/Twilio/Resend hits = **0** |

**The one-sentence risk (as found):** the admin was locked down properly, but **`POST /api/quotes` was an unauthenticated write that accepted attacker-chosen prices, names and quantities** — the last open money-path hole (proof in §6.1). **It is fixed here** and covered by three new regression tests.

---

## 1. Verified runtime scoreboard (run on this audit's fresh DB)

| Check | Result | Boundary |
|---|---|---|
| `npm ci --legacy-peer-deps` (500 pkgs) | ✅ pass | root workspaces |
| `npm run vercel:build` (dashboard + storefront + `dist`) | ✅ pass, 33.5 s | local build, not a deploy |
| Dashboard `tsc -b` | ✅ **0 errors** | React 19 / Tailwind 4 |
| Storefront `tsc -b` | ❌ **1 037 TS errors** (`tsc -b` exit 1) | npm hoists `@types/react` **19.3.0** + `react` **19.3.0** to the root while the app resolves `react` **18.3.1** / `@types/react` **18.3.31** locally → two incompatible `ReactNode` identities |
| API QA `scripts/qa-full.mjs` → SQLite | ✅ **92/92 as received → 96/96 after §11** | fresh `WOODEX_DATA_DIR`, 3 consecutive runs |
| API QA → PGlite (`npm run qa:pg`) | ✅ **92/92 as received → 96/96 after §11** (9.6 s) | PostgreSQL semantics, WASM |
| Login rate limit | ✅ 5 × `401` then `429` | per-email bucket |
| IP backstop | ✅ `429` at attempt 31 | suite-verified |
| Anonymous protected reads (`/api/invoices`, `/api/clients`, …) | ✅ `401` | guard works |
| Viewer role reads (invoices/clients/leads/orders/quotes/users/pages) | ✅ all `403` | **read-scope hole from the 2026-11-06 audit is FIXED** |
| Finance role: payment on invoice, stage move | ✅ allowed | least-privilege scoped |
| Finance role: change order owner | ✅ `403` "may only move order status or stage" | |
| Public order tamper (client price `1`) | ✅ repriced to catalog `185 000` | server-side reprice holds |
| Public order lookup without token | ✅ `404` | reference-only access closed |
| Anonymous `GET /api/events` (SSE) | ✅ `401` | stream closed |
| **Anonymous `POST /api/quotes` with fake SKU + price 1 × 500** | ⛔ **`201` — accepted, `Q-2296`, total `PKR 500`** | **release blocker** |
| Public lead/quote intake rate limit | ⛔ none (3/3 `201` spam leads) | |
| Security headers | ⛔ none (`X-Powered-By: Express` leaks; no HSTS/CSP/nosniff/frame) | |
| Malformed JSON body | ⛔ Express HTML stack trace with absolute `node_modules` paths | |
| CORS preflight from `evil.example` | ⛔ `401` from auth guard (no CORS layer; browser blocks) — *functionally safe, architecturally accidental* | |
| `scripts/secret-scan.mjs` | ⚠️ scans `git show HEAD:<file>` → on `main` that is **1 file**; irrelevant on this branch (738 files) but misleading | |
| Vercel project `woodex-2030` | ⚠️ `READY / STAGED` preview from this branch, **`env: []`**, framework `null`, **repo public** | |

---

## 2. Stack framework audit

### 2.1 The monorepo

```
woodex-2030/
├── apps/api/            Express 4 + node:sqlite | pg         2 744 LOC   ← single source of truth
│   ├── server.mjs       87 routes, schema, seeds, RBAC, SSE
│   ├── db-adapters.mjs  3 drivers: sqlite · pglite · postgres (AsyncLocalStorage tx)
│   └── shop-data.mjs    catalog import from storefront data
├── apps/dashboard/      React 19 · Vite 8 · Tailwind 4 · react-router 8  10 258 LOC  27 routes
├── apps/storefront/     React 18 · Vite 5 · Tailwind 3 · Radix/shadcn    25 routes  11 MB build
├── apps/woodex-reimagined-preview/  96 % duplicate of storefront (reference fork)
├── api/                 Vercel function shims (index · [...path] · sitemap)
├── scripts/             qa-full (813 L) · ci-test · 4 e2e · pg-migrate · schema · secret-scan   2 060 LOC
├── docs/                17 documents, 2 226 lines (plan v2, decision log, 2 audits, runbook)
└── vercel.json          install/build/output + 4 rewrites
```

**Strengths worth protecting:**
1. **One backend, two clients.** The dashboard and storefront share the same 87 endpoints — no duplicated data layer, no drift.
2. **Driver abstraction is genuinely good.** `rewritePlaceholders()` is a hand-written SQL lexer (respects `'`, `"`, `` ` ``, `--`, `/* */`, `$$`) that upgrades `?` → `$n`; `pgSqlForRun()` adds `ON CONFLICT DO NOTHING` for `INSERT OR IGNORE` and `RETURNING id` for identity tables. Request-local transactions use `AsyncLocalStorage`, so concurrent requests never share a PG client. This is the highest-quality code in the repo.
3. **Quote integrity model.** `quotes.version` + `quote_versions` snapshots + "issued quotes are immutable unless set to `Revised`" (409) is real ERP behaviour, not demo code.
4. **Publish model.** `published_snapshot` + HMAC-signed 15-minute preview links + rollback = drafts never leak, and it is proven by tests.

### 2.2 Version skew — the one stack decision that is actively costing you

| | dashboard | storefront | root hoisted |
|---|---|---|---|
| React | **19.2** | **18.3.1** | 19.3.0 |
| Vite | **8.2** | **5.4** | — |
| Tailwind | **4.3** | **3.4** | — |
| `@types/react` | 19 | 18 | **19.3** |

Two React majors, two Vite majors and two Tailwind majors inside one `npm` workspace. Consequences seen today:
- Root `node_modules` holds `react@19.3.0` + `@types/react@19.3.0`, while `apps/storefront/node_modules` holds `react@18.3.1` + `@types/react@18.3.31`. TypeScript resolves *both* `ReactNode` definitions, so every JSX component in the storefront fails `TS2786 'X' cannot be used as a JSX component` — **1 037 errors, none of them real bugs** (and `skipLibCheck`/`strict:false` hide nothing here; the conflict is in the app's own JSX).
- Two `node_modules` copies of `vite` (root + storefront-local) produce `ProxyOptions` type mismatches in `vite.config.ts`.
- **Recommendation:** standardise the storefront on React 19 + Vite 8 + Tailwind 4 (the dashboard is already the modern target and the preview fork adds nothing), or go the other way — but pick one. Do not ship "typecheck passes" claims while `tsc -b` fails.

### 2.3 Build & deploy shape

- `scripts/build-vercel.mjs` merges both SPAs into one `dist/` (`/` storefront, `/dashboard` admin, `/img` shared). Clean and correct.
- `vercel.json` rewrites `/sitemap.xml` → `/api/sitemap`, `/dashboard/:path*` → SPA shell, everything else → storefront SPA shell.
- `api/index.mjs` re-prefixes the catch-all path back to `/api/*` before handing to Express — correct for Vercel's catch-all function.
- **Blocker:** `list_env_vars` returns `[]`. `server.mjs:36` throws when `VERCEL === "1"` and `DATABASE_URL` is missing, so **every current deployment serves static assets only; `/api/*` cannot boot**. The `READY / STAGED` status is therefore not a working app.

---

## 3. Admin dashboard — complete audit

**27 routes, ~10 k LOC, only 2 screens still on mock data.**

| Group (sidebar) | Screens | Data | Notes |
|---|---|---|---|
| Workspace | Overview | 🟡 API + mock | 2 `useApi` calls, mock KPIs still feed the hero row |
| Capture & Relationships | Shared Inbox · Leads & Pipeline · Clients · Tasks | ✅ API | P10 omnichannel inbox, lead scoring with `scoreWhy`, client 360 timeline, site visits |
| Quote & Collect | Quotations · Invoices & Payments | ✅ API | quote builder (757 L), template designer (346 L), versions, printable |
| Commerce & Fulfilment | Commerce · Catalog · Orders · Operations · Projects | 🟡 mostly API | **`Projects` is labelled "(Prototype)" and is 100 % mock** |
| Website & Growth | Website/CMS · Theme Studio · Landing Analytics | ✅ API | 584 L block builder + drag-drop, global sections, live theme tokens |
| Insights & Admin | Analytics · Settings · UI Components | 🟡 Analytics = **mock only** | Signup/SEO/speed screens in Settings are placeholders |

**What is genuinely impressive**
- `lib/auth.ts` — bearer token in a module variable with `localStorage` as *best-effort* (because Arena previews partition storage), one global `fetch` patch that attaches `Authorization` + `X-Woodex-Session`, rejects a `200` HTML body masquerading as a login, and normalises post-login URLs. This is battle-tested against real iframe failure modes.
- `lib/api.ts` — `useApi` with interval polling + a `woodex:rt-refresh` event bus that collapses the (disabled) SSE stream into 15 s polling for serverless. Honest about the constraint.
- `AppShell` capability-gated nav (`can()`), command palette, toast, drawer, DataTable abstractions.
- Team manager in Settings (5-role demo team, last-owner protection, session revocation on disable/reset).

**Gaps**
1. `Analytics.tsx` and `Projects.tsx` present fabricated business numbers next to real ones with no "demo" badge — the audit's Gate B item 9 is still open.
2. No **customer/order search** in the topbar; ⌘K only navigates.
3. No dark-mode toggle in the dashboard shell (storefront has one).
4. No empty/error boundary per screen — a 403 renders as a red string.
5. `ProductEditor` + `Catalog` do not expose bulk price/stock import (the storefront override polling is designed for exactly that).

---

## 4. Storefront reimagine audit

**25 routes:** `/` · `/shop` · `/shop/:id` · `/room-packages` · `/virtual-showroom` · `/series` · `/series/:id` · `/projects` · `/projects/:id` · `/services` · `/services/:slug` · `/b2b` · `/about` · `/contact` · `/custom-design` · `/quotation` · `/showrooms` · `/materials` · `/warranty` · `/blog` · `/blog/:id` · `/checkout` · `/order-status` · `/p/:slug` · `*`

**Architecture that works**
- `lib/runtime.ts` hydrates a *module-level mutable* `products` array from `/api/products`, patches prices/stock from `/api/products/overrides?since=…` every 15 s, and bumps `woodex:runtime` so memoized filters re-render. Dashboard edits reach the storefront without a rebuild. ✅ verified by suite.
- `lib/theme.ts` injects an unlayered `<style id="wx-theme">` rewriting `hsl(var(--…))` triplets for `:root` and `.dark` — no Tailwind rebuild for a brand change. ✅
- `lib/track.ts` — first-party pixel, `sendBeacon`, anonymous `cid`, UTM + referrer capture, 50/90 % scroll depth. No cookies, no third-party. Good for a PK-market privacy posture.
- `PublishedPage.tsx` renders the CMS snapshot with the same typed-block contract as the dashboard preview; `lead-form` blocks POST into `/api/leads` with `source = "Landing: <slug>"` → CRM dedupe.

**Where the "reimagine" is still incomplete**
| Issue | Impact |
|---|---|
| **11 MB build / 9.1 MB of JPEGs in `dist/assets` + `dist/img`** (92 hero/product photos, no AVIF/WebP, no responsive `srcset`) | LCP will be poor on 3G/4G — the #1 conversion killer for furniture |
| **64 JS chunks, `index` = 450 KB raw / 134 KB gz**, every Radix primitive shipped | no route-level budget, no vendor split |
| `supabase/functions/mcp/index.ts` + `src/lib/mcp/*` still in the tree | dead weight from the Lovable lineage; also the only place a Supabase edge function lingers |
| **`apps/woodex-reimagined-preview`** duplicates the entire storefront (22 file diffs) and carries `bun.lock` + `bun.lockb` | 12 MB of ambiguity: *which* storefront is the product? |
| Product/blog/service content still lives in `src/data/*.ts` (3 958 LOC) and is *imported* by the API at boot | catalogue edits require a code change; CMS only covers landing pages |
| No wishlist, no saved quotes, no account area, no i18n (UR/Roman-Urdu), no PWA/manifest | scope, not defects |

---

## 5. Database audit

### 5.1 What the schema actually is

**25 tables** (`products`, `materials`, `services`, `leads`, `quotes`, `quote_versions`, `quote_templates`, `orders`, `invoices`, `payments`, `returns`, `clients`, `client_links`, `tasks`, `site_visits`, `pages`, `page_blocks`, `page_versions`, `saved_sections`, `track_events`, `conversations`, `messages`, `users`, `sessions`, `meta`).

**Deliberate design choices that are defensible**
- ISO-8601 timestamps in `TEXT` → one semantic across SQLite and Postgres, `datetime()` shim in PG.
- JSON blobs in `TEXT` (`items`, `props`, `audit`, `details`) → the CMS/quote payloads evolve weekly; normalising them now would cost more than it buys.
- A `pg-migrate.mjs` that asserts **row count + order-independent content digest per table**, `BY DEFAULT AS IDENTITY` + `setval` so explicit ids survive the cutover, and 92/92 green on PGlite.

**Findings**

| # | Finding | Severity |
|---|---|---|
| D-1 | **Zero foreign keys, zero `CHECK`s, zero `ENUM`s.** Integrity is 100 % application-enforced. One bug, one manual `psql` session, or one partial write leaves `client_links` pointing at deleted parents. | High |
| D-2 | **Aggregate-in-JS.** `/api/clients` runs `clientFull()` per row → **~6 queries × N clients**; `/api/stats` walks orders/invoices/leads in JS. Looks fine at 24 clients, will not at 5 000. | High |
| D-3 | **Statuses are free text** (`orders.status`, `quotes.status`, `returns.state`, `tasks.priority`). Validation lives in route handlers that don't always agree with the dashboard's union types. | Medium |
| D-4 | `client_links` has `UNIQUE(entity, entity_id)` → **one client per entity**, so re-assigning a lead to another client is impossible without delete+insert. | Medium |
| D-5 | **No tenancy.** Tenancy is a commented-out `tenant_id` TODO; one Woodex = one database. Fine *now*, blocks the "Agency OS" multi-client story the docs promise. | Medium |
| D-6 | `track_events` has no retention/TTL job. It will become the largest table. | Medium |
| D-7 | Counters are `nextRef()` over `MAX(id)`-style scans (`Q-`, `WX-`, `INV-26-`); race-safe **only** inside `BEGIN IMMEDIATE`/PG transaction. Any future non-transactional caller breaks it. | Low |
| D-8 | **No backups, no PITR, no migration ledger.** Schema evolution is `ALTER TABLE IF NOT EXISTS` executed at every boot. | High (ops) |
| D-9 | Session table has no index on `expires_at`; expired rows only die lazily on auth. | Low |
| D-10 | Seeds are boot-guarded correctly (`allowDemoSeed = !IS_SERVERLESS && !IS_PRODUCTION`) — **the 2026-11-06 blocker #3 is genuinely fixed.** Production bootstrap requires `INITIAL_ADMIN_EMAIL` + a ≥16-char password and fails closed. ✅ | — |

### 5.2 Where the schema is dishonest about itself

`orders` defaults `owner='Faisal'`, `tasks` defaults `owner='You'`, `site_visits.owner='Unassigned'` — three different sentinels for "nobody". Pick one (`NULL`) and let the UI label it.

---

## 6. Backend API audit

**87 routes.** Auth model: scrypt(32) + `timingSafeEqual`, 64-hex tokens in `sessions`, 7-day expiry, **no cookies** (therefore CSRF-immune by construction), two headers accepted (`Authorization: Bearer` + `X-Woodex-Session`) for proxy-hostile previews.

**Capability guard is centralised and correct** (`server.mjs:600-645`): `capsFor(method, path)` maps a path prefix → capability; public allowlist is explicit; GET and non-GET are checked separately; `finance` gets *read-and-move* on quotes/orders but **cannot** edit fields (verified `403`).

### 6.1 Release blocker — `POST /api/quotes` trusts the client

```
curl -X POST /api/quotes -d '{"customer":"Probe Co","items":[
  {"productId":"no-such-product","name":"Fake","price":1,"qty":500}]}'
→ 201 {"ref":"Q-2296","total":500,"status":"Draft","source":"storefront"}
```

`normalizeQuotePayload()` validates *shape* (name ≤160, price 0..1e9, qty 0.01..1e5, rounding) but **never re-reads `products`**. So anonymously:
- any price, any product name, any quantity, up to **100 line items** per request, **unlimited requests**;
- each request also **fabricates a lead + a client + a task + an inbox thread** (`createLeadRecord`, `matchOrCreateClient`, `ensureConv`) → one unauthenticated call writes to **5 tables** and fires 2 SSE events;
- `Q-` references are the *displayed* quote number in the dashboard pipeline, so a poisoned pipeline is indistinguishable from a real one.

`POST /api/orders` — the same shape — **does** reprice correctly (verified: `price 1` → `185 000`). The fix for quotes is the same 12 lines.
**Minimum fix:** for `source === "storefront"`, resolve every `productId` against `products`, overwrite `name`/`price`, reject unknown ids (400) and out-of-stock (409), cap `qty`, and require `lead` dedupe. Then `captureLead` cannot substitute for authorisation.

### 6.2 Other findings

| # | Finding | Severity | Evidence |
|---|---|---|---|
| B-1 | **No rate limit on public write paths** (`/api/leads`, `/api/quotes`, `/api/orders`, `/api/track`). Only login is throttled. | High | 3/3 spam leads `201` |
| B-2 | **No security headers.** `X-Powered-By: Express`, no HSTS/CSP/`X-Content-Type-Options`/`X-Frame-Options`/`Referrer-Policy`. The dashboard literally renders inside third-party iframes and the API sends nothing to constrain that. | High | `curl -I` |
| B-3 | **Stack traces leak to clients.** A malformed JSON body returns Express's HTML `<pre>SyntaxError … /tmp/wx/node_modules/body-parser/...` with absolute paths. | Medium | reproduced |
| B-4 | **No global error handler.** `wrap()` covers async handlers, but sync middleware (body-parser, static) falls through to Express's default dev page. | Medium | |
| B-5 | **Unauthenticated WhatsApp handoff** (`POST /api/leads/:id/whatsapp-handoff`) on a **sequential integer id**. Mitigated by consent+channel checks (all 3 probes `403`), but it is still a write endpoint keyed by an enumerable id — and it appends to a CRM thread. | Medium | 3× `403` |
| B-6 | `POST /api/target` is classified `theme.manage` but mutates the *sales target*; `GET /api/marketing/stats` is `owner-only` by fallthrough, not by intent. Capability map has drifted from domain meaning. | Medium | code read |
| B-7 | `nextRef()` + `MAX(id)` scanning is only safe inside transactions — documented, fragile. | Low | |
| B-8 | **SSE bus is in-process** → disabled on Vercel (`410`), dashboard falls back to 15 s polling. Honest, but every "live" doc claim should say *polling*. | Low | code read |
| B-9 | `express.json({limit:"2mb"})` — fine, but no schema validator (zod) at the boundary; every route hand-rolls its own checks, which is how §6.1 happened. | Medium | |
| B-10 | No OpenAPI/typed client; `apps/dashboard/src/lib/api.ts` (334 L) is hand-maintained mirror of server shapes. | Low | |
| B-11 | Audit trail is strong for quotes/pages/site-visits, absent for invoices/payments/returns/products. | Medium | code read |

---

## 7. Conflicting / unsafe claims found in the repo

1. **`README.md` on `main` (`e7b7d15`) is a chat transcript, not documentation** — it announces a `docs/CREDENTIALS.md` listing "all credentials and usernames/passwords" and names an admin email, a password, a Supabase project URL and Stripe/SendGrid keys. **The GitHub repository `woodex420/woodex-2030` is public (`private: false`).**
   **Correction, verified:** `docs/CREDENTIALS.md` was *never committed* (`find` → no such file, and it is absent from both branch trees), and the `README.md` on the application branch (`01a10a97`) is a proper, credential-free document. So this is a **reputational and rotation-hygiene issue on `main`, not a live secret in a working tree** — but on a public repo the safest reading is still "assume exposed, rotate, and replace the `main` README". Downgraded from P0 to P1.
2. Three planning documents claim authority: `AGENCY-OS-MASTER-PLAN-V2.md` (v2), `AGENCY-OS-MASTER-PLAN-v3.md`, and `AGENCY-OS-WOODEX-REIMAGINED-TWO-PHASE-PLAN.md`. `DECISION-LOG.md` D-033 says V2 is canonical — but v3 is still in the tree and still referenced.
3. "Storefront typecheck passes" vs **1 037 errors** — the claim only ever held inside the app's own isolated `node_modules` (still true after my changes; the fix is §9 P1 "unify versions").
4. `scripts/secret-scan.mjs` prints *"clean (N tracked files)"* using `git show HEAD:<file>`; on `main` that is 1 file. On this branch it is honest, but the script should scan the **worktree + untracked** files to be meaningful.
5. `docs/audits/WOODEX-2030-MASTER-AUDIT-REPORT-2026-11-06.md` is a good document whose §10 updates contradict its own §1. Keep it, but move the superseded sections under an explicit "Historical" heading so nobody quotes blocker #3 (demo seeds) or #4 (viewer reads) as *current* — **both are fixed and I re-verified them**.

---

## 8. Reimagined target architecture (what "complete" should look like)

```
                    ┌──────────────────────── Vercel ─────────────────────────┐
  storefront (RSC-lite)      dashboard (SPA)              API (Node fn)        │
  React 19 · Vite 8 · TW4    React 19 · Vite 8 · TW4      Fastify/Express      │
  /  /shop  /p/:slug         27 screens · RBAC shell       + zod boundary       │
        │                          │                            │              │
        └──────── typed client (generated from OpenAPI) ───────┘              │
                                   │                                          │
                     PostgreSQL (Neon/Supabase) ── pool ── pg pool max 1/fn    │
                     FKs · CHECK/ENUM · tenant_id · RLS                        │
                     Litestream-style PITR · migration ledger                  │
                     └── queues: email · whatsapp · webhook · nightly rollups ─┘
```

**Six moves that turn this from "strong prototype" into "operable product"**

| # | Move | Why now |
|---|---|---|
| 1 | **Server-authoritative intake** (quotes + orders + leads) with `productId` resolution and a per-IP/per-session token bucket on all four public writes | closes §6.1 and B-1 — the only true NO-GO |
| 2 | **Postgres + FKs + CHECK constraints + a `migrations/` ledger** (you already have the mirror and the migrator; add constraints while the data is still demo) | D-1, D-3, D-8 |
| 3 | **Provision env + a hosted DB canary** (`DATABASE_URL`, `INITIAL_ADMIN_*`) so `/api/*` actually boots on Vercel | the deployed app is currently static-only |
| 4 | **One React/Vite/Tailwind version across the workspace**; delete `apps/woodex-reimagined-preview`; make storefront `tsc -b` required in CI | 1 037 phantom errors hide real ones |
| 5 | **Image pipeline** (AVIF/WebP + `srcset`, uploads to object storage) and route-level code splitting | 11 MB build is a conversion tax |
| 6 | **Finish the honesty pass** — badge `Analytics`/`Projects` as demo, replace remaining mock KPIs in `Overview`, drop the duplicate plan v3 | the repo's credibility is the thing most at risk |

---

## 9. Prioritised remediation backlog

| Priority | Item | Where | Est. |
|---|---|---|---|
| **P0** | Reprice/validate `POST /api/quotes` from catalog; cap per-request writes; rate-limit `/leads`, `/quotes`, `/orders`, `/track` | `server.mjs:830` | 4 h |
| **P1** | Replace the `main` README chat transcript (no credentials in the app tree); rotate anything ever used from it | `README.md` @ `main` | 1 h |
| **P0** | Set Vercel env (`DATABASE_URL` → Postgres, `INITIAL_ADMIN_EMAIL/PASSWORD`) and verify `/api/health` on a preview URL | Vercel project | 2 h |
| **P1** | `helmet` + explicit CORS allowlist + JSON 400/500 error handler (no stack traces) | `server.mjs:566` | 2 h |
| **P1** | Postgres schema v2: FKs, CHECK/ENUM for statuses, migration ledger, backup/PITR | `scripts/schema.postgres.sql` | 2 d |
| **P1** | Unify React/Vite/Tailwind; remove the preview fork; storefront typecheck in CI | workspace | 1 d |
| **P2** | Aggregate SQL for `/clients`, `/stats`, `/marketing/stats`; add indexes (`leads(status,created_at)`, `orders(stage)`, `invoices(status)`) | `server.mjs` | 1 d |
| **P2** | Image pipeline + route-level splitting + bundle budget in CI | `apps/storefront` | 1 d |
| **P2** | Replacement of `Analytics`/`Projects` mock data, or explicit "Demo" badges | dashboard | 4 h |
| **P3** | OpenAPI → typed client; zod at the boundary; invoice/payment/return audit trail | API | 3 d |
| **P3** | `tenant_id` + RLS scaffolding; `track_events` retention job | DB | 3 d |
| **P3** | Payments (Stripe/Safepay/JazzCash) + email (Resend) + WhatsApp Cloud API | new | 1 w |

---

## 10. Honest scorecard

| Dimension | Score | Note |
|---|---|---|
| API surface & business logic | **8.0 / 10** | 87 routes, real transactionality, versions, publish gates |
| Auth & RBAC | **8.5 / 10** | central guard, scoped roles, rate-limited login, revocable sessions |
| Public-input security | **4.0 / 10** → **7.5 / 10 after §11** | quotes trusted the client, no write throttles, no headers — all three fixed & tested |
| Data model | **6.0 / 10** | coherent + migratable, but zero DB-level integrity |
| Dashboard UX completeness | **8.0 / 10** | 27 screens, 25 API-backed; 2 mock |
| Storefront design & behaviour | **8.0 / 10** | live-hydrated, themed, tracked; heavy assets |
| Build & release engineering | **5.5 / 10** | build works, CI exists; Vercel env still empty, storefront typecheck red, 2 deploy targets |
| Documentation truthfulness | **5.0 / 10** | excellent decision log undermined by an unsafe public README and stale claims |
| **Overall** | **6.6 / 10 → 7.4 / 10 as of §11** | *Strong, coherent system; the remaining NO-GO items are operational (hosted DB, backups, CI browser journeys) rather than broken logic.* |

---

## 11. Fixed in this audit — shipped, not recommended

All three changes are on branch `arena/41856005-woodex-2030` and re-verified against a fresh database.

### 11.1 · Anonymous quote poisoning — **closed**

`apps/api/server.mjs` · `applyCatalogPricingToPublicIntake()` + `POST /api/quotes`

| Probe | Before | After |
|---|---|---|
| `productId: "no-such-product", price: 1, qty: 500` | `201`, ref `Q-2296`, total `PKR 500` | **`400` "item 1 is not an available catalog product"** |
| `productId: "dining-set-6", price: 1, qty: 2` | `201`, total `2` | **`201`, price `185 000`, total `PKR 370 000`** (catalog wins) |
| `details.discountPercent: 90` from a visitor | `201`, 90 % off | **`201`, `discountPercent: 0`**, subtotal only |
| custom line, `name` only, `price: 450000` | `201`, total `450 000` | **`201`, price `0`, total `0`** — a request, not an agreed amount |
| staff (`sales`/`owner`) custom line + 10 % discount | — | **unchanged authority**: `875 000` → `787 500`, `source: dashboard`, `status: Sent` |

Also fixed while proving it: staff calls to `/api/quotes` were being **treated as anonymous** because the public-path allowlist returned before the session lookup — a `Bearer` token was ignored, which forced `status: "Draft"`, `source: "storefront"` and lead auto-capture on the dashboard's own quote builder. The guard now resolves a valid token on public paths and leaves invalid ones anonymous.

### 11.2 · Respond-before-commit race in 9 routes — **closed**

`wrapTx(async (req, res) => …)` wrote the HTTP response *inside* the transaction; `COMMIT` only landed when the handler returned. Any client that acted on that response immediately (dashboard drill-down after creating an order, the QA suite reading the tracking digest, a payment posted right after an invoice) could open a snapshot that did not yet contain the row and get a spurious `404`.

Reproduced deterministically: the pre-existing suite reported `tracking secret was not stored` and `finance CAN record payment → 404` on SQLite, and passed on PGlite only because of timing. A new `wrapTxJson()` commits first, then writes; all nine handlers (`PATCH /quotes/:id`, `POST /orders`, `POST /clients`, `POST /clients/:id/merge`, `POST /quotes/:id/invoice`, `POST /invoices/:id/payments`, `POST /returns`, `PUT /pages/:id/blocks`, `POST /pages/:id/rollback`) were converted and every early-return preserved as `{ status, body }`.

### 11.3 · Hardening — **shipped**

| Change | Detail |
|---|---|
| Rate limiting | `express-rate-limit`-free token bucket: 30/min/IP on `POST /leads · /quotes · /orders · /target`, 120/min on `/track` (a pixel must never throttle a real visitor). Verified: `201 × 30` then `429` with `retry-after`. |
| Security headers | `nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, `CORP`, `Permissions-Policy`, HSTS when deployed; `X-Powered-By` removed. |
| CORS | Explicit allowlist (`WOODEX_PUBLIC_ORIGINS`) + `*.vercel.app`; answers preflight `204`; a foreign origin gets **no** `Access-Control-*` header. |
| Error handling | Malformed JSON → `400 {"error":"request body is not valid JSON"}` (was an HTML page with an absolute-path stack trace). Unknown `/api/*` → JSON `404`. `5xx` bodies are generic; detail goes to the server log. |
| Client contract | `apps/storefront/src/pages/Quotation.tsx` now sends `productId` — the field the server re-prices from. Without it, catalog lines would be treated as custom lines and priced `0`. |

### 11.4 · Test suite updated to the real contract

`scripts/qa-full.mjs`: **92 → 96 checks**, all passing on **SQLite 96/96** and **PGlite 96/96**.

Four new checks pin the behaviour above:
1. `public quote intake cannot be poisoned with a made-up product or price`
2. `staff quote creation stays privileged through the public route`
3. `API errors are JSON and never leak a stack trace`
4. `public write paths are rate limited` (flood isolated behind a spoofed `X-Forwarded-For` so it can't starve later tests)

Three existing checks that encoded the *old* insecure contract — `items: [{ name: "Chair", price: 12000 }]` expecting `total === 24000` — now use a real SKU and assert the **catalog** price, which is what made the three "failing" assertions in the first place disappear.

### 11.5 · What is deliberately *not* changed

- `POST /api/orders` was already correct (reprice + hashed tracking token); untouched.
- The dashboard RBAC, session model, CMS publish model and quote versioning were verified and left alone.
- No schema migration, no dependency added (`helmet`/`express-rate-limit` were avoided on purpose — the bucket and headers are ~40 lines and have no supply chain).
- Nothing was deployed, and no Vercel setting was modified.


---

## Appendix A — verification commands used

```bash
# reproduce the audit environment
git archive origin/arena/01a10a97-woodex-2030 | tar -x -C /tmp/wx && cd /tmp/wx
npm ci --legacy-peer-deps && npm run vercel:build            # ✅ 33.5 s
(cd apps/dashboard && npx tsc -b)                            # ✅ 0 errors
(cd apps/storefront && npx tsc -b)                           # ❌ 1037 errors (version skew)

# suites — before the fixes
PORT=3002 WOODEX_DATA_DIR=/tmp/wx-clean node apps/api/server.mjs
WOODEX_DATA_DIR=/tmp/wx-clean node scripts/qa-full.mjs --base=http://127.0.0.1:3002   # 92/92

# after the fixes (this branch)
PORT=3013 WOODEX_DATA_DIR=/tmp/wx-t4 node apps/api/server.mjs
WOODEX_DATA_DIR=/tmp/wx-t4 node scripts/qa-full.mjs --base=http://127.0.0.1:3013       # ✅ 96/96
npm run qa:pg                                                                          # ✅ 96/96 (PGlite)
npm run vercel:build                                                                   # ✅ 31.9 s

# the blocker, before → after
curl -X POST :3002/api/quotes -d '{"customer":"P","items":[{"productId":"no-such-product","price":1,"qty":500}]}'
#   before → 201 {"ref":"Q-2296","total":500}
#   after  → 400 {"error":"item 1 is not an available catalog product"}
```

## Appendix B — sources

- Tree: `arena/01a10a97-woodex-2030` @ `6ad3f2f`; Vercel deployment `dpl_44FLd5gq…` (STAGED, `env: []`).
- Prior audit: `docs/audits/WOODEX-2030-MASTER-AUDIT-REPORT-2026-11-06.md` — §10 claims re-verified here; blockers #3 (demo seeds) and #4 (viewer reads) confirmed **fixed**; blocker #1 (anonymous order/SSE access) confirmed **fixed**; blocker #2 (checkout price trust) fixed **for orders only, still open for quotes**.
- All runtime evidence was produced locally against synthetic data. Nothing in this report is a penetration test of a live host, and no production setting was changed.
