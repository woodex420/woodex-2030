# Woodex 2030 — Agency OS

One monorepo for the Woodex furniture business: a public **storefront**, an
operator **dashboard**, and a single **API** that both read and write.

```
                     ┌──────────── single Vercel project ────────────┐
 customer ─────────▶ │ /              storefront   (Vite · React)     │
 operator ─────────▶ │ /dashboard/*   dashboard    (Vite · React)     │
                     │ /api/*         API          (Express · Node)   │
                     │ /img/*         shared catalogue photography    │
                     └───────────────────────┬───────────────────────┘
                                             ▼
                                  PostgreSQL (DATABASE_URL)
                                  local development: SQLite
```

Both front ends and the API share **one catalogue, one CRM, one quote/order
ledger**. Editing a price in the dashboard is visible on the storefront within
15 seconds — no rebuild, no second copy of the data.

---

## Repository layout

```
apps/
  api/            Express API — 103 routes, SQLite · PGlite · PostgreSQL drivers
    server.mjs      routes, schema, RBAC guard, demo seed
    db-adapters.mjs driver facade (one SQL dialect, three engines)
    shop-data.mjs   loads the catalogue snapshot (esbuild only as a dev fallback)
    seed-data.json  committed catalogue snapshot — what production actually seeds
    public/img/     the only copy of every photograph (92 files)
  dashboard/      operator app — 29 routes, React 19 · Vite 8 · Tailwind 4
  storefront/     customer app — 25 routes, React 19 · Vite 8 · Tailwind 3
    src/assets/img.ts   the one helper that builds /img URLs
api/              Vercel serverless entry (index.mjs) + sitemap
scripts/          QA battery (108 checks), e2e specs, PG migrator, deploy & doc guards
docs/             see docs/README.md — product · architecture · audits · operations · archive
vercel.json       install/build/output + SPA rewrites
```

---

## Run it locally

```bash
npm install --legacy-peer-deps      # once, at the repo root (npm workspaces)

npm run dev:api                     # :3001  API — seeds SQLite on first boot
npm run dev:dashboard               # :5173  operator app  → proxies /api, /img
npm run dev:storefront              # :5174  customer app  → proxies /api, /img
```

The database file lives at `data/woodex.db` (git-ignored). Delete it to reseed.
The seed is local-only: production never creates demo accounts or customers.

Open the two apps:

| App | URL | Sign in |
|---|---|---|
| Storefront | http://localhost:5174 | public |
| Dashboard | http://localhost:5173 | `admin` / `admin` (local demo only) |

Demo team so each role can be exercised: `ayesha@woodex.pk` (editor),
`bilal@woodex.pk` (sales), `farhan@woodex.pk` (finance), `guest@woodex.pk`
(viewer) — password `woodex123` for all. **Local only; never seeded in production.**

### Preview hosts (ports 3001 / 5174)

When the operator app must be exposed on port `3001`, move the API to `3002`:

```bash
npm run dev:preview:api             # API :3002
npm run dev:preview:dashboard       # dashboard :3001 → :3002
npm run dev:preview:storefront      # storefront :5174 → :3002
```

---

## Verify it

```bash
npm run qa               # 108 checks: auth, RBAC, public surface, CRUD, CMS, perf, backups
npm run qa:pg            # the same battery against PostgreSQL semantics (PGlite)
npm run typecheck:all    # both apps
npm run smoke:render     # both apps actually mount (catches duplicate-React white screens)
npm run vercel:build     # storefront + dashboard + img in dist/, 76 routes prerendered
npm run smoke:hostinger  # all-in-one deployment serves real HTML (needs a build first)
npm run secret-scan      # credential pattern scan over every tracked file
```

Current state: **107/107 on SQLite and 107/107 on PGlite.** The full
definition-of-done and the traps that make each command non-optional are in
[`AGENTS.md`](AGENTS.md) — read that before changing anything.

What was fixed and what is still open is recorded in
`docs/product/MASTER-PLAN.md` (the only plan) and §8 of it for the progress log.

---

## How imagery works

Photographs are stored **once**, in `apps/api/public/img`, and served at `/img/*`.
Storefront code calls `img("product-office-chair.jpg")`; the API returns the same
`/img/...` path when it seeds the catalogue, so both apps and the database agree
on one URL per image. Vite proxies `/img` in development and the Vercel build
copies the files to `dist/img`.

## Runtime data flow

| Flow | Path |
|---|---|
| Catalogue | `GET /api/products` (hydrated at boot, refreshed on a 15 s poll) |
| Price/stock edit | dashboard `PATCH /api/products/:id` → storefront picks it up live |
| Contact form | `POST /api/leads` → CRM pipeline (client record, task, inbox thread) |
| Quote request | `POST /api/quotes` → priced **from the catalogue**, never from the client |
| Checkout | `POST /api/orders` → operations board + invoice + a hashed tracking token |
| Website pages | CMS draft → validated publish → immutable snapshot at `/p/:slug` |

---

## Deploy

Two supported targets, both from the same commit:

- **Vercel** — one project: storefront at `/`, dashboard at `/dashboard`, API at
  `/api/*`, PostgreSQL through `DATABASE_URL`. Fails closed without a database
  and never seeds demo content. SSE is disabled serverless by design.
- **Hostinger shared** — recommended shape is one Node process
  (`npm run start:hostinger`) serving the prerendered build, the dashboard and
  the API together, with SQLite or Postgres.

Full steps, environment variables, post-deploy checks and backups:
**[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)**.

Canonical product plan: `docs/product/MASTER-PLAN.md`.
Canonical architecture: `docs/architecture/ARCHITECTURE.md`.
Agent contract (read first): [`AGENTS.md`](AGENTS.md).

## Security

No secrets belong in this repository, and none are tracked. Authentication is a
bearer session (scrypt password hashing, 64-hex tokens, 7-day expiry, revocable),
with a capability guard in front of every non-public route. Public write paths
(`/api/leads`, `/api/quotes`, `/api/orders`, `/api/track`) are rate limited, and
public quotation/order intake is priced server-side from the catalogue.
