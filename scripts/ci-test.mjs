#!/usr/bin/env node
/** Isolated CI acceptance run: fresh SQLite fixture, PGlite rehearsal, API QA,
 * and browser journeys. Never reads or writes the workspace's data/woodex.db or a hosted DB. */
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
  const browser = await chromium.launch();
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

  /* Procurement owns its own throwaway database and asserts the contract that
     matters — a product receipt moves stock exactly once, a material receipt
     moves it never. It is here rather than in a job of its own so a module
     cannot regress without failing the same run that gates the merge. */
  console.log("[ci] Rehearsing procurement: suppliers, purchase orders, goods receiving.");
  await runChecked(process.execPath, ["scripts/test-procurement.mjs"], baseEnv, "procurement", 300_000);

  /* Payables and three-way matching. Money leaves the business here, so this
     runs in the same gated job: a variance that stops blocking payment is a
     financial regression, not a missing feature. */
  console.log("[ci] Rehearsing supplier invoices and three-way matching.");
  await runChecked(process.execPath, ["scripts/test-supplier-invoices.mjs"], baseEnv, "bills", 300_000);

  /* Production. This is the only module that can create stock without a
     purchase and destroy it without a sale, so the QC gate and the consumption
     gate are rehearsed here: a job card that completes un-inspected, or one
     that consumes twice, is an inventory-integrity regression. */
  console.log("[ci] Rehearsing production: BOMs, job cards, consumption, QC.");
  await runChecked(process.execPath, ["scripts/test-production.mjs"], baseEnv, "production", 300_000);

  /* The upgrade path. Every other test builds its database from scratch, which
     is not the situation a deployment is ever in. 011 adds a column to a table
     that already exists, and getting that wrong stopped the API from booting
     while every fresh-database test still passed. */
  console.log("[ci] Rehearsing a schema upgrade from a pre-011 database.");
  await runChecked(process.execPath, ["--experimental-sqlite", "scripts/test-schema-upgrade.mjs"], baseEnv, "upgrade", 300_000);

  /* The Operations screens' contract. These modules shipped backend-first and
     stayed invisible for three increments; this suite asserts the read side the
     screens depend on, including the fields whose absence renders an empty
     table rather than an error. */
  console.log("[ci] Rehearsing the Operations screens' API contract.");
  await runChecked(process.execPath, ["scripts/test-ops-api.mjs"], baseEnv, "ops", 300_000);
  await runChecked(process.execPath, ["scripts/test-inventory.mjs"], baseEnv, "inventory", 300_000);
  await runChecked(process.execPath, ["scripts/test-finance.mjs"], baseEnv, "finance", 300_000);

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
  console.log("[ci] PASS: PostgreSQL rehearsal, SQLite security/API QA, and all browser journeys.");
} catch (error) {
  failed = true;
  console.error("[ci] FAIL:", error instanceof Error ? error.message : String(error));
} finally {
  for (const child of [...children].reverse()) await stop(child);
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (failed) process.exitCode = 1;
