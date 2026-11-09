#!/usr/bin/env node
/**
 * Supplier invoices and three-way matching.
 *
 *   node scripts/test-supplier-invoices.mjs
 *
 * The three-way match compares, per PO line: ordered (PO) vs received (GRN) vs
 * billed (this invoice). The assertions that matter:
 *
 *   1. BILLING FOR GOODS THAT NEVER ARRIVED IS A VARIANCE. That is the whole
 *      purpose. An invoice that agrees with the order but not the receipt is
 *      how a supplier gets paid for stock that is still on their lorry.
 *
 *   2. A VARIANCE BLOCKS PAYMENT BUT NOT RECORDING. The bill is a fact; it gets
 *      logged and flagged. Payment needs an explicit override, and the override
 *      is audited — a silent one would defeat the module.
 *
 *   3. A BILL CANNOT BE OVERPAID. The receivables side has no ceiling
 *      (paid = paid + amount); copying that into money going OUT would let the
 *      same bill be paid twice.
 *
 *   4. THE SAME SUPPLIER DOCUMENT CANNOT BE ENTERED TWICE. The commonest AP
 *      error, and the simplest to prevent: their own reference number, unique
 *      per supplier.
 *
 *   5. CANCELLING A DUPLICATE HEALS ITS SIBLINGS. A cancelled invoice stops
 *      counting against the order, so the surviving one must stop looking
 *      over-invoiced — otherwise the variance is permanent and meaningless.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-bills-${process.pid}`);
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
    const timer = setTimeout(() => reject(new Error(`server did not start in 60s:\n${log.slice(-900)}`)), 60_000);
    const onData = (buf) => {
      log += String(buf);
      const m = log.match(/http:\/\/localhost:(\d+)/);
      if (m) { clearTimeout(timer); resolve(`http://127.0.0.1:${m[1]}`); }
    };
    child.stdout.on("data", onData);
    child.stderr.on("data", onData);
    child.on("exit", (code) => { clearTimeout(timer); reject(new Error(`server exited ${code}:\n${log.slice(-900)}`)); });
  });

  const api = async (p, opts = {}) => {
    const res = await fetch(base + p, {
      method: opts.method ?? "GET",
      headers: { "content-type": "application/json", ...(opts.token ? { authorization: "Bearer " + opts.token } : {}) },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    let json = null;
    try { json = await res.json(); } catch { /* non-JSON is itself a finding */ }
    return { status: res.status, json, headers: res.headers };
  };

  const login = await api("/api/auth/login", { method: "POST", body: { email: "admin@woodex.pk", password: "admin" } });
  const token = login.json?.token;
  check(Boolean(token), "operator signed in", login.json?.error ?? "");

  /* ---------- fixture: supplier, product, PO for 10, GRN for 4 ---------- */
  const mkProduct = await api("/api/products", { method: "POST", token, body: {
    id: "bill-desk", name: "Bill Desk", price: 2000, category: "office-tables",
    stockQty: 0, inStock: true, description: "probe", features: [], images: ["/img/desk-product.jpg"] } });
  check(mkProduct.status === 201, "probe product created");

  const supp = await api("/api/suppliers", { method: "POST", token, body: { code: "SUP-BILL", name: "Bill Timber Co" } });
  const supplierId = supp.json?.id;
  check(Boolean(supplierId), "supplier created");

  const po = await api("/api/purchase-orders", { method: "POST", token, body: {
    supplierId, lines: [{ itemKind: "product", itemId: "bill-desk", qtyOrdered: 10, unitCost: 1000 }] } });
  check(po.status === 201, "PO raised for 10 units at 1000", `got ${po.status}`);
  const poId = po.json?.id;
  const poLineId = po.json?.lines?.[0]?.id;
  await api(`/api/purchase-orders/${poId}`, { method: "PATCH", token, body: { status: "approved" } });

  /* Only 4 of the 10 arrive. */
  const grn = await api("/api/goods-receipts", { method: "POST", token, body: {
    purchaseOrderId: poId, lines: [{ purchaseOrderLineId: poLineId, qtyReceived: 4 }] } });
  check(grn.status === 201, "only 4 of 10 units received", `got ${grn.status}`);
  const grnId = grn.json?.id;

  console.log("\n[bills] recording a supplier invoice");
  const noRef = await api("/api/supplier-invoices", { method: "POST", token, body: {
    supplierId, lines: [{ purchaseOrderLineId: poLineId, qtyBilled: 4, unitCost: 1000 }] } });
  check(noRef.status === 400, "the supplier's own reference is required", `got ${noRef.status}`);

  /* The honest bill: 4 units, matching what arrived. */
  const good = await api("/api/supplier-invoices", { method: "POST", token, body: {
    supplierId, purchaseOrderId: poId, goodsReceiptId: grnId, supplierRef: "INV-7781",
    invoiceDate: "2026-11-09", lines: [{ purchaseOrderLineId: poLineId, qtyBilled: 4, unitCost: 1000 }] } });
  check(good.status === 201, "a bill matching the receipt is accepted", `got ${good.status} ${JSON.stringify(good.json)?.slice(0, 160)}`);
  check(good.json?.matchState === "matched", "and matches: billed 4 = received 4 ≤ ordered 10", good.json?.matchState);
  check(good.json?.total === 4000, "total is derived from the lines (4 × 1000)", String(good.json?.total));
  check(good.json?.status === "draft", "a new bill is a draft, not yet a payable", good.json?.status);
  const goodId = good.json?.id;

  console.log("\n[bills] the duplicate guard");
  const dupe = await api("/api/supplier-invoices", { method: "POST", token, body: {
    supplierId, supplierRef: "INV-7781", lines: [{ purchaseOrderLineId: poLineId, qtyBilled: 4, unitCost: 1000 }] } });
  check(dupe.status === 409, "the same supplier document cannot be entered twice", `got ${dupe.status}`);
  check(dupe.json?.code === "duplicate_supplier_invoice", "the refusal is coded, not a bare 409", dupe.json?.code);
  check(/already recorded as BILL-/.test(dupe.json?.error ?? ""), "and names the invoice that already holds it", dupe.json?.error);

  /* A different supplier may legitimately use the same number. */
  const supp2 = await api("/api/suppliers", { method: "POST", token, body: { code: "SUP-OTHER", name: "Other Co" } });
  const otherSame = await api("/api/supplier-invoices", { method: "POST", token, body: {
    supplierId: supp2.json?.id, supplierRef: "INV-7781", lines: [{ qtyBilled: 1, unitCost: 50, description: "sundries" }] } });
  check(otherSame.status === 201, "another supplier may use the same document number", `got ${otherSame.status}`);
  check(otherSame.json?.matchState === "unlinked", "a bill with no PO is 'unlinked', not falsely 'matched'", otherSame.json?.matchState);

  console.log("\n[bills] the whole point: billed beyond received");
  const overBilled = await api("/api/supplier-invoices", { method: "POST", token, body: {
    supplierId, purchaseOrderId: poId, supplierRef: "INV-7782",
    lines: [{ purchaseOrderLineId: poLineId, qtyBilled: 10, unitCost: 1000 }] } });
  check(overBilled.status === 201, "a bill for goods that never arrived is still RECORDED", `got ${overBilled.status}`);
  check(overBilled.json?.matchState === "variance", "but it is flagged as a variance", overBilled.json?.matchState);
  check(overBilled.headers.get("x-match-state") === "variance", "and the response header says so too", String(overBilled.headers.get("x-match-state")));
  const findings = overBilled.json?.match?.findings ?? [];
  check(findings.some((f) => f.kind === "billed_beyond_receipt"), "the finding names billed-beyond-receipt", JSON.stringify(findings.slice(0, 2)));
  /* `billed` is CUMULATIVE across live invoices on the order: 10 on this one
     plus 4 already billed by the earlier matched bill = 14 against 4 received.
     Asserting 10 here would be asserting the wrong number — the whole point of
     the guard is that it counts what has been billed, not what this one
     document happens to say. */
  check(findings.some((f) => f.billed === 14 && f.received === 4), "with the cumulative numbers (billed 14 across 2 bills, received 4)", JSON.stringify(findings.slice(0, 2)));
  const overId = overBilled.json?.id;

  console.log("\n[bills] paperwork is checked before the bank moves");
  const payDraft = await api(`/api/supplier-invoices/${overId}/payments`, { method: "POST", token, body: { amount: 100 } });
  check(payDraft.status === 409, "a draft cannot be paid — post it first", `got ${payDraft.status}`);
  check(/post the bill before paying/.test(payDraft.json?.error ?? ""), "and says so", payDraft.json?.error);

  const posted = await api(`/api/supplier-invoices/${overId}`, { method: "PATCH", token, body: { status: "posted" } });
  check(posted.status === 200, "the bill posts", `got ${posted.status}`);
  check(posted.json?.matchState === "variance", "posting does not launder the variance", posted.json?.matchState);

  const blocked = await api(`/api/supplier-invoices/${overId}/payments`, { method: "POST", token, body: { amount: 500 } });
  check(blocked.status === 409, "paying a variance bill is REFUSED without an override", `got ${blocked.status}`);
  check(blocked.json?.code === "match_variance", "the refusal is coded match_variance", blocked.json?.code);
  check((blocked.json?.findings ?? []).length > 0, "and carries the findings so the payer can see why", String((blocked.json?.findings ?? []).length));

  const overpay = await api(`/api/supplier-invoices/${overId}/payments`, { method: "POST", token, body: { amount: 99999, overrideVariance: true } });
  check(overpay.status === 409, "even with an override, a bill cannot be overpaid", `got ${overpay.status}`);
  check(/exceeds the .* outstanding/.test(overpay.json?.error ?? ""), "the refusal states the outstanding amount", overpay.json?.error);

  const paid = await api(`/api/supplier-invoices/${overId}/payments`, { method: "POST", token, body: { amount: 10000, overrideVariance: true } });
  check(paid.status === 200, "an explicit override pays it", `got ${paid.status}`);
  check(paid.json?.paid === 10000, "the bill records the full amount paid", String(paid.json?.paid));
  check(paid.json?.status === "paid", "and settles", paid.json?.status);
  check(paid.json?.balance === 0, "with nothing outstanding", String(paid.json?.balance));

  const again = await api(`/api/supplier-invoices/${overId}/payments`, { method: "POST", token, body: { amount: 1, overrideVariance: true } });
  check(again.status === 409, "a settled bill cannot be paid again", `got ${again.status}`);

  console.log("\n[bills] cancelling a duplicate heals its siblings");
  /* Isolated on its own order: 6 ordered, 6 received, two bills of 6 each.
     Together they over-bill (12 vs 6); cancel one and the other is correct. */
  const po2 = await api("/api/purchase-orders", { method: "POST", token, body: {
    supplierId, lines: [{ itemKind: "product", itemId: "bill-desk", qtyOrdered: 6, unitCost: 1000 }] } });
  const po2Id = po2.json?.id;
  const po2Line = po2.json?.lines?.[0]?.id;
  await api(`/api/purchase-orders/${po2Id}`, { method: "PATCH", token, body: { status: "approved" } });
  await api("/api/goods-receipts", { method: "POST", token, body: {
    purchaseOrderId: po2Id, lines: [{ purchaseOrderLineId: po2Line, qtyReceived: 6 }] } });

  const billA = await api("/api/supplier-invoices", { method: "POST", token, body: {
    supplierId, purchaseOrderId: po2Id, supplierRef: "DUP-1",
    lines: [{ purchaseOrderLineId: po2Line, qtyBilled: 6, unitCost: 1000 }] } });
  check(billA.json?.matchState === "matched", "the first bill matches (6 of 6)", billA.json?.matchState);

  const billB = await api("/api/supplier-invoices", { method: "POST", token, body: {
    supplierId, purchaseOrderId: po2Id, supplierRef: "DUP-2",
    lines: [{ purchaseOrderLineId: po2Line, qtyBilled: 6, unitCost: 1000 }] } });
  check(billB.status === 201, "a duplicate entry under a different number is recorded", `got ${billB.status}`);
  check(billB.json?.matchState === "variance", "but together they over-bill (12 vs 6 ordered)", billB.json?.matchState);
  const billBId = billB.json?.id;

  const cancelA = await api(`/api/supplier-invoices/${billA.json?.id}`, { method: "PATCH", token, body: { status: "cancelled" } });
  check(cancelA.status === 200, "the duplicate is cancelled", `got ${cancelA.status}`);
  const bAfter = await api(`/api/supplier-invoices/${billBId}`, { token });
  check(bAfter.json?.matchState === "matched", "its sibling is re-matched and no longer over-billed (6 vs 6)", bAfter.json?.matchState);

  const cancelPaid = await api(`/api/supplier-invoices/${overId}`, { method: "PATCH", token, body: { status: "cancelled" } });
  check(cancelPaid.status === 409, "a paid bill cannot be cancelled", `got ${cancelPaid.status}`);

  console.log("\n[bills] the payables summary");
  const summary = await api("/api/supplier-invoices/summary", { token });
  check(summary.status === 200, "summary is available", `got ${summary.status}`);
  check(typeof summary.json?.payable === "number", "it reports total payable", JSON.stringify(summary.json));
  const list = await api("/api/supplier-invoices", { token });
  check(list.json?.payable === summary.json?.payable, "the list and the summary agree on payable",
    `${list.json?.payable} vs ${summary.json?.payable}`);

  console.log("\n[bills] every financial move is audited");
  const audit = await api("/api/audit?limit=100", { token });
  const actions = (audit.json?.items ?? []).map((i) => i.action);
  check(actions.includes("supplier_invoice.recorded"), "recording a bill is audited");
  check(actions.includes("supplier_invoice.payment_recorded"), "paying one is audited");
  check(actions.includes("supplier_invoice.cancelled"), "cancelling one is audited");
  const health = await api("/api/audit/health", { token });
  check(health.json?.writeFailures === 0, "no audit write was rejected by the action validator", String(health.json?.writeFailures));

  console.log("\n[bills] constants that must not drift");
  {
    const src = fs.readFileSync(path.join(ROOT, "apps/api/server.mjs"), "utf8");
    check(/si_paid_within_total/.test(fs.readFileSync(path.join(ROOT, "scripts/migrations/010_supplier_invoices.sql"), "utf8")),
      "the overpayment ceiling exists in the schema as well as the route");
    check(/uq_supplier_invoices_supplier_ref/.test(src), "the duplicate guard is an index on the SQLite side too");
    /* The tolerance must be read, not hard-coded. */
    check(/procurement\.match_tolerance/.test(src), "the match tolerance is configuration, not a constant");
  }
} catch (error) {
  problems.push(`rehearsal crashed: ${error.message}`);
  console.error(`\n✗ ${error.stack ?? error.message}`);
} finally {
  child?.kill("SIGKILL");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n✗ ${problems.length} supplier-invoice problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  process.exit(1);
}
console.log("\n✓ supplier invoices behave: variances are found and block payment, duplicates are refused, money cannot leave twice.\n");
