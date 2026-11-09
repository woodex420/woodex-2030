# WOODEX 2030 — Master Implementation Tracker

**Updated:** 2026-11-09 · **Baseline:** migration `008` (`audit_logs`)
**Companions:** [`00-CURRENT-STATE.md`](agency-os/00-CURRENT-STATE.md) is authoritative for facts; this file is authoritative for *plan and status*.

> Rule: a status may only move to **Verified** when a named command passes and
> its output is quoted here. A module that compiles is **Implemented**, never
> **Verified**.

---

## 1. Module tracker

| Module | Status | Backend | Frontend | Database | Tests | Priority |
|---|---|---|---|---|---|---|
| Catalogue / Products | ✅ Verified | ✅ | ✅ | ✅ | ✅ | — |
| Quotations + versions | ✅ Verified | ✅ | ✅ | ✅ | ✅ | — |
| CRM (leads/clients/tasks) | ✅ Verified | ✅ | ✅ | ✅ | ✅ | — |
| Orders | ✅ Verified | ✅ | ✅ | ✅ | ✅ | — |
| Returns | ✅ Verified | ✅ | ✅ | ✅ | ✅ | — |
| Projects / BOQ / files | ✅ Verified | ✅ | ✅ | ✅ | ✅ | — |
| CMS / pages / blocks | ✅ Verified | ✅ | ✅ | ✅ | ✅ | — |
| Page versions / rollback | ✅ Verified | ✅ | ✅ | ✅ | ✅ | — |
| Stock ledger | ✅ Verified | ✅ | ✅ | ✅ | ✅ | — |
| Authentication | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P1 (gaps) |
| Deployment (Vercel) | ✅ Verified | — | — | — | ✅ | P0 |
| Finance | 🟡 Partial | ✅ | ✅ | ✅ | ✅ | P1 |
| RBAC | 🟡 Partial | ✅ | ✅ | 🟡 | ✅ | P1 |
| Page builder | 🟡 Partial | ✅ | 🟡 | ✅ | 🟡 | P2 |
| Inventory (warehouses) | 🟡 Partial | ✅ | ✅ | 🟡 | ✅ | P2 |
| Analytics | 🟡 Partial | ✅ | ✅ | ✅ | 🟡 | P2 |
| Integrations | 🟡 Partial | 🟡 | 🟡 | 🟡 | ❌ | P2 |
| Delivery | 🟡 Partial | 🟡 | 🟡 | 🟡 | ❌ | P2 |
| **Tenancy / RLS** | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | **P0** |
| **Audit log** | ✅ Verified | ✅ | ✅ (API) | ✅ | ✅ | **P0** |
| **Hosted drift audit** | ⛔ Blocked | — | — | — | ❌ | **P0** |
| Platform admin | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P3 |
| Procurement | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P2 |
| Production / BOM | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P2 |
| Quality control | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P2 |
| Support / warranty | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P2 |
| Automation / AI | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P3 |
| Queues | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P3 |

Status values: ⛔ Not started · 🟡 Partial · ✅ Implemented · ✅✅ Verified · ⚠️ Blocked

---

## 2. Ordered work queue

Each item is sized by *kind* of work, because that is what determines duration.

| # | Task | Kind | Size | Depends on |
|---|---|---|---|---|
| 1 | `PRODUCTION_DATABASE_URL` secret | Ops | 5 min | **Owner** |
| 2 | ~~Platform `audit_logs` + write points~~ **DONE** | Schema + code | — | ✅ `008` |
| 3 | Tenant schema + context + RLS + cross-tenant tests | **Architecture** | 2–3 wk | Needs decision D-1 |
| 4 | Configurable roles/permissions | Schema + code | 1 wk | — |
| 5 | Password reset + email adapter | Code | 2–3 d | SMTP creds |
| 6 | Distributed rate limiting | Infra | 2 d | Redis/Upstash |
| 7 | Scheduled offsite backup | Infra | 1–2 d | Bucket creds |
| 8 | Cart + reservations write path | Code | 1 wk | — |
| 9 | Page builder v2 (dnd, single renderer) | Frontend | 2 wk | Tailwind decision |
| 10 | Procurement context | New module | 3–4 wk | — |
| 11 | Production + QC context | New module | 4–6 wk | 10 |
| 12 | Delivery + installation | New module | 2–3 wk | 11 |
| 13 | Support + warranty | New module | 2 wk | — |
| 14 | Provider messaging | Integration | 1–2 wk | Provider creds |
| 15 | Payment gateway | Integration | 2 wk | Merchant account |
| 16 | Monitoring + error tracking | Infra | 2 d | Account |
| 17 | Durable queues + workflow engine | Infra | 3–4 wk | Redis |

**Reading the queue:** items 1–2 are days and unblock trust. Item 3 is the
largest single piece of work in the project and gates every multi-tenant claim.
Items 10–13 are the Woodex *operations* build and cannot start until the
business spells out which stages it wants enforced — a decision, not a task.

---

## 3. Decisions required from the owner

These block work. I have proceeded on stated defaults where I reasonably could.

| # | Decision | Default if unanswered | Impact |
|---|---|---|---|
| D-1 | **Is a second company a real near-term customer?** | Treated as *later* — tenancy deferred behind operations work | Determines whether item 3 or items 10–13 come next. Everything else is downstream |
| D-2 | Production host: Vercel only, or Hostinger VPS too? | **Vercel** | Vercel is live and verified; Hostinger would add Nginx/Redis/workers |
| D-3 | Which manufacturing stages must be *enforced* (not just recorded)? | Record-only | Enforced gates change the schema and the UI |
| D-4 | Payment gateway | **Not integrated** | No online payment without a merchant account |
| D-5 | Backup destination | None | RPO stays "manual" |

---

## 4. Recommended sequence

**Phase A — trust (about one week).** Item 1, item 2, item 16. Small, and every
later claim rests on them: an audit log and monitoring are what let you *say*
what happened.

**Phase B — Woodex operations (6–10 weeks).** Items 10 → 11 → 12 → 13, with
item 8 (reservations) folded in wherever stock starts moving. This is the phase
that makes the product do Woodex's actual job: enquiry → quotation → procurement
→ production → QC → delivery → warranty.

**Phase C — platform (4–6 weeks).** Item 3, then item 4, 5, 6, 9. Only after
D-1 is answered *yes*.

**Phase D — scale.** Items 14, 15, 17.

### Why B before C

The specification presents multi-tenancy as mandatory and near-term. I would
sequence it *after* operations, for one reason: multi-tenancy is only valuable
once the thing being multiplied works. Today the operations half is missing
(no procurement, no production, no QC), so onboarding a second agency would give
two companies access to a system that does not yet run a factory.

If D-1 comes back **yes, tenants are imminent**, invert B and C — but do not
start C until the cross-tenant test suite exists, because a partially-tenant
schema is worse than none.

---

## 4a. Completed this cycle

### Audit log (P0-3) — done and gated

`scripts/migrations/008_audit_log.sql`, `audit_logs` in SQLite and PostgreSQL,
`writeAudit()` in `apps/api/server.mjs`, `GET /api/audit` + `/api/audit/health`.

Instrumented write points (each logs **after** the write succeeds, inside the
same transaction, so a rollback leaves no row):

| Action | Trigger |
|---|---|
| `invoice.payment_recorded` | `POST /api/invoices/:id/payments` |
| `invoice.updated` | `PATCH /api/invoices/:id` (status/total change) |
| `return.state_changed` | `PATCH /api/returns/:id` (incl. whether it restocked) |
| `product.updated` | `PATCH /api/products/:id` (price or stock) |
| `user.privileges_changed` | `PATCH /api/users/:id` (role/active/password) |

**Proof:** `npm run test:audit-log` → **9/9**. Mutating `writeAudit` to a no-op
produces **4 failures**, including the control assertion that exists precisely
so the three absence-checks cannot pass vacuously.

**Applied to production** on 2026-11-09 (version 8, checksum `322a02d5…`) and
verified there: ledger 8 rows, 28 FKs, 31 CHECKs, 0 unvalidated, and both
negative probes (malformed action, empty entity) rejected by the live
constraints.

### The deploy/migration ordering gap — a real risk worth recording

Migration 008 was **not** applied to production when the code shipped. The
deploy went READY, the API answered, and every audit write failed silently —
because `writeAudit` deliberately swallows errors so a logging failure cannot
break a payment. The application's own design made the gap invisible.

Nothing in this repository applies migrations to a hosted database
automatically. `vercel:build` rehearses them on a throwaway Postgres; it never
touches production. So **any migration must be applied as a separate, deliberate
step**, and there is currently no guard that fails when the deployed code
expects a table the database does not have.

That is the argument for `PRODUCTION_DATABASE_URL`: `audit:schema` in CI is the
only thing that would have caught this before a human noticed.

**Append-only is enforced statically:** the test greps every file in `scripts/`
and `apps/api/server.mjs` for `UPDATE`/`DELETE` against `audit_logs` and fails if
one appears. There is no API route that mutates the table; only reads.

### Two real defects found while building it

1. **The action validator rejected the real vocabulary.** The first regex
   forbade underscores, so `invoice.payment_recorded` — the single most
   important audit point — was silently dropped while reporting success. The
   test caught it on the first run. A validator that rejects the honest name is
   worse than no validator.
2. **`test-audit-schema.mjs` hard-coded 28/28.** Adding a migration broke a test
   unrelated to the change, and the obvious fix (edit 28 → 31) would rot on
   every future migration. It now derives the expected counts from
   `pg_constraint` and compares them with the audit's claim — so it still
   catches under-declaration (the original bug) but ignores legitimate growth.

---

## 5. What this tracker refuses to do

- It will not mark a module Verified because it compiles.
- It will not convert the gap list into "mostly done". Nine modules are done,
  seventeen are partial or absent.
- It will not claim Hostinger works — it has never been run.
- It will not claim the hosted drift audit guards production — the secret does
  not exist and the step self-reports that it skipped.
