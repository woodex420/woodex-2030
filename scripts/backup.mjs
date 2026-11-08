#!/usr/bin/env node
/**
 * Database backup + verify.
 *
 *   node scripts/backup.mjs                        # back up the local SQLite file
 *   node scripts/backup.mjs --out /backups/wx.db.gz
 *   node scripts/backup.mjs --verify /backups/wx.db.gz
 *   node scripts/backup.mjs --pg "postgres://…"    # dump rows as JSON (Hostinger/Vercel)
 *
 * SQLite is backed up with `VACUUM INTO`, not a file copy: copying a live
 * database can capture a torn write, while VACUUM INTO produces a consistent
 * snapshot even while the API is serving requests.
 *
 * The `--verify` path is the point of the tool. A backup nobody restored is not
 * a backup, so verify opens the archive, runs `PRAGMA integrity_check`, counts
 * every table and compares the totals against the live database.
 *
 * Postgres has no `VACUUM INTO`, so `--pg` writes portable JSON per table plus a
 * manifest. That is enough to restore by hand and to prove the dump is complete;
 * for real servers prefer `pg_dump` (documented in docs/DEPLOYMENT.md).
 */
import fs from "node:fs";
import path from "node:path";
import zlib from "node:zlib";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const args = process.argv.slice(2);
const arg = (f) => { const i = args.indexOf(f); return i >= 0 ? args[i + 1] : undefined; };
const flag = (f) => args.includes(f);

const dataDir = process.env.WOODEX_DATA_DIR ? path.resolve(process.env.WOODEX_DATA_DIR) : path.join(ROOT, "data");
const liveDb = path.join(dataDir, "woodex.db");
const stamp = new Date().toISOString().replace(/[:.]/g, "-").slice(0, 19);

function usage() {
  console.log(`usage:
  node scripts/backup.mjs [--out FILE.gz]        back up the local SQLite database
  node scripts/backup.mjs --verify FILE.gz       restore + integrity-check a backup
  node scripts/backup.mjs --pg URL [--out DIR]   export every table as JSON from Postgres

exit codes: 0 ok · 1 failure · 2 usage`);
}

function fail(message) { console.error(`[backup] ${message}`); process.exit(1); }

/* ---------------- verify ---------------- */
async function verify(file) {
  if (!fs.existsSync(file)) fail(`no such backup: ${file}`);
  const tmp = path.join(dataDir, `.verify-${Date.now()}.db`);
  const gz = file.endsWith(".gz");
  try {
    const raw = fs.readFileSync(file);
    fs.writeFileSync(tmp, gz ? zlib.gunzipSync(raw) : raw);
    const db = new DatabaseSync(tmp);
    const integrity = db.prepare("PRAGMA integrity_check").get();
    const verdict = Object.values(integrity ?? {})[0];
    if (verdict !== "ok") { db.close(); fail(`integrity_check returned "${verdict}"`); }

    const tables = db.prepare("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name").all().map((r) => r.name);
    const counts = {};
    for (const t of tables) counts[t] = db.prepare(`SELECT COUNT(*) AS n FROM "${t}"`).get().n;
    db.close();

    /* Compare against the live database when it is present and is not the file
       being verified (you may be verifying on the machine that produced it). */
    let live = null;
    if (fs.existsSync(liveDb) && path.resolve(liveDb) !== path.resolve(tmp)) {
      const ldb = new DatabaseSync(liveDb);
      live = {};
      for (const t of tables) {
        try { live[t] = ldb.prepare(`SELECT COUNT(*) AS n FROM "${t}"`).get().n; } catch { live[t] = null; }
      }
      ldb.close();
    }

    const total = Object.values(counts).reduce((a, b) => a + Number(b), 0);
    const drift = live ? tables.filter((t) => live[t] !== null && live[t] !== counts[t]) : [];
    console.log(`[backup] ${path.basename(file)}: integrity ok · ${tables.length} tables · ${total} rows`);
    if (live) {
      if (drift.length) {
        console.log(`[backup] row-count differences vs the live database (expected if the backup is older):`);
        for (const t of drift) console.log(`   ${t}: backup ${counts[t]} · live ${live[t]}`);
      } else {
        console.log("[backup] row counts match the live database exactly");
      }
    } else {
      console.log("[backup] live database not reachable — integrity and counts only");
    }
    return true;
  } finally {
    try { fs.unlinkSync(tmp); } catch { /* nothing to clean */ }
  }
}

/* ---------------- sqlite backup ---------------- */
async function backupSqlite(out) {
  if (!fs.existsSync(liveDb)) fail(`no database at ${liveDb} (set WOODEX_DATA_DIR if it lives elsewhere)`);
  fs.mkdirSync(path.dirname(out), { recursive: true });
  const snapshot = path.join(dataDir, `.snapshot-${Date.now()}.db`);
  try {
    const db = new DatabaseSync(liveDb);
    /* VACUUM INTO is the only way to get a consistent snapshot of a database
       that is being written to. A plain fs.copyFile would be torn. */
    db.exec(`VACUUM INTO '${snapshot.replace(/'/g, "''")}'`);
    db.close();
    const raw = fs.readFileSync(snapshot);
    fs.writeFileSync(out, zlib.gzipSync(raw, { level: 9 }));
    console.log(`[backup] wrote ${out} (${(fs.statSync(out).size / 1024).toFixed(1)} kB from ${(raw.length / 1024).toFixed(1)} kB)`);
  } finally {
    try { fs.unlinkSync(snapshot); } catch { /* nothing to clean */ }
  }
  return await verify(out);
}

/* ---------------- postgres export ---------------- */
async function backupPostgres(url, outDir) {
  const { default: pg } = await import("pg").catch(() => ({ default: null }));
  if (!pg) fail("the `pg` package is required for --pg backups (npm i pg)");
  fs.mkdirSync(outDir, { recursive: true });
  const client = new pg.Client({ connectionString: url });
  await client.connect();
  try {
    const tables = (await client.query(
      "SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name"
    )).rows.map((r) => r.table_name);
    const manifest = { createdAt: new Date().toISOString(), tables: {}, source: url.replace(/\/\/[^@]*@/, "//***@") };
    for (const t of tables) {
      const rows = (await client.query(`SELECT * FROM "${t}"`)).rows;
      fs.writeFileSync(path.join(outDir, `${t}.json`), JSON.stringify(rows, null, 0));
      manifest.tables[t] = rows.length;
    }
    fs.writeFileSync(path.join(outDir, "manifest.json"), JSON.stringify(manifest, null, 2));
    const total = Object.values(manifest.tables).reduce((a, b) => a + b, 0);
    console.log(`[backup] ${tables.length} tables · ${total} rows → ${outDir}`);
    return true;
  } finally {
    await client.end();
  }
}

/* ---------------- main ---------------- */
const verifyTarget = arg("--verify");
const pgUrl = arg("--pg");

if (flag("--help") || flag("-h")) { usage(); process.exit(0); }
if (verifyTarget) { await verify(verifyTarget); process.exit(0); }
if (pgUrl) { await backupPostgres(pgUrl, arg("--out") ?? path.join(ROOT, "backups", `pg-${stamp}`)); process.exit(0); }

const out = arg("--out") ?? path.join(ROOT, "backups", `woodex-${stamp}.db.gz`);
await backupSqlite(out);
