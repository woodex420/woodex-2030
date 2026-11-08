# Docs index

One canonical document per question. If two files disagree, the older one is in
`archive/` and is historical only.

## Start here

**[../AGENTS.md](../AGENTS.md)** — rules, traps, and the definition of done. Read before
touching code.

## Product — what we are building
| Document | Status |
|---|---|
| [MASTER-PLAN.md](product/MASTER-PLAN.md) | **The plan.** Measured state, phases, decisions, progress log |
| [PRD-AGENCY-OS.md](product/PRD-AGENCY-OS.md) | Requirements source |
| [AGENCY-OS-MASTER-PLAN-V2.md](product/AGENCY-OS-MASTER-PLAN-V2.md) | Superseded — history |
| [DASHBOARD-IA-AND-SALES-CRM-PLAN-2026-10-05.md](product/DASHBOARD-IA-AND-SALES-CRM-PLAN-2026-10-05.md) | Superseded — history |
| [NEXT-PHASE-DELIVERY-PLAN-2026-10-06.md](product/NEXT-PHASE-DELIVERY-PLAN-2026-10-06.md) | Superseded — history |

## Architecture — how it is built
| Document | Status |
|---|---|
| [ARCHITECTURE.md](architecture/ARCHITECTURE.md) | **Canonical** system map and data flows |
| [DECISION-LOG.md](architecture/DECISION-LOG.md) | **Canonical** decision record (D-001 … D-034) |
| [POSTGRES-CUTOVER.md](architecture/POSTGRES-CUTOVER.md) | Canonical cut-over runbook |
| [CMS-GAP-AUDIT-2026-10-05.md](architecture/CMS-GAP-AUDIT-2026-10-05.md) | Reference — CMS gap analysis |

## Audits — what was found
| Document | Status |
|---|---|
| [docs/audits/WOODEX-2030-STACK-AUDIT-2026-11-08.md](audits/docs/audits/WOODEX-2030-STACK-AUDIT-2026-11-08.md) | **Latest** — full-stack audit; §11 lists what was fixed and verified |
| [WOODEX-2030-MASTER-AUDIT-REPORT-2026-11-06.md](audits/WOODEX-2030-MASTER-AUDIT-REPORT-2026-11-06.md) | Previous audit — §10 supersedes §§1–9; blockers #1–#4 are now closed |
| [QA-REPORT-2026-10-05.md](audits/QA-REPORT-2026-10-05.md) | Historical QA counts (superseded: current suite is 96 checks) |
| [PHASE0-AUDIT-REPORT-2026-10-05.md](audits/PHASE0-AUDIT-REPORT-2026-10-05.md) | Historical — discovery snapshot |
| [PHASE0-AUDIT-AND-PLAN-V2.md](audits/PHASE0-AUDIT-AND-PLAN-V2.md) | Historical — discovery + plan |

## Operations — how to run and ship it
| Document | Status |
|---|---|
| [QUICK-TASKS.md](operations/QUICK-TASKS.md) | **Live backlog** — shipped vs open, owner-gated items marked 🔑 |
| [VERCEL-RUNBOOK-2026-11-06.md](operations/VERCEL-RUNBOOK-2026-11-06.md) | Deployment, database and bootstrap-owner runbook |

## Archive — superseded, kept for traceability
Nothing here is authoritative. Do not cite these as current.

| Document | Why archived |
|---|---|
| [AGENCY-OS-MASTER-PLAN-v3.md](archive/AGENCY-OS-MASTER-PLAN-v3.md) | Superseded by V2 (D-033) |
| [AGENCY-OS-WOODEX-REIMAGINED-TWO-PHASE-PLAN.md](archive/AGENCY-OS-WOODEX-REIMAGINED-TWO-PHASE-PLAN.md) | Two-phase plan superseded by the monorepo delivery |
| [WOODEX-REIMAGINED-FEATURE-GAP-MATRIX.md](archive/WOODEX-REIMAGINED-FEATURE-GAP-MATRIX.md) | Gap matrix for the imported reference app, which has been removed from the tree |
| [storefront-blueprint/](archive/storefront-blueprint/) | 34-file Lovable-era design/IA blueprint for the storefront; the shipped UI is the source of truth now |
