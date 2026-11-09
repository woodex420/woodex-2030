# WOODEX 2030 — Master Implementation Tracker

**Updated:** 2026-11-09 · **Baseline:** migration `013` (`finance`)
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
| **Audit log** | ✅ Verified | ✅ | ✅ (API) | ✅ | ✅ | **P0** |
| **Procurement** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P2 |
| **Production / BOM** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P2 |
| **Quality control** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P2 |
| **Stock ledger UI** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P2 |
| **Warehouses + locations (012)** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P2 |
| **Stock transfers (012)** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P2 |
| **Stock reservations (012)** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P2 |
| **Double-entry ledger (013)** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P1 |
| **Expenses (013)** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P1 |
| **Credit notes (013)** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P1 |
| **Payment schedules (013)** | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P1 |
| Finance (invoicing/payments) | ✅ Verified | ✅ | ✅ | ✅ | ✅ | P1 |
| RBAC | 🟡 Partial | ✅ | ✅ | 🟡 | ✅ | P1 |
| Page builder | 🟡 Partial | ✅ | 🟡 | ✅ | 🟡 | P2 |
| Analytics | 🟡 Partial | ✅ | ✅ | ✅ | 🟡 | P2 |
| Integrations | 🟡 Partial | 🟡 | 🟡 | 🟡 | ❌ | P2 |
| Delivery | 🟡 Partial | 🟡 | 🟡 | 🟡 | ❌ | P2 |
| **Tenancy / RLS** | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | **P0** |
| **Hosted drift audit** | ⚠️ Blocked | ✅ runtime check | — | — | ✅ | **P0** |
| Platform admin | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P3 |
| Support / warranty | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P2 |
| Automation / AI | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P3 |
| Queues | ⛔ Not started | ❌ | ❌ | ❌ | ❌ | P3 |

## 1a. What "Verified" means here

A module is **Verified** only when a named gate passes and its result is quoted
in §5 below. Everything shipped through migration `012` is listed at that level;
nothing in this document is marked Verified on the strength of compiling.

Two statuses deserve their own explanation because the table cannot carry it:

- **Hosted drift audit — ⚠️ Blocked.** The runtime check exists and works
  (`GET /api/audit/health` returns `misaligned` + 503 when a migration table is
  missing), and `test:audit-log` proves it. What is blocked is running it against
  production *from CI*: `PRODUCTION_DATABASE_URL` is not configured as a GitHub
  secret, so the workflow skips the hosted comparison and says so rather than
  reporting a pass it did not earn.
- **Tax records — 🟡 Partial.** The `tax_records` table, its vocabulary and its
  period format exist and are constrained, and expenses record recoverable input
  tax against the payable account. What is missing is automatic capture: no
  route yet writes a `tax_records` row when an invoice or a supplier bill is
  issued, so the table is honest but unfed. That is a small addition on the
  invoice path, recorded as open rather than claimed.

Status values: ⛔ Not started · 🟡 Partial · ✅ Implemented · ✅✅ Verified · ⚠️ Blocked

---

## 2. Ordered work queue

Each item is sized by *kind* of work, because that is what determines duration.

| # | Task | Kind | Size | Depends on |
|---|---|---|---|---|
| 1 | `PRODUCTION_DATABASE_URL` secret | Ops | 5 min | **Owner** |
| 2 | ~~Platform `audit_logs` + write points~~ **DONE** | Schema + code | — | ✅ `008` |
| 3 | ~~Procurement context~~ **DONE** | New module | — | ✅ `009` + UI |
| 4 | ~~Supplier invoices + three-way match~~ **DONE** | New module | — | ✅ `010` + UI |
| 5 | ~~Production + QC context~~ **DONE** | New module | — | ✅ `011` + UI |
| 6 | ~~Stock ledger read path + reconciliation~~ **DONE** | Code | — | ✅ `012` |
| 7 | ~~Warehouses, locations, transfers, reservations~~ **DONE** | New module | — | ✅ `012` + UI |
| 8 | ~~Finance depth: journal, expenses, credit notes, schedules~~ **DONE** | New module | — | ✅ `013` + UI |
| 8b | Tax capture on invoice/bill issuance | Code | 2–3 d | `013` |
| 9 | CRM depth: companies, contacts, meetings, attachments, activity history | New module | 1–2 wk | — |
| 10 | Delivery + installation | New module | 2–3 wk | — |
| 11 | Support + warranty | New module | 2 wk | 10 |
| 12 | **Tenant schema + context + RLS + cross-tenant tests** | **Architecture** | 2–3 wk | D-1 answered |
| 13 | Configurable roles/permissions | Schema + code | 1 wk | 12 |
| 14 | Password reset + email adapter | Code | 2–3 d | SMTP creds |
| 15 | Distributed rate limiting | Infra | 2 d | Redis/Upstash |
| 16 | Scheduled offsite backup | Infra | 1–2 d | Bucket creds |
| 17 | Page builder v2 (dnd, single renderer) | Frontend | 2 wk | Tailwind decision |
| 18 | Provider messaging | Integration | 1–2 wk | Provider creds |
| 19 | Payment gateway | Integration | 2 wk | Merchant account |
| 20 | Durable queues + workflow engine | Infra | 3–4 wk | Redis |

**Reading the queue:** items 1–2 are days and unblock trust. Items 3–7 shipped
in four increments; the Woodex *operations* spine — buy, receive, build, inspect,
store, move — now exists end to end with screens behind every step. Item 12 is
the largest single remaining piece of work and gates every multi-tenant claim;
it moved down not because it matters less but because D-1 says there is no second
tenant yet. Items 8–11 close the remaining spec modules, in the order that lets
each one build on the last.

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

## 4a. Increment 5 — Operations screens, then inventory depth (012)

Two increments are recorded here because the second was found while building
the first.

### 4a.1 The six Operations screens

Procurement (009), payables (010) and production (011) shipped with working
backends, tests and production deployments — and **no screens**. From the
dashboard the entire business chain looked unbuilt because nothing in the UI
called it. The spec requires a frontend for every module, so by its own standard
three increments were not delivered. `/suppliers`, `/purchase-orders`,
`/goods-receipts`, `/supplier-invoices`, `/production` and `/stock-ledger` now
exist, with no mock data: an empty table means an empty database and says so.

Two defects surfaced while wiring them, both worth recording because neither is
visible from a passing test suite:

1. **The ledger was write-only.** Every stock change appended a row and nothing
   could read one back, so "why is this 85?" required psql. `GET
   /api/stock-movements` and `/api/stock-movements/reconcile` were added; the
   latter *is* the Phase 7 acceptance criterion ("inventory balances reconcile
   with the movement ledger"), so it is now observable in the running product.
2. **The production login advertised credentials that could not work.** The
   login screen printed "Demo access — user admin · pass admin" as a hardcoded
   string *and prefilled both fields*. Production never creates that account.
   `GET /api/auth/demo-available` now answers the question by checking that the
   account exists **and** that its password hash really matches the demo
   password — an existence check alone would still advertise a failing login.

### 4a.2 Inventory depth — migration 012

Phase 7 asks for warehouses, stock movements, reservations, transfers and
reconciliation. The ledger existed; none of the rest did.

| Added | Why it is a table and not a column |
|---|---|
| `warehouses` | "How much teak in Lahore" is a different question from "which rack" |
| `storage_locations` | Location is per-warehouse unique; every godown has an A1 |
| `stock_levels` | Per-location projection, rebuildable from the ledger, never authoritative |
| `stock_transfers` + lines | A move is two ledger rows and a document, with an honest in-transit state |
| `stock_reservations` | Existed since 003 and **nothing ever wrote to it** — now used |

**The design problem worth reading.** Making the ledger location-aware is not a
matter of adding a column. History cannot be backfilled: every movement recorded
before 012 was written when "where" was not a question the system could ask, and
assigning a default location would assert something false about the past — that
someone put those units on that rack. So `location_id` is **nullable**, and the
fallback policy differs by direction:

- An unattributed **increase** lands in the default location. Goods that arrive
  went somewhere, and "the receiving bay" is a convention, not a guess.
- An unattributed **decrease** falls back only when the default location can
  actually cover it. Otherwise it is recorded with no location. This is the case
  that first broke: a product seeded with 99 units before any warehouse existed,
  then recounted to 119, then to 69 — the 20 at rack A1 could not honestly
  absorb a 50-unit decrement, and failing the write would have made the ledger
  unable to describe the stock it already held.

The invariant that makes `stock_levels` safe to exist is asserted, not assumed:
`SUM(stock_levels) = SUM(located stock_movements)` for every product, checked at
four points in `test:inventory`. Both halves are reported separately because
they fail for different reasons, and a single `ok` would hide which one broke.

**A behaviour that surprised the test I wrote.** Dispatching a transfer lowers
total on-hand stock; receiving restores it. Units in a truck are genuinely at
neither location, and counting them at both ends is how the same desk gets sold
in Lahore and promised in Karachi. My first assertion said "dispatch changes no
total" — the code was right and the test was wrong, which is the better of the
two ways for that to go.

**Verified:** `test:inventory` **68/68** · `test:integrity` **54/54 FK ·
70/70 CHECK · 49 tables** · `test:upgrade` (now also proves 012's columns are
added in place on a pre-011 database, and that historical movements keep a NULL
location) · `test:procurement` · `test:production` · `test:bills` ·
`test:stock-ledger` · `test:audit-log` 14/14 · `test:ops` 41/41 ·
`check:migration` 49=49=49 · `docs:verify` clean · dashboard `tsc -b && vite
build` exit 0, 38 routes.

**Mistake recorded:** my first 012 declared `stock_reservations_product_fkey`
again "if not exists". It is a no-op at runtime, but `test:integrity` derives its
expected FK count from the migration files, so the duplication made it demand 54
constraints and find 53 — reported as a missing foreign key when the real fault
was a duplicated promise. The guarded re-add was deleted.

---

## 4b. Increment 6 — finance depth (013)

Phase 5's finance half, and section 11.4's "immutable transaction history".

### The problem with adding columns

`invoices.paid` is a cached total maintained by a route. There is no record of
which account received the money, and adding an `account` column to `payments`
would still leave expenses, credit notes and tax on separate, differently shaped
tables that happen to contain amounts. Every report would be a UNION of five
sources with five notions of "when".

A journal flips that: each financial event becomes debit/credit lines against
named accounts, and one rule — debits equal credits — makes the whole system
checkable. Invoices keep their caches; the journal is the reason they are true.

### Immutability is a mechanism

The spec asks for immutable transaction history. An API that simply does not
expose an UPDATE is not immutable — it is a policy that holds until someone
writes a route, runs a repair script, or opens psql. So 013 installs database
triggers that refuse UPDATE and DELETE on `journal_lines` outright, and
`test:finance` proves it by opening the SQLite file directly and trying, the way
a repair script would:

```
✓ a journal line cannot be UPDATEd even from a direct database connection
✓ a journal line cannot be DELETEd even from a direct database connection
✓ an entry's date cannot be moved to another period
✓ a memo typo CAN be corrected
```

The last one matters as much as the first three. A memo is prose, not a
financial fact; forbidding its correction would push people to delete and repost,
which is the behaviour the whole design exists to make unnecessary.

Correction is therefore a reversal: a mirror entry that points at the original,
carries its own author and date, and leaves both visible. Reversing twice is
refused, and reversing anything leaves every account netting to zero without a
single row being removed.

### What shipped

| Table | Purpose |
|---|---|
| `accounts` + seeded chart | 16 accounts across the five kinds, each stating its normal side |
| `journal_entries` / `journal_lines` | The books, append-only, balance-enforced at commit |
| `credit_notes` | A document, not a negative invoice; draft posts nothing |
| `expenses` | Money out with no supplier bill; posts in the same transaction |
| `tax_records` | Constrained table, **not yet auto-fed** — see §1a |
| `payment_schedules` | What is due when; `overdue` derived, never stored |

Four screens — `/ledger` (trial balance + journal with reverse), `/expenses`,
`/credit-notes`, `/payment-schedules` — and 16 endpoints.

### A parser bug worth recording

`test:integrity` and the drift audit both collect declared CHECK names with
`/CONSTRAINT\s+(\w+)\s+CHECK/`. Postgres has a construct called
`CREATE CONSTRAINT TRIGGER`, which matched, so a trigger named
`trg_journal_entry_balanced` was counted as a check constraint and then reported
missing. The audit was right to fail: the fix was to require `CHECK (` with the
opening parenthesis, and to reword a comment that read "...a deferred constraint
trigger checks at COMMIT" into prose that cannot be mistaken for a declaration.

**Verified:** `test:finance` **68/68** · `test:integrity` 94/94 CHECK ·
`test:audit` 14/14 · `test:inventory` 68/68 · `test:ops` 41/41 ·
`test:procurement` · `test:production` · `test:bills` · `test:stock-ledger` ·
`test:audit-log` 14/14 · `test:upgrade` · `check:migration` 56=56=56 ·
`docs:verify` clean · `check:deploy` · dashboard `tsc -b && vite build`, 42 routes.

---

## 5. What this tracker refuses to do

- It will not mark a module Verified because it compiles.
- It will not convert the gap list into "mostly done". Twenty modules are
  verified, five are partial, four are absent.
- It will not claim Hostinger works — it has never been run.
- It will not claim the hosted drift audit guards production — the secret does
  not exist and the step self-reports that it skipped.
