# WOODEX 2030 — Current State Audit

**Date:** 2026-11-09

> **Note (post-`f583ac0`):** migration `008_audit_log.sql` adds `audit_logs`.
> Counts in this document describe `f583ac0`; current counts live in
> [`00-CURRENT-STATE.md`](../agency-os/00-CURRENT-STATE.md) — now
> **32 tables, 31 CHECKs, 8 migrations, 108 routes**.
**Commit audited:** `f583ac0` (`main`, merge of PR #18)
**Method:** direct inspection of source, schema, migrations and gate runs in this
repository. Every claim below cites a file path or a command result. Where
something could not be verified, it is marked **UNVERIFIED** — not assumed.

> Status vocabulary used here and in the tracker:
> `COMPLETE_VERIFIED` · `PARTIALLY_IMPLEMENTED` · `PROTOTYPE` · `PLACEHOLDER` ·
> `MOCK_DATA` · `NOT_IMPLEMENTED` · `BLOCKED` · `NEEDS_VERIFICATION`
>
> A route or component existing is **not** evidence of completeness.

---

## 1. Measured surface

| Thing | Count | Evidence |
|---|---|---|
| API route handlers | 106 | `npm run docs:verify` |
| Dashboard pages | 23 | `ls apps/dashboard/src/pages/` |
| Storefront routes | 25 | `<Route>` in `apps/storefront/src/App.tsx` |
| PostgreSQL tables | 31 | base schema + 7 migrations |
| Migrations | 7, checksummed | `schema_migrations`, `scripts/migrate.mjs:128` |
| FKs / CHECKs | 28 / 28 | `npm run test:integrity` |
| QA checks | 108, both engines | `npm run qa`, `npm run qa:pg` |
| E2E specs | 4 (Playwright) | `scripts/e2e-*.mjs` |
| Prerendered routes | 76 | `npm run vercel:build` |

## 2. Module status — evidence-based

| Module | Status | Evidence and honest limits |
|---|---|---|
| Authentication | `COMPLETE_VERIFIED` | `server.mjs:985` scrypt(32) + `timingSafeEqual`; bearer only, no cookies. Login confirmed against production. **No MFA, no password reset, no email verification** (all four searched: absent) |
| RBAC | `PARTIALLY_IMPLEMENTED` | Capability checks + role→capability map. Roles are **fixed**, not configurable; no `permissions`/`user_roles` tables; no 12-role tenant set |
| Audit logging | `PARTIALLY_IMPLEMENTED` | `audit` JSON blobs on `quotes`/`tasks` only. **No `audit_logs` platform table exists** — platform-wide who-changed-what is absent |
| CRM | `COMPLETE_VERIFIED` | `leads`, `clients`, `client_links`, `tasks`, `site_visits`; 6+6+4+3+3 routes; e2e `e2e-storefront-crm.mjs` |
| Quotations | `COMPLETE_VERIFIED` | `quotes`, `quote_versions` (9 refs), `quote_templates`; versioning + approval; 200-vs-draft transitions guarded by 409 |
| Orders | `PARTIALLY_IMPLEMENTED` | Lifecycle + tracking + returns; **no cart, no checkout session, no customer accounts** |
| Finance | `PARTIALLY_IMPLEMENTED` | `invoices`, `payments`, `returns`; status vocabulary + 28 CHECKs. **No payment gateway, no reconciliation, no credit notes/expenses** |
| Projects / BOQ | `COMPLETE_VERIFIED` | 13 routes; `projects`, `project_milestones`, `boq_lines`, `project_files`; migrator covers all four (`pg-migrate.mjs:38`) |
| CMS | `COMPLETE_VERIFIED` | `pages`, `page_blocks`, `page_versions`, `saved_sections`; publish + rollback; e2e `e2e-page-builder.mjs` |
| Page Builder | `PARTIALLY_IMPLEMENTED` | Typed block editor, ordering, signed iframe preview, versions, publish/rollback. **No drag-and-drop** (no dnd library in `apps/dashboard/package.json`), no layers panel, no responsive inspector, no freeform canvas. `BlockPreview` (line 55) coexists with the iframe (line 414) → preview-drift risk |
| Inventory | `PARTIALLY_IMPLEMENTED` | `stock_movements` ledger + `stock_reservations`, reconcile endpoint, `test:stock` gate. **One implicit warehouse** (no `warehouses` table); **`stock_reservations` is never written** (documented deferral) |
| Procurement | `NOT_IMPLEMENTED` | No `suppliers`, `purchase_requests`, `purchase_orders`, `grn` tables or routes |
| Production / Manufacturing | `NOT_IMPLEMENTED` | No BOM, job cards, work orders or stages |
| Quality Control | `NOT_IMPLEMENTED` | No inspection records or checklists |
| Delivery / Installation | `PARTIALLY_IMPLEMENTED` | Order status + `track_events` public tracking only. No dispatch, POD, installation or sign-off |
| Support / Warranty | `NOT_IMPLEMENTED` | No ticket or warranty tables |
| Multi-tenancy | `NOT_IMPLEMENTED` | `tenant_id` and RLS appear **only inside a SQL comment** (`schema.postgres.sql:185-187`). Zero tables, zero columns, zero policies |
| Platform Admin | `NOT_IMPLEMENTED` | No `tenants`, `subscriptions`, `feature_entitlements` tables |
| Integrations | `PARTIALLY_IMPLEMENTED` | `inbox`/`conversations`/`messages` are manual logging. No provider adapters, no webhook signature validation |
| Analytics | `PARTIALLY_IMPLEMENTED` | `/api/stats` rollup from real rows. No cross-module reporting layer |
| Automation / AI | `NOT_IMPLEMENTED` | No workflow engine, no queue, no agent scaffolding |
| Queues / Realtime | `PARTIALLY_IMPLEMENTED` | SSE in persistent runtime, polling fallback, SSE disabled on Vercel. **No durable queue** |
| Deployment (Vercel) | `COMPLETE_VERIFIED` | `dpl_BHkbaV27NVdZLmijHwQe66samoq1` READY/PROMOTED for `f583ac0`; live API answered during this audit |
| Deployment (Hostinger) | `UNVERIFIED` | Documented shape only; never executed on the account |

## 3. Verification evidence actually run

| Gate | Result |
|---|---|
| `npm run docs:verify` | Documentation matches the code |
| `npm run qa` | 108/108 (SQLite) |
| `npm run qa:pg` | 108/108 (PostgreSQL rehearsal) |
| `npm run test:integrity` | 28/28 FKs, 28/28 CHECKs |
| `npm run test:audit` | 14/14, mutation-tested |
| `npm run typecheck:all` | clean |
| `npm run secret-scan` | clean (406 files) |
| `npm run vercel:build` | exit 0, 76 routes |
| Production DB (hand-checked) | 28 FKs, 0 unvalidated, 28 CHECKs |
| Production API (live) | `/api/health` ok; `?limit=2` → 2 items; `?limit=abc` → 400 |

### What is *not* verified

- `audit:schema` has **never run through the real `pg` driver** against production — only embedded pglite. Its failure branches were verified against real `pg`; the success path was not.
- No browser-driven journey was executed against production in this audit.
- Hostinger was not touched.

## 4. Corrections to the previous review (2 Nov baseline)

The review supplied with this task audits `61b1cd9` (PR #5). Several findings
are **already resolved** and should not be re-fixed:

| Previous finding | Actual state at `f583ac0` |
|---|---|
| Migrator omits `projects`, `project_milestones`, `boq_lines`, `project_files` | **Fixed** — all four in `pg-migrate.mjs:38`, guarded by `check:migration` |
| Sidebar says "Projects (Prototype)" | **Fixed** — no occurrence |
| No FKs or CHECK constraints | **Fixed** — 28/28, enforced by `test:integrity` |
| No ordered migration ledger | **Fixed** — `schema_migrations` with sha256, 7 rows |
| README claims 87 routes / React 18 / 96 checks | **Fixed** — React 19 · Vite 8, 108 checks |
| MASTER-PLAN claims `main` is one file | **Fixed** — explicit `SUPERSEDED` banner |
| Vercel runbook says no deployment / no env vars | **Fixed** — banner added this branch (`4d8ae67`) |

Findings that **remain true**: no tenancy/RLS, in-memory rate limiting,
no scheduled offsite backup, no payment gateway, no procurement/production/QC/
support.
