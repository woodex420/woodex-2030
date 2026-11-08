# WOODEX 2030 — QUICK TASKS

> Companion to `docs/audits/WOODEX-2030-STACK-AUDIT-2026-11-08.md` (see its §11 for the shipped detail).
> **Q1, Q2, Q5 and the commit-race fix are already done and tested on this branch.** What remains is either 🔑 owner-gated (Q3, Q4, Q19–Q23) or engineering work with no decision needed.
> Every open item has a file and a proof of done.

---

## 🔴 DO TODAY (Q1/Q2 shipped · Q3/Q4 need you)

### ✅ Q1 · Reprice `POST /api/quotes` from the catalog — **DONE**
`apps/api/server.mjs` · `applyCatalogPricingToPublicIntake()`.
Anonymous intake is now priced **only** from `products`: unknown SKU → `400`, name/price overwritten, qty checked against stock (`409`), and self-serve `discountPercent` / `taxPercent` / `shipping` forced to `0`. Free-text (custom furniture) lines are stored at price `0` because a visitor's number is a request, not an agreed amount. Staff keep full authority.
Proof: `no-such-product @ price 1 × 500` → **`400`** (was `201 / PKR 500`); `dining-set-6 @ price 1 × 2` → **`PKR 370 000`** from the catalog.
Two related defects fixed in the same pass: staff calls through the public route were being treated as anonymous (token ignored on allowlisted paths), and `Quotation.tsx` wasn't sending `productId`.

### ✅ Q2 · Token-bucket the four public writes — **DONE**
`30/min/IP` on `POST /leads · /quotes · /orders · /target`, `120/min` on `/track` (a pixel must never throttle a real visitor). `429` + `retry-after`. No new dependency.

### 🔑 Q3 · Replace the public `main` README + rotate — 1 h
`README.md` at `main` (`e7b7d15`) is a truncated chat transcript that **announces** `docs/CREDENTIALS.md` ("all credentials and usernames/passwords") and names an admin email, a password and a Supabase project URL. **Verified: that file was never committed** — no `docs/CREDENTIALS.md` exists in either branch tree, and the application branch's README is clean and credential-free. The repository is **public** (`private: false`), so treat anything ever pasted there as exposed.
Do: replace the `main` README with the real one from the app branch, and rotate any credential that was ever used in that draft (Supabase key, admin password).
**Done when:** `main`'s README matches the application README and grep finds no email/password/`supabase.co` in it.

### Q4 · Make the deployed API able to boot — 2 h 🔑
Vercel project `woodex-2030` (`prj_NZ35RoMsW…`) has **`env: []`**; `server.mjs:36` throws when `VERCEL=1` and `DATABASE_URL` is unset, so `/api/*` is dead on every deployment.
1. Provision Postgres (🔑 owner picks provider + plan).
2. `npm run db:apply-schema` against it.
3. Add `DATABASE_URL` (encrypted, all targets) + `INITIAL_ADMIN_EMAIL`/`INITIAL_ADMIN_PASSWORD` (production only, then delete the password var after first boot).
**Done when:** `curl https://<preview>/api/health` → `{"ok":true,"driver":"postgres"}`.

---

## 🟠 THIS WEEK (≈ 2.5 d)

### ✅ Q5 · Security headers + real error handling — **DONE**
`nosniff`, `Referrer-Policy`, `X-Frame-Options: SAMEORIGIN`, `CORP`, `Permissions-Policy`, HSTS when deployed, `X-Powered-By` removed, explicit CORS allowlist (`WOODEX_PUBLIC_ORIGINS` + `*.vercel.app`) answering preflight `204`, malformed JSON → `400 {"error":"request body is not valid JSON"}`, unknown `/api/*` → JSON `404`, and a terminal error boundary so no stack trace ever reaches a client. No `helmet` dependency — ~40 lines, no supply chain.

### ✅ Q-(new) · Respond-before-commit race — **DONE**
`wrapTx()` answered inside the transaction, so a client's very next request could miss the row (`404` on payment, unreadable tracking digest). Reproduced deterministically on SQLite, fixed with `wrapTxJson()` across **9** mutating routes. Covered by the existing suite, which now passes 96/96 on both drivers.

### Q6 · Postgres integrity pass — 1 d
Add to `scripts/schema.postgres.sql` + a new `migrations/0002_constraints.sql`:
`FOREIGN KEY (client_id) REFERENCES clients(id)`, `CHECK (status IN (…))` for `orders/quotes/returns`, `DEFAULT now()` audit columns, `idx_leads_status_created`, `idx_orders_stage`, `idx_invoices_status`, `idx_sessions_expires_at`. Add a `schema_migrations` table and a `npm run db:migrate` that applies ordered files.
**Done when:** `qa-full` + `qa:pg` still **96/96** with constraints enabled.

### Q7 · One React / Vite / Tailwind — 1 d
Storefront → React 19.2, Vite 8, Tailwind 4 (dashboard is already there). Delete `apps/woodex-reimagined-preview` (+ `bun.lock`, `bun.lockb`, its `supabase/functions/mcp`). Add `tsc -b` for **both** apps to CI.
**Done when:** `(cd apps/storefront && npx tsc -b)` exits 0, and `du -sh apps/` drops ~12 MB.

### Q8 · Image pipeline — 4 h
Convert the 92 JPEGs to AVIF + WebP at 3 widths, emit `srcset`, keep the originals out of `dist`. Target `dist` ≤ 3 MB and `index.*.js` ≤ 200 KB.
**Done when:** `du -sh dist` ≤ 3 MB and Lighthouse mobile LCP < 2.5 s on `/`.

---

## 🟡 NEXT SPRINT (≈ 1.5 w)

| Q | Task | File |
|---|---|---|
| Q9 | SQL aggregates for `/api/clients`, `/api/stats`, `/api/marketing/stats` (today: ~6 queries × N rows in JS) | `server.mjs:1411,2001` |
| Q10 | Replace `Analytics.tsx` + `Projects.tsx` mock data, or badge them **Demo** in the sidebar and page header | `apps/dashboard/src/pages/*` |
| Q11 | Remove the last mock KPIs from `Dashboard.tsx` (Overview hero) | `apps/dashboard/src/pages/Dashboard.tsx` |
| Q12 | Invoice / payment / return audit trail (quotes and pages already have one) | `server.mjs:1875-1999` |
| Q13 | `zod` schema per public endpoint → one boundary instead of 60 hand-rolled checks | new `apps/api/schemas.mjs` |
| Q14 | OpenAPI 3.1 from the route table → generate `apps/dashboard/src/lib/api.ts` types | new |
| Q15 | `track_events` retention job (90 d) + a `npm run db:prune` | new |
| Q16 | Tag the two competing plan docs: keep `AGENCY-OS-MASTER-PLAN-V2.md`, archive `-v3` under `docs/archive/` | `docs/*` |
| Q17 | Fix `scripts/secret-scan.mjs` to scan the **worktree incl. untracked** (it currently reads `git show HEAD:<file>` → 1 file on `main`) | `scripts/secret-scan.mjs` |
| Q18 | Add the three findings that can't be seen by `qa-full` to the QA suite: fake-SKU quote must 400, malformed JSON must be JSON 4xx, headers present | `scripts/qa-full.mjs` |

---

## 🟢 WHEN INTEGRATIONS ARE APPROVED 🔑

| Q | Integration | Note |
|---|---|---|
| Q19 | Payments — Safepay / JazzCash / Stripe (PK-first) | checkout currently **creates an order request only**; no capture, no webhook, no reconciliation. Do not present the storefront as "accepting payment" until this exists. |
| Q20 | Transactional email (Resend/SendGrid) | quotes/invoices have no delivery path; WhatsApp is a `wa.me` deep link, not a send |
| Q21 | WhatsApp Cloud API | the inbox is an internal log today; `delivery: "manual_handoff_not_sent"` is honest |
| Q22 | `tenant_id` + RLS | required before "Agency OS" can host a second client |
| Q23 | Backups / PITR + restore drill | no backup exists anywhere in the repo |

---

## 🧪 How to re-verify the shipped work

```bash
npm ci --legacy-peer-deps
PORT=3013 WOODEX_DATA_DIR=/tmp/wx-check npm -w @woodex/api start &   # or: node apps/api/server.mjs
WOODEX_DATA_DIR=/tmp/wx-check node scripts/qa-full.mjs --base=http://127.0.0.1:3013   # 96/96
npm run qa:pg                                                                          # 96/96 (PGlite)
npm run vercel:build                                                                   # ~32 s
```

The four checks that pin this work: *public quote intake cannot be poisoned*, *staff quote creation stays privileged*, *API errors are JSON and never leak a stack trace*, *public write paths are rate limited*.

---

## ✅ Already done — do not re-open

These were listed as blockers in `docs/audits/WOODEX-2030-MASTER-AUDIT-REPORT-2026-11-06.md` §1 and are **fixed** (re-verified 2026-11-08):

- ~~Demo users/customer data seeded in production~~ → `allowDemoSeed = !IS_SERVERLESS && !IS_PRODUCTION`; production bootstraps one owner from env and fails closed.
- ~~`viewer` can read invoices/client PII~~ → verified `403` on invoices, clients, leads, orders, quotes, users, pages.
- ~~Order lookup by reference alone~~ → requires the SHA-256 of a 32-byte tracking token; ref-only returns `404`.
- ~~Anonymous SSE leaks customer/payment metadata~~ → `401` anonymous, `410` serverless, owner-only locally.
- ~~Checkout trusts client prices~~ → server reprices (verified `1 → 185 000`) — **for orders; quotes still open, see Q1**.
- ~~Drafts visible before publish~~ → published snapshot + HMAC preview links, suite-verified.
- ~~Form shows success after a failed POST~~ → `post()` returns `null`; Contact/Quotation/Checkout all reject `null`.
