#!/usr/bin/env node
/**
 * Live schema drift audit — compare a HOSTED database against the migrations.
 *
 *   DATABASE_URL=postgres://… node scripts/audit-live-schema.mjs
 *
 * Why this exists
 * ---------------
 * `test:integrity` rehearses migrations on a throwaway Postgres. It proves the
 * files are correct; it cannot prove the hosted database matches them, because
 * it never talks to the hosted database. That gap is not theoretical:
 *
 *   `tasks_done_boolean` was declared in 002_integrity_constraints.sql and was
 *   absent from production for weeks. The rehearsal passed the whole time —
 *   it was asserting a COUNT (`checks >= 20`), and 20 ≥ 20 holds whether the
 *   missing one is tasks_done_boolean or any other. Production had applied 002
 *   partially and nothing could see it.
 *
 * So this audits the real database by NAME:
 *   - every declared CHECK constraint is present
 *   - every declared foreign key is present and VALIDATED
 *   - every migration in the ledger has a matching file and checksum
 *   - no table is missing against the base schema
 *
 * Read-only. It issues only SELECTs and never mutates the database.
 *
 * Exit code 0 = no drift, 1 = drift (each item named), 2 = cannot connect.
 */
import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (p) => fs.readFileSync(path.join(ROOT, p), "utf8");
const dsn = process.env.DATABASE_URL;

if (!dsn) {
  console.error("DATABASE_URL is required (postgres connection string).");
  process.exit(2);
}

/* ---------- what the migrations say should exist ---------- */

const migrationFiles = fs.readdirSync(path.join(ROOT, "scripts", "migrations"))
  .filter((f) => f.endsWith(".sql")).sort();
const migrationSql = migrationFiles.map((f) => read(path.join("scripts", "migrations", f))).join("\n");

const declaredChecks = new Set([
  /* 002 declares checks in a VALUES list shaped ('table','name','predicate').
     The FK block uses the identical shape and is distinguishable only by the
     constraint-name suffix, so the pattern requires a CHECK-style name —
     without that, every FK column (client_id, lead_id, …) is collected as a
     phantom "check". */
  ...[...read("scripts/migrations/002_integrity_constraints.sql")
    .matchAll(/^\s*\('\w+',\s*'(\w+_(?:known|boolean|positive|non_negative|range))',\s*'/gm)]
    .map((m) => m[1]),
  /* Later migrations write inline CONSTRAINT … CHECK definitions. */
  ...[...migrationSql.matchAll(/CONSTRAINT\s+(\w+)\s+CHECK/gi)].map((m) => m[1]),
]);

/* Foreign keys: the VALUES list carries (child_table, child_col, parent, 'id', …)
   and later migrations use inline FOREIGN KEY clauses. Only the VALUES form can
   be attributed to a table name from the source, so both are collected and
   compared by the generated name Postgres will use. */
const declaredFks = new Set([
  ...[...read("scripts/migrations/002_integrity_constraints.sql")
    .matchAll(/^\s*\('(\w+)',\s*'(\w+)',\s*'\w+',\s*'id',\s*'(?:NO ACTION|CASCADE|SET NULL|RESTRICT)',/gm)]
    .map((m) => `${m[1]}_${m[2]}_fkey`),
  ...[...migrationSql.matchAll(/ALTER TABLE (\w+) ADD CONSTRAINT (\w+) FOREIGN KEY/gi)]
    .map((m) => m[2]),
]);

/* Tables the base schema creates. */
const baseSchema = read("scripts/schema.postgres.sql");
const baseTables = new Set([...baseSchema.matchAll(/CREATE TABLE IF NOT EXISTS (\w+)/gi)].map((m) => m[1]));

/* Migrations may create tables too (003 does). */
for (const m of migrationSql.matchAll(/CREATE TABLE IF NOT EXISTS (\w+)/gi)) baseTables.add(m[1]);

/* ---------- checksums, so "applied" means "applied THIS file" ---------- */

const sha = (s) => crypto.createHash("sha256").update(s).digest("hex");
const fileByVersion = new Map(
  migrationFiles.map((f) => [Number(f.slice(0, 3)), { name: f, sha: sha(read(path.join("scripts", "migrations", f))) }]),
);

/* ---------- talk to the database (read-only) ---------- */

/* Two drivers. `pglite:<dir>` runs the same SQL against embedded Postgres, so
   this audit is itself testable without a hosted database — otherwise the only
   way to find out whether it works is to point it at production and hope. */
let client;
if (dsn.startsWith("pglite:")) {
  const { PGlite } = await import("@electric-sql/pglite");
  client = new PGlite(dsn.slice("pglite:".length));
} else {
  const pg = (await import("pg")).default;
  client = new pg.Client({
    connectionString: dsn,
    ssl: /localhost|127\.0\.0\.1/.test(dsn) ? undefined : { rejectUnauthorized: false },
  });
  try {
    await client.connect();
  } catch (err) {
    console.error(`Could not connect: ${err.message}`);
    process.exit(2);
  }
}

const q = async (sql) => (await client.query(sql)).rows;
const problems = [];
const ok = (label) => console.log(`  ✓ ${label}`);
const bad = (label, detail) => {
  problems.push(`${label}${detail ? ` — ${detail}` : ""}`);
  console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`);
};

console.log(`\n[audit] ${dsn.replace(/:[^:@/]*@/, ":***@")}\n`);

const actualChecks = new Set((await q(`
  SELECT c.conname FROM pg_constraint c
    JOIN pg_class t ON t.oid = c.conrelid
    JOIN pg_namespace n ON n.oid = t.relnamespace
   WHERE c.contype = 'c' AND n.nspname = current_schema()`)).map((r) => r.conname));

const missingChecks = [...declaredChecks].filter((n) => !actualChecks.has(n));
if (missingChecks.length) bad(`CHECK constraints (${declaredChecks.size - missingChecks.length}/${declaredChecks.size})`, `missing: ${missingChecks.join(", ")}`);
else ok(`all ${declaredChecks.size} declared CHECK constraints present`);

const fkRows = await q(`
  SELECT c.conname, c.convalidated FROM pg_constraint c
    JOIN pg_class t ON t.oid = c.conrelid
    JOIN pg_namespace n ON n.oid = t.relnamespace
   WHERE c.contype = 'f' AND n.nspname = current_schema()`);
const actualFks = new Set(fkRows.map((r) => r.conname));
const missingFks = [...declaredFks].filter((n) => !actualFks.has(n));
if (missingFks.length) bad(`foreign keys (${declaredFks.size - missingFks.length}/${declaredFks.size})`, `missing: ${missingFks.join(", ")}`);
else ok(`all ${declaredFks.size} declared foreign keys present`);

const unvalidated = fkRows.filter((r) => !r.convalidated).map((r) => r.conname);
if (unvalidated.length) bad(`${unvalidated.length} foreign key(s) still NOT VALID`, `unvalidated: ${unvalidated.join(", ")} — declared but not enforced against existing rows`);
else ok(`all ${fkRows.length} foreign keys are validated`);

const actualTables = new Set((await q(`
  SELECT t.relname FROM pg_class t JOIN pg_namespace n ON n.oid = t.relnamespace
   WHERE t.relkind = 'r' AND n.nspname = current_schema()`)).map((r) => r.relname));
const missingTables = [...baseTables].filter((n) => !actualTables.has(n));
if (missingTables.length) bad(`tables (${baseTables.size - missingTables.length}/${baseTables.size})`, `missing: ${missingTables.join(", ")}`);
else ok(`all ${baseTables.size} expected tables present`);

/* The ledger must agree with the files, by checksum. A version recorded with a
   different checksum means the file changed after it was applied — which is why
   applied migrations are immutable and corrections ship as new files. */
let ledger = [];
try {
  ledger = await q("SELECT version, name, checksum FROM schema_migrations ORDER BY version");
} catch {
  bad("migration ledger", "schema_migrations table is missing — no migration was ever recorded");
}
if (ledger.length) {
  const applied = new Set(ledger.map((r) => Number(r.version)));
  const unapplied = [...fileByVersion.keys()].filter((v) => !applied.has(v));
  const unknown = ledger.filter((r) => !fileByVersion.has(Number(r.version)));
  const changed = ledger.filter((r) => {
    const f = fileByVersion.get(Number(r.version));
    return f && r.checksum && r.checksum !== "rehearsal" && r.checksum !== f.sha;
  });
  if (unapplied.length) bad(`migration ledger (${applied.size}/${fileByVersion.size})`, `on disk but never applied: ${unapplied.map((v) => fileByVersion.get(v).name).join(", ")}`);
  else ok(`every migration on disk is recorded (${applied.size}/${fileByVersion.size})`);
  if (unknown.length) bad("ledger rows with no file", unknown.map((r) => `v${r.version} ${r.name}`).join(", "));
  if (changed.length) bad("checksum drift — an applied migration was edited", changed.map((r) => `v${r.version} ${r.name}`).join(", "));
  if (!unknown.length && !changed.length) ok("checksums match the files (no applied migration was edited)");
}

await (client.end ? client.end() : client.close());

if (problems.length) {
  console.log(`\n✗ ${problems.length} drift problem(s):\n`);
  for (const p of problems) console.log(`  • ${p}`);
  console.log("\nA hosted database that disagrees with its migrations is a database whose constraints are unknown.\n");
  process.exit(1);
}
console.log("\n✓ no schema drift — the hosted database matches its migrations.\n");
