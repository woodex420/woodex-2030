# WOODEX 2030 — Security & Production Readiness Audit

**Date:** 2026-11-09 · **Commit:** `f583ac0` · **Method:** source inspection + live checks. No penetration test was performed, and none is claimed.

> **Note (post-`f583ac0`):** migration `008_audit_log.sql` adds `audit_logs`.
> Counts in this document describe `f583ac0`; current counts live in
> [`00-CURRENT-STATE.md`](../agency-os/00-CURRENT-STATE.md) — now
> **32 tables, 31 CHECKs, 8 migrations, 108 routes**.

## 1. What is genuinely solid

| Control | Evidence | Assessment |
|---|---|---|
| Password hashing | `server.mjs:985` — `scryptSync(pw, salt, 32)`, per-user salt, `timingSafeEqual` on compare | Correct |
| CSRF exposure | Bearer tokens only, **no cookies** (`grep -c cookie` → 1, unrelated) | Immune by construction |
| Session model | 64-hex tokens in `sessions`, 7-day expiry, server-side revocation | Correct |
| SQL injection | Parameterised statements throughout the adapter | No string-built SQL found in audit |
| Secret hygiene | `npm run secret-scan` clean over 406 tracked files | Gated in CI |
| Rate limiting | Exists on public writes and login (`server.mjs:800`, `2072`) | **In-memory only** — see §2 |
| Tenant isolation | N/A | **Absent** — see §2 |

## 2. Security findings

### S-1 · No tenant isolation — CRITICAL

`tenant_id` and RLS exist only inside a SQL comment (`schema.postgres.sql:185-187`).
Every authenticated user can read every business record. This is not a
vulnerability in the abstract: the platform is *defined* as multi-tenant, so the
first external tenant becomes a data breach.

**Correction:** tenant table, membership, server-derived context (never a
request parameter), RLS policies, and automated cross-tenant tests before any
onboarding. **Blocker for P3-1.**

### S-2 · Rate limiting is per-instance — HIGH

`publicWrites` is a `Map` in process memory (`server.mjs:800`). On Vercel each
lambda instance has its own map, so the effective limit multiplies by the
instance count. A brute-force attempt spread across instances is throttled far
more loosely than the code implies — and the 429 tests pass because they hit
**one** instance.

That last point is the real lesson: the test suite proves the limiter works,
and the limiter is still inadequate in production. The test models the wrong
topology.

### S-3 · No platform audit log — HIGH

26 `audit` references exist, but they are JSON blobs on individual quotes and
tasks. There is **no `audit_logs` table**. Nobody can answer "who changed this
invoice and when" across the platform.

### S-4 · No MFA, password reset, or email verification — MEDIUM

All four searched and absent. A user who forgets their password has no
self-service path; recovery is a manual database intervention.

### S-5 · Integration and messaging are unverified by construction — MEDIUM

`inbox`/`conversations`/`messages` record messages, but no provider adapter
exists. There is no way to distinguish "we logged that we sent it" from "it was
delivered". No webhook signature validation exists because no webhooks exist.

### S-6 · Non-constant-time risk on comparison paths — LOW / UNVERIFIED

Password comparison uses `timingSafeEqual`. Token lookup is a parameterised
`SELECT` on an indexed column, which is standard practice; no timing analysis
was performed and none is claimed.

## 3. Production readiness

| Item | State | Evidence |
|---|---|---|
| Production deploy | **Ready** | `dpl_BHkbaV27NVdZLmijHwQe66samoq1`, READY/PROMOTED, serves `f583ac0` |
| Live API | **Verified** | `/api/health` ok; `?limit=2` correct; `?limit=abc` → 400 |
| Production DB schema | **Verified by hand** | 28 FKs, 0 unvalidated, 28 CHECKs |
| Hosted drift audit in CI | **Skipped** | `PRODUCTION_DATABASE_URL` secret absent |
| Owner login | **Verified** | 200 + session (earlier session; not re-run in this audit) |
| Backup | **Local only** | `backup.mjs` + restore drill pass; no schedule, no offsite |
| Monitoring / error tracking | **Absent** | No Sentry-equivalent, no alerting |
| HTTPS | **Yes** | Vercel-managed |
| Hostinger | **Not executed** | Documented shape only |

### Honest readiness statement

The application is **operationally live for a single business** (Woodex) and
**not ready for a second tenant**. Those are different questions and the
distinction matters: nothing in the current deployment is failing, and nothing
in the current data model can safely admit an external company.

## 4. Blockers requiring owner action

1. **`PRODUCTION_DATABASE_URL` GitHub secret** — I cannot create it. The Vercel
   `DATABASE_URL` is type `sensitive` and `get_env_var` returns `null`. Until it
   exists, the only production schema verification is manual.
2. **Backup destination** — needs an object-storage bucket and credentials.
3. **Payment provider** — needs merchant credentials and a business decision on
   which gateway.
4. **Tenancy scope** — whether external agencies are a real near-term customer
   (see `WOODEX_2030_MASTER_IMPLEMENTATION_TRACKER.md` §3).
