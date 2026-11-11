# Docs index

One canonical document per question. If two files disagree, the older one is in
`archive/` and is historical only.

## Start here

**[../AGENTS.md](../AGENTS.md)** — rules, traps, and the definition of done. Read before
touching code.

## Engineering specifications — start here
| Document | Status |
|---|---|
| [agency-os/README.md](agency-os/README.md) | **Authoritative engineering contract.** Read in order 00→04 |
| [agency-os/00-CURRENT-STATE.md](agency-os/00-CURRENT-STATE.md) | **What actually exists**, measured. Wins every disagreement |
| [agency-os/01-ARCHITECTURE.md](agency-os/01-ARCHITECTURE.md) | System shape, and the four guarded failure modes |
| [agency-os/02-TECH-STACK-DECISIONS.md](agency-os/02-TECH-STACK-DECISIONS.md) | Keep / reject / revisit, with reasons |
| [agency-os/03-DATA-AND-TENANCY.md](agency-os/03-DATA-AND-TENANCY.md) | Schema, integrity gaps, tenancy design + tests |
| [agency-os/04-ROADMAP.md](agency-os/04-ROADMAP.md) | Delivery order and exit criteria |

## Product — what we are building
| Document | Status |
|---|---|
| [MASTER-PLAN.md](product/MASTER-PLAN.md) | Historical plan — §0 superseded; see `agency-os/` for decisions |
| [PRD-AGENCY-OS.md](product/PRD-AGENCY-OS.md) | Requirements source |
| [AGENCY-OS-MASTER-PLAN-V2.md](product/AGENCY-OS-MASTER-PLAN-V2.md) | Superseded — history |
| [DASHBOARD-IA-AND-SALES-CRM-PLAN-2026-10-05.md](product/DASHBOARD-IA-AND-SALES-CRM-PLAN-2026-10-05.md) | Superseded — history |
| [NEXT-PHASE-DELIVERY-PLAN-2026-10-06.md](product/NEXT-PHASE-DELIVERY-PLAN-2026-10-06.md) | Superseded — history |

## Architecture — how it is built
| Document | Status |
|---|---|
| [ARCHITECTURE.md](architecture/ARCHITECTURE.md) | Partly stale — superseded by `agency-os/01-ARCHITECTURE.md` |
| [DECISION-LOG.md](architecture/DECISION-LOG.md) | **Canonical** decision record (D-001 … D-034) |
| [POSTGRES-CUTOVER.md](architecture/POSTGRES-CUTOVER.md) | Canonical cut-over runbook |
| [CMS-GAP-AUDIT-2026-10-05.md](architecture/CMS-GAP-AUDIT-2026-10-05.md) | Reference — CMS gap analysis |

## Audits — what was found
| Document | Status |
|---|---|
| [docs/audits/WOODEX-2030-STACK-AUDIT-2026-11-08.md](audits/WOODEX-2030-STACK-AUDIT-2026-11-08.md) | **Latest** — full-stack audit; §11 lists what was fixed and verified |
| [WOODEX-2030-MASTER-AUDIT-REPORT-2026-11-06.md](audits/WOODEX-2030-MASTER-AUDIT-REPORT-2026-11-06.md) | Previous audit — §10 supersedes §§1–9; blockers #1–#4 are now closed |
| [QA-REPORT-2026-10-05.md](audits/QA-REPORT-2026-10-05.md) | Historical QA counts (superseded: current suite is 107 checks) |
| [PHASE0-AUDIT-REPORT-2026-10-05.md](audits/PHASE0-AUDIT-REPORT-2026-10-05.md) | Historical — discovery snapshot |
| [PHASE0-AUDIT-AND-PLAN-V2.md](audits/PHASE0-AUDIT-AND-PLAN-V2.md) | Historical — discovery + plan |

## Operations — how to run and ship it
| Document | Status |
|---|---|
| [QUICK-TASKS.md](operations/QUICK-TASKS.md) | **Live backlog** — shipped vs open, owner-gated items marked 🔑 |
| [VERCEL-RUNBOOK-2026-11-06.md](operations/VERCEL-RUNBOOK-2026-11-06.md) | Deployment, database and bootstrap-owner runbook |

## Laravel platform — on a branch, not merged
The direction was approved by the owner step by step on 2026-11-11. The code lives in `platform/` on the working branch only.
Nothing here overrides `agency-os/` until a merge is approved and the decision log is updated.

| Document | Status |
|---|---|
| [laravel-os/00-REUSE-PROPOSAL-AND-ADMIN-MASTER-PLAN.md](laravel-os/00-REUSE-PROPOSAL-AND-ADMIN-MASTER-PLAN.md) | **Proposal** — reuse of the Laravel WOODEX OS source and the admin-upgrade master plan. Read-only inspection only; would reverse D-01 if approved |
| [laravel-os/01-PHASE-1A-PREVIEW-EVIDENCE.md](laravel-os/01-PHASE-1A-PREVIEW-EVIDENCE.md) | **Evidence** — the synthetic SQLite preview of that source, run outside this repository on 2026-11-11. Not MySQL proof, not a browser test |
| [laravel-os/02-PHASE-1B-FOUNDATION.md](laravel-os/02-PHASE-1B-FOUNDATION.md) | **Evidence** — the reviewed copy in `platform/` on this branch: import, module switches, test triage, MySQL results from CI, real-browser pass, the UTC audit, and what is still Interior-shaped |
| [laravel-os/03-MIGRATION-REGISTER.md](laravel-os/03-MIGRATION-REGISTER.md) | **Review record** — all 72 migrations, 21 settings migrations and the 11 quarantined ones, each read before use |

## Archive — superseded, kept for traceability
Nothing here is authoritative. Do not cite these as current.

| Document | Why archived |
|---|---|
| [AGENCY-OS-MASTER-PLAN-v3.md](archive/AGENCY-OS-MASTER-PLAN-v3.md) | Superseded by V2 (D-033) |
| [AGENCY-OS-WOODEX-REIMAGINED-TWO-PHASE-PLAN.md](archive/AGENCY-OS-WOODEX-REIMAGINED-TWO-PHASE-PLAN.md) | Two-phase plan superseded by the monorepo delivery |
| [WOODEX-REIMAGINED-FEATURE-GAP-MATRIX.md](archive/WOODEX-REIMAGINED-FEATURE-GAP-MATRIX.md) | Gap matrix for the imported reference app, which has been removed from the tree |
| [storefront-blueprint/](archive/storefront-blueprint/) | 34-file Lovable-era design/IA blueprint for the storefront; the shipped UI is the source of truth now |

- [Agency OS → Laravel gap register, Soon navigation and API v1 plan](laravel-os/05-GAP-REGISTER-AND-NEXT-UPGRADE.md)

- [Storefront lead adapter rehearsal and cutover gates](laravel-os/06-STOREFRONT-LEAD-ADAPTER.md)

- [Storefront v1 anti-spam implementation and provider gates](laravel-os/07-STOREFRONT-ANTISPAM.md)

- [Secure v1 manual WhatsApp handoff](laravel-os/08-SECURE-WHATSAPP-HANDOFF.md)

- [Basket quote-request foundation and catalogue gates](laravel-os/09-BASKET-QUOTE-REQUESTS.md)

- [Commerce categories and catalogue audit](laravel-os/10-COMMERCE-CATEGORIES-AND-CATALOGUE-AUDIT.md)
