#!/usr/bin/env node
/**
 * Every module suite, on every database engine the application runs on.
 *
 *   npm run test:modules                          # SQLite and PostgreSQL
 *   node scripts/test-modules.mjs --engine pglite # one engine
 *   node scripts/test-modules.mjs --only crm,support
 *   node scripts/test-modules.mjs --json out.json # machine-readable result
 *
 * WHY THIS EXISTS (Furniture 2030 Phase 1, finding F-02)
 *
 * The module suites are the tests that create a purchase order, receive it,
 * raise a ticket, post a journal entry. They all ran on SQLite. Production
 * runs PostgreSQL. `npm run qa:pg` did run on PostgreSQL, but it does not call
 * the module endpoints — so the gate could be entirely green while one module
 * suite in eleven worked on the production engine.
 *
 * This runner makes "passes" mean "passes on both". A suite is listed here
 * once and runs unchanged on each engine; scripts/lib/api-under-test.mjs gives
 * it a server and raw database access for whichever engine the run names.
 *
 * NOT IN THIS LIST — read before assuming "both engines" covers everything:
 *
 *   One engine by nature, and each says so:
 *     test-schema-upgrade   SQLite    an old SQLite file must still boot
 *     test-integrity        PGlite    the PostgreSQL constraints reject bad data
 *     test-audit-schema     PGlite    the drift audit detects a missing constraint
 *     test-restore-drill    PGlite    a PostgreSQL backup restores row for row
 *
 *   Mixed, and NOT converted to this runner:
 *     test-throttle         two servers on one database, which PGlite cannot
 *                           share (one process per directory), so the cross-
 *                           process part is SQLite; four assertions run on PGlite
 *     test-audit-log        imports the server in-process on SQLite for the
 *                           audit-trail assertions; only its migration-ledger
 *                           part runs on PGlite. The audit trail itself is
 *                           therefore still proven on SQLite only.
 *
 * ADDING A MODULE: write scripts/test-<name>.mjs with `spawnApi` and `rawDb`
 * from ./lib/api-under-test.mjs (never `DATABASE_URL: ""`, never
 * `new DatabaseSync`), then add <name> to SUITES. It will not be merged green
 * unless it passes on PostgreSQL.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { ROOT, createPgliteDatabase } from "./lib/pg-fixture.mjs";

export const SUITES = [
  "public-surface", "stock-ledger", "procurement", "supplier-invoices", "production", "ops-api",
  "inventory", "finance", "crm", "delivery", "support", "credentials",
];
export const ENGINES = ["sqlite", "pglite"];

const arg = (name) => { const i = process.argv.indexOf(name); return i > -1 ? process.argv[i + 1] : null; };
const only = arg("--only")?.split(",").map((s) => s.trim()).filter(Boolean);
const engineArg = arg("--engine");
const jsonOut = arg("--json");

if (engineArg && !ENGINES.includes(engineArg)) {
  console.error(`--engine must be one of: ${ENGINES.join(", ")}`);
  process.exit(2);
}
const unknown = (only ?? []).filter((name) => !SUITES.includes(name));
if (unknown.length) {
  console.error(`unknown suite(s): ${unknown.join(", ")}\nknown: ${SUITES.join(", ")}`);
  process.exit(2);
}
const suites = only?.length ? SUITES.filter((name) => only.includes(name)) : SUITES;
const engines = engineArg ? [engineArg] : ENGINES;

/* A suite must not be able to drop out of this list by being renamed. */
const missing = suites.filter((name) => !fs.existsSync(path.join(ROOT, "scripts", `test-${name}.mjs`)));
if (missing.length) {
  console.error(`suite file(s) not found: ${missing.map((n) => `scripts/test-${n}.mjs`).join(", ")}`);
  process.exit(2);
}

const work = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-modules-"));
const baseEnv = { ...process.env };
for (const key of ["DATABASE_URL", "VERCEL", "VERCEL_ENV", "INITIAL_ADMIN_EMAIL", "INITIAL_ADMIN_PASSWORD"]) delete baseEnv[key];

function runSuite(name, engine, template) {
  return new Promise((resolve) => {
    const started = Date.now();
    const child = spawn(process.execPath, ["--experimental-sqlite", path.join("scripts", `test-${name}.mjs`)], {
      cwd: ROOT,
      env: { ...baseEnv, WOODEX_TEST_DB: engine, ...(template ? { WOODEX_TEST_PG_TEMPLATE: template } : {}) },
      stdio: ["ignore", "pipe", "pipe"],
    });
    let output = "";
    child.stdout.on("data", (buf) => { output += buf; });
    child.stderr.on("data", (buf) => { output += buf; });
    const timer = setTimeout(() => { output += "\n✗ timed out after 300s\n"; child.kill("SIGKILL"); }, 300_000);
    child.on("close", (code) => {
      clearTimeout(timer);
      const lines = output.split("\n");
      const pass = lines.filter((l) => /^\s*✓ /.test(l)).length;
      const failures = lines.filter((l) => /^\s*✗ /.test(l) && !/\d+ .*(problem|check)\(s\)/.test(l)).map((l) => l.trim());
      const serverErrors = lines.filter((l) => /^\s*⚠ server:/.test(l)).map((l) => l.trim());
      /* Zero checks is not a pass: it is a suite that never got as far as checking. */
      const ok = code === 0 && pass > 0 && failures.length === 0;
      resolve({ suite: name, engine, ok, exit: code, pass, fail: failures.length, seconds: Math.round((Date.now() - started) / 100) / 10,
        failures: failures.slice(0, 12), serverErrors: serverErrors.slice(0, 6), tail: ok ? undefined : output.trim().slice(-600) });
    });
  });
}

const results = [];
let status = 0;
try {
  let template = null;
  if (engines.includes("pglite")) {
    /* Build the PostgreSQL schema once and copy it for each suite; replaying
       every migration twelve times is most of a minute of nothing. */
    const started = Date.now();
    template = path.join(work, "pglite-template");
    const built = await createPgliteDatabase(template);
    console.log(`[modules] PostgreSQL schema built once (${built.applied.length} migrations, ${Date.now() - started} ms); each suite gets its own copy.`);
  }
  for (const engine of engines) {
    console.log(`\n[modules] ${engine === "pglite" ? "PostgreSQL (PGlite)" : "SQLite"}`);
    for (const name of suites) {
      const result = await runSuite(name, engine, template);
      results.push(result);
      console.log(`  ${result.ok ? "✓" : "✗"} ${name.padEnd(20)} ${String(result.pass).padStart(3)} passed${result.fail ? `, ${result.fail} FAILED` : ""}  (${result.seconds}s)`);
      if (!result.ok) {
        status = 1;
        for (const line of result.serverErrors) console.log(`        ${line}`);
        for (const line of result.failures) console.log(`        ${line}`);
        if (!result.failures.length) console.log(`        exit ${result.exit}; last output: ${String(result.tail).replace(/\s+/g, " ").slice(-300)}`);
      }
    }
  }
} catch (error) {
  status = 1;
  console.error(`[modules] could not run: ${error instanceof Error ? error.stack ?? error.message : String(error)}`);
} finally {
  fs.rmSync(work, { recursive: true, force: true });
}

const total = (engine) => results.filter((r) => r.engine === engine);
const summary = engines.map((engine) => {
  const rows = total(engine);
  return `${engine === "pglite" ? "PostgreSQL" : "SQLite"} ${rows.filter((r) => r.ok).length}/${rows.length} suites, ${rows.reduce((n, r) => n + r.pass, 0)} checks`;
}).join(" · ");
if (jsonOut) {
  fs.writeFileSync(jsonOut, JSON.stringify({ ranAt: new Date().toISOString(), node: process.version, engines, suites, ok: status === 0, results }, null, 1) + "\n");
}
if (status === 0 && results.length === suites.length * engines.length) {
  console.log(`\n✓ every module suite passes on every engine — ${summary}`);
} else {
  status = 1;
  console.error(`\n✗ module suites failed — ${summary}`);
}
process.exit(status);
