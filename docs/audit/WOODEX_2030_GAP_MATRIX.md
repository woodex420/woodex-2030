# WOODEX 2030 — Gap Matrix

**Date:** 2026-11-09 · **Commit:** `f583ac0`

Gaps between the WOODEX Agency OS specification and this repository, ordered by
what actually blocks a credible release. "Spec says" quotes intent; "Repo has"
is measured.

## P0 — blocks any tenant-facing or production claim

| # | Gap | Repo has | Why it blocks | Correction |
|---|---|---|---|---|
| P0-1 | **Tenant isolation** | Nothing. `tenant_id`/RLS only in a comment (`schema.postgres.sql:185`) | The product is defined as multi-tenant. Onboarding a second company today would expose every other company's invoices, clients and stock. **This is the single largest gap.** | Tenant table + membership + server-derived tenant context + RLS policies + cross-tenant tests |
| P0-2 | **Hosted drift audit never runs** | CI step exists but `PRODUCTION_DATABASE_URL` secret is absent → emits a notice and skips | The one guard that proves production matches its migrations has never executed there. Production is otherwise verified only by hand | Create the GitHub secret (owner action — Vercel's `DATABASE_URL` is `sensitive`, unreadable) |
| P0-3 | **Platform audit log** | Per-record `audit` JSON on quotes/tasks only | No who-did-what across the platform; incident response and billing disputes are unreconstructable | `audit_logs` table + write points on sensitive mutations |

## P1 — blocks operational use by Woodex itself

| # | Gap | Repo has | Correction |
|---|---|---|---|
| P1-1 | Configurable roles & permissions | 12 fixed role names + capability map | `roles`, `permissions`, `role_permissions`, `user_roles`; keep the capability layer |
| P1-2 | Password reset / email verification / MFA | None (all four searched: absent) | Reset tokens + email adapter; MFA later |
| P1-3 | Distributed rate limiting | In-memory `Map` (`server.mjs:800`) — resets per serverless instance | Redis/Upstash or edge protection |
| P1-4 | Scheduled offsite backup | Local `backup.mjs` + restore drill only | Cron + object storage + encryption + retention |
| P1-5 | Payment gateway | Manual `payments` rows | JazzCash/Easypaisa adapter; **must not auto-approve** |
| P1-6 | Reserved stock at cart stage | `stock_reservations` table exists, **never written** | Wire reservations into cart/checkout (documented deferral — belongs with the cart rework) |

## P2 — product completeness

| # | Gap | Correction |
|---|---|---|
| P2-1 | Procurement (suppliers → requisition → PO → GRN → invoice) | New bounded context + tables + UI |
| P2-2 | Production (BOM, job cards, stages, work centres) | New bounded context; **consumer of** the existing stock ledger |
| P2-3 | Quality control (plans, inspections, rework, dispatch gate) | Gate that blocks dispatch without approval |
| P2-4 | Delivery & installation (dispatch, POD, sign-off) | Extend `orders` + `track_events` |
| P2-5 | Support & warranty | New bounded context |
| P2-6 | Page builder v2 (drag-drop, layers, responsive inspector) | No dnd library present; `BlockPreview` vs iframe drift must be resolved first |
| P2-7 | Provider-backed messaging | Replace manual inbox logging with adapters + signature verification |
| P2-8 | Warehouses / multi-location stock | `warehouses` table; make `stock_movements.warehouse_id` additive, not a rewrite |
| P2-9 | Tailwind 3 (storefront) vs 4 (dashboard) | Standardise before a shared design system |

## P3 — platform scale

| # | Gap | Correction |
|---|---|---|
| P3-1 | Platform control plane (subscriptions, entitlements, tenant admin) | Depends on P0-1 |
| P3-2 | Durable queues + workflow engine | Redis + worker; tenant-aware job context |
| P3-3 | Permission-controlled AI agents | Read-only suggestions first; never financial approvals |
| P3-4 | Versioned `/api/v1` + OpenAPI | Big-bang renames break clients — alias, then migrate |
| P3-5 | Next.js / Laravel migration | **Not recommended now.** See §3 of the tracker |

## Explicit non-goals for the next phase

The supplied specification is a full enterprise rebuild document. Implementing
it wholesale would replace a working, tested system with an untested one. The
following are deliberately **deferred**, with reasons:

- **Next.js / Laravel rewrite** — the current stack passes 108 checks and serves production. Migration without a concrete requirement is pure risk (spec §6 itself says not to force this).
- **Microservices** — spec §6.2 says modular monolith first. Agreed.
- **`/api/v1` prefix now** — 106 routes; renaming is a coordinated breaking change, not a task.
