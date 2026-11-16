#!/usr/bin/env node
/**
 * An INSERT must report the id it created — on PostgreSQL as well as SQLite.
 *
 *   node scripts/test-return-id.mjs
 *
 * WHY THIS EXISTS (Furniture 2030 Phase 1, finding F-02)
 *
 * SQLite reports the new row's id after every INSERT. PostgreSQL reports it
 * only when the statement says `RETURNING id`. The adapter adds that clause
 * for the tables named in RETURN_ID_TABLES (apps/api/db-adapters.mjs) — and
 * that list had stopped at the original 22 tables. For the 41 added since,
 * `lastInsertRowid` was 0 on PostgreSQL: the header row was written, and its
 * lines, messages or journal entries were then filed under id 0.
 *
 * A list kept by hand drifts the moment a table is added, and nothing said so.
 * This test asks the database instead of the author:
 *
 *   1. THE LIST IS THE CATALOGUE. Build the real schema (base file plus every
 *      migration), read which tables have an integer `id`, and require the
 *      list to match exactly — nothing missing, nothing extra. A missing table
 *      returns 0 silently; an extra one makes every insert into it an error.
 *
 *   2. THE REWRITE IS RIGHT. The SQL the adapter produces for the awkward
 *      cases: a trailing semicolon, INSERT OR IGNORE, a statement that already
 *      has RETURNING, a table with no integer id.
 *
 *   3. IT WORKS END TO END. Real inserts through the real adapter, into tables
 *      that were missing from the list, return the id that is actually in the
 *      row — and a conflict that inserts nothing returns 0, not a stale id.
 */
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { createPgliteDatabase, openPglite, tablesWithIntegerId } from "./lib/pg-fixture.mjs";
import { RETURN_ID_TABLES, pgSqlForRun, createDb } from "../apps/api/db-adapters.mjs";

const problems = [];
const check = (ok, label, detail = "") => {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
};

const work = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-return-id-"));
const dir = path.join(work, "pglite");
try {
  console.log("\n[return-id] the list is the catalogue");
  const built = await createPgliteDatabase(dir);
  check(built.applied.length > 0, `the complete schema was built (${built.applied.length} migrations on top of the base file)`);
  const target = await openPglite(dir);
  const withId = await tablesWithIntegerId(target);
  const allTables = (await target.q(
    "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'"
  )).map((row) => row.table_name);
  await target.close();

  const missing = withId.filter((t) => !RETURN_ID_TABLES.has(t));
  const extra = [...RETURN_ID_TABLES].filter((t) => !withId.includes(t));
  check(withId.length >= 60, `the catalogue lists the tables that generate an id (${withId.length} of ${allTables.length})`);
  check(missing.length === 0,
    "every table with an integer id is in RETURN_ID_TABLES",
    missing.length ? `MISSING: ${missing.join(", ")} — an insert into these reports id 0 on PostgreSQL. Add them in apps/api/db-adapters.mjs.` : "");
  check(extra.length === 0,
    "and nothing is listed that has no integer id",
    extra.length ? `EXTRA: ${extra.join(", ")} — "RETURNING id" on these is an error. Remove them from apps/api/db-adapters.mjs.` : "");
  const noId = allTables.filter((t) => !withId.includes(t)).sort();
  check(noId.includes("products") && noId.includes("sessions") && noId.includes("stock_levels"),
    "tables keyed by text or by a pair of columns are correctly left out", noId.join(", "));

  console.log("\n[return-id] the rewrite is right");
  const rewrite = (sql) => pgSqlForRun(sql);
  check(rewrite("INSERT INTO suppliers(name) VALUES(?)") === "INSERT INTO suppliers(name) VALUES($1) RETURNING id",
    "a plain insert gains RETURNING id", rewrite("INSERT INTO suppliers(name) VALUES(?)"));
  check(rewrite("INSERT INTO suppliers(name) VALUES(?);\n") === "INSERT INTO suppliers(name) VALUES($1) RETURNING id",
    "a closing semicolon is removed first, not left in front of the clause", rewrite("INSERT INTO suppliers(name) VALUES(?);\n"));
  check(rewrite("INSERT OR IGNORE INTO users(email) VALUES(?);") === "INSERT INTO users(email) VALUES($1) ON CONFLICT DO NOTHING RETURNING id",
    "INSERT OR IGNORE becomes ON CONFLICT DO NOTHING, then RETURNING id", rewrite("INSERT OR IGNORE INTO users(email) VALUES(?);"));
  check(rewrite("INSERT INTO tickets(subject) VALUES(?) RETURNING id, ref") === "INSERT INTO tickets(subject) VALUES($1) RETURNING id, ref",
    "a statement that already says RETURNING is left alone");
  check(!/RETURNING/i.test(rewrite("INSERT INTO stock_levels(product_id, location_id, qty) VALUES(?,?,?)")),
    "a table with no integer id gains nothing");
  check(!/RETURNING/i.test(rewrite("INSERT OR IGNORE INTO meta(key,value) VALUES(?,?)")) && /ON CONFLICT DO NOTHING$/.test(rewrite("INSERT OR IGNORE INTO meta(key,value) VALUES(?,?)")),
    "nor does INSERT OR IGNORE into one");
  check(rewrite("UPDATE tickets SET subject = ? WHERE id = ?") === "UPDATE tickets SET subject = $1 WHERE id = $2",
    "an UPDATE is only re-numbered");

  console.log("\n[return-id] it works end to end, through the real adapter");
  const db = await createDb({ sqliteFile: path.join(work, "unused.db"), databaseUrl: `pglite:${dir}` });
  try {
    check(db.kind === "pglite", "the adapter opened PostgreSQL (PGlite)", db.kind);
    const stamp = "2026-11-16T00:00:00.000Z";
    /* Three tables that were NOT in the list before this change. */
    const probes = [
      ["suppliers", "INSERT INTO suppliers(code, name, created_at, updated_at) VALUES(?,?,?,?)", ["SUP-PROBE", "Probe Timber Co", stamp, stamp]],
      ["warehouses", "INSERT INTO warehouses(code, name, created_at, updated_at) VALUES(?,?,?,?)", ["PRB", "Probe Warehouse", stamp, stamp]],
      ["companies", "INSERT INTO companies(name, name_norm, created_at, updated_at) VALUES(?,?,?,?)", ["Probe Holdings", "probe holdings", stamp, stamp]],
    ];
    for (const [table, sql, params] of probes) {
      let info = null, error = "";
      try { info = await db.prepare(sql).run(...params); } catch (e) { error = String(e.message); }
      const newest = info ? (await db.prepare(`SELECT MAX(id) AS id FROM ${table}`).get()).id : null;
      check(Boolean(info) && info.lastInsertRowid > 0 && info.lastInsertRowid === Number(newest),
        `${table}: the id reported is the id in the row`, error || `reported ${info?.lastInsertRowid}, newest row ${newest}`);
    }
    /* Two rows in one statement: the first id, and it is a real one. */
    const two = await db.prepare("INSERT INTO suppliers(code, name, created_at, updated_at) VALUES(?,?,?,?),(?,?,?,?)")
      .run("SUP-PROBE-A", "Probe A", stamp, stamp, "SUP-PROBE-B", "Probe B", stamp, stamp);
    check(two.changes === 2 && two.lastInsertRowid > 0, "a two-row insert reports two changes and a real id", JSON.stringify(two));
    /* A conflict that inserts nothing must say 0 — not the id of some other row. */
    const first = await db.prepare("INSERT OR IGNORE INTO users(email,name,role,pass_salt,pass_hash,active,created_at,updated_at) VALUES(?,?,?,?,?,1,?,?)")
      .run("probe@woodex.test", "Probe", "viewer", "s", "h", stamp, stamp);
    const again = await db.prepare("INSERT OR IGNORE INTO users(email,name,role,pass_salt,pass_hash,active,created_at,updated_at) VALUES(?,?,?,?,?,1,?,?)")
      .run("probe@woodex.test", "Probe", "viewer", "s", "h", stamp, stamp);
    check(first.lastInsertRowid > 0 && first.changes === 1, "INSERT OR IGNORE reports the id when it inserts", JSON.stringify(first));
    check(again.lastInsertRowid === 0 && again.changes === 0, "and 0 when the row already existed", JSON.stringify(again));
    /* No integer id: the insert still works and the id is 0, never NaN. */
    const meta = await db.prepare("INSERT INTO meta(key, value) VALUES(?, ?)").run("__return_id_probe__", "1");
    check(meta.changes === 1 && meta.lastInsertRowid === 0, "a table keyed by text inserts fine and reports 0", JSON.stringify(meta));
  } finally { await db.close(); }
} catch (error) {
  problems.push(`test crashed: ${error instanceof Error ? error.message : String(error)}`);
  console.error(`\n✗ ${error instanceof Error ? error.stack : error}`);
} finally {
  fs.rmSync(work, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n✗ ${problems.length} return-id problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  process.exit(1);
}
console.log(`\n✓ every table that generates an id reports it on PostgreSQL (${RETURN_ID_TABLES.size} tables, checked against the catalogue).\n`);
