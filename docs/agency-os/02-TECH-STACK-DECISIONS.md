> **2026-11-11 update:** D-01 below is superseded for the future admin/backend direction by the owner-approved Laravel reuse plan and Phase 2 approval. The existing Express API/dashboard remain operational until module-by-module replacement is verified; React storefront stays. See [reuse plan](../laravel-os/00-REUSE-PROPOSAL-AND-ADMIN-MASTER-PLAN.md). This is not a deployment or cutover approval.

# WOODEX OS — Technology Decisions and Deviation Record

**Status:** proposed. Decisions here are recommendations for the owner to approve;
they are not implemented unless [`00-CURRENT-STATE.md`](00-CURRENT-STATE.md) says so.

**Date:** 2026-11-08
**Supersedes:** the aspirational migration language in `docs/product/MASTER-PLAN.md`
and `docs/architecture/ARCHITECTURE.md`, which pre-date the working deployment.

---

## 1. Why this document exists instead of a rewrite

The master plan recommends Next.js + Laravel + PostgreSQL. The November 2026
architecture review recommended the opposite, and its reasoning is sound:

> "The current implementation is a functioning foundation. Replacing it
> immediately with Next.js and Laravel would introduce migration cost and
> operational risk while important existing business workflows and deployment
> controls are still being stabilized."

A rewrite is an option we retained, not a decision we made. It costs ~4–6 months
before the first business feature ships, and it puts the 148-product catalogue,
the CRM records and the quotation history through a migration whose correctness
we would then have to prove separately. It buys SSR, a mature queue system and a
richer ORM — none of which is currently the bottleneck.

**Decision D-01: do not rewrite. Evolve the existing Express + Vite modular
monolith until a concrete requirement defeats it.** The criteria that would
overturn this are listed in §7.

## 2. Stack: decided, rejected, deferred

| Concern | Decision | Rationale |
|---|---|---|
| Frontend framework | **Keep React 19 + Vite 8** (not Next.js) | 76 routes prerender today; SEO is covered. SSR is not currently required. |
| Backend | **Keep Express 4** (not Laravel) | 103 routes and the whole RBAC model already work and are tested. |
| Database | **PostgreSQL** — already the production engine | Mandatory, and already true. |
| ORM | **None — custom SQL + adapter** | One dialect, three engines already implemented and rehearsed. Adding an ORM now would mean re-proving every query. |
| Auth | **Existing bearer sessions + scrypt** | Working, verified live. |
| Authorization | **Capability checks + role mapping** | Works. Needs tenant dimension (see §4). |
| Realtime | **SSE**, polling fallback on Vercel | Working. No WebSocket server on serverless. |
| Queues | **Deferred** — no durable queue | Nothing today needs one. Add when automation lands. |
| Cache | **Deferred** — no Redis | Single critical path is fast; no measured need. |
| Search | **PostgreSQL full-text** when needed | Not yet needed at 148 products. |
| File storage | **Deferred** — metadata/URL references only | Real upload/storage is a gap, tracked. |
| Testing | **Node test scripts + jsdom** | 108 QA checks, both engines, plus 4 build guards. |
| CI/CD | **GitHub Actions** | In place. |
| Hosting | **Vercel (current)** vs **Hostinger VPS (documented)** — *undecided* | See §5. |

### 2.1 On the framework question

Next.js is genuinely better than Vite for this product at scale — per-request
HTML, streaming, and a server runtime that does not need a separate Express
process. It is also a *second* migration on top of tenancy.

The honest position: adopting Next.js now would mean rewriting 29 dashboard
routes and 25 storefront routes while also adding tenancy to a backend that is
about to change shape. Doing both at once is how migrations fail.

**If Next.js is adopted, do it after tenancy is proven, and migrate the admin
first** — the admin is authenticated and cannot be crawled, so a botched rollout
does not damage SEO.

### 2.2 On Laravel specifically

Laravel's advantages are Eloquent, queues, Sanctum and Reverb. The existing code
already has sessions, RBAC and an API that passes 108 checks. The cost of moving
is a full re-implementation of 103 routes in PHP, plus the loss of the current
deployment pipeline.

**Do not run Express and Laravel against the same business tables.** The review
is right that concurrent write ownership of the same records is the failure mode
that destroys data. If Laravel is ever adopted, it is a module-by-module
replacement behind a routing layer, with exactly one writer per table at a time.

## 3. Deviations from the master plan, stated plainly

| Master plan says | We are doing | Why |
|---|---|---|
| Next.js frontend | Vite + React | Works; SSR not required yet |
| Laravel backend | Express | Works; 108 passing checks |
| Eloquent ORM | Custom SQL | Already supports 3 engines |
| Redis | Not yet | No measured need |
| Laravel Reverb / WebSockets | SSE + polling | Serverless has no socket server |
| shadcn/ui | Custom components + some Radix (storefront) | Dashboard components already exist |
| Tailwind one version | **Three and four, split across apps** | **Real debt — see §6** |
| Multi-tenant shared schema + RLS | Not started | Next major project — see §4 |

None of these are hidden. Each is either a deliberate simplification or tracked
debt, and the current-state file is the authority on which is which.

## 4. The next architectural decision: tenancy

This is the gate for every multi-company feature, and it is not a UI change.

Options considered:

1. **Shared schema + `tenant_id` + PostgreSQL RLS** (master plan's choice).
   Cheapest for many small tenants. Highest risk: one missing predicate leaks
   data. RLS is the safety net that makes it defensible.
2. **Schema-per-tenant.** Stronger isolation, simpler queries, harder migrations
   at scale (hundreds of schemas).
3. **Database-per-tenant.** Strongest isolation, highest operational cost.
   Appropriate for a handful of large organisations.

**Recommendation: option 1, with RLS mandatory from the first migration, not
added later.** Retrofitting RLS onto tables that already contain mixed-tenant rows
is dramatically harder than starting with it.

**Non-negotiable rules if option 1 is approved:**

- Tenant context comes from the authenticated session, **never** from a request
  body, query parameter or header.
- Every tenant-owned table gets `tenant_id NOT NULL` and an index leading with it.
- RLS policies are enabled **and forced** on those tables.
- A test suite must attempt cross-tenant reads and writes and prove they fail.
  Without that test, tenancy is a claim, not a control.
- No tenant onboarding until that test is green.

## 5. Hosting: the open decision

| Option | Fit | Concern |
|---|---|---|
| **Vercel (current)** | Excellent for the storefront and API | Serverless: no SSE, no long jobs, in-process rate limits do not hold |
| **Hostinger VPS** | Full Node process, SSE, cron, queues | We become the sysadmin: TLS, backups, monitoring, patching |
| **Hybrid** — Vercel front, VPS worker | Best of both | Two deployments, two failure domains, more moving parts |

**Recommendation:** stay on Vercel while the product is a single tenant with
modest traffic. Move to a VPS when **any** of these becomes true: durable
background jobs are required, SSE is required in production, in-process rate
limiting is insufficient, or a single request exceeds the function timeout.

The `start:hostinger` script and `smoke:hostinger` check already exist, so the
option stays open rather than being merely aspirational.

## 6. Recognised debt

| Debt | Impact | Trigger to pay it down |
|---|---|---|
| Tailwind 3 (storefront) vs 4 (dashboard) | Cannot share design tokens; two mental models | Before any shared component library |
| No ORM | Schema changes are manual SQL | If migrations exceed ~2/month |
| No migration ledger | Drift hard to detect | Before tenancy |
| No FKs/CHECKs in most tables | Integrity is application-only | Before tenancy |
| No durable queue | Automation cannot be reliable | When automation lands |
| Metadata-only file storage | No real document management | When projects need drawings |
| In-process rate limiting | Not shared across instances | When abuse is observed |

## 7. What would overturn D-01 (no rewrite)

Revisit the decision only if a **concrete, measured** requirement appears:

1. The storefront must serve per-request personalised HTML at a volume
   prerendering cannot handle.
2. A module genuinely needs a mature queue/worker ecosystem.
3. The team adds PHP/Laravel expertise and cannot hire Node developers.
4. Tenancy proves impractical on the current stack after a real attempt.

Absent one of these, rewriting is a cost with no matching benefit. "The plan says
Next.js" is not a requirement — it is a preference, and preferences do not
survive contact with a working system.
