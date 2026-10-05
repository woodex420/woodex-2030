# Postgres cut-over runbook (Supabase-compatible)

Status: **data layer ready & verified** — schema, migrator and full verification run green on
real Postgres semantics (PGlite WASM in CI-less sandbox; same SQL runs on Supabase).
The API still executes queries through `node:sqlite`; flipping it to `pg` is the remaining
Step 3 below (a bounded async refactor, listed honestly rather than hand-waved).

## Files
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

## Step 3 — flip the driver (the real work, ~1 focused session)
In `apps/api`: introduce `db = createDb(DATABASE_URL)` with the same surface
(`prepare(sql).get/all/run`, `exec`) implemented over a `pg.Pool`:
1. Queries become async → make the affected route handlers `async` (`wrap()` already
   supports async fn since P7 publish flow).
2. `info.lastInsertRowid` → `RETURNING id` on every INSERT.
3. `?` placeholders → `$n` (helper-level rewrite or literal pass-through since all SQL
   already flows through `prepare`).
4. Keep SQLite as `DATABASE_URL`-unset fallback (dev default) — one file, two adapters.
5. Re-run this repo's E2E curl battery against Postgres before shipping; rollback = unset
   `DATABASE_URL` (SQLite file was never dropped, just parked).

## Notes / decisions
- Booleans are `INTEGER 0/1`, dates are ISO `TEXT`, JSON is `TEXT` — matches app semantics
  exactly; migrating to `BOOLEAN/JSONB/TIMESTAMPTZ` is a later normalization ticket.
- `PRAGMA`/`sqlite_sequence` assumptions were removed when `pages.theme` used a guarded ALTER;
  the PG path creates it directly.
- Never hand-edit digests: if the migrator FAILs on a table, diff rows with
  `SELECT * FROM <t> ORDER BY <key>` on both engines.
