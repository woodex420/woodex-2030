# WOODEX OS — Delivery Roadmap

**Status:** proposed sequence. Each phase has an exit criterion that is a test,
not an opinion.

**Baseline:** `main` @ `15e6242`. Production is live and verified.

---

## 1. The ordering rule

The master plan lists nine phases. This document reorders them around one
question: **what blocks the most other work?**

Tenancy blocks integrations (tenant-owned credentials), automation (tenant-scoped
rules) and external onboarding. The stock ledger blocks procurement, production
and delivery. Everything else is independent and can be sequenced by business
value.

## 2. Phases

### Phase A — Integrity foundation *(no user-visible features)*

**Why first:** every later module writes data. Constraints added later must
first find the rows that already violate them.

- Add FKs and CHECK constraints table by table, with a pre-flight query that
  reports existing violations before the constraint is added.
- Introduce an ordered migration ledger (`schema_migrations`), so drift is
  detectable and rollback is possible.
- Automated encrypted backup plus **one performed restore drill**, recorded.
- Move rate limiting to a shared store or edge control.

**Exit:** restore drill passes from a real backup; `schema_migrations` reflects
the current schema; no table lacks a PK.

### Phase B — Stock movement ledger

**Why second:** it is small, self-contained, and unblocks three modules.

- `stock_movements` append-only; `stock_qty` becomes derived and reconcilable.
- Reservation model separate from stock.
- Reconciliation test: `SUM(delta)` equals cached `stock_qty` for every product.

**Exit:** the reconciliation test passes; no code path updates `stock_qty` directly.

### Phase C — Operations: procurement → production → delivery

Delivered as three increments, each useful alone:

1. **Procurement** — suppliers, requisitions, POs, goods received. Creates ledger movements.
2. **Production** — BOM, job cards, stages, QC. Consumes ledger movements.
3. **Delivery** — dispatch, POD, installation checklist.

**Exit per increment:** the corresponding rows move through their states in an
end-to-end test, and every stock change is a ledger row.

### Phase D — Support and warranty

Low risk, high perceived value, no dependency on tenancy.
Tickets, warranty registration, service visits, resolution history.

**Exit:** a delivered order can produce a warranty claim that resolves, audited.

### Phase E — Tenancy

**The largest and riskiest phase. Do not start before A is complete.**

Executed exactly as specified in [`03-DATA-AND-TENANCY.md`](03-DATA-AND-TENANCY.md) §6,
including all seven acceptance tests in §5.

**Exit:** all seven tenancy tests green, including pool-state leakage and
aggregate scoping. No second tenant exists before that point.

### Phase F — Integrations and automation

Only now, because both need tenant-scoped credentials and a durable job runner.

- Integration gateway: encrypted per-tenant credentials, retries, webhook signature verification.
- Workflow engine: trigger → conditions → actions, with execution history.
- Requires the hosting decision from [`02-TECH-STACK-DECISIONS.md`](02-TECH-STACK-DECISIONS.md) §5.

**Exit:** a failure in an integration is retried, logged, and visible; an
automation run can be inspected after the fact.

### Phase G — Payments

Deliberately late: payments require reconciliation, refunds and accounting
semantics that are not modelled yet. Checkout today creates an **order request**,
not a payment, and that is honest rather than broken.

**Exit:** a payment can be taken, reconciled to an invoice, and refunded, with
every state change audited.

## 3. What not to build

- **A design-system rewrite before Phase A.** Two Tailwind majors is real debt,
  but it is not blocking, and a rewrite now would delay integrity work.
- **A Next.js migration.** See [`02-TECH-STACK-DECISIONS.md`](02-TECH-STACK-DECISIONS.md) §7.
- **AI features.** Every automation and AI capability needs the queue, the
  audit trail and the tenant boundary that earlier phases create.
- **An accounting engine.** Double-entry bookkeeping needs a defined chart of
  accounts and tax model. Do not build it speculatively.

## 4. Sequencing at a glance

```
A integrity ──► B stock ledger ──► C ops (procurement → production → delivery)
     │                                       
     ├──► D support/warranty (parallel, independent)
     │
     └──► E tenancy ──► F integrations/automation ──► G payments
```

A is the only true blocker. D can run in parallel with anything. E must precede
F and G.

## 5. Definition of done for the platform

A second organisation can be onboarded when, and only when:

- Tenancy acceptance tests (§5 of the data document) all pass;
- A backup has been restored successfully at least once, on record;
- Every financial state change is audited;
- Stock reconciles from the ledger;
- A support claim can be raised and resolved end to end.

Until those hold, the platform is a **single-tenant operational system** — which
is a perfectly good product. It is simply not yet a multi-tenant Agency OS, and
describing it as one would repeat the documentation mistake this repository is
still recovering from.
