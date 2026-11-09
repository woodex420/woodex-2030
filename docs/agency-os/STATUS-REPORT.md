# WOODEX 2030 — Status Report

**Date:** 2026-11-09
**Production:** https://woodex-2030.vercel.app
**Snapshot commit:** `7f17fd7` · deploy `dpl_86P1Nkf3jJxathWkhA3WFE3uz2gc` (READY, PROMOTED)

> This report is a **point-in-time snapshot** taken at the commit above. The
> figures below it — 26 FKs, 20 CHECKs, 4 migrations — were accurate then and are
> deliberately not rewritten, because the narrative around them describes a
> specific milestone. For what is true *now*, see `00-CURRENT-STATE.md`, which is
> authoritative: **28 FKs, 28 CHECKs, 7 migrations**, `main @ 2df2b6d`.
**Local preview:** storefront `:5174` · dashboard `:3001` · API `:3002`

---

## 1. Completed work

### Phase A — Database integrity + migration ledger

Production had a database with no enforced constraints, so bad rows were
possible and invisible. Phase A added the constraints and the machinery that
proves they exist.

| Item | Result |
|---|---|
| Foreign keys | **26** enforced in production (28 today) |
| CHECK constraints | **20** enforced in production (28 today) |
| Migration ledger | `schema_migrations` records every applied file with a sha256 |
| Migration runner | `pg-migrate.mjs` applies files in order, idempotently |
| Integrity rehearsal | `test:integrity` proves each constraint *rejects* bad data |

The implementation detail that matters: a constraint is only real if
something tries to break it. `test:integrity` inserts the rows each constraint
is meant to forbid and asserts they fail. An unenforced constraint looks
identical to a correct one from the outside.

**Exit criteria met:** 26 FKs, 20 CHECKs, ledger populated, rehearsal green.

---

### Phase B — Stock-movement ledger

Scoped as "add inventory history". Reading the code first found **two live
production bugs**, both reachable by anyone:

1. **Nothing decremented stock on sale.** `POST /api/orders` checked
   `stock_qty` and then never reduced it. A product with 99 units could be
   sold an unlimited number of times. The public checkout is unauthenticated.
2. **Closing a return never restocked.** It wrote a status and dropped the
   units permanently.

Both are now fixed, and the ledger that was the original goal exists:

| Property | Implementation |
|---|---|
| Append-only | `stock_movements` has no UPDATE and no DELETE path; asserted by test |
| `stock_qty` | A **derived cache** over `SUM(delta)`, reconciled for every product |
| Atomic sale | `UPDATE … WHERE stock_qty >= ?` inside the txn; no match → **409** + rollback |
| Product edit | Never writes stock — it used to post back a stale quantity, silently undoing sales |
| Returns | Restock once, on transition into `Closed`, only for real catalogue products |
| Opening balances | Migration `004` backfilled 148 receipts |

**Production verification:** `cache 14,652 units = ledger 14,652 units`,
**0 products drifted** across 148.

Why the guarded UPDATE matters: read-then-write would let two concurrent buyers
both pass the stock check and both buy the last unit.

---

### Sitemap — three attempts, one real cause

`/sitemap.xml` was an acceptance criterion for weeks and returned **401** the
entire time. Two of my fixes failed. The honest account:

| Attempt | Diagnosis | Outcome |
|---|---|---|
| 1 | Allow `/api/sitemap` in the auth gate | still 401 |
| 2 | Add a dedicated `api/sitemap.mjs` adapter | still 401 |
| 3 | **Traced the actual rewrite chain** | **fixed** |

The real chain:

```
browser  /sitemap.xml
rewrite  /api/sitemap          (old rule)
Vercel   serves api/sitemap.mjs → sets req.url = "/sitemap.xml"
next     calls the handler in api/index.mjs
adapter  index.mjs prepends /api to any path outside /api
express  receives /api/sitemap.xml  ← no route, not allow-listed → 401
```

The custom adapter *guaranteed* the double prefix. Fixed by deleting it and
routing the rewrite to `/api/index`, so exactly one function prefixes once.
All three spellings are now routed **and** allow-listed, so it cannot depend on
which prefix Vercel adds.

**Why it took three tries:** every local test spawned Express directly and
requested `/api/sitemap` — the spelling I had assumed. The test agreed with the
bug. There is now a test of all three spellings asserting byte-identical output,
plus `check:deploy` fails if a second sitemap entrypoint reappears.

---

### Sitemap origin — the 401 hid a second bug

Once the 401 was gone, the sitemap returned **200 with 165 well-formed `<loc>`
entries, every one pointing at `http://localhost:5174`.**

The origin came from `theme.siteUrl`, falling back to a dev constant. Production
has **no theme row**, so it always fell back. Locally that constant is correct,
which is why it never looked wrong — it is only wrong in the one environment
with a different host.

Fixed: the origin now derives from **the request's own Host header**, after an
explicit theme setting and `SITE_URL`. The dev constant survives as a last
resort a real request never reaches.

**Production verification** (cache-busted): all 165 URLs now
`https://woodex-2030.vercel.app/…`.

**Caveat, stated plainly:** the un-cache-busted URL still served the old
localhost document from Vercel's CDN at the time of checking. The correct
document is proven present; the cached copy expires on its own. Nothing in the
app is wrong.

### A guard that was itself broken

The first version of the origin guard **passed code that returned localhost
unconditionally** and **failed the correct code** — wrong in both directions,
because it matched the presence of a string rather than behaviour. Found by
mutating the code and watching the guard not react. It now inspects the first
`return`, verified by mutation in both directions.

I would have shipped a guard that defended nothing and reported it as covered.

---

## 2. Production verification

| Check | Result |
|---|---|
| `/api/health` | **200** `{"ok":true}` |
| `/api/products` | **200**, `total: 148` |
| `POST /api/auth/login` | **200** + token |
| `/sitemap.xml` | **200** XML, 165 URLs, correct origin |
| Stock reconciliation | **0 drifted** of 148 products |
| Migrations applied | **4 / 4** |
| DB contents | 148 products · 16 materials · 7 services · 1 user |

## 3. Gates

| Gate | Result |
|---|---|
| `qa` (API/security, SQLite) | **107/107** |
| `qa:pg` (same, real Postgres + 4 migrations) | **107/107** |
| `test:stock` | **20/20** |
| `test:public` | 3 identical sitemap spellings, 165 URLs on serving host |
| `test:integrity` | 28/28 FKs, every table has a PK |
| `test:router`, `test:blocks`, `check:deploy`, `check:migration`, `docs:verify` | PASS |
| `vercel:build` | **76/76** routes prerendered |
| `typecheck:all`, `secret-scan` | PASS |
| Browser E2E (Playwright) | **not run here** — chromium download blocked in this sandbox; belongs to CI |

---

## 4. Pending work

### Blocking / correctness

1. **Restore drill from a real backup** — Phase A exit criterion, never done. A
   backup that has never been restored is a hypothesis, not a backup.
   `db:backup` and `db:verify-backup` exist; a full restore into a scratch
   database has not been performed.
2. **`tasks.*` CHECK discrepancy** — production has 20 CHECKs, none on `tasks`.
   Either the schema intends constraints that were never applied, or the
   rehearsal asserts more than production has. Unresolved; it means the two
   schemas are not provably identical.
3. **Stale `INITIAL_ADMIN_PASSWORD`** on Vercel — a `sensitive` var that cannot
   be read, left over from initial provisioning. Should be deleted so it cannot
   mislead a future operator into thinking it is current.

### Scheduled by the roadmap

4. **Phase C — Procurement → Production → Delivery.** The next real work; these
   three now have a ledger to write into. `stock_reservations` exists but
   nothing writes to it: carts do not reserve stock, so two customers can still
   race for the last unit at the *cart* stage (checkout is safe).
5. **Phase D — Reporting.** No aggregate layer yet; everything is row-level.
6. **Phase E — Multi-tenancy.** Shared schema + `tenant_id` + forced RLS. The
   standing decision; not started.

### Deferred deliberately

- **No `warehouse_id`** on movements. One location today; a nullable column is
  cheaper to add later than a fake warehouse table is to remove now.
- **No reservation writes.** A reservation is a claim on available stock, not a
  movement, and getting the expiry sweep right is Phase C's problem.

---

## 5. Standing decisions

| ID | Decision |
|---|---|
| D-01 | **Do not rewrite.** Extend the existing React/Vite + Express app. |
| D-02 | Stay on Supabase + Vercel. |
| D-03 | Multi-tenancy via shared schema + `tenant_id` + forced RLS (Phase E). |
| D-04 | Migrations are immutable once applied; corrections are new files. |

## 6. Credentials

- **Production:** `admin@woodex.pk` / `WoodexAdmin2030`
- **Local seed:** `admin@woodex.pk` / `admin` — never in production

## 7. How to reproduce the local preview

```bash
npm ci --legacy-peer-deps
PORT=3002 node --experimental-sqlite apps/api/server.mjs        # API
ARENA_PREVIEW=1 API_PROXY_TARGET=http://localhost:3002 \
  npx -w @woodex/storefront vite --port 5174 --host 0.0.0.0     # storefront
ARENA_PREVIEW=1 API_PROXY_TARGET=http://localhost:3002 \
  npx -w @woodex/dashboard vite --port 3001 --host 0.0.0.0      # dashboard
```

`ARENA_PREVIEW=1` is required behind the preview proxy: it pins HMR to port 443
and the Vite configs already allow the `.e2b.app` host.
