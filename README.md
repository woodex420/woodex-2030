# Woodex Agency OS — Monorepo

Woodex 2030 is a full-stack monorepo for the Storefront, operations Dashboard,
and shared API. The canonical product plan is `docs/AGENCY-OS-MASTER-PLAN-V2.md`.
Local development uses SQLite; the Vercel target uses persistent PostgreSQL.

```
                 Single Vercel project · woodex-2030
  customer ───▶ /                 Storefront (Vite static build)
  operator ───▶ /dashboard/*      Dashboard (Vite static build)
                 /api/*           Express API (Node serverless functions)
                                       │
                                       ▼
                              PostgreSQL (DATABASE_URL)

  Local development: Storefront + Dashboard + Express API → SQLite
  Shared source of truth: catalog, CRM, quotations, orders and operations
```

## Run (three terminals, or use the scripts)

```bash
npm install --legacy-peer-deps     # once, at repo root (workspaces)
npm run dev:api                    # :3001 — seeds DB on first boot from storefront data
npm run dev:dashboard              # :5173 — proxy /api + /img → :3001
npm run dev:storefront             # :5174 — proxy /api + /img → :3001
```

Vite proxies make both apps same-origin for the browser — no CORS, works behind
the preview host. `data/woodex.db` is git-ignored (delete it to re-seed).

### Dashboard preview on port 3001

To expose the operator app directly on Arena's preview port `3001`, keep the API
on `3002` so it does not conflict with Vite. Run these in three terminals:

```bash
npm run dev:preview:api         # API :3002
npm run dev:preview:dashboard   # Dashboard :3001 → API :3002
npm run dev:preview:storefront  # Storefront :5174 → API :3002
```

The dashboard's signed page preview switches to the storefront sibling preview
host; both Vite proxy targets remain configurable through `API_PROXY_TARGET`.

## Runtime data flow

| Flow | Path |
|---|---|
| Storefront catalog | bundled `products.ts` (205-line source) **hydrated at boot + every 15 s** from `GET /api/products/overrides` — dashboard price/stock edits appear live |
| Contact form | `POST /api/leads` → dashboard CRM kanban (15 s poll, drag = `PATCH`) |
| Quote request | `POST /api/quotes` → dashboard quotation pipeline (Draft → Review → Approval → Sent, audit trail) |
| Checkout | `POST /api/orders` → dashboard operations board (stage advance = `PATCH`) |
| Catalog management | dashboard `PATCH /api/products/:id` → storefront sees overrides |

Local development seed: the API bundles `apps/storefront/src/data/{products,materials,services}.ts`
via esbuild (asset imports → image basenames) and inserts the catalog plus local-only demo records.
Vercel production does not create demo customers, orders, invoices, or demo logins; it only
idempotently inserts repository-owned public catalog rows into the configured PostgreSQL DB.

## Notable fixes applied to the storefront (per MASTER-PLAN)

- lockfile removed (was pinned to an unreachable private npm cache)
- Lovable dev-only tooling (`@lovable.dev/mcp-js`, `lovable-tagger`) removed from config
- Google-Fonts `@import` moved before `@tailwind` directives (was silently dropped)
- `server.allowedHosts` for the Arena preview proxy

## Dashboard design system

Implements `design.md` v1.0 (TailAdmin-inspired patterns, original code — §32).
Tokens in `apps/dashboard/src/index.css @theme`; screens: Overview, CRM kanban,
E-Quotation builder + approval workflow, Catalog, Projects workspace, Operations,
Analytics, Settings. Status system (§14) shared: dot + label, never color alone.

## Vercel deployment

The single Vercel project `woodex-2030` serves the Storefront at `/`, the Dashboard
at `/dashboard`, and the Express API at `/api/*`. `vercel.json` and the project
settings use the combined `npm run vercel:build` output (`dist`); see
[`docs/VERCEL-FULLSTACK-RUNBOOK-2026-11-06.md`](docs/VERCEL-FULLSTACK-RUNBOOK-2026-11-06.md)
for the database, bootstrap-owner, and release gates.

Vercel is connected to `woodex420/woodex-2030` with `main` as the production
branch. The project build settings are configured, but a persistent PostgreSQL
provider and Vercel environment variables are still required before the serverless
API can start. Production uses PostgreSQL only; it fails closed rather than using
an ephemeral SQLite file. No hosted database has been connected and no Production
deployment was made as part of this setup.

The canonical product plan is [`docs/AGENCY-OS-MASTER-PLAN-V2.md`](docs/AGENCY-OS-MASTER-PLAN-V2.md);
`docs/AGENCY-OS-MASTER-PLAN-v3.md` is retained as superseded historical traceability.

## Security

No secrets in this repo. Never commit `sbp_` tokens, service-role keys or Stripe keys;
the public `woodex420/woodex` repo leak (MASTER-PLAN §10) must be rotated before any
live integration.
