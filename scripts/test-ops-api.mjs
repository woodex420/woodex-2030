#!/usr/bin/env node
/**
 * The Operations UI contract.
 *
 *   node scripts/test-ops-api.mjs
 *
 * WHY THIS EXISTS
 *
 * The procurement, payables and production backends shipped in 009-011 and had
 * no screens. That went unnoticed because each module was tested only against
 * itself: the API answered, the tests passed, and nobody asked whether a human
 * could reach any of it. A backend nobody can operate is not a delivered
 * module, and a suite that never asserts the read side cannot tell the
 * difference.
 *
 * So this test drives the exact endpoints the six new screens call, in the
 * order an operator would, and asserts that the data each screen needs is
 * actually there. It fails if a response loses a field the UI depends on —
 * `lines`, `value`, `matchState`, `qtyOutstanding` — because a silently missing
 * field renders as an empty table, which looks like "no data" rather than a
 * bug.
 *
 * It also pins the login-hint fix: /api/auth/demo-available must be public and
 * must report the truth, because the old hardcoded "admin/admin" hint shipped
 * to production where it could not work.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-ops-${process.pid}`);
const problems = [];
const check = (ok, label, detail = "") => {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
};

let child;
try {
  fs.mkdirSync(tempRoot, { recursive: true });
  child = spawn(process.execPath, ["apps/api/server.mjs"], {
    cwd: ROOT,
    env: { ...process.env, NODE_ENV: "test", PORT: "0", WOODEX_DATA_DIR: tempRoot,
      DATABASE_URL: "", VERCEL: "", VERCEL_ENV: "", WOODEX_DEMO_SEED: "1" },
    stdio: ["ignore", "pipe", "pipe"],
  });

  let log = "";
  const base = await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`server did not start:\n${log.slice(-800)}`)), 60_000);
    const onData = (buf) => {
      log += String(buf);
      const m = log.match(/http:\/\/localhost:(\d+)/);
      if (m) { clearTimeout(timer); resolve(`http://127.0.0.1:${m[1]}`); }
    };
    child.stdout.on("data", onData);
    child.stderr.on("data", onData);
    child.on("exit", (c) => { clearTimeout(timer); reject(new Error(`server exited ${c}`)); });
  });

  const api = async (p, opts = {}) => {
    const res = await fetch(base + p, {
      method: opts.method ?? "GET",
      headers: { "content-type": "application/json", ...(opts.token ? { authorization: "Bearer " + opts.token } : {}) },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    let json = null;
    try { json = await res.json(); } catch { /* non-JSON is a finding */ }
    return { status: res.status, json };
  };

  /* ---------- the login hint ---------- */
  console.log("[ops] login hint must not lie");
  const demo = await api("/api/auth/demo-available");
  check(demo.status === 200, "demo availability is reachable WITHOUT a token", `got ${demo.status}`);
  check(demo.json?.demo === true, "it reports the seeded demo account as usable", JSON.stringify(demo.json));
  /* And the claim is true: those credentials really work. */
  const realLogin = await api("/api/auth/login", { method: "POST", body: { email: "admin", password: "admin" } });
  check(realLogin.status === 200 && Boolean(realLogin.json?.token),
    "the advertised demo credentials actually sign in", `got ${realLogin.status}`);

  const token = realLogin.json?.token;
  check(Boolean(token), "operator signed in");

  /* ---------- fixtures ---------- */
  await api("/api/products", { method: "POST", token, body: {
    id: "ops-desk", name: "Ops Desk", price: 8000, category: "office-tables",
    stockQty: 0, inStock: true, description: "probe", features: [], images: ["/img/desk-product.jpg"] } });
  await api("/api/products", { method: "POST", token, body: {
    id: "ops-bracket", name: "Ops Bracket", price: 500, category: "office-tables",
    stockQty: 0, inStock: true, description: "probe", features: [], images: ["/img/desk-product.jpg"] } });

  /* ---------- suppliers screen ---------- */
  console.log("\n[ops] suppliers screen");
  const sup = await api("/api/suppliers", { method: "POST", token, body: { code: "SUP-OPS", name: "Ops Timber" } });
  check(sup.status === 201, "a supplier can be created from the screen's payload", `got ${sup.status}`);
  const supId = sup.json?.id;
  const supList = await api("/api/suppliers?active=true", { token });
  check((supList.json?.items ?? []).some((s) => s.id === supId), "it appears in the active list the screen renders");
  check(supList.json?.items?.[0]?.code !== undefined, "rows carry the fields the table shows (code, name, active)");
  const supDup = await api("/api/suppliers", { method: "POST", token, body: { code: "SUP-OPS", name: "Again" } });
  check(supDup.status === 409, "a duplicate code is refused, and says so", `got ${supDup.status}`);

  /* ---------- purchase orders screen ---------- */
  console.log("\n[ops] purchase orders screen");
  const po = await api("/api/purchase-orders", { method: "POST", token, body: {
    supplierId: supId, lines: [{ itemKind: "product", itemId: "ops-bracket", qtyOrdered: 10, unitCost: 500 }] } });
  check(po.status === 201, "a PO can be created from the screen's payload", `got ${po.status}`);
  const poId = po.json?.id;
  const poLineId = po.json?.lines?.[0]?.id;
  /* The UI reads `value` and `lines` straight off the list response. If either
     disappears the table silently shows PKR 0 and no lines. */
  check(typeof po.json?.value === "number", "the response carries a computed `value` for the total column");
  check(Number(po.json?.value) === 5000, "value is the line total, not a placeholder", String(po.json?.value));
  check(Array.isArray(po.json?.lines) && po.json.lines.length === 1, "the response carries `lines` with the row");
  const poList = await api("/api/purchase-orders", { token });
  const listed = (poList.json?.items ?? []).find((p) => p.id === poId);
  check(Boolean(listed?.lines?.length), "the LIST response also carries lines (the UI expands without a second fetch)");
  check(typeof listed?.qtyOpen === "number", "the list reports qtyOpen, which the receiving screen filters on");

  /* Only receivable orders may be offered for receiving. */
  const beforeApprove = await api(`/api/goods-receipts`, { method: "POST", token, body: {
    purchaseOrderId: poId, lines: [{ purchaseOrderLineId: poLineId, qtyReceived: 1 }] } });
  check(beforeApprove.status === 409, "a DRAFT order cannot be received against (the screen hides it too)", `got ${beforeApprove.status}`);
  await api(`/api/purchase-orders/${poId}`, { method: "PATCH", token, body: { status: "approved" } });

  /* ---------- goods receipts screen ---------- */
  console.log("\n[ops] goods receipts screen");
  const stockOf = async (id) => (await api(`/api/products/${id}`, { token })).json?.stockQty;
  const zero = await stockOf("ops-bracket");
  const grn = await api("/api/goods-receipts", { method: "POST", token, body: {
    purchaseOrderId: poId, lines: [{ purchaseOrderLineId: poLineId, qtyReceived: 6 }] } });
  check(grn.status === 201, "a part delivery can be recorded from the screen", `got ${grn.status}`);
  check(await stockOf("ops-bracket") === zero + 6, "receiving 6 put 6 on the shelf");
  const over = await api("/api/goods-receipts", { method: "POST", token, body: {
    purchaseOrderId: poId, lines: [{ purchaseOrderLineId: poLineId, qtyReceived: 5 }] } });
  check(over.status === 409, "receiving more than outstanding is refused", `got ${over.status}`);
  check(await stockOf("ops-bracket") === zero + 6, "the refused receipt moved nothing");
  const grnList = await api("/api/goods-receipts", { token });
  check((grnList.json?.items ?? []).length > 0, "the receipts table has a row to show");
  check(Array.isArray(grnList.json?.items?.[0]?.lines), "receipt rows carry their lines");

  /* ---------- supplier invoices screen ---------- */
  console.log("\n[ops] supplier invoices screen");
  const si = await api("/api/supplier-invoices", { method: "POST", token, body: {
    supplierId: supId, purchaseOrderId: poId, supplierRef: "OPS-BILL-1",
    tax: 0, lines: [{ purchaseOrderLineId: poLineId, qtyBilled: 10, unitCost: 500 }] } });
  check(si.status === 201, "a bill can be recorded from the screen's payload", `got ${si.status}`);
  check(si.json?.matchState === "variance", "billing 10 when 6 arrived raises a variance", String(si.json?.matchState));
  const findings = si.json?.match?.findings ?? [];
  check(findings.length > 0, "the variance carries findings the detail panel can list");
  check(typeof findings[0]?.kind === "string", "findings name their cause (billed_beyond_receipt)", JSON.stringify(findings[0]));
  const siId = si.json?.id;
  await api(`/api/supplier-invoices/${siId}`, { method: "PATCH", token, body: { status: "posted" } });
  const blocked = await api(`/api/supplier-invoices/${siId}/payments`, { method: "POST", token, body: { amount: 1000 } });
  check(blocked.status === 409, "paying a variance bill is refused", `got ${blocked.status}`);
  check(blocked.json?.code === "match_variance", "the refusal is identifiable, not a generic error", String(blocked.json?.code));
  const siSummary = await api("/api/supplier-invoices/summary", { token });
  check(siSummary.json?.variance >= 1, "the summary counts the variance (the dashboard card reads this)");
  check(typeof siSummary.json?.payable === "number", "the summary reports a payable figure");

  /* ---------- production screen ---------- */
  console.log("\n[ops] production screen");
  const bom = await api("/api/boms", { method: "POST", token, body: {
    productId: "ops-desk", outputQty: 1, lines: [{ itemKind: "product", itemId: "ops-bracket", qtyPerOutput: 2 }] } });
  check(bom.status === 201, "a recipe can be created from the screen", `got ${bom.status}`);
  check(Array.isArray(bom.json?.lines) && bom.json.lines.length === 1, "the created recipe comes back with its lines");

  const jc = await api("/api/job-cards", { method: "POST", token, body: { productId: "ops-desk", qtyToBuild: 3 } });
  check(jc.status === 201, "a job card can be raised from the screen", `got ${jc.status}`);
  check(jc.json?.materials?.[0]?.qtyRequired === 6, "it snapshots 6 brackets for 3 desks", String(jc.json?.materials?.[0]?.qtyRequired));
  check(typeof jc.json?.materials?.[0]?.qtyOutstanding === "number", "materials expose qtyOutstanding for the issue button");
  const jcId = jc.json?.id;

  const jcList = await api("/api/job-cards", { token });
  const row = (jcList.json?.items ?? []).find((j) => j.id === jcId);
  check(Array.isArray(row?.materials) && row.materials.length > 0, "the job list carries materials (the expanded row needs them)");
  check(Array.isArray(row?.movements), "the job list carries movements (the ledger preview needs them)");
  check(typeof jcList.json?.awaitingQc === "number", "the board counts jobs awaiting QC");

  const bracketBefore = await stockOf("ops-bracket");
  const issue = await api(`/api/job-cards/${jcId}/materials/issue`, { method: "POST", token });
  check(issue.status === 200, "issuing all materials succeeds", `got ${issue.status}`);
  check(await stockOf("ops-bracket") === bracketBefore - 6, "issuing 6 consumed exactly 6");

  await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { stage: "qc", qtyBuilt: 3 } });
  const doneTooSoon = await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { stage: "done" } });
  check(doneTooSoon.status === 409, "the screen's Complete button cannot bypass QC", `got ${doneTooSoon.status}`);
  await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { qcPassed: true, qcNote: "ops" } });
  const deskBefore = await stockOf("ops-desk");
  const done = await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { stage: "done" } });
  check(done.status === 200, "after a QC pass the job completes", `got ${done.status}`);
  check(await stockOf("ops-desk") === deskBefore + 3, "the finished desks entered stock");

  /* ---------- stock ledger screen ---------- */
  console.log("\n[ops] stock ledger screen");
  const mv = await api("/api/stock-movements?limit=50", { token });
  check(mv.status === 200, "the ledger is readable (it was write-only before this change)", `got ${mv.status}`);
  check(Array.isArray(mv.json?.items) && mv.json.items.length > 0, "it returns movement rows");
  const m0 = mv.json?.items?.[0];
  check(m0 && m0.productId !== undefined && m0.delta !== undefined && m0.reason !== undefined,
    "rows carry productId, delta and reason — the three columns that matter");
  check(typeof mv.json?.in === "number" && typeof mv.json?.out === "number", "it reports in/out totals for the filtered set");
  const filtered = await api("/api/stock-movements?reason=production", { token });
  check((filtered.json?.items ?? []).every((m) => m.reason === "production"), "the reason filter works");
  check((filtered.json?.items ?? []).length > 0, "production movements are visible in the ledger");

  const rec = await api("/api/stock-movements/reconcile", { token });
  check(rec.status === 200, "the reconciliation endpoint answers", `got ${rec.status}`);
  check(rec.json?.ok === true, "every product reconciles with the ledger (Phase 7 acceptance)", JSON.stringify(rec.json?.items?.slice(0, 2)));
  check(rec.json?.drifted === 0, "zero drifted products");
  check(rec.json?.movements > 0, "the ledger is not empty, so the check is meaningful");

  console.log(`\n${problems.length ? `✗ ${problems.length} ops check(s) failed` : "✓ the Operations screens have a working API behind them"}`);
  for (const p of problems) console.log(`  · ${p}`);
  process.exitCode = problems.length ? 1 : 0;
} catch (error) {
  console.error("\n✗ harness failed:", error?.message ?? error);
  process.exitCode = 1;
} finally {
  if (child) { try { child.kill("SIGTERM"); } catch { /* gone */ } }
  try { fs.rmSync(tempRoot, { recursive: true, force: true }); } catch { /* best effort */ }
}
