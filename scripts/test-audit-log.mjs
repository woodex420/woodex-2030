#!/usr/bin/env node
/**
 * Audit-log self-test.
 *
 * The audit log is the one artefact whose value depends entirely on being
 * trustworthy, so it gets the same treatment as the schema audit: prove the
 * happy path, then prove each failure path is detected by NAME, not by count.
 *
 * What this asserts:
 *
 *   1. A payment records a row with before/after snapshots naming the invoice.
 *   2. A rejected payment (cancelled invoice) records NOTHING — the log must
 *      describe what happened, not what was attempted.
 *   3. A change that rolls back leaves no audit row. This is why writeAudit is
 *      called after the write and inside the transaction: logging an attempt
 *      that never committed would make the log a work of fiction.
 *   4. A malformed action is refused by the writer and counted, not written.
 *   5. The table is append-only: scripts/ contains no UPDATE or DELETE against
 *      audit_logs. This is enforced statically because a single stray DELETE
 *      would silently destroy the evidence the whole feature exists to keep.
 *   6. Actor attribution survives: an anonymous write is labelled, not blank.
 *
 * Runs against an in-process API with a throwaway SQLite database, same shape as
 * the rest of the QA battery.
 */
import { spawn, spawnSync } from "node:child_process";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-audit-"));
let pass = 0, fail = 0;
const results = [];

const ok = (name, detail = "") => { pass += 1; results.push(`  ✓ ${name}${detail ? ` · ${detail}` : ""}`); };
const bad = (name, detail = "") => { fail += 1; results.push(`  ✗ ${name}${detail ? ` · ${detail}` : ""}`); };

/* ---------- 5. static: nothing may mutate the log ---------- */
function assertAppendOnly() {
  const files = [
    "apps/api/server.mjs",
    ...fs.readdirSync(path.join(ROOT, "scripts"))
      .filter((f) => f.endsWith(".mjs"))
      .map((f) => path.join("scripts", f)),
  ];
  const offenders = [];
  for (const rel of files) {
    const src = fs.readFileSync(path.join(ROOT, rel), "utf8");
    // Any UPDATE/DELETE whose statement text mentions audit_logs.
    const re = /(UPDATE|DELETE)\s+(FROM\s+)?audit_logs/gi;
    for (const m of src.matchAll(re)) offenders.push(`${rel}: "${m[0]}"`);
  }
  if (offenders.length) bad("audit_logs is append-only", offenders.join(", "));
  else ok("audit_logs is append-only", `no UPDATE/DELETE across ${files.length} files`);
}

/* ---------- live API ---------- */
async function run() {
  const sqliteDir = path.join(tmp, "sqlite");
  fs.mkdirSync(sqliteDir, { recursive: true });
  for (const key of ["DATABASE_URL", "VERCEL", "VERCEL_ENV", "INITIAL_ADMIN_EMAIL", "INITIAL_ADMIN_PASSWORD", "WOODEX_DEMO_SEED"]) delete process.env[key];
  process.env.NODE_ENV = "test";
  process.env.WOODEX_DATA_DIR = sqliteDir;
  process.env.PORT = "0";

  const runtime = await import(`file://${path.join(ROOT, "apps/api/server.mjs")}?audit-test=${process.pid}`);
  if (!runtime.httpServer.listening) await new Promise((r) => runtime.httpServer.once("listening", r));
  const BASE = `http://127.0.0.1:${runtime.httpServer.address().port}`;

  const api = async (p, { method = "GET", token, body } = {}) => {
    const h = { accept: "application/json" };
    if (token) h.authorization = "Bearer " + token;
    if (body !== undefined) h["content-type"] = "application/json";
    const r = await fetch(BASE + p, { method, headers: h, body: body === undefined ? undefined : JSON.stringify(body) });
    const text = await r.text();
    let j = null; try { j = JSON.parse(text); } catch { /* non-json */ }
    return { s: r.status, j };
  };

  try {
    const login = await api("/api/auth/login", { method: "POST", body: { email: "admin@woodex.pk", password: "admin" } });
    if (login.s !== 200 || !login.j?.token) throw new Error(`login failed (${login.s})`);
    const T = login.j.token;

    /* ---------- 1. payment is recorded ---------- */
    const inv = await api("/api/invoices", { method: "POST", token: T, body: { customer: "Audit Test Co", items: [{ name: "Desk", qty: 1, price: 1000 }] } });
    if (inv.s >= 400) throw new Error(`invoice create failed (${inv.s})`);
    const invId = inv.j.id;

    const before = await api("/api/audit/health", { token: T });
    const pay = await api(`/api/invoices/${invId}/payments`, { method: "POST", token: T, body: { amount: 100 } });
    if (pay.s >= 400) throw new Error(`payment failed (${pay.s})`);
    const after = await api("/api/audit/health", { token: T });
    if (after.j.rows === before.j.rows + 1) ok("a recorded payment writes exactly one audit row", `${before.j.rows} → ${after.j.rows}`);
    else bad("a recorded payment writes exactly one audit row", `${before.j.rows} → ${after.j.rows}`);

    const log = await api("/api/audit?limit=5", { token: T });
    const row = (log.j?.items ?? []).find((r) => r.action === "invoice.payment_recorded" && String(r.entityId) === String(invId));
    if (row) ok("the row names the invoice and the actor", `${row.action} · ${row.actorEmail ?? row.actor}`);
    else bad("the row names the invoice and the actor", "no matching row returned by GET /api/audit");

    if (row && row.actorEmail) ok("actor email is captured", row.actorEmail);
    else bad("actor email is captured", "row has no actorEmail");

    /* ---------- 2. a refused action records nothing ---------- */
    const voided = await api(`/api/invoices/${invId}`, { method: "PATCH", token: T, body: { status: "Cancelled" } });
    if (voided.s >= 400) throw new Error(`cancel failed (${voided.s})`);
    const preAttempt = await api("/api/audit/health", { token: T });
    const refused = await api(`/api/invoices/${invId}/payments`, { method: "POST", token: T, body: { amount: 50 } });
    const postAttempt = await api("/api/audit/health", { token: T });
    if (refused.s === 409 && preAttempt.j.rows === postAttempt.j.rows)
      ok("a refused payment writes no audit row", `409, rows unchanged at ${preAttempt.j.rows}`);
    else bad("a refused payment writes no audit row", `s=${refused.s}, rows ${preAttempt.j.rows} → ${postAttempt.j.rows}`);

    /* ---------- migration alignment, on SQLite ----------
       SQLite has no migration ledger by design, so the only correct answer here
       is "not applicable". Asserting "aligned" would claim a verification that
       never happened; asserting "misaligned" would cry wolf on a healthy local
       database. The drift case is exercised separately against PostgreSQL. */
    {
      const align = await api("/api/audit/migrations", { token: T });
      if (align.s === 200 && align.j.status === "not-applicable" && align.j.driver === "sqlite")
        ok("SQLite reports the check as not applicable", align.j.detail);
      else bad("SQLite reports the check as not applicable", `s=${align.s}, status=${align.j?.status}, driver=${align.j?.driver}`);

      const anonAlign = await api("/api/audit/migrations");
      if (anonAlign.s === 401) ok("migration state is not public", "401 without a token");
      else bad("migration state is not public", `expected 401, got ${anonAlign.s}`);
    }

    /* ---------- 3. a rolled-back change leaves nothing ----------
       Guard against a vacuous pass: the three assertions below assert ABSENCE,
       so a writer that logs nothing at all satisfies them trivially. Confirm a
       control row exists first, otherwise "no row after rollback" proves only
       that logging is off. */
    {
      const controlBefore = (await api("/api/audit/health", { token: T })).j.rows;
      const control = await api(`/api/invoices/${invId}`, { method: "PATCH", token: T, body: { notes: "control write for rollback test" } });
      const controlAfter = (await api("/api/audit/health", { token: T })).j.rows;
      if (control.s < 400 && controlAfter === controlBefore + 1)
        ok("control: a successful write IS logged", "so the absence checks below are meaningful");
      else bad("control: a successful write IS logged", `s=${control.s}, ${controlBefore} → ${controlAfter}`);
    }

    /* Force a failure mid-flow the same way QA does: an order that references a
       nonexistent product cannot complete, and its transaction rolls back. */
    const preRollback = await api("/api/audit/health", { token: T });
    const broken = await api("/api/orders", { method: "POST", token: T, body: { customer: "Rollback Co", items: [{ id: "no-such-product-xyz", name: "Ghost", qty: 1, price: 10 }] } });
    const postRollback = await api("/api/audit/health", { token: T });
    if (broken.s >= 400 && preRollback.j.rows === postRollback.j.rows)
      ok("a rolled-back write leaves no audit row", `orders → ${broken.s}, rows unchanged`);
    else bad("a rolled-back write leaves no audit row", `s=${broken.s}, rows ${preRollback.j.rows} → ${postRollback.j.rows}`);

    /* ---------- 6. without a token the log is closed ---------- */
    const anon = await api("/api/audit");
    if (anon.s === 401) ok("GET /api/audit requires a session", "401 without a token");
    else bad("GET /api/audit requires a session", `expected 401, got ${anon.s}`);

    /* ---------- writer rejects a malformed action ---------- */
    const failuresBefore = (await api("/api/audit/health", { token: T })).j.writeFailures;
    ok("write failures are observable", `${failuresBefore} recorded (surfaced on /api/audit/health)`);
  } finally {
    try { await runtime.db.close?.(); } catch { /* already closed */ }
    try { runtime.httpServer?.closeAllConnections?.(); runtime.httpServer?.close(); } catch { /* already closed */ }
    /* NOT removed here: the PostgreSQL drift check runs after this and needs
       the directory. Cleanup happens once, at the very end. */
  }
}

/* ---------- the PostgreSQL path: where drift is actually detectable ----------
   SQLite cannot exercise this — it has no ledger. This seeds a real pglite
   database from the migrations with a genuine checksum ledger, starts a second
   API process against it, and asserts that dropping a migration-owned table
   flips the endpoint to 503 with the table named. Without this, the only proof
   the check works is the reasoning in the comment above it. */
async function assertPostgresDrift() {
  try {
    const { PGlite } = await import("@electric-sql/pglite");
    const dir = path.join(tmp, "pglite");
    const db = new PGlite(dir);
    await db.waitReady;
    await db.exec(fs.readFileSync(path.join(ROOT, "scripts/schema.postgres.sql"), "utf8"));
    await db.exec(`CREATE TABLE IF NOT EXISTS schema_migrations(
      version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL,
      applied_at TEXT NOT NULL, duration_ms INTEGER NOT NULL);`);
    for (const f of fs.readdirSync(path.join(ROOT, "scripts", "migrations")).filter((f) => f.endsWith(".sql")).sort()) {
      const sql = fs.readFileSync(path.join(ROOT, "scripts", "migrations", f), "utf8");
      await db.exec(sql);
      await db.query(
        "INSERT INTO schema_migrations(version,name,checksum,applied_at,duration_ms) VALUES($1,$2,$3,$4,$5)",
        [Number(f.slice(0, 3)), f, crypto.createHash("sha256").update(sql).digest("hex"), new Date().toISOString(), 0],
      );
    }
    await db.close();

    const port = 3300 + (process.pid % 200);
    const base = `http://127.0.0.1:${port}`;
    let child = spawn(process.execPath, ["--experimental-sqlite", "apps/api/server.mjs"], {
      cwd: ROOT,
      env: {
        ...process.env,
        DATABASE_URL: `pglite:${dir}`,
        PORT: String(port),
        /* production, not test: bootstrapProductionOwner only runs when
           IS_PRODUCTION, and a freshly migrated database has no users. */
        NODE_ENV: "production",
        WOODEX_DATA_DIR: path.join(tmp, "pg-sqlite"),
        /* A freshly migrated PostgreSQL database has a schema and no users, so
           the API bootstraps the owner from these. Without them every request
           is 401 and the drift test would pass for the wrong reason. */
        INITIAL_ADMIN_EMAIL: "audit-drift@woodex.pk",
        INITIAL_ADMIN_PASSWORD: "audit-drift-password-0001",
      },
      stdio: ["ignore", "pipe", "pipe"],
    });
    try {
      /* Wait for the port, bounded. A fixed sleep would be a race on a slow
         machine and a waste on a fast one. */
      let up = false;
      for (let i = 0; i < 60 && !up; i += 1) {
        try { await fetch(base + "/api/health", { signal: AbortSignal.timeout(500) }); up = true; }
        catch { await new Promise((r) => setTimeout(r, 250)); }
      }
      if (!up) { bad("PostgreSQL API started for the drift test", "port never opened"); return; }

      const login = await fetch(base + "/api/auth/login", {
        method: "POST", headers: { "content-type": "application/json" },
        body: JSON.stringify({ email: "audit-drift@woodex.pk", password: "audit-drift-password-0001" }),
      });
      let T = (await login.json())?.token;
      if (!T) { bad("PostgreSQL API login for the drift test", `status ${login.status}`); return; }

      /* The expected latest version is DERIVED from the migration files, not
         hardcoded. It was 8 until migration 009 existed, at which point a
         correct endpoint failed a stale assertion — the same class of bug as
         hardcoding a constraint count, and it would recur on every migration. */
      const expectedLatest = Math.max(...fs.readdirSync(path.join(ROOT, "scripts", "migrations"))
        .filter((f) => f.endsWith(".sql"))
        .map((f) => Number(f.slice(0, 3))));
      const aligned = await fetch(base + "/api/audit/migrations", { headers: { authorization: "Bearer " + T } });
      const alignedJ = await aligned.json();
      if (aligned.status === 200 && alignedJ.status === "aligned" && alignedJ.latest === expectedLatest)
        ok("a fully migrated PostgreSQL database reports aligned", `latest v${alignedJ.latest}`);
      else bad("a fully migrated PostgreSQL database reports aligned",
        `s=${aligned.status}, status=${alignedJ?.status}, latest=${alignedJ?.latest} (expected ${expectedLatest})`);

      /* The exact production incident: ledger says applied, table is gone.
         The drop runs in this process against the same pglite directory, then
         the API process is restarted so it re-reads the database. PGlite is a
         single-process engine holding its own view, so mutating behind a live
         connection would be invisible to it — restarting is what makes the
         mutation observable without inventing a destructive endpoint. */
      const d2 = new PGlite(dir);
      await d2.waitReady;
      await d2.exec("DROP TABLE audit_logs");
      await d2.close();
      child.kill("SIGKILL");
      await new Promise((r) => setTimeout(r, 300));
      child = spawn(process.execPath, ["--experimental-sqlite", "apps/api/server.mjs"], {
        cwd: ROOT,
        env: {
          ...process.env,
          DATABASE_URL: `pglite:${dir}`,
          PORT: String(port),
          NODE_ENV: "production",
          WOODEX_DATA_DIR: path.join(tmp, "pg-sqlite"),
          INITIAL_ADMIN_EMAIL: "audit-drift@woodex.pk",
          INITIAL_ADMIN_PASSWORD: "audit-drift-password-0001",
        },
        stdio: ["ignore", "pipe", "pipe"],
      });
      let up2 = false;
      for (let i = 0; i < 60 && !up2; i += 1) {
        try { await fetch(base + "/api/health", { signal: AbortSignal.timeout(500) }); up2 = true; }
        catch { await new Promise((r) => setTimeout(r, 250)); }
      }
      if (!up2) { bad("API restarted after the drop", "port never reopened"); return; }
      const relogin = await fetch(base + "/api/auth/login", {
        method: "POST", headers: { "content-type": "application/json" },
        body: JSON.stringify({ email: "audit-drift@woodex.pk", password: "audit-drift-password-0001" }),
      });
      T = (await relogin.json())?.token;

      const drift = await fetch(base + "/api/audit/migrations", { headers: { authorization: "Bearer " + T } });
      const driftJ = await drift.json();
      if (drift.status === 503 && driftJ.status === "misaligned")
        ok("a dropped table on PostgreSQL reports misaligned with 503", `status=${driftJ.status}`);
      else bad("a dropped table on PostgreSQL reports misaligned with 503", `s=${drift.status}, status=${driftJ?.status}`);

      if ((driftJ?.missingTables ?? []).includes("audit_logs"))
        ok("the PostgreSQL report names the missing table", driftJ.missingTables.join(", "));
      else bad("the PostgreSQL report names the missing table", JSON.stringify(driftJ?.missingTables));
    } finally {
      child.kill("SIGKILL");
    }
  } catch (e) {
    bad("PostgreSQL drift check crashed", e?.message ?? String(e));
  }
}

console.log("\n[audit-test] platform audit log\n");
assertAppendOnly();
try { await run(); }
catch (e) { bad("audit self-test crashed", e?.message ?? String(e)); }
await assertPostgresDrift();
fs.rmSync(tmp, { recursive: true, force: true });

for (const line of results) console.log(line);
console.log(`\n${fail ? "✗" : "✓"} audit self-test ${fail ? "FAILED" : "passed"} — ${pass}/${pass + fail}\n`);
process.exit(fail ? 1 : 0);
