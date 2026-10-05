#!/usr/bin/env node
/** Disposable Postgres-semantic rehearsal: migrate current SQLite data into PGlite,
 * then run the exact full QA battery against an API and SQL assertions sharing the
 * same in-process PGlite instance. The temp data directory is removed afterward. */
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const dir = path.join(os.tmpdir(), `woodex-pg-qa-${process.pid}`);
const run = (args, env = process.env) => spawnSync(process.execPath, args, { cwd: ROOT, env, stdio: "inherit" });
let status = 1;
try {
  fs.rmSync(dir, { recursive: true, force: true });
  const migrated = run(["scripts/pg-migrate.mjs", "--pglite", dir, "--truncate"]);
  if (migrated.error) throw migrated.error;
  if (migrated.status !== 0) throw new Error(`Postgres rehearsal migration exited ${migrated.status}`);
  const env = { ...process.env, DATABASE_URL: `pglite:${dir}`, PORT: "0", QA_PG_REHEARSAL: "1" };
  const qa = run(["scripts/qa-full.mjs", "--pg-rehearsal"], env);
  if (qa.error) throw qa.error;
  status = qa.status ?? 1;
} catch (error) {
  console.error("[qa:pg]", error.message);
  status = 1;
} finally {
  fs.rmSync(dir, { recursive: true, force: true });
}
process.exitCode = status;
