# Postgres cut-over runbook (Supabase-compatible)

> **Corrected 2026-11-16.** The data-copy commands on this page were wrong in a
> way that mattered: run as written, `pg-migrate.mjs` failed on a duplicate key,
> and with `--truncate` it reported `ALL GREEN` while leaving nine tables empty
> (Furniture 2030 finding F-04). The tool is fixed and the commands below are
> updated. The procedure for the copy itself — backups, how to read the output,
> what each failure means — now lives in
> [`../operations/DATA-MIGRATION-RUNBOOK.md`](../operations/DATA-MIGRATION-RUNBOOK.md).
>
> The counts quoted further down (22 tables, 77 checks) are from when this page
> was written and are kept as history. Current figures:
> [`../agency-os/00-CURRENT-STATE.md`](../agency-os/00-CURRENT-STATE.md).

Status: **dual-driver flip implemented and rehearsed** — schema/migrator verification is green and the API's full 77-check battery passes on both SQLite and PGlite (embedded PostgreSQL). The only remaining cutover step is provisioning/migrating a real hosted PostgreSQL instance and running the same canary against it.

## Files
- `apps/api/db-adapters.mjs` — promise-based SQLite/PostgreSQL facade, `pg.Pool` transactions with request-local connection context, Postgres placeholder/`RETURNING`/`INSERT OR IGNORE` compatibility; `DATABASE_URL` unset keeps SQLite as default.
- `scripts/qa-pg.mjs` — disposable PGlite rehearsal: migrates the current SQLite snapshot, runs the full HTTP + SQL QA suite against one shared PGlite instance, then removes its temporary data directory (`npm run qa:pg`).
- `scripts/schema.postgres.sql` — 1:1 mirror of the SQLite schema (TEXT-carried ISO timestamps
  and JSON blobs are deliberate: every existing comparison/sort keeps working) **plus**
  compatibility shims: `datetime(text)` and a `group_concat(text)` aggregate, so the API's
  current SQL runs unchanged. Tenancy/RLS is stubbed as a comment block for P2's tenant work.
- `scripts/pg-migrate.mjs` — copier + verifier:
  - `node scripts/pg-migrate.mjs --pglite <dir> --truncate` → rehearse locally (embedded Postgres/WASM)
  - `node scripts/pg-migrate.mjs --dsn postgres://… --truncate --confirm-replace` → real server.
    It **replaces** the target's contents with the source; it does not merge. Repeatable.
  - copies every table with explicit ids in the order the foreign keys require, inside one
    transaction; after the last table it asserts per-table **row count + content digest**
    and runs a battery of the app's trickiest queries (shims, ISO substr grouping, session
    continuity, finance sums, RETURNING write path); only then commits and `setval`s every
    identity past `MAX(id)` (post-cutover inserts can't collide). Any failure rolls back.

## Step 1 — provision (Supabase example)
1. New project; note DB connection string (Session pooler or direct).
2. Either paste `schema.postgres.sql` into the SQL editor, or:
   `psql "$DATABASE_URL" -f scripts/schema.postgres.sql`

## Step 2 — migrate + verify (maintenance window, ~seconds at current scale)
1. Pause writers (stop API dev/procs).
2. Back up the target (`pg_dump`, or `node scripts/backup.mjs --pg "$DATABASE_URL"`), then
   `node scripts/pg-migrate.mjs --dsn "$DATABASE_URL" --truncate --confirm-replace`
   → require **ALL GREEN**, `0 table(s) differ` and exit code 0.
   (A plain run without `--truncate` is refused: a new database already holds the
   rows the migrations seed.)
3. Re-running the same command is safe and gives the same result.
4. Sessions copy too — logged-in users notice nothing.

## Step 3 — driver flip (implemented; live PG canary still required)

The API now creates `db = await createDb({ sqliteFile, databaseUrl: process.env.DATABASE_URL })`.
Both adapters expose promise-returning `prepare(sql).get/all/run`, `exec`, and `transaction`;
all DB-using routes/helpers await their queries.

- No `DATABASE_URL` → SQLite remains the default. Existing `data/woodex.db` is untouched.
- `DATABASE_URL=postgres://…` → `pg.Pool`; transaction callbacks pin one connection with
  `AsyncLocalStorage`, so concurrent requests don't cross transaction boundaries.
- `DATABASE_URL=pglite:/path` → embedded Postgres for local rehearsal/CI; not a production URL.
- The facade translates `?` to `$n`, `INSERT OR IGNORE` to `ON CONFLICT DO NOTHING`, and
  requests `RETURNING id` on identity-table inserts so existing `lastInsertRowid` consumers work.
- SQLite `PRAGMA`/schema bootstrap runs only on SQLite; Postgres schema comes from Step 1/2.

### Verify locally

```bash
npm run qa                 # SQLite API battery
npm run qa:pg              # disposable PGlite migration + full 77-check API/SQL battery
```

`qa:pg` applies the 22-table migration, then imports the API in-process so the HTTP tests and
direct integrity/cleanup assertions use the *same* PGlite instance. Temporary rehearsal data
is deleted afterward. Latest rehearsal: **22/22 table digests + migrator query battery green;
77/77 API checks green**.

### Real hosted cutover (remaining operational step)

1. Provision Postgres and store `DATABASE_URL` in the runtime secret manager (never commit it).
2. Pause writers; back up the target; run
   `node scripts/pg-migrate.mjs --dsn "$DATABASE_URL" --truncate --confirm-replace`;
   require `ALL GREEN` and exit code 0. See the
   [data migration runbook](../operations/DATA-MIGRATION-RUNBOOK.md).
3. Run an in-process canary against that same target:
   `DATABASE_URL="$DATABASE_URL" PORT=0 node scripts/qa-full.mjs --pg-rehearsal`
   (the flag starts the API on an ephemeral port and shares its DB handle with test assertions).
4. Start the normal API with `DATABASE_URL="$DATABASE_URL" npm -w @woodex/api run start`.
   Rollback remains `unset DATABASE_URL` and restart; the SQLite file is still parked.

## Notes / decisions
- Booleans are `INTEGER 0/1`, dates are ISO `TEXT`, JSON is `TEXT` — matches app semantics
  exactly; migrating to `BOOLEAN/JSONB/TIMESTAMPTZ` is a later normalization ticket.
- `PRAGMA`/`sqlite_sequence` assumptions were removed when `pages.theme` used a guarded ALTER;
  the PG path creates it directly.
- Never hand-edit digests: if the migrator FAILs on a table, diff rows with
  `SELECT * FROM <t> ORDER BY <key>` on both engines. A failed run has already
  rolled back, so the target is as it was before the run.
