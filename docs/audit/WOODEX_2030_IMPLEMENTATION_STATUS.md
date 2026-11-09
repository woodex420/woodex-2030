# WOODEX 2030 — Implementation Status

**Date:** 2026-11-09 · **Commit:** `f583ac0` · Companion to `00-CURRENT-STATE.md` (authoritative) and the tracker (planning).

> **Note (post-`f583ac0`):** migration `008_audit_log.sql` adds `audit_logs`.
> Counts in this document describe `f583ac0`; current counts live in
> [`00-CURRENT-STATE.md`](../agency-os/00-CURRENT-STATE.md) — now
> **32 tables, 31 CHECKs, 8 migrations, 108 routes**.

This file answers one question: **for each module, what is done, what is not,
and what would prove it done?**

## Legend

`V` verified by gate · `P` partial · `N` none · `B` blocked on owner
A module is only `V` when its database, API, permissions *and* tests all exist.

| Module | DB | API | UI | Auth | Tests | Status |
|---|---|---|---|---|---|---|
| Products / catalogue | V | V | V | V | V | **Done** |
| Quotes + versions | V | V | V | V | V | **Done** |
| Clients / leads / tasks | V | V | V | V | V | **Done** |
| Orders | V | V | V | V | V | **Done** (no cart) |
| Returns | V | V | V | V | V | **Done** |
| Invoices / payments | V | V | V | V | V | **Partial** — no gateway, no reconciliation |
| Projects / BOQ / files | V | V | V | V | V | **Done** |
| CMS / pages / blocks | V | V | V | V | V | **Done** |
| Page versions / rollback | V | V | V | V | V | **Done** |
| Page builder (drag-drop) | P | P | P | V | P | **Partial** — no dnd, no layers |
| Stock ledger | V | V | V | V | V | **Done** |
| Stock reservations | V (table) | N | N | — | N | **Not written** |
| Warehouses | N | N | N | — | N | **None** |
| Auth / sessions | V | V | V | V | V | **Done** — no reset/MFA |
| RBAC (capabilities) | P | V | V | V | V | **Partial** — roles fixed |
| Audit log | N | N | N | — | N | **None** — JSON blobs only |
| Analytics | P | V | V | V | P | **Partial** — single rollup |
| Inbox / messaging | P | V | V | V | P | **Partial** — manual only |
| Themes | V | V | V | V | P | **Done** |
| Procurement | N | N | N | — | N | **None** |
| Production / BOM | N | N | N | — | N | **None** |
| Quality control | N | N | N | — | N | **None** |
| Delivery / POD | P | P | P | V | N | **Partial** — status only |
| Support / warranty | N | N | N | — | N | **None** |
| Tenancy / RLS | N | N | N | — | N | **None** |
| Platform admin | N | N | N | — | N | **None** |
| Integrations | N | N | N | — | N | **None** |
| Automation / AI | N | N | N | — | N | **None** |
| Queues | N | N | N | — | N | **None** |

## Honest summary

**Nine modules are genuinely complete** — catalogue, quotes, CRM, orders,
returns, projects/BOQ, CMS, page versions and the stock ledger — each with real
persistence, authorization and tests.

**Six are partial**, and in each case the missing half is a *specific, named
thing* rather than general incompleteness: no cart, no payment gateway, no
drag-and-drop, no configurable roles, no reservation writes, no ready-warehouses.

**Fourteen do not exist.** Every one of them is a new bounded context, not a
repair: tenancy, platform admin, procurement, production, QC, support,
warehouses, integrations, automation, queues.

The distinction that matters for planning: repairing a partial module is
measured in days; adding a bounded context is measured in weeks. The gap list is
therefore *not* uniform, and a plan that treats it as uniform will be wrong.

## What would flip each partial to done

| Module | The one missing thing | Proof it is done |
|---|---|---|
| Finance | Payment gateway + reconciliation | A real payment recorded from a provider callback, with an idempotency test |
| Page builder | dnd + single renderer | Delete `BlockPreview`; drag a block; assert published output equals preview |
| RBAC | Configurable roles | Create a custom role via API, assert its permissions gate a route |
| Inventory | Reservation writes | Two concurrent checkouts, one must fail on reserved stock |
| Inbox | Provider adapter | A real send with a signature-verified webhook |
| Analytics | Reporting layer | A metric that cannot be produced from one table |
