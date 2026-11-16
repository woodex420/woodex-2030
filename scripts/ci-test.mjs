#!/usr/bin/env node
/** Isolated CI acceptance run: fresh SQLite fixture, PGlite rehearsal, API QA,
 * and browser journeys. Never reads or writes the workspace's data/woodex.db or a hosted DB.
 *
 * Needs `npm run vercel:build` to have run first: the last step opens every
 * dashboard screen in the built bundle (dist/). The CI workflow builds before
 * calling this. */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-ci-"));
const dataDir = path.join(tempRoot, "data");
fs.mkdirSync(dataDir, { recursive: true });
const apiPort = Number(process.env.CI_API_PORT || 3001);
const dashboardPort = Number(process.env.CI_DASHBOARD_PORT || 5173);
const storefrontPort = Number(process.env.CI_STOREFRONT_PORT || 5174);
const npm = process.platform === "win32" ? "npm.cmd" : "npm";
const apiBase = `http://127.0.0.1:${apiPort}`;
const dashboardBase = `http://127.0.0.1:${dashboardPort}`;
const storefrontBase = `http://127.0.0.1:${storefrontPort}`;
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const children = [];

const baseEnv = { ...process.env, NODE_ENV: "test", WOODEX_DATA_DIR: dataDir, WOODEX_DEMO_SEED: "1" };
for (const key of ["DATABASE_URL", "VERCEL", "VERCEL_ENV", "INITIAL_ADMIN_EMAIL", "INITIAL_ADMIN_PASSWORD"]) delete baseEnv[key];

function attachLogs(child, label) {
  child.stdout?.on("data", (chunk) => process.stdout.write(`[${label}] ${chunk}`));
  child.stderr?.on("data", (chunk) => process.stderr.write(`[${label}] ${chunk}`));
}

function start(command, args, env, label) {
  const child = spawn(command, args, {
    cwd: ROOT,
    env,
    stdio: ["ignore", "pipe", "pipe"],
    detached: process.platform !== "win32",
    shell: process.platform === "win32",
  });
  attachLogs(child, label);
  children.push(child);
  child.once("error", (error) => console.error(`[${label}] process error: ${error.message}`));
  return child;
}

function runChecked(command, args, env, label, timeoutMs = 240_000) {
  return new Promise((resolve, reject) => {
    const child = start(command, args, env, label);
    const timer = setTimeout(() => {
      stop(child).finally(() => reject(new Error(`${label} timed out after ${Math.round(timeoutMs / 1000)} seconds`)));
    }, timeoutMs);
    child.once("close", (code, signal) => {
      clearTimeout(timer);
      if (code === 0) resolve();
      else reject(new Error(`${label} exited ${code ?? signal ?? "unknown"}`));
    });
  });
}

/* GitHub annotations are readable through the API even when the raw job log is
   not. Anything a journey reports on stderr is re-emitted as an annotation so a
   failing CI run explains itself in `gh api …/annotations`. */
const annotate = (message) => {
  const one = String(message).replace(/\r?\n/g, " ⏎ ").slice(0, 900);
  if (process.env.GITHUB_ACTIONS === "true") console.log(`::error title=CI journey::${one}`);
};

function runJourney(script, env, label, timeoutMs = 300_000) {
  return new Promise((resolve, reject) => {
    const child = start(process.execPath, ["--experimental-sqlite", script], env, label);
    let tail = "";
    child.stdout?.on("data", (c) => { tail = (tail + c).slice(-1500); });
    child.stderr?.on("data", (c) => { tail = (tail + c).slice(-1500); });
    const timer = setTimeout(() => {
      stop(child).finally(() => { annotate(`${label} timed out`); reject(new Error(`${label} timed out`)); });
    }, timeoutMs);
    child.once("close", (code) => {
      clearTimeout(timer);
      if (code === 0) return resolve();
      annotate(`${label} exited ${code}: ${tail.trim() || "(no output)"}`);
      reject(new Error(`${label} exited ${code}`));
    });
  });
}

async function waitFor(url, child, label) {
  const deadline = Date.now() + 60_000;
  let lastError = "not responding yet";
  while (Date.now() < deadline) {
    if (child.exitCode !== null) throw new Error(`${label} exited ${child.exitCode} before becoming ready`);
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(1500) });
      if (response.ok) return;
      lastError = `HTTP ${response.status}`;
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
    }
    await delay(300);
  }
  throw new Error(`${label} did not become ready at ${url}: ${lastError}`);
}

async function stop(child) {
  if (!child || child.exitCode !== null) return;
  const exited = new Promise((resolve) => child.once("close", resolve));
  try {
    if (child.pid && process.platform !== "win32") process.kill(-child.pid, "SIGTERM");
    else child.kill("SIGTERM");
  } catch { /* already stopped */ }
  await Promise.race([exited, delay(4000)]);
  if (child.exitCode === null) {
    try {
      if (child.pid && process.platform !== "win32") process.kill(-child.pid, "SIGKILL");
      else child.kill("SIGKILL");
    } catch { /* already stopped */ }
    await Promise.race([exited, delay(1000)]);
  }
}

/* Fail once, clearly, if the browser cannot launch — before spending two minutes
   starting servers. The four journeys all abort on the same launch, so a
   preflight turns "e2e:login exited 1" into the actual reason. */
async function browserPreflight() {
  const { chromium } = await import("playwright");
  const { launchOptions } = await import("./lib/browser.mjs");
  const browser = await chromium.launch(launchOptions());
  await browser.close();
}

let failed = false;
try {
  console.log("[ci] Browser preflight.");
  try {
    await browserPreflight();
    console.log("[ci] Chromium launches.");
  } catch (error) {
    console.error("[ci] Chromium cannot launch: " + String(error.message).split("\n")[0]);
    console.error("[ci] the workflow runs `npx playwright install --with-deps chromium`; if that step passed, "
      + "check that the installed browser matches the playwright version in package-lock.json.");
    throw error;
  }

  console.log("[ci] Running disposable PostgreSQL-semantic QA rehearsal.");
  await runChecked(process.execPath, ["scripts/qa-pg.mjs"], baseEnv, "qa:pg", 600_000);

  /* An insert must report the id it created, on PostgreSQL too. The list of
     tables that do so is compared with the catalogue, so a table added by a
     migration cannot be forgotten — which is how 41 of 63 were (F-02). */
  console.log("[ci] Checking that every insert reports its id on PostgreSQL.");
  await runChecked(process.execPath, ["--experimental-sqlite", "scripts/test-return-id.mjs"], baseEnv, "return-id", 300_000);

  /* The SQLite → PostgreSQL copy. qa:pg above depends on it, and it once
     reported success while leaving nine tables empty (F-04). This counts the
     rows itself instead of reading the tool's report. */
  console.log("[ci] Rehearsing the SQLite → PostgreSQL copy: every row, rollback on failure.");
  await runChecked(process.execPath, ["--experimental-sqlite", "scripts/test-pg-migrate.mjs"], baseEnv, "migrate", 600_000);

  /* Every module suite, on BOTH engines. Each owns a throwaway database and
     asserts the contract that matters for its module:

       procurement        a product receipt moves stock exactly once, a material receipt never
       supplier-invoices  three-way matching — a variance that stops blocking payment is a
                          financial regression, not a missing feature
       production         the only module that can create stock without a purchase and destroy
                          it without a sale: the QC gate and the consumption gate
       ops-api            the read side the Operations screens depend on, including the fields
                          whose absence renders an empty table rather than an error
       stock-ledger, inventory, finance, crm, delivery, support, credentials, public-surface

     They used to run here one by one, on SQLite only, while production runs
     PostgreSQL; pointed at PostgreSQL, ten of eleven failed (F-02, F-03). They
     are in this job rather than one of their own so a module cannot regress on
     either engine without failing the same run that gates the merge. The list
     lives in scripts/test-modules.mjs. */
  console.log("[ci] Rehearsing every module on SQLite and on PostgreSQL.");
  await runChecked(process.execPath, ["--experimental-sqlite", "scripts/test-modules.mjs"], baseEnv, "modules", 900_000);

  /* The upgrade path. Every other test builds its database from scratch, which
     is not the situation a deployment is ever in. 011 adds a column to a table
     that already exists, and getting that wrong stopped the API from booting
     while every fresh-database test still passed. */
  console.log("[ci] Rehearsing a schema upgrade from a pre-011 database.");
  await runChecked(process.execPath, ["--experimental-sqlite", "scripts/test-schema-upgrade.mjs"], baseEnv, "upgrade", 300_000);

  /* Two servers on one database — which the embedded PostgreSQL cannot share,
     so this one stays on its own; it carries its own PostgreSQL section. */
  await runChecked(process.execPath, ["scripts/test-throttle.mjs"], baseEnv, "throttle", 300_000);

  console.log("[ci] Starting isolated SQLite API for full API/security QA.");
  const api = start(process.execPath, ["--experimental-sqlite", "apps/api/server.mjs"], {
    ...baseEnv,
    PORT: String(apiPort),
  }, "api");
  await waitFor(`${apiBase}/api/health`, api, "API");
  await runChecked(process.execPath, ["--experimental-sqlite", "scripts/qa-full.mjs"], {
    ...baseEnv,
    QA_BASE: apiBase,
  }, "qa:sqlite", 300_000);

  console.log("[ci] Starting Dashboard and Storefront for browser acceptance.");
  const dashboard = start(npm, ["run", "dev", "--workspace", "@woodex/dashboard", "--", "--port", String(dashboardPort)], {
    ...baseEnv,
    API_PROXY_TARGET: apiBase,
  }, "dashboard");
  const storefront = start(npm, ["run", "dev", "--workspace", "@woodex/storefront", "--", "--port", String(storefrontPort)], {
    ...baseEnv,
    API_PROXY_TARGET: apiBase,
  }, "storefront");
  await Promise.all([
    waitFor(`${dashboardBase}/`, dashboard, "Dashboard"),
    waitFor(`${storefrontBase}/`, storefront, "Storefront"),
  ]);

  const browserEnv = {
    ...baseEnv,
    E2E_REQUIRED: "1",
    BASE: dashboardBase,
    DASHBOARD_BASE: dashboardBase,
    STOREFRONT_BASE: storefrontBase,
    E2E_SQLITE_DB: path.join(dataDir, "woodex.db"),
  };
  /* Browser journeys. Each script prints its own diagnosis, but `runChecked`
     only reports an exit code — so on failure re-echo the last lines of output
     here. Without this, a CI log says "e2e:login exited 1" and nothing else
     (this job has failed that way since the first commit). */
  const browserJourneys = [
    ["e2e:login", "scripts/e2e-login.mjs"],
    ["e2e:storefront-crm", "scripts/e2e-storefront-crm.mjs"],
    ["e2e:quotation-template", "scripts/e2e-quotation-template.mjs"],
    ["e2e:page-builder", "scripts/e2e-page-builder.mjs"],
    /* Last, because it only reads: sign in and open every screen declared in
       the dashboard's router. The four journeys above walk four paths; two
       screens outside them crashed on open with the whole gate green (F-05). */
    ["e2e:dashboard-routes", "scripts/e2e-dashboard-routes.mjs"],
  ];
  for (const [label, script] of browserJourneys) {
    try {
      await runJourney(script, browserEnv, label);
    } catch (error) {
      console.error(`[ci] ${label} failed — see the [${label}] lines above for the cause.`);
      console.error(`[ci] dashboard=${dashboardBase} storefront=${storefrontBase} api=${apiBase}`);
      throw error;
    }
  }
  /* The same screen check, against what actually ships. Everything above ran
     on the dev servers; a deployment serves the BUILT dashboard, minified, under
     /dashboard/. Pointed at that build for the first time, this check found the
     dashboard blank in the single-process shape: the server answered the
     dashboard's own script with the HTML shell, and the smoke test for that
     shape only ever fetched the shell. So the build is opened too. */
  console.log("[ci] Opening every dashboard screen in the production build, served by one process.");
  const distRoot = path.join(ROOT, "dist");
  if (!fs.existsSync(path.join(distRoot, "dashboard", "index.html"))) {
    throw new Error("dist/dashboard/index.html is missing — run `npm run vercel:build` before `node scripts/ci-test.mjs` (the CI workflow does)");
  }
  const builtPort = Number(process.env.CI_BUILT_PORT || 4181);
  const builtBase = `http://127.0.0.1:${builtPort}`;
  const builtDataDir = path.join(tempRoot, "built-data");
  fs.mkdirSync(builtDataDir, { recursive: true });
  const built = start(process.execPath, ["--experimental-sqlite", "apps/api/server.mjs"], {
    ...baseEnv,
    WOODEX_DATA_DIR: builtDataDir,
    WOODEX_SERVE_STATIC: distRoot,
    PORT: String(builtPort),
  }, "built");
  await waitFor(`${builtBase}/api/health`, built, "Production build");
  try {
    await runJourney("scripts/e2e-dashboard-routes.mjs", {
      ...baseEnv,
      E2E_REQUIRED: "1",
      BASE: `${builtBase}/dashboard`,
      DASHBOARD_BASE: `${builtBase}/dashboard`,
    }, "e2e:built-routes");
  } catch (error) {
    console.error("[ci] e2e:built-routes failed — see the [e2e:built-routes] lines above for the cause.");
    console.error(`[ci] production build served at ${builtBase}/dashboard`);
    throw error;
  }

  console.log("[ci] PASS: PostgreSQL rehearsal, every module on both engines, SQLite security/API QA, all browser journeys, and every dashboard screen opened — on the dev server and in the production build.");
} catch (error) {
  failed = true;
  console.error("[ci] FAIL:", error instanceof Error ? error.message : String(error));
} finally {
  for (const child of [...children].reverse()) await stop(child);
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (failed) process.exitCode = 1;
