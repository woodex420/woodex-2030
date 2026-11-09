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
| **Hosted drift audit** | ⚠️ Blocked | ✅ runtime check | — | — | ✅ | **P0** |
| Platform admin | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P3 |
| Procurement | 🟡 Increment 1 ✅ | ✅ | ✅ | ✅ | ✅ | P2 |
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
| 10 | ~~Procurement context~~ **Increment 1 DONE** (suppliers, POs, goods receiving) | New module | 3–4 wk | — |
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

### The deploy/migration ordering gap, and the guard for it

Migration 008 was **not** applied to production when the code shipped. The
deploy went READY, the API answered every request, and every audit write failed
silently — because `writeAudit` deliberately swallows errors so a logging
failure cannot break a payment. The feature's own safety design made its absence
invisible.

Nothing here applies migrations to a hosted database automatically:
`vercel:build` rehearses them on a throwaway Postgres and never touches
production. So the deployed code and the real schema first meet at runtime, and
nothing was checking that they agree.

`GET /api/audit/migrations` now does:

| `status` | Meaning |
|---|---|
| `aligned` | every migration-owned table exists on PostgreSQL |
| `misaligned` → **503** | a table the migrations create is gone; the payload names it |
| `not-applicable` | SQLite — no ledger by design, so nothing is claimed |
| `pending` | no migrations recorded; a fresh environment, not a broken one |

Deliberately **not** a boot-time check: throwing at startup over a soft
dependency takes the whole API down, and a 503 for an audit gap is a worse
outage than the gap. It is on-demand and owner-gated, and returns a **table
name**, not a count — "one table is missing" is not actionable.

Tested in `test:audit-log` (14/14) against a real pglite database with a genuine
checksum ledger: aligned → 200, then drop `audit_logs`, restart, → 503 naming
it. Mutating the check to always report `aligned` fails the suite.

**The pglite lesson:** the adapter reports three kinds — `sqlite`, `pglite`,
`postgres` — and pglite *is* PostgreSQL: it has the ledger and
`information_schema`. The first version tested `!== "postgres"`, sent pglite down
the `sqlite_master` path, and threw `relation "sqlite_master" does not exist`.
The rehearsal engine must take the same path as production or the rehearsal
proves nothing.

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

### Procurement (item 10) — increment 1, done and gated

`scripts/migrations/009_procurement.sql` (+ `rollback/009_procurement.rollback.sql`),
five tables mirrored in SQLite and PostgreSQL, eight routes under
`/api/suppliers`, `/api/purchase-orders`, `/api/goods-receipts`, and a new
`purchasing` role.

The module turns on one distinction, and the schema enforces it rather than
documenting it:

| Line kind | Stocked? | What a receipt does |
|---|---|---|
| `product` | yes | `applyStockMovement(reason:'receipt', refType:'goods_receipt')` → one ledger row |
| `material` | **no** | records quantity and cost; **never touches stock** |

A material is a finish code (`Natural Oak`, `Brushed Brass`): `materials` is
`(id, json)` with `applicableTo`/`brightness`/`category`/`image`/`name` and no
price, unit or quantity. Receiving one into stock would invent inventory for a
colour. `scripts/test-procurement.mjs` asserts **both** directions, and a
mutation that makes materials move stock fails 5 checks.

**Verified, not assumed:**

| Gate | Result |
|---|---|
| `test:integrity` | 33/33 FKs · 40/40 CHECKs · 38 tables with a PK · 9 migrations, idempotent |
| `qa:pg` (real PostgreSQL/WASM) | 108/108 |
| `test:procurement` | 52/52, plus mutation → 5 checks fail |
| `test:stock` | reconciliation holds with procurement in the path |
| `test:audit-log` | 14/14 |
| `check:migration` | 37 SQLite = 37 migrated = 37 PostgreSQL |
| `docs:verify` · `vercel:build` | clean · exit 0, 76 routes, restore drill 38 tables |

**Three traps this work hit, recorded so the next module does not:**

1. **The receipt idempotency gap.** 003's unique index covered `reason='sale'`
   only, so a retried goods receipt would double-count stock. `009` adds
   `uq_stock_movements_receipt_ref`, scoped to
   `reason='receipt' AND ref_type='goods_receipt'` — deliberately narrow,
   because 003 seeds opening balances as `reason='receipt'` with `ref_type NULL`
   and a broader predicate would break production seeding.
2. **A rollback file in `scripts/migrations/` would be applied as a migration.**
   Six scripts enumerate that directory; `rollback/` is invisible to all of
   them. `migrate.mjs --rollback <n>` now exists to run one, and refuses when
   data or a later migration makes the undo dishonest.
3. **`test-audit-log.mjs` hard-coded `latest === 8`.** A correct endpoint failed
   a stale assertion the moment 009 existed — the same class of bug as the
   hard-coded 28/28 counts. It now derives the expected version from the files.

### Supplier invoices + three-way matching (item 10, increment 2) — done and gated

`scripts/migrations/010_supplier_invoices.sql` (+ rollback), three tables
(`supplier_invoices`, `supplier_invoice_lines`, `supplier_payments`), six routes,
and a matching engine that compares **ordered (PO) vs received (GRN) vs billed**
per line.

**Why a new table rather than `invoices.supplier_id`.** `invoices` is
receivables — `customer` is a *name*, the money flows in, and the lifecycle is
`Issued → Paid`. A supplier invoice is a payable: a real counterparty, money
flowing out, `Draft → Posted → Paid`. Merging them would put an
`AND direction='out'` filter on every receivables query, and one missed filter
reports a payable as revenue. The PRD already splits them (Phase 1 receivables,
Phase 2 payables).

| Verdict | Meaning | Payment |
|---|---|---|
| `matched` | billed ≤ received ≤ ordered on every line | allowed |
| `tolerance` | over-received within the configured tolerance | allowed |
| `variance` | billed beyond receipt or order | **refused** without `overrideVariance` |
| `unlinked` | no PO — nothing to compare against | allowed |

Design decisions worth keeping:

* **The verdict is stored with its numbers** (`match_json`). "matched" with no
  figures behind it is a claim; the first dispute will ask which numbers
  disagreed.
* **A variance blocks payment, not recording.** A supplier's bill is a fact that
  arrives whether or not it agrees with us; refusing to log it only moves the
  argument to a spreadsheet.
* **Overpayment is refused** (`si_paid_within_total` + a route check). The
  receivables side sets `paid = paid + amount` with no ceiling — a real bug
  there, not one to copy into money going out.
* **The duplicate guard is `(supplier_id, supplier_ref)`** — their own document
  number, unique per supplier. Paying one bill twice is the commonest AP error
  and usually clerical, not criminal.
* **Cancelling a duplicate re-matches its siblings**, or a cancelled bill leaves
  the survivor permanently looking over-invoiced.
* **Tolerance is configuration** (`meta['procurement.match_tolerance']`, default
  0), not a constant.

**Four bugs this work found in itself, each caught by a gate:**

1. **`matchSupplierInvoice` read DB column names** (`purchase_order_line_id`)
   from objects produced by the camelCase row mapper (`purchaseOrderLineId`).
   Every line came back `unlinked`, every total `0` — the match *ran* and
   verified nothing. The test asserting "billed beyond received" is what failed.
2. **`bump()` was defined and never called**, so per-line variance was computed
   correctly and then discarded: a document whose only line was a variance still
   reported `matched`, contradicting its own `findings` array.
3. **`/api/supplier-invoices/summary` was registered after `/:id`** and was
   therefore unreachable — `:id` matched the literal word "summary".
4. **The 009 receipt guard was deleted by an edit to the 010 block** (my
   `old_text` ended with the index, so the replacement consumed it).
   `test:procurement` failed with "the receipt idempotency index exists" —
   a regression caught by the suite written for the previous increment.

**Verified:** `test:integrity` **39/39 FK · 50/50 CHECK · 41 tables** ·
`qa:pg` **108/108** on real PostgreSQL · `test:bills` 50/50 · `test:procurement`
52/52 · `check:migration` 40=40=40 · `docs:verify` clean · `vercel:build` exit 0,
76 routes. Mutation (disabling variance escalation) → 8 checks fail.

---

## 5. What this tracker refuses to do

- It will not mark a module Verified because it compiles.
- It will not convert the gap list into "mostly done". Nine modules are done,
  seventeen are partial or absent.
- It will not claim Hostinger works — it has never been run.
- It will not claim the hosted drift audit guards production — the secret does
  not exist and the step self-reports that it skipped.
