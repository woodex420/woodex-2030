// Empirical check of `scripts/pg-migrate.mjs --pglite <dir> --truncate`: compare row counts, table by table, between the SQLite source and the PGlite target.
import { fileURLToPath } from "node:url";
const HERE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
import { spawnSync } from "node:child_process"; import fs from "node:fs"; import os from "node:os"; import path from "node:path"; import { pathToFileURL } from "node:url";
const ROOT = process.argv[2] || HERE_ROOT;
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-migrator-probe-")); const sqliteDir = path.join(tmp, "sqlite"); fs.mkdirSync(sqliteDir);
const clean = { ...process.env }; for (const k of ["DATABASE_URL", "VERCEL", "VERCEL_ENV", "INITIAL_ADMIN_EMAIL", "INITIAL_ADMIN_PASSWORD"]) delete clean[k];
const env = { ...clean, NODE_ENV: "test", WOODEX_DATA_DIR: sqliteDir, PORT: "0", WOODEX_DEMO_SEED: "1" };
const node = (args, e) => spawnSync(process.execPath, ["--experimental-sqlite", ...args], { cwd: ROOT, env: e, encoding: "utf8", maxBuffer: 1 << 26 });
const seeder = path.join(tmp, "seed.mjs");
fs.writeFileSync(seeder, `import { pathToFileURL } from "node:url"; const rt = await import(pathToFileURL(${JSON.stringify(ROOT + "/apps/api/server.mjs")}).href);
if (rt.httpServer) { if (!rt.httpServer.listening) await new Promise((r) => rt.httpServer.once("listening", r)); await new Promise((res, rej) => rt.httpServer.close((e) => (e ? rej(e) : res()))); rt.httpServer.closeAllConnections?.(); }
await rt.db.close(); process.exit(0);`);
if (node([seeder], env).status !== 0) { console.error("seed failed"); process.exit(2); }
const pgDir = path.join(tmp, "pglite");
const mig = node(["scripts/pg-migrate.mjs", "--pglite", pgDir, "--truncate"], { ...env, WOODEX_SQLITE_SOURCE: path.join(sqliteDir, "woodex.db") });
const migOut = (mig.stdout || "") + (mig.stderr || "");
const said = migOut.split("\n").map((l) => l.trim()).filter((l) => l && !/ExperimentalWarning|trace-warnings/.test(l));
console.log(`migrator exit code: ${mig.status} | it reported: ${said.slice(-2).join(" / ").slice(0, 220)}`);
const { DatabaseSync } = await import("node:sqlite"); const src = new DatabaseSync(path.join(sqliteDir, "woodex.db"), { readOnly: true });
const { PGlite } = await import(pathToFileURL(ROOT + "/node_modules/@electric-sql/pglite/dist/index.js").href); const pg = new PGlite(pgDir);
const tables = src.prepare("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name").all().map((r) => r.name);
const diffs = []; let srcTotal = 0, tgtTotal = 0;
for (const t of tables) { const a = src.prepare(`SELECT COUNT(*) c FROM "${t}"`).get().c; let b; try { b = Number((await pg.query(`SELECT COUNT(*)::int AS c FROM "${t}"`)).rows[0].c); } catch (e) { b = "missing"; }
  srcTotal += a; if (typeof b === "number") tgtTotal += b; if (a !== b) diffs.push({ table: t, sqlite: a, postgres: b }); }
console.log(`tables compared: ${tables.length} | rows in SQLite source: ${srcTotal} | rows in PostgreSQL target: ${tgtTotal}`);
console.log(`tables whose row count differs after a migration the tool reported as successful: ${diffs.length}`); for (const d of diffs) console.log(`  ${d.table.padEnd(22)} sqlite ${String(d.sqlite).padStart(5)}  ->  postgres ${String(d.postgres).padStart(5)}`);
const units = src.prepare("SELECT COALESCE(SUM(delta),0) u FROM stock_movements").get().u; console.log(`stock ledger units in source: ${units}`);
await pg.close(); fs.rmSync(tmp, { recursive: true, force: true });
