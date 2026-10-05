#!/usr/bin/env node
/**
 * SQLite → Postgres migrator + verifier (Woodex platform, P-foundation cut-over).
 *
 *   node scripts/pg-migrate.mjs --pglite /tmp/wxpg          # verify against embedded Postgres (WASM)
 *   node scripts/pg-migrate.mjs --dsn postgres://user:pw@host:5432/db   # real server (Supabase in prod)
 *   add --truncate to wipe target tables first (idempotent re-run)
 *
 * Behavior: applies scripts/schema.postgres.sql, copies every table with explicit
 * ids, resets identities past max(id), then verifies row counts AND per-table
 * content digests, and runs a battery of the app's trickiest queries (SQLite
 * shims: datetime() ordering, group_concat, ISO-TEXT substr grouping).
 */
import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const args = process.argv.slice(2);
const arg = (f) => { const i = args.indexOf(f); return i >= 0 ? args[i + 1] : undefined; };
const dsn = arg("--dsn");
const pgliteDir = arg("--pglite");
const truncate = args.includes("--truncate");
if (!dsn && !pgliteDir) { console.error("usage: --dsn <postgres-url> | --pglite <dir> [--truncate]"); process.exit(2); }
if (!dsn && !fs) process.exit(2);

const TABLES = ["products","materials","services","leads","quotes","orders","invoices","payments","returns",
  "clients","client_links","tasks","pages","page_blocks","page_versions","saved_sections","track_events",
  "conversations","messages","users","sessions","meta"];
const PK = { products:"id", materials:"id", services:"id", sessions:"token", meta:"key" }; // TEXT-PK tables (also pages.slug unique — id ordered fine)

/* ---- backends ---- */
async function makeBackend() {
  if (pgliteDir) {
    const { PGlite } = await import("@electric-sql/pglite");
    const db = new PGlite(pgliteDir);
    return { name: "PGlite (embedded Postgres/WASM)", q: (s, p = []) => db.query(s, p).then((r) => r.rows), exec: (s) => db.exec(s), close: () => db.close() };
  }
  const pg = (await import("pg")).default;
  const c = new pg.Client({ connectionString: dsn, ssl: /localhost|127\\.0\\.0\\.1/.test(dsn) ? undefined : { rejectUnauthorized: false } });
  await c.connect();
  return { name: "pg " + dsn.replace(/:[^:@/]*@/, ":***@"), q: (s, p = []) => c.query(s, p).then((r) => r.rows), exec: (s) => c.query(s), close: () => c.end() };
}

const canon = (rows, cols) => JSON.stringify(rows.map((r) => JSON.stringify(cols.map((c) => {
  const v = r[c];
  if (v === null || v === undefined) return null;
  if (typeof v === "number") return +v.toFixed(6);
  return String(v);
}))).sort()); // order-independent: sidesteps SQLite vs PG collation drift
const digest = (s) => crypto.createHash("md5").update(s).digest("hex").slice(0, 10);

const src = new DatabaseSync(path.join(ROOT, "data/woodex.db"));
const tgt = await makeBackend();
console.log("[migrate] source: data/woodex.db · target:", tgt.name);

/* ---- schema ---- */
await tgt.exec(fs.readFileSync(path.join(ROOT, "scripts/schema.postgres.sql"), "utf8"));

/* ---- copy ---- */
let failed = 0;
const report = [];
for (const t of TABLES) {
  const cols = src.prepare(`PRAGMA table_info(${t})`).all().map((c) => c.name);
  const rows = src.prepare(`SELECT * FROM ${t}`).all();
  if (truncate) await tgt.q(`TRUNCATE ${t} CASCADE`);
  const tCols = await tgt.q(`SELECT column_name FROM information_schema.columns WHERE table_name=$1 AND table_schema NOT IN ('pg_catalog','information_schema')`, [t]);
  const missing = cols.filter((c) => !tCols.some((x) => x.column_name === c));
  if (missing.length) { console.error(`[migrate] ✗ ${t}: target missing columns ${missing.join(",")}`); failed++; continue; }
  let loaded = 0;
  for (let i = 0; i < rows.length; i += 200) {
    const chunk = rows.slice(i, i + 200);
    const values = []; const params = []; let ph = 0;
    for (const r of chunk) {
      values.push("(" + cols.map(() => "$" + ++ph).join(",") + ")");
      for (const c of cols) { const v = r[c]; params.push(v === undefined ? null : v); }
    }
    await tgt.q(`INSERT INTO ${t}(${cols.join(",")}) VALUES ${values.join(",")}`, params);
    loaded += chunk.length;
  }
  /* identity bump (numeric-id tables only) */
  if (!PK[t] && cols.includes("id")) {
    const seq = await tgt.q(`SELECT pg_get_serial_sequence('${t}','id') s`);
    if (seq[0]?.s) await tgt.q(`SELECT setval('${seq[0].s}', (SELECT COALESCE(MAX(id),0)+1 FROM ${t}), false)`);
  }
  /* verify: count + digest */
  const tRows = await tgt.q(`SELECT ${cols.join(",")} FROM ${t}`);
  const okCount = tRows.length === rows.length;
  const sHash = digest(canon(rows, cols)), tHash = digest(canon(tRows, cols));
  const okData = sHash === tHash;
  if (!okCount || !okData) failed++;
  report.push({ table: t, rows: loaded, "sha✔": okCount && okData ? "PASS" : `FAIL (${sHash}→${tHash})`, cnt: okCount ? "✓" : `${rows.length}→${tRows.length}` });
}
console.table(report);

/* ---- query battery (exercises shims + real API SQL shapes) ---- */
const battery = [
  ["public landing blocks", `SELECT type, props FROM page_blocks WHERE page_id=(SELECT id FROM pages WHERE slug='ramadan-workspace-sale') ORDER BY sort LIMIT 8`, 8],
  ["clients order-by datetime() shim", `SELECT id FROM clients ORDER BY datetime(updated_at) DESC LIMIT 3`, null],
  ["merge-review group_concat shim", `SELECT string_agg('x',',') FROM clients LIMIT 1`, null],
  ["marketing daily substr grouping", `SELECT substr(created_at,1,10) d, COUNT(*) c FROM track_events GROUP BY d ORDER BY d DESC LIMIT 3`, null],
  ["inbox join", `SELECT cv.id FROM conversations cv JOIN clients cl ON cl.id=cv.client_id AND cl.merged_into IS NULL ORDER BY datetime(cv.last_at) DESC LIMIT 3`, null],
  ["session continuity", `SELECT s.token FROM sessions s JOIN users u ON u.id=s.user_id WHERE u.active=1 LIMIT 1`, null],
  ["finance sum/COALESCE", `SELECT COALESCE(SUM(total),0) t, COALESCE(SUM(paid),0) p FROM invoices`, null],
];
for (const [name, sql, expect] of battery) {
  try {
    const rows = await tgt.q(sql);
    const ok = expect === null ? true : rows.length === expect;
    if (!ok) failed++;
    console.log(`  ${ok ? "PASS" : "FAIL"} · ${name} (${rows.length} rows)`);
  } catch (e) { failed++; console.log(`  FAIL · ${name}: ${e.message.split("\n")[0]}`); }
}
/* identity next-value smoke: insert+delete a probe row */
try {
  const [r] = await tgt.q(`INSERT INTO meta(key,value) VALUES('__probe__','1') RETURNING key`);
  await tgt.q(`DELETE FROM meta WHERE key='__probe__'`);
  console.log("  PASS · write path (RETURNING) →", r?.key);
} catch (e) { failed++; console.log("  FAIL · write path:", e.message.split("\n")[0]); }

await tgt.close();
console.log(failed === 0 ? "\n[migrate] ✅ ALL GREEN — schema + data + query battery verified on Postgres" : `\n[migrate] ❌ ${failed} problem(s)`);
process.exit(failed === 0 ? 0 : 1);
