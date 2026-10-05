# Woodex Agency OS — Monorepo

Frontend + backend platform per `design.md` v1.0 and the MASTER-PLAN: a reimagined
storefront, an operational agency dashboard, and a shared runtime API — one repo,
one SQLite database, live data in both directions.

```
                    ┌────────────────────────────┐
  customer ───────▶ │ apps/storefront  :5174      │  woodex-reimagined (23 routes)
                    │  • catalog + configurator   │  quote request / lead / checkout
                    │  • runtime sync (overrides) │──────┐
                    └────────────────────────────┘      ▼
                    ┌────────────────────────────┐   ┌──────────────┐
  operator ───────▶ │ apps/dashboard   :5173      │◀─▶│ apps/api     │
                    │  design-system shell + 7    │   │ Express +    │
                    │  screens, kanban, approval  │   │ node:sqlite  │
                    │  gates, live ops board      │   │ :3001        │
                    └────────────────────────────┘   └──────┬───────┘
                                                            │
                            data/woodex.db  ◀── single source of truth
                            148 products · 16 materials · 7 services
                            leads · quotes · orders (persisted, live-polled)
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

## Runtime data flow

| Flow | Path |
|---|---|
| Storefront catalog | bundled `products.ts` (205-line source) **hydrated at boot + every 15 s** from `GET /api/products/overrides` — dashboard price/stock edits appear live |
| Contact form | `POST /api/leads` → dashboard CRM kanban (15 s poll, drag = `PATCH`) |
| Quote request | `POST /api/quotes` → dashboard quotation pipeline (Draft → Review → Approval → Sent, audit trail) |
| Checkout | `POST /api/orders` → dashboard operations board (stage advance = `PATCH`) |
| Catalog management | dashboard `PATCH /api/products/:id` → storefront sees overrides |

Seed: first API boot bundles `apps/storefront/src/data/{products,materials,services}.ts`
via esbuild (asset imports → image basenames) and inserts them + demo leads/quotes/orders.

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

## Supabase path (later)

The API mirrors a PostgREST-style shape. When the exact project ref + anon key +
safe preflight are confirmed (see PRD §13), point `apps/api` at Postgres (or run the
browser directly against Supabase) without touching dashboard/storefront pages.

## Security

No secrets in this repo. Never commit `sbp_` tokens, service-role keys or Stripe keys;
the public `woodex420/woodex` repo leak (MASTER-PLAN §10) must be rotated before any
live integration.
