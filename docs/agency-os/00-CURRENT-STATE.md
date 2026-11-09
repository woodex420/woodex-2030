# WOODEX OS — Current Implemented State

**Status:** authoritative. This file records what the repository *does today*, with
evidence, not what the roadmap intends.

**Baseline:** `main` @ `2df2b6d` (merge of PR #15)
**Last verified:** 2026-11-09
**Verification method:** counts taken from source and from a green gate run. Where
something was not independently verified, it is marked **UNVERIFIED** rather than
assumed.

> Rule for this directory: if a document here disagrees with this file, this file
> wins, and the other document is wrong. Update it with evidence — a file path, a
> command output, or a live check — or do not update it at all.

---

## 1. Measured stack

| Layer | Actually in use | Evidence |
|---|---|---|
| Storefront | React 19.2 · Vite 8.2 · Tailwind **3.4** · react-router-dom 6 | `apps/storefront/package.json` |
| Dashboard | React 19.2 · Vite 8.2 · Tailwind **4.3** · react-router 8 | `apps/dashboard/package.json` |
| API | Express 4.21 · Node ≥22.5 | `apps/api/package.json` |
| Drivers | SQLite (`node:sqlite`) · PGlite · PostgreSQL (`pg`) | `apps/api/db-adapters.mjs` |
| Database (prod) | PostgreSQL on Supabase, project `krtncckcfvzirnrcdjrm` | live check, 2026-11-08 |
| Hosting (prod) | Vercel, serverless function, region `iad1` | `vercel.json`, deployment `dpl_8eUwmfe7WKCDYZnnrxHez2jPnmyZ` |

**Known inconsistency:** the two apps use different Tailwind major versions
(3.4 vs 4.3). This is real, tracked debt — see §9. A shared design system cannot
be standardised until it is resolved.

## 2. Measured surface area

| Thing | Count | Evidence |
|---|---|---|
| API route handlers | **151** | `grep -cE '^app\.(get|post\|patch\|put\|delete)\(' apps/api/server.mjs` |
| Storefront routes | **25** | `<Route` in `apps/storefront/src/App.tsx` |
| Dashboard routes | **38** | `<Route` in `apps/dashboard/src/App.tsx` |
| PostgreSQL tables | **49** | base schema + `scripts/migrations/*.sql` (distinct names) |
| SQLite tables | **49** | `apps/api/server.mjs` (distinct names; two are created twice) |
| Migrations applied | **12** | `scripts/migrations/*.sql`, all recorded in `schema_migrations` |
| CHECK constraints | **73** | distinct `<table>_<rule>` names declared across the migrations |
| Seed catalogue | **148 products · 16 materials · 7 services** | `apps/api/seed-data.json` |
| Prerendered storefront routes | **76** | `npm run vercel:build` |
| QA checks | **108** (sqlite) and **108** (postgres rehearsal) | `npm run qa`, `npm run qa:pg` |

Counts rot. `npm run docs:verify` re-checks them and fails when this file drifts.

## 3. What works, verified

- **Authentication.** Bearer sessions, scrypt password hashing, capability
  checks, role→capability mapping. Live login confirmed against production on
  2026-11-08 (`POST /api/auth/login` → 200 + session).
- **Catalogue.** 148 products seeded in production; `/api/products` returns them.
- **CRM and sales.** Leads, clients, contacts, tasks, quotations, quotation
  versions, templates, approvals.
- **Commerce.** Products, categories, orders, returns.
- **Projects / BOQ.** `projects`, `project_milestones`, `boq_lines`,
  `project_files` exist and are migratable.
- **CMS and page builder.** Typed block editor, block ordering, saved sections,
  signed iframe preview, versions, publish, rollback.
- **Finance (partial).** Invoices and manual payment records.
- **Procurement (009).** Suppliers, purchase orders, goods receipts; receiving
  posts a `receipt` ledger movement.
- **Payables (010).** Supplier invoices, three-way matching, an audited variance
  override and a payment ceiling.
- **Production (011).** BOMs (versioned, one active per product), job cards whose
  materials are snapshotted from the recipe at raise time, material issue, the
  `queued → in_progress → qc → done` path, and a QC gate that makes `done`
  unreachable without a recorded pass. Issuing consumes stock through the ledger
  exactly once (keyed on the cumulative total per line, so partial issues can be
  topped up and retries collide); a finish code moves no stock; completing with
  components still unissued is refused unless explicitly overridden, and the
  override is audited. `npm run test:production` proves each of those, and
  `npm run test:upgrade` proves an existing database still boots.
- **Real-time.** SSE on the persistent runtime; polling fallback on Vercel.
- **Deployment guards.** Routing, seed-snapshot, router-basename and migration
  coverage checks all run inside `vercel:build`.

## 4. Deployment facts — verified

| Item | State |
|---|---|
| Production URL | `https://woodex-2030.vercel.app` |
| Current deployment | `dpl_8eUwmfe7WKCDYZnnrxHez2jPnmyZ`, READY, PROMOTED |
| `/api/*` routing | All paths rewrite to the single `api/index.mjs` function |
| API boot | Verified; catalogue bootstrap runs on first request |
| Production database | Seeded: 148 products, 16 materials, 7 services, 1 owner |

**Do not reintroduce**, each of these caused a production outage:
- a `vercel.json` rewrite whose `source` equals its `destination`;
- `api/[...path].mjs` — Vercel compiles it to a *single segment*, not a catch-all;
- runtime `esbuild` of storefront `.ts` inside the serverless function;
- a dashboard path outside the router's basename (`/admin` is rewritten, so the
  router must normalise it before mounting).

All four are now guarded by `npm run check:deploy` and `npm run test:router`.

## 5. Not built

Listed to stop anyone assuming otherwise. None of this exists today:

- Multi-tenancy: no `tenant_id`, no tenant context, no RLS, no control plane.
- Procurement: suppliers, purchase orders and goods receiving **shipped (009)**.
- Payables: supplier invoices and three-way matching **shipped (010)**.
- Production: BOMs, job cards, material issue, stages and QC **shipped (011)**.
- Work centres and machine capacity: not modelled. A job card records what is
  built and by whom, not which bench it occupied.
- Delivery: no dispatch, POD or installation workflow.
- Support / warranty: `/support` renders a placeholder.
- Payment gateway: checkout creates an order request, not a payment.
- Durable queues: no Redis, no job runner; rate limiting is in-process.
- ORM: none. Custom SQL + adapter. That is a deliberate choice, not an omission.
- Next.js or Laravel: not present. Both apps are Vite.

## 6. Verified risks, ranked

| Priority | Risk | Consequence |
|---|---|---|
| P0 | No tenant isolation | Cross-company data leakage if a second tenant is onboarded |
| ~~P0~~ | ~~No tested backup/restore drill~~ **FIXED** | `npm run test:restore` backs up a populated database, restores it into a separate one, and compares every table row for row. The first real execution found a restore that reported success and wrote nothing |
| P1 | In-process rate limiting | Limits are not shared across serverless instances |
| ~~P1~~ | ~~PostgreSQL schema lacks FK/CHECK constraints~~ **FIXED** | 28 FKs + 28 CHECKs enforced. `test:integrity` proves each rejects bad data and compares constraint **names** — a count let `tasks_done_boolean` go missing in production for weeks. `npm run audit:schema` compares a live database against its migrations |
| ~~P1~~ | ~~No ordered migration ledger~~ **FIXED** | `schema_migrations` + `scripts/migrate.mjs`; checksum drift is a hard error. 7 migrations applied, each with a recorded sha256 |
| ~~P1~~ | ~~`tasks.priority`, `returns.state`, `invoices.status` are open vocabularies~~ **FIXED** | All three closed in `005`, `006` and `007`, in that order — normalise at the API boundary, prove it live, then constrain. Two of the three were hiding live data bugs: returns silently skipped the inventory restock, and a payment silently undid a cancellation. See 03 §2 |
| P2 | `quotes.status` and `leads.status` still open at the storage layer | `quotes.status` is validated in code (`400` on anything outside `QUOTE_STATUSES`) but has no CHECK; `leads.status` has no allow-list at all. Low risk — neither feeds inventory or payments — but see 03 §2 |
| P2 | Tailwind 3 / 4 split | Two styling systems; design tokens cannot be shared |
| P2 | Vite rather than Next.js | No SSR; SEO relies on prerendering (76 routes) |
| P2 | SSE unavailable on Vercel | Polling fallback only in production |

## 7. Test and gate inventory

Every gate below runs in CI and/or inside `vercel:build`.

| Command | Covers |
|---|---|
| `npm run qa` | 108 API checks against SQLite |
| `npm run qa:pg` | 108 checks against a PostgreSQL rehearsal |
| `npm run typecheck:all` | Both apps |
| `npm run secret-scan` | Tracked files |
| `npm run smoke:render` | Both apps mount in jsdom |
| `npm run check:deploy` | Routing, seed snapshot, no bracket catch-all |
| `npm run test:router` | 21 router-basename cases |
| `npm run check:migration` | Migrator covers every table, in dependency order |
| `npm run seed:data:check` | Seed snapshot matches storefront sources |
| `npm run vercel:build` | Full production assembly, 76 routes prerendered |
| `npm run docs:verify` | The counts in §2 of this file are still true |

## 8. How to verify production in five minutes

```bash
curl -s https://woodex-2030.vercel.app/api/health          # {"ok":true,...}
curl -s "https://woodex-2030.vercel.app/api/products?limit=1"   # 1 item, total 148
curl -s -o /dev/null -w '%{http_code}\n' \
  "https://woodex-2030.vercel.app/api/products?limit=abc"        # expect 400

curl -s -X POST https://woodex-2030.vercel.app/api/auth/login \
  -H 'content-type: application/json' \
  -d '{"email":"admin@woodex.pk","password":"<the owner password>"}'
# expect 200 + {"token":"...","user":{...},"caps":["*"]}

# database (Supabase SQL editor, project krtncckcfvzirnrcdjrm)
SELECT (SELECT COUNT(*) FROM products) AS products,
       (SELECT COUNT(*) FROM materials) AS materials,
       (SELECT COUNT(*) FROM services) AS services,
       (SELECT COUNT(*) FROM users) AS users;
# expect 148, 16, 7, >=1
```

## 9. Known debt, with owners' decisions pending

1. **Tailwind majors differ.** Pick one before building a shared design system.
2. **No ORM.** Keep custom SQL, or adopt one. Do not adopt two.
3. **Vite vs Next.js.** Prerendering covers SEO today. Revisit only if a
   concrete requirement (SSR, streaming, per-request HTML) demands it.
4. **No tenant model.** This is the gating decision for any multi-company use.
