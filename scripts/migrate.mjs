#!/usr/bin/env node
/**
 * Ordered, checksummed migration runner for the PostgreSQL schema.
 *
 *   node scripts/migrate.mjs --dsn postgres://…            # apply pending
 *   node scripts/migrate.mjs --pglite /tmp/wxpg            # embedded Postgres
 *   node scripts/migrate.mjs --dsn … --status              # show ledger only
 *   node scripts/migrate.mjs --dsn … --dry-run             # what would run
 *   node scripts/migrate.mjs --dsn … --rollback 009        # undo the newest
 *
 * Why a ledger: until now the only way to know whether production had a given
 * schema change was to run a query and interpret the result by hand. That does
 * not scale past one person and one afternoon, and it cannot answer "was this
 * database ever migrated?" after the fact.
 *
 * Design decisions worth knowing before you add a migration:
 *
 *   * Immutable once applied. Each file's SHA-256 is recorded. Editing an
 *     already-applied file is an error, not a convenience — otherwise two
 *     databases that both claim "migration 002" can hold different schemas.
 *     To change something, add the next number.
 *
 *   * Each migration runs in its own transaction, with its ledger row written
 *     inside the same transaction. A failure leaves neither the schema change
 *     nor the ledger claim behind, so re-running resumes exactly where it
 *     stopped.
 *
 *   * PostgreSQL-only statements are permitted inside files (DO blocks,
 *     ALTER ... VALIDATE), which is why the file body is executed as a batch
 *     rather than split on semicolons. Splitting SQL on semicolons breaks on
 *     dollar-quoted bodies, which is exactly what these migrations use.
 *
 *   * Advisory lock. Two deploys running migrations at once is the classic way
 *     to get a half-applied schema; the lock serialises them.
 */
import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const MIGRATIONS_DIR = path.join(ROOT, "scripts", "migrations");
const LOCK_ID = 0x776f6f64; // "wood" — arbitrary, stable

const args = process.argv.slice(2);
const arg = (f) => { const i = args.indexOf(f); return i >= 0 ? args[i + 1] : undefined; };
const dsn = arg("--dsn");
const pgliteDir = arg("--pglite");
const statusOnly = args.includes("--status");
const dryRun = args.includes("--dry-run");
const rollbackVersion = arg("--rollback");

if (!dsn && !pgliteDir) {
  console.error("usage: node scripts/migrate.mjs --dsn <postgres-url> | --pglite <dir> [--status] [--dry-run] [--rollback <version>]");
  process.exit(2);
}

/* ---------- rollback ----------
   Rollback files live in scripts/migrations/rollback/ because every *.sql file
   in scripts/migrations/ is treated as a forward migration by this runner, the
   pg-migrate rehearsal, test-integrity and the docs verifier. Naming a file
   there "009_x.rollback.sql" would apply it as migration 009.

   A rollback runs in one transaction together with the deletion of its ledger
   row, for the same reason a forward migration does: a half-rolled-back
   database that still claims the migration is applied is the worst of both. */
const ROLLBACK_DIR = path.join(MIGRATIONS_DIR, "rollback");
function readRollback(version) {
  if (!fs.existsSync(ROLLBACK_DIR)) return null;
  const want = String(version).padStart(3, "0");
  const file = fs.readdirSync(ROLLBACK_DIR)
    .filter((f) => f.endsWith(".sql") && f.startsWith(want + "_"))
    .sort()[0];
  if (!file) return null;
  return { file, sql: fs.readFileSync(path.join(ROLLBACK_DIR, file), "utf8") };
}

/* ---------- collect migration files, ordered by numeric prefix ---------- */
function readMigrations() {
  if (!fs.existsSync(MIGRATIONS_DIR)) return [];
  const files = fs.readdirSync(MIGRATIONS_DIR).filter((f) => f.endsWith(".sql"));
  const parsed = files.map((name) => {
    const m = name.match(/^(\d+)_/);
    if (!m) throw new Error(`migration "${name}" must start with a numeric prefix, e.g. 003_add_thing.sql`);
    return { version: Number(m[1]), name };
  });
  parsed.sort((a, b) => a.version - b.version);

  /* Duplicate versions are a real hazard: order becomes filesystem-dependent. */
  const seen = new Map();
  for (const p of parsed) {
    if (seen.has(p.version)) {
      throw new Error(`duplicate migration version ${p.version}: "${seen.get(p.version)}" and "${p.name}"`);
    }
    seen.set(p.version, p.name);
  }
  return parsed.map((p) => {
    const sql = fs.readFileSync(path.join(MIGRATIONS_DIR, p.name), "utf8");
    return { ...p, sql, checksum: crypto.createHash("sha256").update(sql).digest("hex") };
  });
}

/* ---------- backends ---------- */
async function makeBackend() {
  if (pgliteDir) {
    const { PGlite } = await import("@electric-sql/pglite");
    const db = new PGlite(pgliteDir);
    return {
      name: "PGlite (embedded Postgres/WASM)",
      q: (s, p = []) => db.query(s, p).then((r) => r.rows),
      exec: (s) => db.exec(s),
      close: () => db.close(),
      tx: async (fn) => { await db.exec("BEGIN"); try { const r = await fn(); await db.exec("COMMIT"); return r; } catch (e) { try { await db.exec("ROLLBACK"); } catch {} throw e; } },
    };
  }
  const pgMod = await import("pg");
  const pg = pgMod.default ?? pgMod;
  const client = new pg.Client({
    connectionString: dsn,
    ssl: /localhost|127\.0\.0\.1/.test(dsn) ? undefined : { rejectUnauthorized: false },
    connectionTimeoutMillis: 15_000,
  });
  await client.connect();
  return {
    name: "PostgreSQL",
    q: (s, p = []) => client.query(s, p).then((r) => r.rows),
    exec: (s) => client.query(s),
    close: () => client.end(),
    tx: async (fn) => {
      await client.query("BEGIN");
      try { const r = await fn(); await client.query("COMMIT"); return r; }
      catch (e) { try { await client.query("ROLLBACK"); } catch {} throw e; }
    },
  };
}

const backend = await makeBackend();
let applied = 0;
let failed = 0;

try {
  const migrations = readMigrations();
  if (!migrations.length) {
    console.log("no migration files found in scripts/migrations/");
    process.exit(0);
  }

  /* Ledger. Created before any migration so the first one is recorded too. */
  await backend.exec(`
    CREATE TABLE IF NOT EXISTS schema_migrations(
      version     INTEGER PRIMARY KEY,
      name        TEXT    NOT NULL,
      checksum    TEXT    NOT NULL,
      applied_at  TEXT    NOT NULL,
      duration_ms INTEGER NOT NULL
    );
  `);

  const rows = await backend.q("SELECT version, name, checksum, applied_at FROM schema_migrations ORDER BY version");
  const appliedByVersion = new Map(rows.map((r) => [Number(r.version), r]));

  /* Integrity check runs even in --status: a drifted database must be loud. */
  const problems = [];
  for (const m of migrations) {
    const rec = appliedByVersion.get(m.version);
    if (rec && rec.checksum !== m.checksum) {
      problems.push(
        `migration ${String(m.version).padStart(3, "0")} (${m.name}) was applied but the file has changed since.\n` +
        `    recorded sha256 ${rec.checksum}\n    current  sha256 ${m.checksum}\n` +
        `    Applied migrations are immutable — add a new numbered migration instead.`
      );
    }
  }
  for (const rec of rows) {
    if (!migrations.some((m) => m.version === Number(rec.version))) {
      problems.push(`ledger records migration ${rec.version} (${rec.name}) but no such file exists — this database was migrated by something else.`);
    }
  }

  const pending = migrations.filter((m) => !appliedByVersion.has(m.version));

  /* ---------- rollback path ---------- */
  if (rollbackVersion) {
    const rb = readRollback(rollbackVersion);
    if (!rb) {
      console.error(`✗ no rollback file for version ${rollbackVersion} in scripts/migrations/rollback/`);
      process.exitCode = 1;
    } else if (!appliedByVersion.has(Number(rollbackVersion))) {
      console.error(`✗ migration ${rollbackVersion} is not recorded as applied — nothing to roll back.`);
      process.exitCode = 1;
    } else if (problems.length) {
      /* Refuse to roll back a database whose ledger is already inconsistent:
         the question "which schema is this?" has no answer, and guessing at it
         is how a rollback makes things worse. */
      console.error("✗ refusing to roll back while the schema ledger is inconsistent. Resolve the problems above first.");
      process.exitCode = 1;
    } else if (Number(rollbackVersion) !== Math.max(...migrations.map((m) => m.version))) {
      console.error(`✗ refusing to roll back ${rollbackVersion}: migrations applied after it must be rolled back first (newest first).`);
      process.exitCode = 1;
    } else if (dryRun) {
      console.log(`  would roll back ${rollbackVersion}  ${rb.file}\n\nDry run — no changes made.`);
    } else {
      console.log(`\n[db] target      ${backend.name}`);
      console.log(`[db] rollback    ${rb.file}\n`);
      await backend.tx(async () => {
        await backend.exec(rb.sql);
        await backend.q("DELETE FROM schema_migrations WHERE version=$1", [Number(rollbackVersion)]);
      });
      console.log(`✓ rolled back ${rollbackVersion}.`);
    }
    await backend.close().catch(() => {});
    process.exit(process.exitCode ?? 0);
  }

  console.log(`\n[db] target      ${backend.name}`);
  console.log(`[db] migrations  ${migrations.length} file(s), ${rows.length} applied, ${pending.length} pending\n`);

  if (statusOnly || problems.length) {
    for (const m of migrations) {
      const rec = appliedByVersion.get(m.version);
      const mark = rec ? "applied" : "PENDING";
      console.log(`  ${String(m.version).padStart(3, "0")}  ${mark.padEnd(8)} ${m.name}${rec ? `  · ${rec.applied_at}` : ""}`);
    }
  }

  if (problems.length) {
    console.error(`\n✗ schema ledger is inconsistent (${problems.length} problem(s)):\n`);
    for (const p of problems) console.error(`  • ${p}\n`);
    process.exitCode = 1;
  } else if (statusOnly) {
    console.log("\nLedger consistent.");
  } else if (!pending.length) {
    console.log("Already up to date — nothing to apply.");
  } else if (dryRun) {
    for (const m of pending) console.log(`  would apply ${String(m.version).padStart(3, "0")}  ${m.name}`);
    console.log("\nDry run — no changes made.");
  } else {
    /* Serialise concurrent runners. pg_advisory_lock blocks until free. */
    await backend.q("SELECT pg_advisory_lock($1)", [LOCK_ID]);
    try {
      for (const m of pending) {
        const started = Date.now();
        process.stdout.write(`  applying ${String(m.version).padStart(3, "0")}  ${m.name} … `);
        await backend.tx(async () => {
          await backend.exec(m.sql);
          await backend.q(
            "INSERT INTO schema_migrations(version,name,checksum,applied_at,duration_ms) VALUES($1,$2,$3,$4,$5)",
            [m.version, m.name, m.checksum, new Date().toISOString(), Date.now() - started]
          );
        });
        console.log(`ok (${Date.now() - started}ms)`);
        applied++;
      }
    } finally {
      await backend.q("SELECT pg_advisory_unlock($1)", [LOCK_ID]).catch(() => {});
    }
    console.log(`\n✓ ${applied} migration(s) applied.`);
  }
} catch (error) {
  failed++;
  console.error(`\n✗ ${error instanceof Error ? error.message : String(error)}`);
  process.exitCode = 1;
} finally {
  await backend.close().catch(() => {});
}

if (applied && !failed) console.log("");
