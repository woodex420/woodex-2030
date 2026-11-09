# WOODEX 2030 — Production Readiness Report

**Date:** 2026-11-09 · **Commit:** `f583ac0`

> **Note (post-`f583ac0`):** migration `008_audit_log.sql` adds `audit_logs`.
> Counts in this document describe `f583ac0`; current counts live in
> [`00-CURRENT-STATE.md`](../agency-os/00-CURRENT-STATE.md) — now
> **32 tables, 31 CHECKs, 8 migrations, 108 routes**.
**Question:** can this run Woodex's business tomorrow, and can it run a *second*
company?

## Answer

**Yes for one business. No for two.** These are separate verdicts and conflating
them is how multi-tenant products ship with a data leak.

| Dimension | One business | Multiple tenants |
|---|---|---|
| Serves traffic | ✅ live, READY/PROMOTED | — |
| Data integrity | ✅ 28 FKs / 28 CHECKs / 28 CHECKs, 0 unvalidated | — |
| Backup & restore | ⚠️ works, but manual and local | — |
| Isolation | not required | ❌ **absent** |
| Audit trail | ⚠️ per-record only | ❌ none |
| Monitoring | ❌ none | ❌ none |
| Rate limiting | ⚠️ per-instance only | ⚠️ worse when scaled |

## Checklist against spec §16.3

| Item | State |
|---|---|
| Production database provisioned | ✅ Supabase `krtncckcfvzirnrcdjrm` |
| Schema migrated | ✅ 7 migrations, checksummed |
| Data migration verified | ✅ migrator covers all 32 tables; `check:migration` gates it |
| Owner account created | ✅ login verified 200 |
| HTTPS | ✅ |
| CORS configured | ✅ via single-origin rewrites |
| API health verified | ✅ live during this audit |
| Queue workers running | ❌ no queue exists |
| Scheduler running | ❌ |
| Redis verified | ❌ not used |
| File storage verified | ⚠️ `project_files` stores URL metadata only |
| Backups enabled | ⚠️ script exists, **no schedule** |
| Restore tested | ✅ `test:restore` drill passes in CI |
| Monitoring enabled | ❌ |
| Error tracking | ❌ |
| Security checks passed | ⚠️ scan + RBAC yes; no pentest |
| Smoke tests passed | ✅ |
| Rollback procedure tested | ✅ Vercel rollback available; app-level page rollback tested |

**Score: 11 of 18.** The seven misses cluster in two areas — operational
instrumentation (monitoring, error tracking) and durable execution (queue,
scheduler, Redis). Neither is needed to serve one business today; both are
needed before the platform can be trusted with SLAs.

## The three things I would fix before scaling

1. **Tenant isolation.** Nothing else about multi-tenancy matters until data
   cannot cross tenants. This is a schema + middleware + test project, not a
   feature.
2. **Backup offsite + scheduled.** The restore drill proves the backup is
   *restorable*; nothing proves it is *being taken*. Right now the answer to
   "what is our RPO?" is "however long since someone last ran it by hand."
3. **Monitoring.** Production is verified by hand today, which means it is
   verified when someone remembers to look.

## Owner actions only

| Action | Why I cannot do it |
|---|---|
| Create `PRODUCTION_DATABASE_URL` GitHub secret | Vercel `DATABASE_URL` is `sensitive`; `get_env_var` → `null`. Needs the connection string pasted into GitHub |
| Choose backup destination | Needs a storage bucket + credentials |
| Choose payment gateway | Business decision + merchant account |
| Decide tenancy timeline | Determines whether Phase B or C comes next (see tracker §3) |
