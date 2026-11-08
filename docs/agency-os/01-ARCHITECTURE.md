# WOODEX OS — Architecture (as built, and as it extends)

**Status:** describes the system that exists today, plus the extension points for
planned work. Anything described as "planned" is not implemented — check
[`00-CURRENT-STATE.md`](00-CURRENT-STATE.md).

**Baseline:** `main` @ `15e6242`

---

## 1. Shape: a modular monolith, deployed as a serverless function

```
┌────────────────────────┐   ┌────────────────────────┐
│  Storefront (Vite SPA) │   │ Dashboard (Vite SPA)   │
│  /                     │   │  /dashboard  (alias /admin)
│  25 routes, React 19   │   │  29 routes, React 19   │
└───────────┬────────────┘   └───────────┬────────────┘
            │            same-origin      │
            └──────────────┬──────────────┘
                           ▼
             ┌──────────────────────────────┐
             │  api/index.mjs               │
             │  Vercel function entry       │
             │  → apps/api/server.mjs       │
             │  Express 4 · 103 routes      │
             └──────────────┬───────────────┘
                            ▼
             ┌──────────────────────────────┐
             │  db-adapters.mjs             │
             │  one SQL dialect, 3 engines  │
             └───┬──────────┬───────────┬───┘
                 ▼          ▼           ▼
              SQLite     PGlite    PostgreSQL (prod)
```

Two independent SPAs sharing one API and one origin. There is no server-side
rendering; the storefront's SEO comes from prerendering 76 routes at build time.

## 2. Why the deployment shape constrains the architecture

The API runs as a **serverless function**, and that decides several things:

| Constraint | Consequence |
|---|---|
| Files must be statically traceable | No runtime bundling of other packages (this caused a total outage) |
| No long-lived process | No SSE in production; clients fall back to polling |
| Multiple cold instances | In-process rate limiting is per-instance, not global |
| Function timeout | No long-running jobs; automation needs a queue |
| Read-only filesystem | Seeding must be idempotent against the database, not files |

Every one of these is recorded as a live constraint in the current-state file.
They are the reason a naive "just add a background worker" instruction would fail.

## 3. Request path, and the four rules that keep it working

```
browser → Vercel edge → vercel.json rewrites → /api/index function
```

Four defects each took production down; all four are now guarded:

1. **A rewrite may not rewrite a prefix onto itself.** `"/api/:path*" → "/api/:path*"`
   doubled the prefix, so `POST /api/auth/login` arrived as `/api/api/auth/login`.
   → guarded by `check:deploy` (self-rewrite detection).
2. **`api/[...path].mjs` is a single segment, not a catch-all.** Outside Next.js,
   Vercel compiles `[...path]` to `^/api/([^/]+)$`. Nested paths fell through to the
   SPA shell, and a POST to a static file returns **405** — which the dashboard
   rendered as "Sign-in failed". → guarded by `check:deploy` (every API path must
   resolve to the function).
3. **The function may not read files outside its bundle.** The API bundled
   storefront `.ts` at runtime with esbuild; those files are not traced in, so the
   module threw during initialisation and every request returned 503. The
   catalogue bootstrap could therefore never run — which is why the database
   looked empty. → fixed with a build-time snapshot, guarded by `seed:data:check`.
4. **A SPA router may not be mounted on a path outside its basename.** `/admin` is
   *rewritten* (not redirected) to `/dashboard`, so the address bar kept `/admin`
   and `<BrowserRouter basename="/dashboard">` matched nothing and rendered **zero
   DOM** — a blank white screen with no error. → guarded by `test:router`.

The pattern worth internalising: **each of these failed silently.** No exception
reached the user, and the build was green. That is why the guards exist and why
they run inside the build rather than in a separate CI job that can be skipped.

## 4. Authentication and authorization

```
POST /api/auth/login  → verify scrypt(pw, salt) → issue opaque session token
                        → sessions row (7-day expiry)
every /api/* request  → session middleware → req.user
                      → capability check per route
```

- Passwords: `scrypt` with a per-user 8-byte salt, compared with `timingSafeEqual`.
- Sessions: opaque random tokens in a `sessions` table; revocable.
- Tokens: accepted from `Authorization: Bearer`, or `X-Woodex-Session` for
  proxies that strip `Authorization`, or `?token=` **only** on the SSE endpoint
  (EventSource cannot set headers — URLs leak into logs, so it is scoped to one path).
- Capabilities: routes map to capabilities (`catalog.manage`, `finance.manage`,
  …); roles map to capability sets; `*` grants all.

**Gap:** capabilities are global, not tenant-scoped. Adding tenancy requires the
capability check to also assert `tenant_id` membership — see
[`03-DATA-AND-TENANCY.md`](03-DATA-AND-TENANCY.md).

## 5. Data layer

`db-adapters.mjs` presents one interface over three engines:

| Engine | Used for | Notes |
|---|---|---|
| SQLite (`node:sqlite`) | local dev, QA | Files under `WOODEX_DATA_DIR` |
| PGlite | Postgres rehearsal in tests | Real Postgres semantics, in-process |
| PostgreSQL (`pg`) | production | Pool of 1 on serverless |

Dialect divergence is handled in the adapter (for example
`INSERT OR IGNORE` → `ON CONFLICT DO NOTHING`). This is deliberate: one SQL
dialect is written, the adapter translates.

**Catalogue seeding** runs from a committed snapshot (`apps/api/seed-data.json`,
148 products) rather than from TypeScript sources, because those sources are not
present in the serverless bundle. `seed:data:check` fails the build if the
snapshot drifts from the storefront.

## 6. CMS and page builder

Pages are stored as a **typed block tree**, not HTML:

```
pages → page_blocks (ordered, typed) → page_versions → saved_sections
```

- The builder edits blocks with a properties inspector.
- Preview is a signed iframe (the signature stops a tenant previewing another
  tenant's draft once tenancy exists).
- Publishing snapshots the block tree into `pages.published_snapshot`, so a
  later edit cannot retroactively change what a visitor saw.
- The storefront renders the **same** block definitions the builder uses. There is
  a known risk here: a separate `BlockPreview` mini-renderer exists alongside the
  iframe preview, which can drift. Tracked debt.

**Not yet:** freeform canvas positioning, responsive per-breakpoint overrides,
multi-user editing. The current editor is structured-blocks, not Figma.

## 7. Extension points for planned work

These are the seams intended for future modules. None is implemented.

| Planned capability | Intended seam | Blocking decision |
|---|---|---|
| Multi-tenancy | `tenant_id` on every table + RLS + tenant resolution in session middleware | Owner approval of option 1 |
| Stock ledger | Append-only `stock_movements`; `products.stock_qty` becomes derived | — |
| Procurement | New tables; hooks at order approval | — |
| Production | New tables; hooks at order confirmation | — |
| Automation | Trigger → condition → action engine, needs durable queue | Hosting decision |
| Integrations | Central gateway with encrypted per-tenant credentials | Tenancy (credentials are tenant-owned) |

**Ordering matters.** Integrations and automation both need tenant-scoped
credentials and a job runner. Building them first would mean rebuilding them.

## 8. Deliberate non-goals

Recorded so they are not mistaken for omissions:

- No microservices. One deployable unit is the right size for this product.
- No GraphQL. 103 REST routes with one consumer do not justify it.
- No ORM. The adapter already abstracts three engines; an ORM would abstract a
  fourth thing that does not exist.
- No SSR until a measured requirement appears (see tech-stack decisions §7).
