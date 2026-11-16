/**
 * Start the API for a test — on SQLite or on PostgreSQL, chosen by the run.
 *
 *   node scripts/test-crm.mjs                       # SQLite (the default)
 *   WOODEX_TEST_DB=pglite node scripts/test-crm.mjs # PostgreSQL semantics
 *   npm run test:modules                            # every module suite, both engines
 *
 * WHY THIS EXISTS (Furniture 2030 Phase 1, findings F-02 / F-03)
 *
 * Every module suite used to pin `DATABASE_URL: ""`, so every one of them ran
 * on SQLite and only on SQLite. Production runs PostgreSQL. The two engines
 * disagree in ways that matter here — whether an INSERT reports its new id,
 * whether a timestamp comes back as text or as a Date, whether `1` is a
 * boolean — and each of those disagreements shipped as a defect that every
 * test was green on. Ten of eleven suites failed the first time they were
 * pointed at PostgreSQL.
 *
 * So a suite no longer chooses its engine. It asks this module for a server
 * and for raw database access, and the same assertions run on both.
 *
 * PostgreSQL here is PGlite: the real PostgreSQL parser, planner, types,
 * constraints and trigger language compiled to WASM, in a throwaway directory.
 * It is not the `pg` network driver that production uses; the adapter's two
 * PostgreSQL paths share their SQL rewriting, and `npm run audit:schema`
 * covers the hosted database itself.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { ROOT, createPgliteDatabase } from "./pg-fixture.mjs";

const requested = String(process.env.WOODEX_TEST_DB ?? "sqlite").trim().toLowerCase();
if (!["sqlite", "pglite"].includes(requested)) {
  /* A typo here must not quietly fall back to SQLite and report a PostgreSQL pass. */
  throw new Error(`WOODEX_TEST_DB must be "sqlite" or "pglite", got "${process.env.WOODEX_TEST_DB}"`);
}

/** The engine this run exercises: "sqlite" or "pglite". */
export const ENGINE = requested;
export const IS_POSTGRES = ENGINE === "pglite";

/**
 * The DATABASE_URL for a test's throwaway database under `tempRoot`.
 * SQLite: "" (the server creates <tempRoot>/woodex.db itself).
 * PGlite: a new directory carrying the complete schema. When the runner has
 * built a template (WOODEX_TEST_PG_TEMPLATE) it is copied, which is much
 * faster than replaying every migration for every suite.
 */
export async function testDatabaseUrl(tempRoot, name = "pglite") {
  if (!IS_POSTGRES) return "";
  const dir = path.join(tempRoot, name);
  if (!fs.existsSync(dir)) {
    const template = process.env.WOODEX_TEST_PG_TEMPLATE;
    if (template && fs.existsSync(template)) fs.cpSync(template, dir, { recursive: true });
    else await createPgliteDatabase(dir);
  }
  return `pglite:${dir}`;
}

/**
 * Spawn the API against a throwaway database in `tempRoot`.
 * Returns the child process; its stdout/stderr carry the usual boot log
 * (the suites read the port from it), and `rawDb(child)` reaches its database.
 * `env` is merged last, so a suite can add or override settings.
 * `database` names the database inside tempRoot, for suites that start a
 * second server on the same data.
 */
export async function spawnApi({ tempRoot, env = {}, database = "pglite" }) {
  fs.mkdirSync(tempRoot, { recursive: true });
  const child = spawn(process.execPath, ["--experimental-sqlite", "scripts/lib/api-under-test-child.mjs"], {
    cwd: ROOT,
    env: { ...process.env, NODE_ENV: "test", PORT: "0", WOODEX_DATA_DIR: tempRoot,
      DATABASE_URL: await testDatabaseUrl(tempRoot, database), VERCEL: "", VERCEL_ENV: "", WOODEX_DEMO_SEED: "1", ...env },
    stdio: ["ignore", "pipe", "pipe", "ipc"],
  });
  /* The child reports which driver it actually opened. If that is not the
     engine this run names, stop: a PostgreSQL run that quietly fell back to
     SQLite would print a green tick for a database it never touched. */
  child.on("message", (message) => {
    if (message?.kind !== "ready") return;
    if (message.driver !== ENGINE) {
      console.log(`  ✗ the API opened "${message.driver}" but this run is for "${ENGINE}" — refusing to continue`);
      child.kill("SIGKILL");
    }
  });
  /* A check that fails with "got 500" says nothing about why. The server has
     already logged the reason; repeat that one line next to the failed check,
     so a red run explains itself without a second run under a debugger. */
  let pendingLine = "";
  child.stderr.on("data", (buf) => {
    const lines = (pendingLine + String(buf)).split("\n");
    pendingLine = lines.pop() ?? "";
    for (const line of lines) {
      if (/→ 5\d\d\b/.test(line)) console.log(`    ⚠ server: ${line.trim().slice(0, 300)}`);
    }
  });
  return child;
}

/** Wait for the boot log to announce the port. Resolves to the base URL. */
export function baseUrlOf(child, timeoutMs = 90_000) {
  let log = "";
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`server did not start in ${timeoutMs / 1000}s:\n${log.slice(-900)}`)), timeoutMs);
    const onData = (buf) => {
      log += String(buf);
      const m = log.match(/http:\/\/localhost:(\d+)/);
      if (m) { clearTimeout(timer); resolve(`http://127.0.0.1:${m[1]}`); }
    };
    child.stdout.on("data", onData);
    child.stderr.on("data", onData);
    child.on("exit", (code) => { clearTimeout(timer); reject(new Error(`server exited ${code}:\n${log.slice(-900)}`)); });
  });
}

let nextId = 0;
/**
 * Raw SQL on the server's own database connection — the engine-neutral
 * replacement for `new DatabaseSync(file)`.
 *
 *   const db = rawDb(child);
 *   await db.get("SELECT resolve_by FROM tickets WHERE id = ?", id);   // one row or null
 *   await db.all("SELECT …");                                         // rows
 *   await db.run("UPDATE … WHERE id = ?", value, id);                 // throws if the database refuses
 *
 * `?` placeholders work on both engines. Timestamps arrive as ISO strings on
 * both (the channel is JSON), so they compare directly with API responses.
 */
export function rawDb(child) {
  const pending = new Map();
  child.on("message", (message) => {
    if (message?.kind !== "sql-result") return;
    const waiter = pending.get(message.id);
    if (!waiter) return;
    pending.delete(message.id);
    if (message.ok) waiter.resolve(message.result);
    else waiter.reject(new Error(message.error));
  });
  child.on("exit", () => {
    for (const waiter of pending.values()) waiter.reject(new Error("the API process exited before answering"));
    pending.clear();
  });
  const send = (mode, sql, params) => new Promise((resolve, reject) => {
    const id = ++nextId;
    pending.set(id, { resolve, reject });
    child.send({ kind: "sql", id, mode, sql, params }, (error) => {
      if (error) { pending.delete(id); reject(error); }
    });
  });
  return {
    engine: ENGINE,
    get: (sql, ...params) => send("get", sql, params),
    all: (sql, ...params) => send("all", sql, params),
    run: (sql, ...params) => send("run", sql, params),
    exec: (sql) => send("exec", sql, []),
  };
}
