# WOODEX Platform — planning & coordination

Analysis and master plan for the WOODEX furniture platform: public storefront, internal admin
dashboard, Supabase backend, WhatsApp CRM, virtual showroom, and a custom page builder to replace
Elementor.

## Start here

| Document | What it answers |
|---|---|
| **[MASTER-PLAN.md](MASTER-PLAN.md)** | The plan: verified state, architecture, 18 modules, 6 phases, security findings, decisions, next 10 actions |
| [docs/PROJECT-ANALYSIS.md](docs/PROJECT-ANALYSIS.md) | Full technical inventory of all four repos, with every figure measured |
| [docs/DATABASE-INVENTORY.md](docs/DATABASE-INVENTORY.md) | All **45 tables** with columns, keys and references |
| [docs/PAGE-BUILDER-SPEC.md](docs/PAGE-BUILDER-SPEC.md) | The Elementor replacement: data model, block library, editor UX, renderer, build phases |
| [docs/DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md) | Token architecture, the zero-visual-diff token bridge, TailAdmin mapping |
| [WORKSPACE.md](WORKSPACE.md) | Where each checkout lives and how to restore trimmed areas |

## The three headline findings

1. **Two codebases are being planned as one.** The storefront (`woodex-reimagined`: React 18.3, Tailwind 3.4, 27 Radix packages, 23 routes) and the dashboard (`woodex420/woodex@woodex-admin`: 13 pages, 5,350 LOC, TailAdmin tokens) need separate upgrades.
2. **No path to the database from this environment.** `api.supabase.com` *and* `*.supabase.co` are both unreachable, so the `sbp_…` token cannot be used here. The schema in these docs was parsed from migration files, not read from the live database. Unblocking options are in MASTER-PLAN §3.
3. **Real secrets are committed to a public repo** — Stripe secret keys and a credentials file listing every integration secret. Rotate before further development: MASTER-PLAN §10.

## Verified good news

The backend is far more complete than usual at this stage: **45 tables, 106 RLS policies, 32 edge
functions, 8 storage buckets**, covering quotations, orders, deliveries, returns, inventory, WhatsApp
CRM, showroom and analytics. `user_permissions` and `user_activity_log` already exist for RBAC and
auditing — they are simply unused. Much of this plan is wiring, not inventing.
