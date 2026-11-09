#!/usr/bin/env node
/**
 * Restore drill — prove a backup can actually be restored.
 *
 *   node scripts/test-restore-drill.mjs
 *
 * A backup nobody restored is not a backup; it is a hypothesis. This drill
 * creates a populated database, backs it up, restores that backup into a
 * separate empty database, and then compares every table row-for-row. It runs
 * entirely against embedded Postgres in a temp directory, so it needs no
 * hosted database and no credentials.
 *
 * It exists because the restore path was, until now, only described. The first
 * time it was actually run it reported success and restored nothing:
 *
 *   The sequence-reset step ran inside the same transaction as the inserts and
 *   swallowed its own error with `.catch(() => {})`. In Postgres a failed
 *   statement aborts the whole transaction, so the COMMIT that followed
 *   degraded into a ROLLBACK. 32 tables, "9 rows restored", zero rows written —
 *   with a success message and exit code 0.
 *
 * That is the failure mode this drill is designed to make impossible to ship:
 * a restore that looks like it worked. Comparing row counts and content
 * afterwards is the only thing that catches it.
 */
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import crypto from "node:crypto";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (p) => fs.readFileSync(path.join(ROOT, p), "utf8");
const tempRoot = path.join(os.tmpdir(), `woodex-restore-drill-${process.pid}`);
const problems = [];
const check = (ok, label, detail = "") => {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
};

const srcDir = path.join(tempRoot, "src");
const dstDir = path.join(tempRoot, "dst");
const dumpDir = path.join(tempRoot, "dump");
const sqliteDir = path.join(tempRoot, "sqlite");
for (const d of [srcDir, dstDir, dumpDir, sqliteDir]) fs.mkdirSync(d, { recursive: true });

const run = (label, args, env = {}) => {
  const r = spawnSync(process.execPath, args, {
    cwd: ROOT, env: { ...process.env, ...env }, encoding: "utf8",
  });
  if (r.status !== 0) {
    console.error(`\n[drill] ${label} failed:\n${r.stdout ?? ""}${r.stderr ?? ""}`);
    throw new Error(label);
  }
  return `${r.stdout ?? ""}${r.stderr ?? ""}`;
};

/* Build a database: base schema + every migration, recorded in the ledger. */
const buildSchema = async (dir) => {
  const { PGlite } = await import("@electric-sql/pglite");
  const db = new PGlite(dir);
  await db.exec(read("scripts/schema.postgres.sql"));
  await db.exec(`CREATE TABLE IF NOT EXISTS schema_migrations(
    version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL,
    applied_at TEXT NOT NULL, duration_ms INTEGER NOT NULL);`);
  for (const f of fs.readdirSync(path.join(ROOT, "scripts", "migrations")).filter((x) => x.endsWith(".sql")).sort()) {
    const sql = read(path.join("scripts", "migrations", f));
    await db.exec(sql);
    await db.query("INSERT INTO schema_migrations VALUES($1,$2,$3,$4,$5)",
      [Number(f.slice(0, 3)), f, crypto.createHash("sha256").update(sql).digest("hex"), new Date().toISOString(), 0]);
  }
  return db;
};

let src, dst;
try {
  console.log("\n[drill] building a populated source database …");
  src = await buildSchema(path.join(srcDir, "pg"));
  /* Products are the table that carries real business meaning, so the drill
     asserts on them specifically: a restore that "works" but drops the
     catalogue is not a successful restore. */
  for (let i = 1; i <= 5; i++) {
    await src.query(
      "INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at) VALUES($1,$2,$3,$4,$5,$6)",
      [`drill-${i}`, JSON.stringify({ id: `drill-${i}`, name: `Drill Desk ${i}` }), 5000 + i, 1, 7 + i, new Date().toISOString()],
    );
  }
  const srcCount = (await src.query("SELECT count(*)::int c FROM products")).rows[0].c;
  const srcUnits = (await src.query("SELECT coalesce(sum(stock_qty),0)::int s FROM products")).rows[0].s;
  console.log(`[drill] source holds ${srcCount} products / ${srcUnits} stock units`);
  await src.close();
  src = null;

  console.log("[drill] backing up …");
  const backupOut = run("backup", ["scripts/backup.mjs", "--pg", `pglite:${path.join(srcDir, "pg")}`, "--out", dumpDir]);
  const tablesMatch = backupOut.match(/(\d+) tables · (\d+) rows/);
  check(Boolean(tablesMatch), "backup reports a table and row count", backupOut.trim().split("\n").pop());
  check(fs.existsSync(path.join(dumpDir, "manifest.json")), "dump has a manifest");
  check(fs.existsSync(path.join(dumpDir, "products.json")), "dump contains products.json");

  console.log("[drill] restoring into a separate empty database …");
  dst = await buildSchema(path.join(dstDir, "pg"));
  await dst.close();
  dst = null;
  run("restore", ["scripts/backup.mjs", "--restore", dumpDir, "--to", `pglite:${path.join(dstDir, "pg")}`, "--truncate"]);

  console.log("\n[drill] comparing source against restored");
  const { PGlite } = await import("@electric-sql/pglite");
  const a = new PGlite(path.join(srcDir, "pg"));
  const b = new PGlite(path.join(dstDir, "pg"));
  const manifest = JSON.parse(fs.readFileSync(path.join(dumpDir, "manifest.json"), "utf8"));

  const normalise = (rows) => JSON.stringify(
    rows.map((r) => Object.fromEntries(Object.entries(r).sort()))
      .sort((x, y) => JSON.stringify(x).localeCompare(JSON.stringify(y))),
  );

  let checked = 0;
  const diffTables = [];
  for (const table of Object.keys(manifest.tables)) {
    const srcRows = (await a.query(`SELECT * FROM "${table}"`)).rows;
    const dstRows = (await b.query(`SELECT * FROM "${table}"`)).rows;
    checked++;
    if (srcRows.length !== dstRows.length || normalise(srcRows) !== normalise(dstRows)) diffTables.push(table);
  }
  check(diffTables.length === 0, `every table restored identically (${checked} tables)`,
    diffTables.length ? `differs: ${diffTables.join(", ")}` : "");

  const dstCount = (await b.query("SELECT count(*)::int c FROM products")).rows[0].c;
  const dstUnits = (await b.query("SELECT coalesce(sum(stock_qty),0)::int s FROM products")).rows[0].s;
  check(dstCount === srcCount, `product count preserved (${dstCount}/${srcCount})`);
  check(dstUnits === srcUnits, `stock units preserved (${dstUnits}/${srcUnits})`);

  /* The specific regression: a restore that reports success and writes nothing.
     Asserting the restored copy is non-empty is what catches that class of bug,
     because the failure mode reports success. */
  check(dstCount > 0, "restored database is not empty (a silent rollback would pass every count check above)");

  await a.close();
  await b.close();
} catch (err) {
  problems.push(`drill threw: ${err.message}`);
  console.log(`\n  ✗ drill aborted: ${err.message}`);
} finally {
  try { await src?.close(); } catch { /* already closed */ }
  try { await dst?.close(); } catch { /* already closed */ }
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (problems.length) {
  console.log(`\n✗ ${problems.length} restore-drill problem(s):\n`);
  for (const p of problems) console.log(`  • ${p}`);
  console.log("\nA backup that cannot be restored is not a backup.\n");
  process.exit(1);
}
console.log("\n✓ restore drill passed — the backup restores faithfully, row for row.\n");
