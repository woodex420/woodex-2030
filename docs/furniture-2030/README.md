# WOODEX Furniture 2030 on this repository

This folder records the owner's decisions of 2026-11-16, an independent audit of this code base, and the plan to launch the Furniture business on it.

## What changed on 2026-11-16

The owner decided that WOODEX Furniture 2030 is built on **this repository** (Express API, React dashboard, React storefront). It is not a Laravel project. The Laravel route and a separate repository are on hold. The launch target is a **Hostinger Business** plan of its own, with **cash on delivery** as the only payment method at launch.

That confirms standing decision D-01 ("do not rewrite") in [`../agency-os/02-TECH-STACK-DECISIONS.md`](../agency-os/02-TECH-STACK-DECISIONS.md) and answers hosting question D-2 in the [tracker](../WOODEX_2030_MASTER_IMPLEMENTATION_TRACKER.md).

## Standing of this folder

- [`../../AGENTS.md`](../../AGENTS.md) still comes first: its traps and its definition of done apply to all work planned here.
- For facts about what the code does, [`../agency-os/00-CURRENT-STATE.md`](../agency-os/00-CURRENT-STATE.md) stays authoritative. Where the audit here found that a claim holds on SQLite but not on PostgreSQL, that is stated with the command that shows it.
- For module status, the [tracker](../WOODEX_2030_MASTER_IMPLEMENTATION_TRACKER.md) stays the record. This folder adds the launch scope, the owner decisions and the defects that stand between the current state and a launch.

## Documents

| # | Document | What it settles |
|---|---|---|
| 01 | [Owner decisions](01-OWNER-DECISIONS.md) | What the owner decided, what is on hold, and what is still open |
| 02 | [Audit findings](02-AUDIT-FINDINGS.md) | What was verified on 2026-11-16 and the defects found, each with evidence |
| 03 | [Launch plan](03-LAUNCH-PLAN.md) | Phases, exit tests, and the proposed first phase |
| 04 | [Hostinger Business preflight](04-HOSTINGER-BUSINESS-PREFLIGHT.md) | What the plan supports, what must change, and the database choice |
| 05 | [Live site and catalogue](05-LIVE-SITE-AND-CATALOGUE.md) | State of woodexfurniture.pk and what a catalogue migration needs |
| 06 | [Phase 1 report](06-PHASE-1-REPORT.md) | What Phase 1 fixed, how each fix is proven, what is still open, and what the owner must do |

Generated inventories, re-derived from the code by a script:

| Inventory | Content |
|---|---|
| [API routes](inventory/api-routes.md) | All 214 routes with the access rule the server applies |
| [Roles and capabilities](inventory/roles-and-capabilities.md) | The 7 roles and what each can reach |
| [Database tables](inventory/database-tables.md) | All 71 tables with columns |
| [Dashboard screens](inventory/dashboard-screens.md) | Dashboard routes with the browser render result |
| [Storefront routes](inventory/storefront-routes.md) | Storefront routes and the sitemap list |

[`evidence/README.md`](evidence/README.md) explains how to re-run every check quoted in these documents.

## Status

**Phase 1 is delivered** (2026-11-16): see the [report](06-PHASE-1-REPORT.md). It fixed the defects that stood between a green gate and working software — the PostgreSQL failures, the data-losing copy tool, the two crashing screens — and added the checks that would have caught them.

Two things are waiting for the owner, both in [01](01-OWNER-DECISIONS.md): change the production password (Q-1), and make the repository private (Q-2). Phase 2, the real catalogue, starts only when the owner approves it and has answered Q-4 and Q-5.

The audit in [02](02-AUDIT-FINDINGS.md) and the inventories describe commit `9b8cdca`, before Phase 1. They are kept as the record of what was found; the status table at the top of 02 says where each finding stands now.
