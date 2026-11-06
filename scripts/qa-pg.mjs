#!/usr/bin/env node
/** Disposable Postgres-semantic rehearsal. Create a fresh, repository-seeded SQLite
 * fixture, migrate it into temporary PGlite, run the full QA battery, then remove
 * both databases. No existing workspace DB or hosted database is read or written. */
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-pg-qa-${process.pid}`);
const pgliteDir = path.join(tempRoot, "pglite");
const sqliteDir = path.join(tempRoot, "sqlite");
const sqliteFile = path.join(sqliteDir, "woodex.db");
const run = (args, env = process.env) => spawnSync(process.execPath, args, { cwd: ROOT, env, stdio: "inherit" });
const isolatedEnvKeys = ["DATABASE_URL", "VERCEL", "VERCEL_ENV", "INITIAL_ADMIN_EMAIL", "INITIAL_ADMIN_PASSWORD", "NODE_ENV", "WOODEX_DATA_DIR", "PORT", "WOODEX_DEMO_SEED"];
const savedEnv = new Map(isolatedEnvKeys.map((key) => [key, process.env[key]]));
let status = 1;

try {
  fs.rmSync(tempRoot, { recursive: true, force: true });
  fs.mkdirSync(sqliteDir, { recursive: true });

  // Reuse the application's local-only schema/demo seed path, but in a fresh temp directory.
  for (const key of ["DATABASE_URL", "VERCEL", "VERCEL_ENV", "INITIAL_ADMIN_EMAIL", "INITIAL_ADMIN_PASSWORD"]) delete process.env[key];
  process.env.NODE_ENV = "test";
  process.env.WOODEX_DATA_DIR = sqliteDir;
  process.env.PORT = "0";
  process.env.WOODEX_DEMO_SEED = "1";
  const runtime = await import(`${pathToFileURL(path.join(ROOT, "apps/api/server.mjs")).href}?qa-seed=${process.pid}`);
  if (runtime.httpServer) {
    if (!runtime.httpServer.listening) await new Promise((resolve) => runtime.httpServer.once("listening", resolve));
    await new Promise((resolve, reject) => runtime.httpServer.close((error) => error ? reject(error) : resolve()));
    runtime.httpServer.closeAllConnections?.();
  }
  await runtime.db.close();
  if (!fs.existsSync(sqliteFile)) throw new Error("Temporary SQLite fixture was not initialized.");

  const migrationEnv = { ...process.env, WOODEX_SQLITE_SOURCE: sqliteFile };
  const migrated = run(["scripts/pg-migrate.mjs", "--pglite", pgliteDir, "--truncate"], migrationEnv);
  if (migrated.error) throw migrated.error;
  if (migrated.status !== 0) throw new Error(`Postgres rehearsal migration exited ${migrated.status}`);

  const qaEnv = { ...process.env, DATABASE_URL: `pglite:${pgliteDir}`, PORT: "0", QA_PG_REHEARSAL: "1", NODE_ENV: "test", WOODEX_DATA_DIR: sqliteDir };
  const qa = run(["scripts/qa-full.mjs", "--pg-rehearsal"], qaEnv);
  if (qa.error) throw qa.error;
  status = qa.status ?? 1;
} catch (error) {
  console.error("[qa:pg]", error instanceof Error ? error.message : String(error));
  status = 1;
} finally {
  fs.rmSync(tempRoot, { recursive: true, force: true });
  for (const [key, value] of savedEnv) {
    if (value === undefined) delete process.env[key];
    else process.env[key] = value;
  }
}
process.exitCode = status;
