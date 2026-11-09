# WOODEX OS — Data Model and Multi-Tenancy

**Status:** describes the current schema, then specifies tenancy as a design.
**Tenancy is not implemented.** Do not onboard a second organisation until every
requirement in §5 is green.

**Baseline:** `main` @ `43aba70` — 29 PostgreSQL tables, 29 SQLite tables (the same set).

---

## 1. Current data model

29 tables, grouped by bounded context. Both schemas are kept in step by hand;
`check:migration` fails if they diverge in migrator coverage.

| Context | Tables |
|---|---|
| Catalogue | `products`, `materials`, `services` |
| Sales | `leads`, `quotes`, `quote_versions`, `quote_templates` |
| Commerce | `orders`, `invoices`, `payments`, `returns` |
| CRM | `clients`, `client_links`, `tasks`, `site_visits` |
| Projects | `projects`, `project_milestones`, `boq_lines`, `project_files` |
| CMS | `pages`, `page_blocks`, `page_versions`, `saved_sections` |
| Comms | `conversations`, `messages` |
| Marketing | `track_events` |
| Platform | `users`, `sessions`, `meta` |

### 1.1 Storage convention worth knowing

Most records are **JSON in a text/JSONB column plus a small number of indexed
columns** for the fields queries filter on:

```sql
products(id TEXT PRIMARY KEY, json TEXT, price REAL, in_stock INT, stock_qty INT, updated_at TEXT)
```

This keeps the schema stable as the product shape changes — a new field needs no
migration. The cost is that the database cannot constrain the contents of `json`,
and you cannot index a field without promoting it to a real column.

**Trade-off, accepted deliberately.** For 148 products this is correct. For
millions of rows with complex reporting it is not, and the promotion path is to
add real columns for the fields that reporting actually filters on.

## 2. Integrity: what is and is not enforced

**Now enforced** (was: "currently weak" — corrected after the Phase A work landed):

- Every one of the 29 tables has a primary key. `client_links` was the last one
  without a key; it is now keyed on `(entity, entity_id)`, which also states the
  invariant that a record belongs to exactly one client.
- **28 foreign keys**, each with a deliberate `ON DELETE`: `CASCADE` where the
  application already deletes children by hand, `SET NULL` for history that
  should outlive its parent, `RESTRICT` where no delete path exists and one
  would need a decision rather than a default. 26 come from `002`; the stock
  ledger adds two more in `003` (`stock_movements.product_id` and
  `stock_reservations.product_id`, both `RESTRICT` — deleting a product that
  has stock history would erase the audit trail).
- **28 CHECK constraints** on money, quantities, percentages and the status
  vocabularies the API treats as closed sets.
- **An ordered migration ledger** (`schema_migrations`) with a SHA-256 per file.
  Editing an applied migration is a hard error, not a warning.

Everything above is applied by `scripts/migrations/*.sql` through
`scripts/migrate.mjs`, and rehearsed on a throwaway database by
`npm run test:integrity` — which asserts each constraint *rejects* the data it
is meant to reject, because an unenforced constraint looks identical to a
correct one until something tries to break it.

### 2.0 Production drifted, and the rehearsal could not see it

`tasks_done_boolean` was declared in `002_integrity_constraints.sql` and was
**absent from the hosted database for weeks**. Production had applied 002
partially and nothing reported it.

The rehearsal passed the whole time, and the reason matters: it asserted a
**floor** — `checks >= 21`. That holds whether the missing constraint is
`tasks_done_boolean` or any other one, and it holds when exactly the wrong
twenty are present. A count cannot distinguish "the right twenty-one" from
"twenty-one of twenty-two". It now compares constraint **names** as a set and
reports the missing one by name.

The second half of the gap is that `test:integrity` only ever builds a *fresh*
database, so by construction it cannot see a hosted database that disagrees with
its files. `npm run audit:schema` closes that: it connects to a real database
and compares it against the migrations — every CHECK by name, every FK present
*and validated*, every expected table, and every ledger checksum. It is
read-only, and it is verified to exit 1 on drift (a dropped
`tasks_done_boolean` is caught and named) and 0 when clean.

**The lesson generalises:** a test that asserts a count of constraints, run
against a database it just created, proves the files are correct and says
nothing about the one that is serving traffic.

**Closed since this document was first written:** `tasks.priority`,
`returns.state` and `invoices.status` were all open vocabularies. All three are
now constrained, and in every case the order was **code first, migration
second** — normalise at the API boundary, prove it live, then let the CHECK
enforce it:

| Column | Constraint | What the open column was hiding |
|---|---|---|
| `tasks.priority` | `tasks_priority_known` | Free text. Dashboard sent `high`, QA sent `High`, seeds used both. |
| `returns.state` | `returns_state_known` | `PATCH` restocked inventory only when the state was literally `"Closed"`, so `"closed"` resolved the RMA and **silently skipped the restock**. Separately, order lines without an `id` — every seeded or imported order — were skipped entirely. |
| `invoices.status` | `invoices_status_known` | Recording a payment on a cancelled invoice flipped it back to `Paid`, **silently undoing the cancellation**. |

Two of those three were live data bugs, not tidiness. The general lesson:
an unconstrained enum is not a cosmetic gap, because the code eventually starts
comparing against its values as though they were guaranteed.

**A correction worth recording:** `002` left `returns.state` open on the stated
grounds that the demo seed wrote `Approved`, `Rejected` and `Completed`. It
never did — nothing in the codebase ever wrote those to a return. The column was
open because nobody normalised it, and a wrong reason in a migration comment
survives long enough to be trusted. `002` is immutable, so the correction ships
in `006`.

**Still not enforced, deliberately:** `invoices.status` admits only what is
**stored** (`Issued`, `Partially Paid`, `Paid`, `Cancelled`). `Overdue` is
derived on read by `decorateInvoice` from the due date and remaining balance,
and the CHECK rejects it: storing a derived value freezes it, and the first
payment would leave a permanently stale `Overdue` behind. `Draft` is rejected
because no code path produces an invoice in that state.

**What a constraint still cannot do:** guard sequences. `invoices_status_known`
cannot stop a cancelled invoice being flipped back to `Paid`, because that is a
transition rather than an illegal value. Those are code-level guards in the
routes (`409`), shipped alongside each migration — never a substitute for the
CHECK, and never expressible as one.

**What is still open at the storage layer**, checked rather than assumed:
`orders.status` and `orders.stage` *are* constrained. The remaining gaps are
`quotes.status` and `leads.status`:

- **`quotes.status` is validated in code but not in the database.** The routes
  check against `QUOTE_STATUSES` and return `400` for anything else, so the
  behaviour is correct — but nothing at the storage layer backs it up, and any
  future write path that bypasses those routes could store a value the twelve-
  member enum does not describe.
- **`leads.status` is neither.** It is written directly by several flows
  (`Site visit booked`, `Site visit completed`, `Qualified`, …) with no
  allow-list anywhere.

Neither is urgent — both are validated or generated internally, unlike the three
columns that leaked into inventory and payment logic. Recording them here so the
next person does not have to re-derive it, and so "all vocabularies are closed"
is never claimed without the caveat.

### 2.1 Contract for new and changed tables

From now on, every new table and every touch of an existing one must add:

1. `FOREIGN KEY` with an explicit `ON DELETE` behaviour.
2. `CHECK` for money `>= 0`, quantities `>= 0`, and status values in a known set.
3. `NOT NULL` on anything the application assumes is present.
4. An index for every column used in a `WHERE` that is not already leading a key.

This is written as a rule because retrofitting constraints later means first
finding the rows that already violate them — usually in production.

## 3. The `stock_qty` problem — fixed in Phase B

`products.stock_qty` **was** a mutable integer on the product record with no
history: you could not answer "why is this 47?", could not attribute a shortage
to an order, and could not reconcile a physical count.

It was worse than that. **Nothing decremented it.** `POST /api/orders` checked
availability and then never reduced it, so a product with 99 units could be sold
an unlimited number of times. The check that made checkout look responsible was
decorative. The same was true of `PATCH /api/returns/:id`: closing a return
changed a status and left the units gone forever.

### What exists now

`stock_movements(product_id, delta, reason, ref_type, ref_id, note, created_by,
created_at)` — **insert-only**. There is no UPDATE and no DELETE against it
anywhere in the API, asserted by `npm run test:stock`.

- `products.stock_qty` is a **derived cache** over `SUM(delta)`. The
  reconciliation test recomputes it for every product and fails on any
  disagreement, so the cache is verified rather than trusted.
- Reasons are a closed set: `receipt`, `sale`, `return`, `adjustment`,
  `transfer`, `wastage`. A `CHECK` enforces it, so an unenumerated reason means
  somebody bypassed the helper.
- **Sales consume stock atomically.** The decrement is
  `UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?`
  inside the checkout transaction. If no row matches, the whole checkout rolls
  back — order, invoice, client and task — with `409 insufficient_stock`, rather
  than selling air. Read-then-write would have lost this race.
- **The product edit form no longer writes stock.** It used to post back
  whatever quantity it loaded when the page opened, silently undoing every sale
  that happened in the meantime. Stock now changes only via an explicit delta
  (recorded as `adjustment`) through the ledger.
- **Closing a return restocks**, once. Re-closing is a no-op, and only lines
  naming a real catalogue product are restocked — a bespoke return must not
  invent inventory.
- A `UNIQUE` partial index prevents the same sale being recorded twice for one
  order line, so a retried request cannot double-decrement.

### Deliberately not built yet

`stock_reservations` exists as a table but nothing writes to it. A reservation is
a claim on available stock, not a movement — recording it as a delta would make
stock look sold before it shipped. Carts do not reserve yet; that belongs with
the cart/checkout rework, not with the ledger.

There is no `warehouse_id`. There is one warehouse today; a nullable column
added later is cheap, whereas inventing a warehouse table now and migrating every
row into one fake location is not.

### The remaining honest gap

`stock_qty` can still disagree with the ledger if someone runs SQL by hand. The
reconciliation test catches it in CI and on demand via
`GET /api/products/:id/stock`, which returns `stockQty`, `ledgerQty` and a
`reconciles` boolean — but nothing stops a manual `UPDATE` at the database.

## 4. Multi-tenancy — the design

**Recommendation: shared schema, `tenant_id` on every tenant-owned row, RLS forced.**

### 4.1 Schema shape

```
tenants(id, slug, name, status, created_at)
memberships(user_id, tenant_id, role, created_at)   -- a user may belong to many
tenant_domains(tenant_id, hostname, verified_at)
tenant_settings(tenant_id, key, value)
```

Every business table gains `tenant_id BIGINT NOT NULL`, and every index that
supports a query leads with it:

```sql
CREATE INDEX ON orders (tenant_id, status, created_at DESC);
```

A composite key including `tenant_id` prevents cross-tenant uniqueness collisions
(two tenants may both have order `REF-001`).

### 4.2 Resolution — the only acceptable source of truth

```
request → session token → users row → membership(tenant_id) → set local role
```

The tenant comes from the **authenticated membership**, never from:

- a request body field,
- a query parameter,
- a header,
- a subdomain alone (a subdomain selects which tenant to *try*, it does not grant it).

A user in two tenants has two memberships and must select an active one; that
selection is itself authorised.

### 4.3 Row-Level Security

```sql
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE orders FORCE  ROW LEVEL SECURITY;   -- applies to the table owner too

CREATE POLICY tenant_isolation ON orders
  USING (tenant_id = current_setting('app.tenant_id')::bigint)
  WITH CHECK (tenant_id = current_setting('app.tenant_id')::bigint);
```

`FORCE` matters: without it, the role that owns the table bypasses its own
policies, which is exactly the role a migration or an admin script runs as.

The tenant setting must be established per transaction, and reset on connection
return to the pool. **With a pool of one on serverless, a stale setting is a
cross-tenant leak** — this is the single most dangerous part of the design and
must be covered by a test that fails loudly.

### 4.4 What RLS does and does not buy

It buys a second line of defence: if a query forgets `WHERE tenant_id = ...`,
the policy still refuses to return other tenants' rows.

It does **not** replace application-level scoping, and it does not protect
`SECURITY DEFINER` functions or raw SQL that deliberately changes the setting.

## 5. Acceptance tests — tenancy is not done until these pass

Tenancy is a claim until it is proven. Required, all as automated tests:

1. **Cross-tenant read.** As tenant A, request tenant B's order by id → 404/403,
   never 200. Test at the API **and** directly against the policy.
2. **Cross-tenant write.** As tenant A, attempt to create a row for tenant B → rejected.
3. **Cross-tenant id probing.** Enumerate ids as A; no B row may appear in any list.
4. **Pool state leakage.** Run A's request, return the connection to the pool, run
   B's request on the same connection; B must not see A's tenant setting.
5. **Aggregate leakage.** Counts, sums and dashboard KPIs must be tenant-scoped.
   This is where leaks hide: `SELECT COUNT(*)` with no predicate returns everything.
6. **Admin separation.** A platform administrator must not automatically read
   tenant business data. Support access is explicit, time-boxed and audited.
7. **Membership revocation.** Removing a membership revokes access immediately,
   including already-issued session tokens.

## 6. Migration to tenancy — the only safe order

1. Add `tenants`; create one tenant representing the current business.
2. Add `tenant_id` **NULL-able** to every business table.
3. Backfill every row to the single tenant. Verify counts match exactly.
4. Add `NOT NULL`, indexes and foreign keys.
5. Enable RLS in a **non-enforcing** mode; run the full suite; fix all failures.
6. Force RLS. Re-run the suite plus the §5 tests.
7. Only then does a second tenant exist.

Steps 2–4 must be reversible at every point, and step 3 must be verified with a
row-count and content digest comparison — the same discipline as the
SQLite→PostgreSQL migrator, which is how the missing project tables were caught.

**Do not skip step 1.** A nullable `tenant_id` on rows owned by no tenant is the
state that never gets cleaned up.
