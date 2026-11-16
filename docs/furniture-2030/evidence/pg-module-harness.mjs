#!/usr/bin/env node
/**
 * Runs the repository's MODULE tests against PostgreSQL semantics (PGlite) instead of SQLite.
 *
 * Why this exists: every module test in scripts/test-*.mjs pins `DATABASE_URL: ""`, so it only
 * ever exercises SQLite, and `npm run qa:pg` does not call the module endpoints. Production runs
 * PostgreSQL. This harness closes that gap without changing the tests' assertions:
 *
 *   1. build a seeded SQLite fixture and migrate it into PGlite exactly as scripts/qa-pg.mjs does;
 *   2. copy each test, changing ONLY the `ROOT` constant and the `DATABASE_URL: ""` pin;
 *   3. run the copy and count its own ✓ / ✗ lines.
 *
 * Nothing in the repository is modified; everything happens under the OS temp directory.
 *
 *   node docs/furniture-2030/evidence/pg-module-harness.mjs                 # all tests, this checkout
 *   node docs/furniture-2030/evidence/pg-module-harness.mjs --only crm,support
 *   node docs/furniture-2030/evidence/pg-module-harness.mjs --root /tmp/patched-copy --json out.json
 *
 * Reading the result: a ✗ caused by the test opening the SQLite file directly (`DatabaseSync`,
 * "no such table") is an artefact of running a SQLite-shaped test on PostgreSQL, not an
 * application defect. A ✗ with HTTP 500 or an id of 0 is an application defect.
 */
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const arg = (name) => { const i = process.argv.indexOf(name); return i > -1 ? process.argv[i + 1] : null; };
const ROOT = path.resolve(arg("--root") || path.join(path.dirname(fileURLToPath(import.meta.url)), "..", "..", ".."));
const ALL = ["public-surface", "procurement", "supplier-invoices", "production", "ops-api", "inventory", "finance", "crm", "delivery", "support", "credentials"];
const only = arg("--only")?.split(",").map((s) => s.trim()).filter(Boolean);
const tests = only?.length ? ALL.filter((t) => only.includes(t)) : ALL;
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-pg-modules-"));
const node = (args, env, opts = {}) => spawnSync(process.execPath, ["--experimental-sqlite", ...args], { cwd: ROOT, env, encoding: "utf8", maxBuffer: 64 * 1024 * 1024, timeout: 420000, ...opts });
const clean = { ...process.env }; for (const k of ["DATABASE_URL", "VERCEL", "VERCEL_ENV", "INITIAL_ADMIN_EMAIL", "INITIAL_ADMIN_PASSWORD"]) delete clean[k];

// 1. seeded SQLite fixture, the same way scripts/qa-pg.mjs builds one
const sqliteDir = path.join(tmp, "sqlite"); fs.mkdirSync(sqliteDir, { recursive: true });
const seeder = path.join(tmp, "seed.mjs");
fs.writeFileSync(seeder, `import { pathToFileURL } from "node:url";
const rt = await import(pathToFileURL(${JSON.stringify(path.join(ROOT, "apps/api/server.mjs"))}).href);
if (rt.httpServer) { if (!rt.httpServer.listening) await new Promise((r) => rt.httpServer.once("listening", r));
  await new Promise((res, rej) => rt.httpServer.close((e) => (e ? rej(e) : res()))); rt.httpServer.closeAllConnections?.(); }
await rt.db.close(); process.exit(0);\n`);
const seeded = node([seeder], { ...clean, NODE_ENV: "test", WOODEX_DATA_DIR: sqliteDir, PORT: "0", WOODEX_DEMO_SEED: "1" });
if (seeded.status !== 0 || !fs.existsSync(path.join(sqliteDir, "woodex.db"))) { console.error("seeding failed:\n" + (seeded.stderr || "").slice(-1500)); process.exit(2); }

// 2. migrate it into a template PGlite database
const template = path.join(tmp, "pglite-template");
const migrated = node(["scripts/pg-migrate.mjs", "--pglite", template, "--truncate"], { ...clean, NODE_ENV: "test", WOODEX_DATA_DIR: sqliteDir, PORT: "0", WOODEX_DEMO_SEED: "1", WOODEX_SQLITE_SOURCE: path.join(sqliteDir, "woodex.db") });
if (migrated.status !== 0) { console.error("migration into PGlite failed:\n" + ((migrated.stdout || "") + (migrated.stderr || "")).slice(-2000)); process.exit(2); }

// 3. run each module test against its own copy of that database
const results = [];
for (const name of tests) {
  const src = path.join(ROOT, "scripts", `test-${name}.mjs`);
  if (!fs.existsSync(src)) { results.push({ test: name, error: "test file not found" }); continue; }
  const dbDir = path.join(tmp, `pglite-${name}`); fs.cpSync(template, dbDir, { recursive: true });
  let code = fs.readFileSync(src, "utf8");
  const before = code;
  code = code.replace(/const ROOT = path\.resolve\(path\.dirname\(fileURLToPath\(import\.meta\.url\)\), "\.\."\);/, `const ROOT = ${JSON.stringify(ROOT)};`);
  const pins = (code.match(/DATABASE_URL: ""/g) || []).length;
  code = code.replace(/DATABASE_URL: ""/g, `DATABASE_URL: ${JSON.stringify("pglite:" + dbDir)}`);
  if (code === before || pins === 0) { results.push({ test: name, error: "could not retarget the test (ROOT or DATABASE_URL pin not found)" }); continue; }
  const copy = path.join(tmp, `test-${name}.pg.mjs`); fs.writeFileSync(copy, code);
  const t0 = Date.now(); const run = node([copy], { ...clean });
  const outText = (run.stdout || "") + "\n" + (run.stderr || "");
  const lines = outText.split("\n");
  const pass = lines.filter((l) => /^\s*✓ /.test(l)).length;
  const failLines = lines.filter((l) => /^\s*✗ /.test(l) && !/problem\(s\)/.test(l)).map((l) => l.trim().slice(2).slice(0, 220));
  const artefacts = failLines.filter((l) => /no such table|DatabaseSync|SQLITE|unable to open database/i.test(l)).length;
  results.push({ test: name, exit: run.status, timedOut: run.error?.code === "ETIMEDOUT" || undefined, seconds: Math.round((Date.now() - t0) / 1000), pass, fail: failLines.length,
    sqliteOnlyArtefacts: artefacts, firstFailures: failLines.slice(0, 6), crash: pass + failLines.length === 0 ? outText.trim().slice(-400) : undefined });
}
fs.rmSync(tmp, { recursive: true, force: true });

const commit = spawnSync("git", ["rev-parse", "--short", "HEAD"], { cwd: ROOT, encoding: "utf8" }).stdout?.trim() || "not a git checkout";
const report = { root: ROOT, commit, node: process.version, ranAt: new Date().toISOString(), driver: "PGlite (PostgreSQL semantics)", results };
if (arg("--json")) fs.writeFileSync(arg("--json"), JSON.stringify(report, null, 1) + "\n");
console.log(`PostgreSQL-semantic module run — ${ROOT} @ ${commit} — node ${process.version}`);
console.log("test".padEnd(20) + "pass".padStart(6) + "fail".padStart(6) + "  exit  note");
for (const r of results) {
  if (r.error) { console.log(r.test.padEnd(20) + "   —     —    —   " + r.error); continue; }
  console.log(r.test.padEnd(20) + String(r.pass).padStart(6) + String(r.fail).padStart(6) + String(r.exit).padStart(6) + "  " + (r.fail ? r.firstFailures[0] : r.crash ? "no checks ran: " + r.crash.slice(-160).replace(/\s+/g, " ") : "all checks passed"));
}
const failed = results.filter((r) => r.error || r.exit !== 0).length;
console.log(`\n${results.length - failed} of ${results.length} module suites pass on PostgreSQL semantics.`);
process.exit(failed ? 1 : 0);
