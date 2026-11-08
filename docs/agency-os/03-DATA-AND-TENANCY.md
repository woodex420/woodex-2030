# WOODEX OS — Data Model and Multi-Tenancy

**Status:** describes the current schema, then specifies tenancy as a design.
**Tenancy is not implemented.** Do not onboard a second organisation until every
requirement in §5 is green.

**Baseline:** `main` @ `15e6242` — 30 PostgreSQL tables, 31 SQLite tables.

---

## 1. Current data model

29–30 tables, grouped by bounded context. Both schemas are kept in step by hand;
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

**Currently weak.** The review was right:

- Most tables have a primary key and little else.
- Foreign keys are largely absent (SQLite did not enforce them; PostgreSQL would).
- No `CHECK` constraints on money, quantities or status values.
- No migration ledger — schema changes are applied by running a full DDL file.

**Consequence:** a bug in application code can write an order pointing at a
non-existent client, or a negative quantity. Nothing at the storage layer stops it.

### 2.1 Contract for new and changed tables

From now on, every new table and every touch of an existing one must add:

1. `FOREIGN KEY` with an explicit `ON DELETE` behaviour.
2. `CHECK` for money `>= 0`, quantities `>= 0`, and status values in a known set.
3. `NOT NULL` on anything the application assumes is present.
4. An index for every column used in a `WHERE` that is not already leading a key.

This is written as a rule because retrofitting constraints later means first
finding the rows that already violate them — usually in production.

## 3. The `stock_qty` problem, stated precisely

`products.stock_qty` is a **mutable integer on the product record**.

This means stock has no history: you cannot answer "why is this 47?", cannot
attribute a shortage to a customer order, and cannot reconcile a physical count.
Two concurrent decrements can interleave and lose an update.

**Required design — append-only movement ledger:**

```
stock_movements(id, product_id, warehouse_id, delta, reason, ref_type, ref_id,
                created_by, created_at)
```

Rules:
- Every change is an **insert**, never an update. Corrections are compensating rows.
- `products.stock_qty` becomes a **derived cache**, recomputable from the ledger
  (`SUM(delta)`), and must be reconcilable by a test.
- Reasons are an enumerated set: `receipt`, `sale`, `return`, `adjustment`,
  `transfer`, `wastage`, `reservation`.
- Reservations are separate from stock: `reserved` is not a movement, it is a
  claim on available stock.

**This must land before any warehouse or procurement module**, because those
modules exist to create movements.

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
