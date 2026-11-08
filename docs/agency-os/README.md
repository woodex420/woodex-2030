# WOODEX OS — Engineering Specifications

The authoritative engineering contract for this repository. Written to replace a
set of product documents that described an intended system rather than the one
that exists — a drift that misled a full architecture review.

## Read in this order

| # | Document | What it settles |
|---|---|---|
| 00 | [Current State](00-CURRENT-STATE.md) | **What actually exists**, with measured numbers. The source of truth. |
| 01 | [Architecture](01-ARCHITECTURE.md) | How the system is shaped, why, and the four failure modes now guarded. |
| 02 | [Tech Stack Decisions](02-TECH-STACK-DECISIONS.md) | What we keep, what we reject, and what would change our mind. |
| 03 | [Data and Tenancy](03-DATA-AND-TENANCY.md) | The schema, its weaknesses, and the tenancy design + acceptance tests. |
| 04 | [Roadmap](04-ROADMAP.md) | Delivery order, with a testable exit criterion per phase. |

## The one rule

> If a document here disagrees with [`00-CURRENT-STATE.md`](00-CURRENT-STATE.md),
> the current-state file wins and the other document is wrong.

Every number in the current-state file is re-derived from source by
`npm run docs:verify`, which runs inside the production build. Documentation
cannot silently rot here again — if the code changes and the docs do not, the
build fails.

## What this set is not

It is **not** a commitment to Next.js + Laravel. The master plan proposed that
stack; the November 2026 review recommended evolving the existing Express + Vite
monolith, and [`02-TECH-STACK-DECISIONS.md`](02-TECH-STACK-DECISIONS.md) explains
why, with the explicit conditions that would overturn it.

It is also **not** a promise that multi-tenancy is close. Tenancy is Phase E, it
depends on an integrity foundation that does not exist yet, and
[`04-ROADMAP.md`](04-ROADMAP.md) states plainly what must be true before a second
organisation is onboarded.

## Relationship to older documents

| Older document | Standing |
|---|---|
| `docs/product/MASTER-PLAN.md` | §0 marked superseded; historical reasoning retained |
| `docs/product/AGENCY-OS-MASTER-PLAN-V2.md` | Intent. Superseded by this set for decisions. |
| `docs/architecture/ARCHITECTURE.md` | Partly stale — this set supersedes it |
| `docs/operations/VERCEL-RUNBOOK-2026-11-06.md` | Historical; see the deployment facts in 00 |
| `docs/archive/**` | Frozen history. Never authoritative. |
