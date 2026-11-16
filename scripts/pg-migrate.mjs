#!/usr/bin/env node
/**
 * SQLite → PostgreSQL copy, with proof.
 *
 *   node scripts/pg-migrate.mjs --pglite /tmp/wxpg --truncate          # rehearsal, embedded PostgreSQL
 *   node scripts/pg-migrate.mjs --dsn postgres://user:pw@host:5432/db   # a real server, empty target
 *   node scripts/pg-migrate.mjs --dsn … --truncate --confirm-replace   # REPLACE what the server holds
 *
 *   WOODEX_SQLITE_SOURCE=/path/to/woodex.db   the SQLite file to copy (default: data/woodex.db)
 *
 * What it does, in order:
 *   1. brings the target to the complete schema (base file + pending migrations);
 *   2. checks, before changing anything, that the copy can work;
 *   3. inside ONE transaction: empties the target tables (only with --truncate),
 *      copies every table parents-first, then re-reads every table and compares
 *      it with the source — row count and content;
 *   4. commits only if every table matches. Otherwise it rolls back and the
 *      target holds exactly what it held before.
 *
 * Runbook: docs/operations/DATA-MIGRATION-RUNBOOK.md
 *
 * WHAT WAS WRONG BEFORE (Furniture 2030 Phase 1, finding F-04)
 *
 * `--truncate` ran `TRUNCATE <table> CASCADE` inside the copy loop, once per
 * table, immediately before copying that table. CASCADE also empties every
 * table that references the one being truncated. Four tables are copied before
 * a table they reference (leads and clients → companies, stock_movements →
 * storage_locations, tasks → site_visits), so when the loop reached the parent
 * it emptied children that had already been copied and already been verified.
 * On the demo data nine tables ended empty — every lead, client, quote,
 * invoice, payment, task and the whole stock ledger — and the run printed
 * "ALL GREEN" and exited 0, because each table was checked the moment it was
 * copied and never again.
 *
 * Three things are different now, and each would have caught it alone:
 *   - the target is emptied ONCE, in one statement, before anything is copied;
 *   - tables are copied in the order the target's own foreign keys require,
 *     read from the catalogue rather than from the hand-written list below;
 *   - verification happens AFTER the last table is copied, over every table.
 *
 * Also changed: the source is opened read-only and is never written to; a
 * migration already recorded in the ledger is not re-run; a target that
 * already holds rows is refused up front instead of failing halfway on a
 * duplicate key; and replacing the contents of a real server needs an explicit
 * second flag.
 */
import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { DatabaseSync } from "node:sqlite";
import { ROOT, applyFullSchema, openPglite, foreignKeys, parentsFirst, referencedRowsFirst } from "./lib/pg-fixture.mjs";

const args = process.argv.slice(2);
const arg = (f) => { const i = args.indexOf(f); return i >= 0 ? args[i + 1] : undefined; };
const dsn = arg("--dsn");
const pgliteDir = arg("--pglite");
const truncate = args.includes("--truncate");
const confirmReplace = args.includes("--confirm-replace");
if (!dsn && !pgliteDir) {
  console.error("usage: --dsn <postgres-url> | --pglite <dir>  [--truncate] [--confirm-replace]");
  process.exit(2);
}

/* Every table that is copied. The ORDER here no longer matters — the copy is
   ordered by the target's foreign keys at run time — but the MEMBERSHIP does:
   scripts/check-migration-coverage.mjs fails if a table exists in the SQLite
   schema and is not listed here, because a table missing from this list is a
   table whose rows are silently left behind. */
const TABLES = ["products","materials","services","leads","quotes","quote_versions","quote_templates","orders","invoices","payments","returns",
  "clients","client_links","tasks","site_visits","pages","page_blocks","page_versions","saved_sections","track_events",
  "projects","project_milestones","boq_lines","project_files",
  "conversations","messages","users","sessions","meta","stock_movements","stock_reservations","audit_logs",
  "suppliers","purchase_orders","purchase_order_lines","goods_receipts","goods_receipt_lines",
  "supplier_invoices","supplier_invoice_lines","supplier_payments",
  "boms","bom_lines","job_cards","job_card_materials",
  "warehouses","storage_locations","stock_levels","stock_transfers","stock_transfer_lines",
  "accounts","journal_entries","journal_lines","credit_notes","expenses","tax_records","payment_schedules",
  "companies","contacts","meetings","attachments","activities",
  "dispatch_runs","deliveries","delivery_lines","installed_assets",
  "tickets","ticket_messages","service_visits","warranty_claims",
  "password_resets","throttle_buckets"];

/* ---- target ---- */
async function makeBackend() {
  if (pgliteDir) return openPglite(pgliteDir);
  const pg = (await import("pg")).default;
  const client = new pg.Client({ connectionString: dsn, ssl: /localhost|127\.0\.0\.1/.test(dsn) ? undefined : { rejectUnauthorized: false } });
  await client.connect();
  return { name: "PostgreSQL " + dsn.replace(/:[^:@/]*@/, ":***@"), q: (s, p = []) => client.query(s, p).then((r) => r.rows), exec: (s) => client.query(s), close: () => client.end() };
}

/* ---- source: read-only, and never written to ---- */
const sqliteSource = process.env.WOODEX_SQLITE_SOURCE ? path.resolve(process.env.WOODEX_SQLITE_SOURCE) : path.join(ROOT, "data/woodex.db");
if (!fs.existsSync(sqliteSource)) {
  console.error(`[migrate] ✗ source SQLite file not found: ${sqliteSource}`);
  process.exit(2);
}
let src;
try { src = new DatabaseSync(sqliteSource, { readOnly: true }); src.prepare("SELECT 1").get(); }
catch { src = new DatabaseSync(sqliteSource); } // an older Node without readOnly: still nothing below writes to it
const sourceHas = (table) => Boolean(src.prepare("SELECT 1 AS present FROM sqlite_master WHERE type = 'table' AND name = ?").get(table));

const digest = (s) => crypto.createHash("md5").update(s).digest("hex").slice(0, 10);
const fail = (message) => { console.error(`[migrate] ✗ ${message}`); };

const tgt = await makeBackend();
console.log(`[migrate] source: ${path.relative(ROOT, sqliteSource) || sqliteSource} (read-only) · target: ${tgt.name}`);

let exitCode = 1;
let inTransaction = false;
try {
  /* ---- 1. schema ---- */
  const schema = await applyFullSchema(tgt);
  console.log(`[migrate] schema ready — ${schema.applied.length} migration(s) applied now, ${schema.alreadyApplied} already recorded`);

  /* ---- 2. preflight: everything that can be known before touching data ---- */
  const columnRows = await tgt.q(
    "SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema()");
  const targetType = new Map(); // "table.column" → data_type
  for (const row of columnRows) targetType.set(`${row.table_name}.${row.column_name}`, row.data_type);

  const plan = [];
  const preflight = [];
  for (const table of TABLES) {
    const present = sourceHas(table);
    const cols = present ? src.prepare(`PRAGMA table_info(${table})`).all().map((c) => c.name) : [];
    const count = present ? Number(src.prepare(`SELECT COUNT(*) AS n FROM ${table}`).get().n) : 0;
    if (!columnRows.some((row) => row.table_name === table)) { preflight.push(`${table}: missing from the target schema`); continue; }
    const missing = cols.filter((c) => !targetType.has(`${table}.${c}`));
    if (missing.length) { preflight.push(`${table}: the target has no column ${missing.join(", ")}`); continue; }
    plan.push({ table, present, cols, sourceCount: count });
  }
  if (preflight.length) {
    for (const problem of preflight) fail(problem);
    throw new Error(`${preflight.length} table(s) cannot be copied — nothing was changed`);
  }

  /* Order by the target's own foreign keys. */
  const keys = await foreignKeys(tgt);
  const { ordered, cycles } = parentsFirst(TABLES, keys);
  if (cycles.length) throw new Error(`foreign keys form a cycle between ${cycles[0].join(", ")} — no copy order exists; nothing was changed`);
  const moved = ordered.filter((table, i) => TABLES.indexOf(table) !== i).length;
  console.log(`[migrate] copy order follows ${keys.length} foreign keys (${moved} table(s) sit in a different position than in the list)`);
  console.log(`[migrate] copy order: ${ordered.join(", ")}`);
  const selfReference = new Map(); // table → { childColumn, parentColumn }
  for (const key of keys) {
    if (key.child === key.parent && key.childColumns.length === 1) selfReference.set(key.child, { childColumn: key.childColumns[0], parentColumn: key.parentColumns[0] });
  }
  /* CASCADE reaches every table that references one being emptied. If such a
     table is not in the list, it would be emptied and not refilled. */
  const outside = [...new Set(keys.filter((k) => TABLES.includes(k.parent) && !TABLES.includes(k.child)).map((k) => k.child))];
  if (outside.length) throw new Error(`table(s) ${outside.join(", ")} reference copied tables but are not in the copy list — they would be emptied and left empty; nothing was changed`);

  /* What the target holds now. */
  const before = new Map();
  for (const { table } of plan) before.set(table, Number((await tgt.q(`SELECT COUNT(*) AS n FROM ${table}`))[0].n));
  const occupied = [...before.entries()].filter(([, n]) => n > 0);
  const rowsThere = occupied.reduce((n, [, c]) => n + c, 0);
  if (occupied.length && !truncate) {
    fail(`the target already holds ${rowsThere} row(s) in ${occupied.length} table(s): ${occupied.map(([t, n]) => `${t} ${n}`).join(", ")}`);
    fail("a brand-new database is not empty either: the migrations seed reference rows (the chart of accounts, the default warehouse).");
    fail("re-run with --truncate to REPLACE the target's contents with the source. Nothing was changed.");
    throw new Error("target is not empty");
  }
  if (occupied.length && truncate && dsn && !confirmReplace) {
    fail(`--truncate would DELETE ${rowsThere} row(s) from ${occupied.length} table(s) on ${tgt.name} and replace them with the source.`);
    fail("take a backup first (npm run db:backup), then add --confirm-replace to proceed. Nothing was changed.");
    throw new Error("replacing a server's contents needs --confirm-replace");
  }

  /* ---- 3. one transaction: empty, copy, verify ---- */
  await tgt.exec("BEGIN");
  inTransaction = true;
  /* A timestamp written without a zone means UTC in this application (SQLite's
     datetime('now') is UTC). Say so for this transaction, so the result does
     not depend on how the server happens to be configured. */
  await tgt.exec("SET LOCAL TIME ZONE 'UTC'");

  if (truncate) {
    /* ONE statement, before any copy. Naming every table means CASCADE has
       nothing left to surprise: nothing copied later can be emptied by it. */
    await tgt.exec(`TRUNCATE ${TABLES.join(", ")} RESTART IDENTITY CASCADE`);
    for (const { table } of plan) {
      const left = Number((await tgt.q(`SELECT COUNT(*) AS n FROM ${table}`))[0].n);
      if (left !== 0) throw new Error(`${table} still holds ${left} row(s) after TRUNCATE`);
    }
    console.log(`[migrate] target emptied once, before the copy (${rowsThere} row(s) in ${occupied.length} table(s))`);
  }

  /* Values are converted to what the target column expects. SQLite stores a
     boolean as 0/1; PostgreSQL's BOOLEAN wants true/false. */
  const toTarget = (table, column, value) => {
    if (value === undefined || value === null) return null;
    const type = targetType.get(`${table}.${column}`);
    if (type === "boolean") return value === true || Number(value) === 1;
    if (type === "text") return typeof value === "string" ? value : String(value);
    return typeof value === "bigint" ? Number(value) : value;
  };

  const byTable = new Map(plan.map((entry) => [entry.table, entry]));
  for (const table of ordered) {
    const entry = byTable.get(table);
    if (!entry.present || entry.sourceCount === 0) { entry.loaded = 0; continue; }
    let rows = src.prepare(`SELECT * FROM ${table}`).all();
    const self = selfReference.get(table);
    if (self && entry.cols.includes(self.childColumn) && entry.cols.includes(self.parentColumn)) {
      rows = referencedRowsFirst(rows, self.childColumn, self.parentColumn);
    }
    let loaded = 0;
    for (let i = 0; i < rows.length; i += 200) {
      const chunk = rows.slice(i, i + 200);
      const values = []; const params = []; let ph = 0;
      for (const r of chunk) {
        values.push("(" + entry.cols.map(() => "$" + ++ph).join(",") + ")");
        for (const c of entry.cols) params.push(toTarget(table, c, r[c]));
      }
      try { await tgt.q(`INSERT INTO ${table}(${entry.cols.join(",")}) VALUES ${values.join(",")}`, params); }
      catch (error) { throw new Error(`${table}: rows ${i + 1}–${i + chunk.length} were rejected by the target — ${String(error.message).split("\n")[0]}`); }
      loaded += chunk.length;
    }
    entry.loaded = loaded;
  }

  /* ---- 4. verify EVERYTHING, after the last table is in ----
     Each engine is asked for the same canonical text: a timestamp as ISO UTC,
     a date as YYYY-MM-DD, a boolean as 0/1, a number to six places. Then the
     two sides are compared order-independently (collation differs). */
  const INTEGER_TYPES = new Set(["smallint", "integer", "bigint"]);
  const FLOAT_TYPES = new Set(["numeric", "double precision", "real"]);
  const TIMESTAMP_TYPES = new Set(["timestamp with time zone", "timestamp without time zone"]);
  const typeOf = (table, column) => targetType.get(`${table}.${column}`);
  /* The source value, as the text the target should now hold. Decided by the
     TARGET column's type, never by what JavaScript type a driver happened to
     return: `pg` returns BIGINT and NUMERIC as strings, PGlite as numbers. */
  const canonSource = (table, column, value) => {
    if (value === null || value === undefined) return null;
    const type = typeOf(table, column);
    if (TIMESTAMP_TYPES.has(type)) {
      const text = String(value).trim();
      const zoned = /(?:Z|[+-]\d{2}(?::?\d{2})?)$/i.test(text);
      const ms = Date.parse(zoned ? text : `${text.replace(" ", "T")}Z`);
      return Number.isFinite(ms) ? new Date(ms).toISOString() : text;
    }
    if (type === "date") return String(value).slice(0, 10);
    if (type === "boolean") return value === true || Number(value) === 1 ? "1" : "0";
    if (FLOAT_TYPES.has(type)) return Number(value).toFixed(6);
    if (INTEGER_TYPES.has(type)) return String(typeof value === "bigint" ? value : Number(value));
    return String(value);
  };
  /* The same text, asked of the target in SQL so both drivers answer alike. */
  const selectTarget = (table, column) => {
    const type = typeOf(table, column);
    if (type === "timestamp with time zone") return `to_char(${column} AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"') AS ${column}`;
    if (type === "timestamp without time zone") return `to_char(${column}, 'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"') AS ${column}`;
    if (type === "date") return `to_char(${column}, 'YYYY-MM-DD') AS ${column}`;
    if (type === "boolean") return `(${column}::int)::text AS ${column}`;
    if (FLOAT_TYPES.has(type)) return `${column}::float8 AS ${column}`;
    return `${column}::text AS ${column}`;
  };
  const canonTarget = (table, column, value) => {
    if (value === null || value === undefined) return null;
    if (FLOAT_TYPES.has(typeOf(table, column))) return Number(value).toFixed(6);
    return String(value);
  };
  const fingerprint = (rows) => digest(JSON.stringify(rows.map((r) => JSON.stringify(r)).sort()));

  let problems = 0;
  let sourceTotal = 0, targetTotal = 0;
  const report = [];
  for (const table of ordered) {
    const entry = byTable.get(table);
    const targetCount = Number((await tgt.q(`SELECT COUNT(*) AS n FROM ${table}`))[0].n);
    sourceTotal += entry.sourceCount; targetTotal += targetCount;
    let content = "—";
    if (entry.present && entry.cols.length) {
      const sourceRows = src.prepare(`SELECT * FROM ${table}`).all().map((r) => entry.cols.map((c) => canonSource(table, c, r[c])));
      const targetRows = (await tgt.q(`SELECT ${entry.cols.map((c) => selectTarget(table, c)).join(", ")} FROM ${table}`))
        .map((r) => entry.cols.map((c) => canonTarget(table, c, r[c])));
      content = fingerprint(sourceRows) === fingerprint(targetRows) ? "same" : "DIFFERENT";
    }
    const ok = targetCount === entry.sourceCount && content !== "DIFFERENT";
    if (!ok) problems++;
    report.push({ table, source: entry.sourceCount, target: targetCount, content, result: ok ? "PASS" : "FAIL" });
  }
  console.table(report);
  console.log(`[migrate] final check, after the last table: ${sourceTotal} row(s) in the source, ${targetTotal} in the target, ${report.filter((r) => r.result === "FAIL").length} table(s) differ`);
  for (const row of report.filter((r) => r.result === "FAIL")) fail(`${row.table}: source ${row.source} row(s), target ${row.target}; content ${row.content}`);

  /* ---- query battery (the application's trickiest SQL shapes) ---- */
  /* What the source itself answers, for the queries both engines can run:
     the target must return the same number of rows. */
  const sourceRows = (sql) => { try { return src.prepare(sql).all().length; } catch { return null; } };
  const landingSql = `SELECT type, props FROM page_blocks WHERE page_id=(SELECT id FROM pages WHERE slug='ramadan-workspace-sale') ORDER BY sort LIMIT 8`;
  const battery = [
    ["public landing blocks", landingSql, sourceRows(landingSql)],
    ["clients order-by datetime() shim", `SELECT id FROM clients ORDER BY datetime(updated_at) DESC LIMIT 3`, null],
    ["merge-review group_concat shim", `SELECT string_agg('x',',') FROM clients LIMIT 1`, null],
    ["marketing daily substr grouping", `SELECT substr(created_at,1,10) d, COUNT(*) c FROM track_events GROUP BY d ORDER BY d DESC LIMIT 3`, null],
    ["inbox join", `SELECT cv.id FROM conversations cv JOIN clients cl ON cl.id=cv.client_id AND cl.merged_into IS NULL ORDER BY datetime(cv.last_at) DESC LIMIT 3`, null],
    ["session continuity", `SELECT s.token FROM sessions s JOIN users u ON u.id=s.user_id WHERE u.active=1 LIMIT 1`, null],
    ["finance sum/COALESCE", `SELECT COALESCE(SUM(total),0) t, COALESCE(SUM(paid),0) p FROM invoices`, null],
  ];
  for (const [name, sql, expect] of battery) {
    /* A failed statement would poison the surrounding transaction, so each
       probe runs inside its own savepoint. */
    await tgt.exec("SAVEPOINT battery");
    try {
      const rows = await tgt.q(sql);
      const ok = expect === null ? true : rows.length === expect;
      if (!ok) problems++;
      console.log(`  ${ok ? "PASS" : "FAIL"} · ${name} (${rows.length} rows${expect === null ? "" : `, source ${expect}`})`);
      await tgt.exec("RELEASE SAVEPOINT battery");
    } catch (e) {
      problems++;
      console.log(`  FAIL · ${name}: ${e.message.split("\n")[0]}`);
      await tgt.exec("ROLLBACK TO SAVEPOINT battery");
    }
  }

  if (problems) throw new Error(`${problems} problem(s) — the copy does not match the source`);

  /* ---- identities: the next generated id must be past the highest copied id.
     Done last, after verification, so a run that is rolled back never moves a
     sequence. */
  for (const table of ordered) {
    const entry = byTable.get(table);
    const idType = targetType.get(`${table}.id`);
    if (!entry.cols.includes("id") || !["smallint", "integer", "bigint"].includes(idType)) continue;
    const seq = await tgt.q(`SELECT pg_get_serial_sequence('${table}','id') s`);
    if (seq[0]?.s) await tgt.q(`SELECT setval('${seq[0].s}', (SELECT COALESCE(MAX(id),0)+1 FROM ${table}), false)`);
  }
  /* write-path smoke: a row can still be inserted and its key read back */
  const [probe] = await tgt.q(`INSERT INTO meta(key,value) VALUES('__probe__','1') RETURNING key`);
  await tgt.q(`DELETE FROM meta WHERE key='__probe__'`);
  if (probe?.key !== "__probe__") throw new Error("write-path probe did not return its key");
  console.log("  PASS · write path (RETURNING) →", probe.key);

  await tgt.exec("COMMIT");
  inTransaction = false;
  exitCode = 0;
  console.log(`\n[migrate] ✅ ALL GREEN — ${targetTotal} row(s) in ${report.length} tables, verified after the last table was copied; committed.`);
} catch (error) {
  if (inTransaction) {
    try { await tgt.exec("ROLLBACK"); console.error("[migrate] rolled back — the target holds exactly what it held before this run."); }
    catch (rollbackError) { console.error(`[migrate] ROLLBACK FAILED: ${rollbackError.message} — inspect the target before using it.`); }
  }
  console.error(`\n[migrate] ❌ ${error instanceof Error ? error.message : String(error)}`);
  exitCode = 1;
} finally {
  await tgt.close().catch(() => {});
  try { src.close(); } catch { /* already closed */ }
}
process.exit(exitCode);
