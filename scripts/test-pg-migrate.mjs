#!/usr/bin/env node
/**
 * The SQLite → PostgreSQL copy must move every row — and must never say it did
 * when it did not.
 *
 *   node scripts/test-pg-migrate.mjs
 *
 * WHY THIS EXISTS (Furniture 2030 Phase 1, finding F-04)
 *
 * scripts/pg-migrate.mjs printed "ALL GREEN" and exited 0 on a run that left
 * nine tables empty: every lead, client, quote, invoice, payment and task, and
 * the whole stock ledger (416 rows in, 213 out). It checked each table the
 * moment that table was copied; a later TRUNCATE … CASCADE then emptied it,
 * and nothing looked again.
 *
 * So this test does not read the tool's own report. It opens both databases
 * itself and counts.
 *
 *   1. A full copy: every table holds the rows the source holds, including the
 *      four parent/child pairs the old copy order got backwards, each with a
 *      real reference in the data.
 *   2. Running it again changes nothing.
 *   3. Without --truncate, a target that holds rows is refused before anything
 *      is touched.
 *   4. A row the target rejects fails the run AND leaves the target as it was.
 *   5. Rows that vanish AFTER their table was copied are caught by the final
 *      check — the F-04 failure itself, reproduced with a trigger.
 *   6. After a copy the next generated id is past the highest copied id.
 *   7. The source file is never written to.
 */
import { spawnSync } from "node:child_process";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { pathToFileURL } from "node:url";
import { DatabaseSync } from "node:sqlite";
import { ROOT, openPglite, foreignKeys } from "./lib/pg-fixture.mjs";

const MIGRATOR = path.join("scripts", "pg-migrate.mjs");

const problems = [];
const check = (ok, label, detail = "") => {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
};

const work = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-pg-migrate-"));
const cleanEnv = { ...process.env };
for (const key of ["DATABASE_URL", "VERCEL", "VERCEL_ENV", "INITIAL_ADMIN_EMAIL", "INITIAL_ADMIN_PASSWORD", "WOODEX_SQLITE_SOURCE"]) delete cleanEnv[key];

const sha = (file) => crypto.createHash("sha256").update(fs.readFileSync(file)).digest("hex");
const migrate = (source, target, ...flags) => {
  const run = spawnSync(process.execPath, ["--experimental-sqlite", MIGRATOR, "--pglite", target, ...flags],
    { cwd: ROOT, env: { ...cleanEnv, WOODEX_SQLITE_SOURCE: source }, encoding: "utf8", timeout: 300_000 });
  return { code: run.status, out: `${run.stdout ?? ""}${run.stderr ?? ""}` };
};

/* Row counts read directly — never from the tool's output. */
const sourceCounts = (file) => {
  const db = new DatabaseSync(file);
  try {
    const counts = new Map();
    for (const { name } of db.prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'").all()) {
      counts.set(name, Number(db.prepare(`SELECT COUNT(*) AS n FROM ${name}`).get().n));
    }
    return counts;
  } finally { db.close(); }
};
const targetCounts = async (dir) => {
  const target = await openPglite(dir);
  try {
    const counts = new Map();
    const tables = await target.q("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'");
    for (const { table_name: name } of tables) {
      if (name === "schema_migrations") continue;
      counts.set(name, Number((await target.q(`SELECT COUNT(*) AS n FROM ${name}`))[0].n));
    }
    return counts;
  } finally { await target.close(); }
};
const differences = (expected, actual) => {
  const out = [];
  for (const [table, n] of expected) if ((actual.get(table) ?? -1) !== n) out.push(`${table} ${n}→${actual.get(table) ?? "missing"}`);
  return out;
};
const sum = (counts) => [...counts.values()].reduce((n, c) => n + c, 0);

try {
  /* ---- a source built by the application itself, then given real references ---- */
  console.log("\n[pg-migrate] building a source database");
  const sourceDir = path.join(work, "sqlite");
  fs.mkdirSync(sourceDir, { recursive: true });
  const source = path.join(sourceDir, "woodex.db");
  const seed = spawnSync(process.execPath, ["--experimental-sqlite", "--input-type=module", "-e", `
    const runtime = await import(${JSON.stringify(pathToFileURL(path.join(ROOT, "apps/api/server.mjs")).href)});
    if (runtime.httpServer) {
      if (!runtime.httpServer.listening) await new Promise((resolve) => runtime.httpServer.once("listening", resolve));
      await new Promise((resolve, reject) => runtime.httpServer.close((error) => (error ? reject(error) : resolve())));
      runtime.httpServer.closeAllConnections?.();
    }
    await runtime.db.close();
    process.exit(0);`],
    { cwd: ROOT, env: { ...cleanEnv, NODE_ENV: "test", WOODEX_DATA_DIR: sourceDir, PORT: "0", WOODEX_DEMO_SEED: "1" }, encoding: "utf8", timeout: 120_000 });
  if (seed.status !== 0 || !fs.existsSync(source)) throw new Error(`could not build the SQLite source: ${String(seed.stderr).slice(-400)}`);

  const stamp = "2026-11-16T00:00:00.000Z";
  const refs = {};
  {
    const db = new DatabaseSync(source);
    const one = (sql, ...p) => db.prepare(sql).get(...p);
    const run = (sql, ...p) => Number(db.prepare(sql).run(...p).lastInsertRowid);
    /* The four pairs the hand-written copy order had child-before-parent. */
    refs.company = run("INSERT INTO companies(name, name_norm, created_at, updated_at) VALUES(?,?,?,?)", "Fixture Holdings", "fixture holdings", stamp, stamp);
    refs.client = one("SELECT MIN(id) AS id FROM clients").id;
    refs.lead = one("SELECT MIN(id) AS id FROM leads").id;
    run("UPDATE clients SET company_id = ? WHERE id = ?", refs.company, refs.client);
    run("UPDATE leads SET company_id = ? WHERE id = ?", refs.company, refs.lead);
    refs.warehouse = run("INSERT INTO warehouses(code, name, is_default, active, created_at, updated_at) VALUES(?,?,1,1,?,?)", "FX", "Fixture Warehouse", stamp, stamp);
    refs.location = run("INSERT INTO storage_locations(warehouse_id, code, name, active, created_at, updated_at) VALUES(?,?,?,1,?,?)", refs.warehouse, "FX-A1", "Fixture bay", stamp, stamp);
    refs.movement = one("SELECT MIN(id) AS id FROM stock_movements").id;
    run("UPDATE stock_movements SET location_id = ? WHERE id = ?", refs.location, refs.movement);
    refs.visit = run("INSERT INTO site_visits(ref, lead_id, client_id, scheduled_at, address, created_at, updated_at) VALUES(?,?,?,?,?,?,?)",
      "SV-FIXTURE-1", refs.lead, refs.client, stamp, "Fixture address", stamp, stamp);
    refs.task = one("SELECT MIN(id) AS id FROM tasks").id;
    run("UPDATE tasks SET site_visit_id = ? WHERE id = ?", refs.visit, refs.task);
    /* A table that points at itself, across more than one insert batch (200
       rows): the first client is merged into one created 450 rows later. */
    const add = db.prepare("INSERT INTO clients(name, created_at, updated_at) VALUES(?,?,?)");
    let last = 0;
    for (let i = 1; i <= 450; i++) last = Number(add.run(`Fixture client ${i}`, stamp, stamp).lastInsertRowid);
    refs.mergedFrom = one("SELECT MAX(id) AS id FROM clients WHERE id < ?", last - 449).id;
    refs.mergedInto = last;
    run("UPDATE clients SET merged_into = ? WHERE id = ?", refs.mergedInto, refs.mergedFrom);
    db.close();
  }
  const expected = sourceCounts(source);
  const sourceSha = sha(source);
  check(sum(expected) > 800 && (expected.get("stock_movements") ?? 0) > 0 && (expected.get("clients") ?? 0) > 450,
    `the source holds ${sum(expected)} rows in ${[...expected.values()].filter((n) => n > 0).length} tables`);

  /* ---- 1. a full copy ---- */
  console.log("\n[pg-migrate] 1. a full copy into an empty PostgreSQL");
  const target = path.join(work, "pglite");
  const first = migrate(source, target, "--truncate");
  check(first.code === 0, "the copy exits 0", first.out.slice(-500));
  let actual = await targetCounts(target);
  let diff = differences(expected, actual);
  check(diff.length === 0, `every table holds what the source holds (${sum(actual)} rows, counted directly)`, diff.join(", "));
  for (const table of ["leads", "clients", "quotes", "invoices", "payments", "tasks", "stock_movements", "client_links", "quote_versions"]) {
    if ((expected.get(table) ?? 0) === 0) continue;
    check(actual.get(table) === expected.get(table), `${table}: ${expected.get(table)} rows arrived (this table used to end empty)`, `got ${actual.get(table)}`);
  }
  {
    const pg = await openPglite(target);
    const value = async (sql) => Object.values((await pg.q(sql))[0] ?? {})[0];
    check(Number(await value(`SELECT company_id FROM clients WHERE id = ${refs.client}`)) === refs.company, "clients → companies: the reference survived");
    check(Number(await value(`SELECT company_id FROM leads WHERE id = ${refs.lead}`)) === refs.company, "leads → companies: the reference survived");
    check(Number(await value(`SELECT location_id FROM stock_movements WHERE id = ${refs.movement}`)) === refs.location, "stock_movements → storage_locations: the reference survived");
    check(Number(await value(`SELECT site_visit_id FROM tasks WHERE id = ${refs.task}`)) === refs.visit, "tasks → site_visits: the reference survived");
    check(Number(await value(`SELECT merged_into FROM clients WHERE id = ${refs.mergedFrom}`)) === refs.mergedInto, "a client merged into one 450 rows later was copied (self-reference across batches)");
    await pg.close();
  }
  const order = (first.out.match(/copy order: (.+)/)?.[1] ?? "").split(", ").filter(Boolean);
  check(order.length >= 60 && order.indexOf("companies") < order.indexOf("clients") && order.indexOf("storage_locations") < order.indexOf("stock_movements")
    && order.indexOf("site_visits") < order.indexOf("tasks"), "the copy order puts each parent before its child", order.slice(0, 8).join(", "));
  check(/emptied once, before the copy/.test(first.out) && !/TRUNCATE .* CASCADE/i.test(first.out.replace(/emptied once[^\n]*/g, "")),
    "the target was emptied once, before anything was copied");

  /* ---- 2. again ---- */
  console.log("\n[pg-migrate] 2. the same copy again");
  const second = migrate(source, target, "--truncate");
  actual = await targetCounts(target);
  diff = differences(expected, actual);
  check(second.code === 0 && diff.length === 0, "a second run exits 0 and the target is identical", diff.join(", ") || second.out.slice(-300));
  check(/0 migration\(s\) applied now/.test(second.out), "and it does not re-run migrations that are already recorded");

  /* ---- 3. without --truncate ---- */
  console.log("\n[pg-migrate] 3. a target that already holds rows, without --truncate");
  const third = migrate(source, target);
  check(third.code === 1 && /already holds/.test(third.out) && /Nothing was changed/.test(third.out), "is refused, with the reason and the remedy", third.out.slice(-300));
  diff = differences(expected, await targetCounts(target));
  check(diff.length === 0, "and the target is untouched", diff.join(", "));

  /* ---- 4. a row the target rejects ---- */
  console.log("\n[pg-migrate] 4. a source row the target must reject");
  const brokenDir = path.join(work, "broken");
  fs.mkdirSync(brokenDir, { recursive: true });
  const broken = path.join(brokenDir, "woodex.db");
  fs.copyFileSync(source, broken);
  {
    const db = new DatabaseSync(broken);
    /* SQLite does not enforce the reference; PostgreSQL does. */
    db.prepare("UPDATE tasks SET site_visit_id = ? WHERE id = ?").run(987654321, refs.task);
    db.close();
  }
  const fourth = migrate(broken, target, "--truncate");
  check(fourth.code === 1 && /tasks: rows/.test(fourth.out), "the run fails and names the table", fourth.out.slice(-300));
  check(/rolled back/.test(fourth.out), "it says it rolled back");
  diff = differences(expected, await targetCounts(target));
  check(diff.length === 0, "and the target still holds the previous, complete copy", diff.join(", "));

  /* ---- 5. rows that vanish after their table was copied (F-04 itself) ---- */
  console.log("\n[pg-migrate] 5. rows that vanish after their table was copied");
  {
    const pg = await openPglite(target);
    const keys = await foreignKeys(pg);
    const referenced = new Set(keys.map((k) => k.parent));
    const withRows = order.filter((table) => (expected.get(table) ?? 0) > 0);
    const victim = withRows.find((table) => !referenced.has(table));
    const later = withRows[withRows.length - 1];
    check(Boolean(victim) && later !== victim && order.indexOf(later) > order.indexOf(victim), `fixture: "${victim}" is copied before "${later}"`);
    await pg.exec(`CREATE FUNCTION wx_test_lose_rows() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN DELETE FROM ${victim}; RETURN NULL; END $$;
      CREATE TRIGGER wx_test_lose_rows AFTER INSERT ON ${later} FOR EACH STATEMENT EXECUTE FUNCTION wx_test_lose_rows();`);
    await pg.close();
    const fifth = migrate(source, target, "--truncate");
    check(fifth.code === 1, "a copy that lost rows exits 1 (it used to exit 0)", fifth.out.slice(-300));
    check(new RegExp(`✗ ${victim}: source ${expected.get(victim)} row\\(s\\), target 0`).test(fifth.out), `the final check names "${victim}" and both counts`, fifth.out.split("\n").filter((l) => l.includes("✗")).join(" | ").slice(0, 300));
    check(!/ALL GREEN/.test(fifth.out) && /rolled back/.test(fifth.out), "it does not print ALL GREEN, and it rolls back");
    const cleanup = await openPglite(target);
    await cleanup.exec("DROP TRIGGER wx_test_lose_rows ON " + later + "; DROP FUNCTION wx_test_lose_rows();");
    await cleanup.close();
    diff = differences(expected, await targetCounts(target));
    check(diff.length === 0, "and the target still holds the previous, complete copy", diff.join(", "));
  }

  /* ---- 6. identities ---- */
  console.log("\n[pg-migrate] 6. the next generated id");
  {
    const pg = await openPglite(target);
    const [{ max }] = await pg.q("SELECT MAX(id) AS max FROM clients");
    const [{ id }] = await pg.q("INSERT INTO clients(name, created_at, updated_at) VALUES('After the copy', $1, $1) RETURNING id", [stamp]);
    check(Number(id) === Number(max) + 1, "a row created after the copy gets the next id, not a used one", `max ${max}, got ${id}`);
    await pg.close();
  }

  /* ---- 7. the source ---- */
  console.log("\n[pg-migrate] 7. the source");
  check(sha(source) === sourceSha, "the source file is byte-for-byte what it was before five runs");
} catch (error) {
  problems.push(`test crashed: ${error instanceof Error ? error.message : String(error)}`);
  console.error(`\n✗ ${error instanceof Error ? error.stack : error}`);
} finally {
  fs.rmSync(work, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n✗ ${problems.length} migrator problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  process.exit(1);
}
console.log("\n✓ the copy moves every row, refuses what it cannot do, and never reports success for a copy that lost data.\n");
