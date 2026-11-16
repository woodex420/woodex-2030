// What happens when the documented cut-over command runs WITHOUT --truncate against an empty PostgreSQL (PGlite) database?
import { spawnSync } from "node:child_process"; import fs from "node:fs"; import os from "node:os"; import path from "node:path"; import { fileURLToPath } from "node:url";
const ROOT = process.argv[2] || path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..", ".."); const tmp = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-mig-")); const sqliteDir = path.join(tmp, "sqlite"); fs.mkdirSync(sqliteDir);
const clean = { ...process.env }; for (const k of ["DATABASE_URL", "VERCEL", "VERCEL_ENV", "INITIAL_ADMIN_EMAIL", "INITIAL_ADMIN_PASSWORD"]) delete clean[k];
const env = { ...clean, NODE_ENV: "test", WOODEX_DATA_DIR: sqliteDir, PORT: "0", WOODEX_DEMO_SEED: "1" };
const node = (args, e) => spawnSync(process.execPath, ["--experimental-sqlite", ...args], { cwd: ROOT, env: e, encoding: "utf8", maxBuffer: 1 << 26 });
const seeder = path.join(tmp, "seed.mjs");
fs.writeFileSync(seeder, `import { pathToFileURL } from "node:url"; const rt = await import(pathToFileURL(${JSON.stringify(ROOT + "/apps/api/server.mjs")}).href);
if (rt.httpServer) { if (!rt.httpServer.listening) await new Promise((r) => rt.httpServer.once("listening", r)); await new Promise((res, rej) => rt.httpServer.close((e) => (e ? rej(e) : res()))); rt.httpServer.closeAllConnections?.(); }
await rt.db.close(); process.exit(0);`);
node([seeder], env);
const mig = node(["scripts/pg-migrate.mjs", "--pglite", path.join(tmp, "pglite")], { ...env, WOODEX_SQLITE_SOURCE: path.join(sqliteDir, "woodex.db") });
const said = ((mig.stdout || "") + (mig.stderr || "")).split("\n").map((l) => l.trim()).filter((l) => l && !/ExperimentalWarning|trace-warnings/.test(l));
console.log("WITHOUT --truncate, into an empty PGlite database: exit code", mig.status); for (const l of said.filter((l) => /✗|error|Error|duplicate|ALL GREEN|failed/i.test(l)).slice(0, 6)) console.log("  " + l.slice(0, 200));
fs.rmSync(tmp, { recursive: true, force: true });
