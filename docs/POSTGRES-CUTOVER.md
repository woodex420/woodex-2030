# Postgres cut-over runbook (Supabase-compatible)

Status: **dual-driver flip implemented and rehearsed** — schema/migrator verification is green and the API's full 77-check battery passes on both SQLite and PGlite (embedded PostgreSQL). The only remaining cutover step is provisioning/migrating a real hosted PostgreSQL instance and running the same canary against it.

## Files
- `apps/api/db-adapters.mjs` — promise-based SQLite/PostgreSQL facade, `pg.Pool` transactions with request-local connection context, Postgres placeholder/`RETURNING`/`INSERT OR IGNORE` compatibility; `DATABASE_URL` unset keeps SQLite as default.
- `scripts/qa-pg.mjs` — disposable PGlite rehearsal: migrates the current SQLite snapshot, runs the full HTTP + SQL QA suite against one shared PGlite instance, then removes its temporary data directory (`npm run qa:pg`).
- `scripts/schema.postgres.sql` — 1:1 mirror of the SQLite schema (TEXT-carried ISO timestamps
  and JSON blobs are deliberate: every existing comparison/sort keeps working) **plus**
  compatibility shims: `datetime(text)` and a `group_concat(text)` aggregate, so the API's
  current SQL runs unchanged. Tenancy/RLS is stubbed as a comment block for P2's tenant work.
- `scripts/pg-migrate.mjs` — copier + verifier:
  - `node scripts/pg-migrate.mjs --pglite <dir>` → verify locally (embedded Postgres/WASM)
  - `node scripts/pg-migrate.mjs --dsn postgres://… [--truncate]` → real server; idempotent with `--truncate`
  - copies all 22 tables with explicit ids, `setval`s every identity past `MAX(id)`
    (post-cutover inserts can't collide), then asserts per-table **row count + content digest**
    and runs a battery of the app's trickiest queries (shims, ISO substr grouping, session
    continuity, finance sums, RETURNING write path).

## Step 1 — provision (Supabase example)
1. New project; note DB connection string (Session pooler or direct).
2. Either paste `schema.postgres.sql` into the SQL editor, or:
   `psql "$DATABASE_URL" -f scripts/schema.postgres.sql`

## Step 2 — migrate + verify (maintenance window, ~seconds at current scale)
1. Pause writers (stop API dev/procs).
2. `node scripts/pg-migrate.mjs --dsn "$DATABASE_URL"` → require **ALL GREEN**.
3. Re-run once with `--truncate` to prove idempotency if desired.
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
2. Pause writers; run `node scripts/pg-migrate.mjs --dsn "$DATABASE_URL"`; require `ALL GREEN`.
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
  `SELECT * FROM <t> ORDER BY <key>` on both engines.
