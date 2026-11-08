#!/usr/bin/env node
/**
 * Fails when the SQLite→PostgreSQL migrator would skip a table.
 *
 * Regression: scripts/pg-migrate.mjs carried a hand-maintained TABLES list that
 * did not include projects, project_milestones, boq_lines or project_files.
 * All four existed in both schemas, so a cutover would have reported success
 * while silently dropping every project, milestone, BOQ line and file record.
 *
 * A hand-written list drifts. This compares it against the tables the API
 * actually creates, and against the PostgreSQL schema, and fails on any gap.
 *
 * Run: node scripts/check-migration-coverage.mjs
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (p) => fs.readFileSync(path.join(ROOT, p), "utf8");

/* 1. Every table the API creates in SQLite. */
const sqliteTables = new Set();
for (const m of read("apps/api/server.mjs").matchAll(/CREATE TABLE IF NOT EXISTS\s+([a-z_][a-z0-9_]*)/gi)) {
  sqliteTables.add(m[1].toLowerCase());
}

/* 2. The migrator's explicit copy list. */
const migratorSrc = read("scripts/pg-migrate.mjs");
const listMatch = migratorSrc.match(/const TABLES = \[([\s\S]*?)\];/);
if (!listMatch) {
  console.error("✗ could not find the TABLES list in scripts/pg-migrate.mjs");
  process.exit(1);
}
const migrated = [...listMatch[1].matchAll(/"([a-z_][a-z0-9_]*)"/gi)].map((m) => m[1].toLowerCase());
const migratedSet = new Set(migrated);

/* 3. Every table the PostgreSQL schema creates — the base file PLUS every
      numbered migration. New tables belong in a migration, not in the base
      schema, but they are still part of the PostgreSQL schema. Counting only
      the base file made the first migration that added a table look like a
      coverage gap, which is the reverse of the bug this check exists for. */
const pgTables = new Set();
const addPgTables = (src) => {
  for (const m of src.replace(/--[^\n]*/g, "").matchAll(/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?([a-z_][a-z0-9_]*)/gi)) {
    pgTables.add(m[1].toLowerCase());
  }
};
addPgTables(read("scripts/schema.postgres.sql"));
const migDir = path.join(ROOT, "scripts", "migrations");
if (fs.existsSync(migDir)) {
  for (const f of fs.readdirSync(migDir).filter((f) => f.endsWith(".sql")).sort()) {
    addPgTables(fs.readFileSync(path.join(migDir, f), "utf8"));
  }
}

const problems = [];

/* Anything SQLite creates must be migrated. */
for (const t of [...sqliteTables].sort()) {
  if (!migratedSet.has(t)) problems.push(`${t}: exists in SQLite but is NOT migrated (data would be silently lost)`);
}

/* Anything the migrator copies must exist in both schemas. */
for (const t of migrated) {
  if (!sqliteTables.has(t)) problems.push(`${t}: listed in the migrator but never created by the API`);
  if (!pgTables.has(t)) problems.push(`${t}: listed in the migrator but absent from schema.postgres.sql`);
}

/* Duplicates in the list would copy a table twice. */
const dupes = migrated.filter((t, i) => migrated.indexOf(t) !== i);
if (dupes.length) problems.push(`duplicate entries in TABLES: ${[...new Set(dupes)].join(", ")}`);

/* Parent-before-child ordering for tables the target guards with foreign keys. */
const order = (a, b) => {
  const i = migrated.indexOf(a), j = migrated.indexOf(b);
  if (i === -1 || j === -1 || i < j) return;
  problems.push(`${b} is copied before its parent ${a} (foreign keys reject the insert)`);
};
order("clients", "projects");
order("projects", "project_milestones");
order("projects", "boq_lines");
order("projects", "project_files");

console.log(`SQLite tables : ${sqliteTables.size}`);
console.log(`Migrated      : ${migrated.length}`);
console.log(`PostgreSQL    : ${pgTables.size}`);

if (problems.length) {
  console.error(`\n${problems.length} migration coverage problem(s):`);
  for (const p of problems) console.error("  ✗ " + p);
  process.exit(1);
}
console.log("\nAll SQLite tables are covered by the migrator, in dependency order.");
