# Data migration runbook — SQLite → PostgreSQL

How to copy a WOODEX database from the SQLite file into PostgreSQL with
`scripts/pg-migrate.mjs`, and how to know it worked.

Last revised 2026-11-16 (Furniture 2030, Phase 1). The tool's behaviour is
enforced by `npm run test:migrate`; if this page and that test disagree, the
test is right and this page needs correcting.

## When you need this

| Situation | Use this runbook? |
|---|---|
| Moving a site that ran on the SQLite file to a PostgreSQL server | Yes |
| Rehearsing that move on your own machine | Yes — section 2 |
| A new PostgreSQL site with no existing data | No. Start the application; it creates the schema. |
| Adding a table or column to a database already on PostgreSQL | No. That is a schema migration: `scripts/migrations/` and `npm run db:migrate:status`. |
| Restoring a backup | No. See `scripts/backup.mjs` (`--verify`, `--restore`); `npm run test:restore` rehearses it. |

## 1. What the tool does

1. Brings the target to the complete schema: the base file, then every
   migration that the target's ledger does not already record.
2. Checks, before changing any row, that the copy can work: every source column
   exists on the target, the foreign keys have a valid order, and the target is
   in the state the flags expect.
3. In **one transaction**: empties the target tables (only with `--truncate`),
   copies every table parents-first, then re-reads every table on both sides
   and compares row count and content.
4. Commits only if every table matches. Otherwise it rolls back, and the target
   holds exactly what it held before the run.

The source file is opened read-only and is never written to.

**It replaces; it does not merge.** After a successful run the target holds
what the source holds and nothing else. Rows that existed only on the target
are gone.

## 2. Rehearse first (no server needed)

```bash
WOODEX_SQLITE_SOURCE=/path/to/woodex.db \
  node scripts/pg-migrate.mjs --pglite /tmp/woodex-rehearsal --truncate
```

`--pglite` is PostgreSQL running inside Node, in a folder. The same schema, the
same constraints, the same checks — and nothing outside that folder is touched.
Read the output as in section 4. Delete the folder afterwards.

A rehearsal that fails here will fail on the server for the same reason, so fix
the source (or report the defect) before going further.

## 3. The real run

### Before

1. **Stop the application.** The copy locks every table until it finishes, and
   anything written to the source after the copy starts is not copied.
2. **Back up both sides.**
   - Source (the SQLite file): `npm run db:backup` writes a consistent, compressed
     snapshot to `backups/`; `npm run db:verify-backup -- backups/<file>.db.gz`
     proves it opens and counts its rows. Set `WOODEX_DATA_DIR` first if the
     file is not in `data/`.
   - Target (the PostgreSQL database):
     `mkdir -p backups && pg_dump "$DATABASE_URL" -Fc -f backups/before-copy.dump`
     where `pg_dump` is available. Where it is not,
     `node scripts/backup.mjs --pg "$DATABASE_URL" --out backups/before-copy`
     exports every table as JSON, and
     `node scripts/backup.mjs --restore backups/before-copy --to "$DATABASE_URL" --truncate`
     puts it back (`npm run test:restore` rehearses that pair).
   - Keep backups in `backups/`: Git ignores that folder. A dump holds customer
     data and must never be committed.
3. Have the connection string for the target. One long transaction does the
   whole copy, so use the direct connection or a session pooler (port 5432 on
   Supabase) rather than a transaction pooler.

### Run

An empty, brand-new database still holds a few rows: the migrations create the
chart of accounts (16 rows in `accounts`) and one procurement setting (1 row in
`meta`). So the tool will refuse a plain run and tell you so. The source carries
its own copy of those rows, and that copy is the one its journal entries point
at — replacing is the correct thing to do.

```bash
WOODEX_SQLITE_SOURCE=/path/to/woodex.db \
  node scripts/pg-migrate.mjs --dsn "postgres://USER:PASSWORD@HOST:5432/DATABASE" \
  --truncate --confirm-replace
```

| Flag | Meaning |
|---|---|
| `--dsn <url>` | A PostgreSQL server. |
| `--pglite <dir>` | Embedded PostgreSQL in a folder (rehearsal, tests). |
| `--truncate` | Empty the target's tables first, then copy. Without it, a target that holds any row is refused. |
| `--confirm-replace` | Required with `--dsn` and `--truncate` when the target holds rows. It is the "yes, delete what is there" answer. |

Do not put the connection string in a file that is committed, in a chat
message, or in a ticket. Type it, or read it from an environment variable.
The tool prints the target with the password replaced by `***`, so its output
is safe to share.

### After

1. Read the output (section 4). Exit code `0` and the `ALL GREEN` line are both
   required.
2. Point the application at the PostgreSQL database and start it.
3. Sign in, open Overview, and open one record you know: a client, an invoice, a
   product with stock. Check a figure you can verify from somewhere else.
4. Keep the SQLite file and the target backup until the site has run a full
   working week on PostgreSQL.

## 4. Reading the output

A good run ends like this:

```text
[migrate] source: data/woodex.db (read-only) · target: PostgreSQL postgres://USER:***@HOST:5432/DATABASE
[migrate] schema ready — 0 migration(s) applied now, 18 already recorded
[migrate] copy order follows 86 foreign keys (54 table(s) sit in a different position than in the list)
[migrate] copy order: products, materials, services, quote_templates, orders, …
[migrate] target emptied once, before the copy (17 row(s) in 2 table(s))
┌─────────┬──────────────────────────┬────────┬────────┬─────────┬────────┐
│ (index) │ table                    │ source │ target │ content │ result │
│ 0       │ 'products'               │ 148    │ 148    │ 'same'  │ 'PASS' │
│ 1       │ 'materials'              │ 16     │ 16     │ 'same'  │ 'PASS' │
  …one line for each of the 71 tables…
[migrate] final check, after the last table: 416 row(s) in the source, 416 in the target, 0 table(s) differ
  PASS · public landing blocks (8 rows, source 8)
  …
[migrate] ✅ ALL GREEN — 416 row(s) in 71 tables, verified after the last table was copied; committed.
```

The figures above are from the demonstration data (416 rows); yours will differ.

What to check, in this order:

1. **The `final check` line.** The two row totals are equal and `0 table(s)
   differ`. This line is computed after the last table is copied — it is the
   state the database is actually left in.
2. **The table.** Every row says `PASS`; `source` equals `target`; `content` is
   `same` (or `—` for a table the source does not have).
3. **`committed`** at the end of the last line.
4. **Exit code 0.** In a script: `node scripts/pg-migrate.mjs … && echo ok`.

## 5. When it fails

Every failure ends with `❌` and a non-zero exit code. If the copy had started,
you will also see `rolled back — the target holds exactly what it held before
this run.` Nothing needs cleaning up; fix the cause and run again.

| Message | Cause | What to do |
|---|---|---|
| `source SQLite file not found` | Wrong path in `WOODEX_SQLITE_SOURCE`. | Correct the path. |
| `the target already holds N row(s)` | No `--truncate`, and the target is not empty (a new database never is). | Add `--truncate` if replacing is what you intend. |
| `--truncate would DELETE N row(s)` | A server target holds rows and `--confirm-replace` was not given. | Back up the target (section 3), then add `--confirm-replace`. |
| `<table>: the target has no column …` | The source has a column the PostgreSQL schema lacks. | A schema defect. Do not work around it; the column needs a migration. |
| `<table>: rows a–b were rejected by the target — …` | A row breaks a rule PostgreSQL enforces and SQLite does not: a reference to a row that does not exist, a duplicate, a value out of range. | The message names the constraint. Correct the row in a **copy** of the source, rehearse, then repeat. |
| `<table>: source N row(s), target M; content …` | The table does not match after the whole copy. | Do not use the target. Report it with the full output — this is the check that exists because a past version lost rows silently. |
| `foreign keys form a cycle` | Two tables reference each other. | A schema defect; no copy order exists. |
| `ROLLBACK FAILED` | The connection dropped mid-run. | PostgreSQL discards an unfinished transaction on its own, but confirm: restore the target backup if in doubt. |

## 6. What changed on 2026-11-16, and why

Before this date `--truncate` emptied each table immediately before copying it,
with `CASCADE` — which also empties every table that refers to it. Four tables
were copied before a table they refer to, so they were filled, checked, and then
emptied again when the tool reached the other table. On the demonstration data
nine tables ended empty (every lead, client, quote, invoice, payment and task,
and the whole stock ledger: 416 rows in, 213 out), and the run printed
`ALL GREEN` with exit code 0, because each table had been checked only at the
moment it was copied.

No production database was migrated with that version as part of this work.
If one was migrated earlier with `--truncate`, compare its row counts with the
source file it came from.

Now:

- the target is emptied **once**, in one statement, before anything is copied;
- tables are copied in the order the target's **own foreign keys** require,
  read from the database rather than from a hand-written list;
- everything is verified **after the last table**, and the result decides
  whether the transaction is committed;
- `npm run test:migrate` reproduces the old failure with a trigger that deletes
  rows after their table was copied, and requires the tool to fail.
