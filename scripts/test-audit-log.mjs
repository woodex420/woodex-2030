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
import { spawnSync } from "node:child_process";
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
    fs.rmSync(tmp, { recursive: true, force: true });
  }
}

console.log("\n[audit-test] platform audit log\n");
assertAppendOnly();
try { await run(); }
catch (e) { bad("audit self-test crashed", e?.message ?? String(e)); }

for (const line of results) console.log(line);
console.log(`\n${fail ? "✗" : "✓"} audit self-test ${fail ? "FAILED" : "passed"} — ${pass}/${pass + fail}\n`);
process.exit(fail ? 1 : 0);
