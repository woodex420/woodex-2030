/**
 * One way to build "the PostgreSQL schema this application runs on".
 *
 * The complete schema is scripts/schema.postgres.sql (the original 29 tables)
 * PLUS every numbered file in scripts/migrations/ (everything added since).
 * Three callers need exactly that and must never disagree about it:
 *
 *   - scripts/pg-migrate.mjs      copies a SQLite database into PostgreSQL;
 *   - scripts/lib/api-under-test.mjs  gives every module test a PostgreSQL
 *                                 database to run against;
 *   - scripts/test-return-id.mjs  reads the catalogue to learn which tables
 *                                 generate an id.
 *
 * `applyFullSchema` follows the rules of scripts/migrate.mjs, which remains the
 * tool for a hosted database: a migration is applied once, recorded in
 * schema_migrations with its SHA-256, and never re-run. The first version of
 * the copy tool re-executed every migration on every run and then inserted the
 * ledger row again, so a second run against the same target died on the ledger's
 * primary key — after re-running data-touching migrations against whatever was
 * already there.
 */
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
const MIGRATIONS_DIR = path.join(ROOT, "scripts", "migrations");

/** Numbered migrations, in order, each with the checksum the ledger records. */
export function readMigrations() {
  if (!fs.existsSync(MIGRATIONS_DIR)) return [];
  return fs.readdirSync(MIGRATIONS_DIR)
    .filter((name) => name.endsWith(".sql"))
    .map((name) => {
      const prefix = name.match(/^(\d+)_/);
      if (!prefix) throw new Error(`migration "${name}" must start with a numeric prefix, e.g. 003_add_thing.sql`);
      const sql = fs.readFileSync(path.join(MIGRATIONS_DIR, name), "utf8");
      return { version: Number(prefix[1]), name, sql, checksum: crypto.createHash("sha256").update(sql).digest("hex") };
    })
    .sort((a, b) => a.version - b.version);
}

/**
 * Bring a PostgreSQL target to the complete schema.
 * `target` is { q(sql, params) → rows, exec(sql) }.
 * Returns { applied: [names], alreadyApplied: n }.
 * Throws if the ledger disagrees with the files (an applied migration that has
 * since been edited, or a ledger row with no file).
 */
export async function applyFullSchema(target) {
  await target.exec(fs.readFileSync(path.join(ROOT, "scripts", "schema.postgres.sql"), "utf8"));
  await target.exec(`CREATE TABLE IF NOT EXISTS schema_migrations(
    version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL,
    applied_at TEXT NOT NULL, duration_ms INTEGER NOT NULL);`);

  const migrations = readMigrations();
  const ledger = new Map((await target.q("SELECT version, name, checksum FROM schema_migrations ORDER BY version"))
    .map((row) => [Number(row.version), row]));

  const problems = [];
  for (const m of migrations) {
    const recorded = ledger.get(m.version);
    if (recorded && recorded.checksum !== m.checksum) {
      problems.push(`migration ${String(m.version).padStart(3, "0")} (${m.name}) was applied but its file has changed since — applied migrations are immutable`);
    }
  }
  for (const [version, row] of ledger) {
    if (!migrations.some((m) => m.version === version)) problems.push(`the ledger records migration ${version} (${row.name}) but no such file exists`);
  }
  if (problems.length) throw new Error("schema ledger is inconsistent:\n  " + problems.join("\n  "));

  const applied = [];
  for (const m of migrations) {
    if (ledger.has(m.version)) continue;
    const started = Date.now();
    await target.exec("BEGIN");
    try {
      await target.exec(m.sql);
      await target.q("INSERT INTO schema_migrations(version,name,checksum,applied_at,duration_ms) VALUES($1,$2,$3,$4,$5)",
        [m.version, m.name, m.checksum, new Date().toISOString(), Date.now() - started]);
      await target.exec("COMMIT");
    } catch (error) {
      try { await target.exec("ROLLBACK"); } catch { /* keep the migration's own error */ }
      throw new Error(`migration ${m.name} failed: ${error instanceof Error ? error.message : String(error)}`);
    }
    applied.push(m.name);
  }
  return { applied, alreadyApplied: migrations.length - applied.length };
}

/** Open a PGlite directory as a `{ q, exec, close }` target. */
export async function openPglite(dir) {
  const { PGlite } = await import("@electric-sql/pglite");
  const db = new PGlite(dir);
  await db.waitReady;
  return {
    name: "PGlite (embedded Postgres/WASM)",
    q: (sql, params = []) => db.query(sql, params).then((result) => result.rows),
    exec: (sql) => db.exec(sql),
    close: () => db.close(),
  };
}

/** Create a new, empty PGlite database carrying the complete schema. */
export async function createPgliteDatabase(dir) {
  fs.mkdirSync(path.dirname(dir), { recursive: true });
  const target = await openPglite(dir);
  try { return await applyFullSchema(target); }
  finally { await target.close(); }
}

/**
 * Tables in the current schema that hand out an integer `id` — the tables for
 * which an INSERT must report the new id back to the caller.
 */
export async function tablesWithIntegerId(target) {
  const rows = await target.q(`
    SELECT c.table_name
      FROM information_schema.columns c
      JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
     WHERE c.table_schema = current_schema() AND t.table_type = 'BASE TABLE'
       AND c.column_name = 'id' AND c.data_type IN ('smallint', 'integer', 'bigint')
     ORDER BY c.table_name`);
  return rows.map((row) => row.table_name);
}

/**
 * Foreign keys between base tables in the current schema:
 * [{ child, parent, constraint }] — `child` holds the reference, `parent` is
 * what it points at, so a parent must be loaded first and emptied last.
 */
export async function foreignKeys(target) {
  const rows = await target.q(`
    SELECT con.conname AS constraint, child.relname AS child, parent.relname AS parent
      FROM pg_constraint con
      JOIN pg_class child  ON child.oid  = con.conrelid
      JOIN pg_class parent ON parent.oid = con.confrelid
      JOIN pg_namespace ns ON ns.oid = child.relnamespace
     WHERE con.contype = 'f' AND ns.nspname = current_schema()
     ORDER BY child.relname, con.conname`);
  return rows.map((row) => ({ child: row.child, parent: row.parent, constraint: row.constraint }));
}

/**
 * Order `tables` so every table comes after the tables it references.
 * Stable: where the foreign keys express no preference the given order is kept.
 * A table that references itself is fine. A cycle between different tables has
 * no valid order; it is returned in `cycles` and the tables keep their given
 * positions relative to each other.
 */
export function parentsFirst(tables, keys) {
  const wanted = new Set(tables);
  const parentsOf = new Map(tables.map((t) => [t, new Set()]));
  for (const { child, parent } of keys) {
    if (child !== parent && wanted.has(child) && wanted.has(parent)) parentsOf.get(child).add(parent);
  }
  const ordered = [];
  const placed = new Set();
  const remaining = [...tables];
  const cycles = [];
  while (remaining.length) {
    const index = remaining.findIndex((t) => [...parentsOf.get(t)].every((p) => placed.has(p)));
    if (index === -1) {
      /* Every remaining table waits on another remaining table: a cycle. */
      cycles.push([...remaining]);
      for (const t of remaining) { ordered.push(t); placed.add(t); }
      break;
    }
    const [next] = remaining.splice(index, 1);
    ordered.push(next);
    placed.add(next);
  }
  return { ordered, cycles };
}
