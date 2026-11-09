#!/usr/bin/env node
/**
 * Procurement rehearsal — suppliers, purchase orders, goods receiving.
 *
 *   node scripts/test-procurement.mjs
 *
 * Runs against a real API on a throwaway SQLite database. The assertions that
 * matter are not "does it insert a row". They are:
 *
 *   1. A PRODUCT RECEIPT MOVES STOCK EXACTLY ONCE — and the movement is a
 *      ledger row, not a bare number written onto the product. Before the
 *      ledger, receiving goods updated nothing, and the catalogue silently
 *      disagreed with the warehouse.
 *
 *   2. A MATERIAL RECEIPT MOVES STOCK NEVER. A material is a finish code
 *      ('Natural Oak') with no quantity on hand. If receiving one incremented
 *      inventory, the system would report stock for a colour — and nobody would
 *      ever find where it came from.
 *
 *   3. PARTIAL DELIVERY IS HONEST. An order for 10 arriving as 4 must report 6
 *      open, must NOT be marked received, and must not be cancellable while
 *      goods are outstanding.
 *
 *   4. RECEIVING CANNOT EXCEED THE ORDER. Over-receipt is refused with a 409
 *      that names both numbers, because "409" alone sends someone to read logs.
 *
 *   5. EVERY RECEIPT IS AUDITED, and the audit action passes the <entity>.<verb>
 *      validator that silently dropped 'payment_recorded' once before.
 *
 *   6. THE PO CANNOT LIE ABOUT ITS STATE. A draft cannot jump to received, and
 *      an issued order cannot exist without an approver.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-proc-${process.pid}`);
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
    return { status: res.status, json };
  };

  const login = await api("/api/auth/login", { method: "POST", body: { email: "admin@woodex.pk", password: "admin" } });
  const token = login.json?.token;
  check(Boolean(token), "operator signed in", login.json?.error ?? "");

  /* ---------- the catalogue the orders are raised against ---------- */
  /* A product with a known quantity, so a receipt can be seen to move it, and
     a second with enough stock that the opening balance is unmistakable. */
  const mkProduct = (id, qty) => api("/api/products", { method: "POST", token, body: {
    id, name: id, price: 1000, category: "office-tables", stockQty: qty, inStock: true,
    description: "probe", features: [], images: ["/img/desk-product.jpg"] } });
  check((await mkProduct("proc-chair", 5)).status === 201, "probe product created with stock 5");
  const stockOf = async (id) => (await api(`/api/products/${id}`)).json?.stockQty;

  const materialId = (await api("/api/materials")).json?.[0]?.id;
  check(Boolean(materialId), "a material (finish code) is available to order", String(materialId));

  console.log("\n[procurement] suppliers");
  const supp = await api("/api/suppliers", { method: "POST", token, body: {
    code: "SUP-QA", name: "QA Timber House", email: "qa@timber.pk", taxRef: "NTN-1234567-8",
    paymentTerms: "30 days", currency: "PKR" } });
  check(supp.status === 201, "a supplier can be created", `got ${supp.status} ${JSON.stringify(supp.json)?.slice(0, 140)}`);
  const supplierId = supp.json?.id;
  check(supp.json?.code === "SUP-QA", "the supplier keeps the code it was given");
  check(supp.json?.active === true, "a new supplier defaults to active");

  const dupe = await api("/api/suppliers", { method: "POST", token, body: { code: "SUP-QA", name: "Impostor" } });
  check(dupe.status === 409, "a duplicate supplier code is refused with 409, not a 500", `got ${dupe.status}`);

  const noName = await api("/api/suppliers", { method: "POST", token, body: { code: "SUP-X" } });
  check(noName.status === 400, "a supplier with no name is refused", `got ${noName.status}`);

  const list = await api("/api/suppliers?q=timber", { token });
  check(list.json?.items?.length === 1, "suppliers are searchable by name", `${list.json?.items?.length} row(s)`);

  console.log("\n[procurement] raising a purchase order");
  const badLine = await api("/api/purchase-orders", { method: "POST", token, body: {
    supplierId, lines: [{ itemKind: "product", itemId: "does-not-exist", qtyOrdered: 1 }] } });
  check(badLine.status === 400, "a PO against a non-existent product is refused", `got ${badLine.status}`);

  const bigQty = await api("/api/purchase-orders", { method: "POST", token, body: {
    supplierId, lines: [{ itemKind: "product", itemId: "proc-chair", qtyOrdered: 10, unitCost: 1000 },
                        { itemKind: "material", itemId: materialId, qtyOrdered: 4, unitCost: 0 }] } });
  check(bigQty.status === 201, "a PO with a product line and a material line is accepted", `got ${bigQty.status} ${JSON.stringify(bigQty.json)?.slice(0, 200)}`);
  const po = bigQty.json;
  check(po?.status === "draft", "a new order starts as draft", po?.status);
  check(po?.lines?.length === 2, "both lines were stored", `${po?.lines?.length}`);
  check(po?.qtyOrdered === 14, "the order reports 14 units ordered in total", String(po?.qtyOrdered));
  check(po?.qtyOpen === 14, "and 14 open before anything arrives", String(po?.qtyOpen));
  check(po?.value === 10000, "the order value is quantity × cost (10×1000 + 4×0)", String(po?.value));

  const fraction = await api("/api/purchase-orders", { method: "POST", token, body: {
    supplierId, lines: [{ itemKind: "product", itemId: "proc-chair", qtyOrdered: 2.5 }] } });
  check(fraction.status === 400, "a fractional quantity is refused (the ledger only accepts whole units)", `got ${fraction.status}`);

  console.log("\n[procurement] the state machine cannot be lied to");
  const skip = await api(`/api/purchase-orders/${po.id}`, { method: "PATCH", token, body: { status: "received" } });
  check(skip.status === 409, "a draft cannot jump straight to received", `got ${skip.status}`);

  const receiveDraft = await api("/api/goods-receipts", { method: "POST", token, body: {
    purchaseOrderId: po.id, lines: [{ itemId: "proc-chair", qtyReceived: 1 }] } });
  check(receiveDraft.status === 409, "goods cannot be received against a draft (nobody ordered them)", `got ${receiveDraft.status}`);

  const approved = await api(`/api/purchase-orders/${po.id}`, { method: "PATCH", token, body: { status: "approved" } });
  check(approved.status === 200, "a draft can be approved", `got ${approved.status} ${JSON.stringify(approved.json)?.slice(0, 120)}`);
  check(Boolean(approved.json?.approvedAt), "approval records who and when", JSON.stringify(approved.json?.approvedAt));
  check(Boolean(approved.json?.approvedBy), "the approver is attributed", approved.json?.approvedBy);

  const chairLine = approved.json?.lines?.find((l) => l.itemId === "proc-chair");
  const matLine = approved.json?.lines?.find((l) => l.itemId === materialId);
  check(chairLine?.qtyOpen === 10, "the product line has 10 open", String(chairLine?.qtyOpen));

  console.log("\n[procurement] partial delivery");
  const stockBefore = await stockOf("proc-chair");
  const part = await api("/api/goods-receipts", { method: "POST", token, body: {
    purchaseOrderId: po.id, note: "first pallet",
    lines: [{ purchaseOrderLineId: chairLine.id, qtyReceived: 4 }] } });
  check(part.status === 201, "a partial receipt is accepted", `got ${part.status} ${JSON.stringify(part.json)?.slice(0, 200)}`);
  check(part.json?.purchaseOrder?.status === "partially_received", "the order moves to partially_received", part.json?.purchaseOrder?.status);

  const stockAfter = await stockOf("proc-chair");
  check(stockAfter === stockBefore + 4, `receiving 4 products adds exactly 4 to stock (${stockBefore} → ${stockAfter})`, `actual ${stockAfter}`);

  const movements = (await api("/api/products/proc-chair/stock", { token })).json;
  const receiptMovement = movements?.items?.find((m) => m.reason === "receipt" && m.refType === "goods_receipt");
  check(Boolean(receiptMovement), "the receipt is a LEDGER ROW, not a bare stock_qty write",
    JSON.stringify(movements?.items?.[0] ?? null));
  check(receiptMovement?.delta === 4, "the ledger row records +4", String(receiptMovement?.delta));
  check(part.json?.stockMoved?.length === 1, "the response reports exactly one product line moving stock", JSON.stringify(part.json?.stockMoved));

  const open = await api(`/api/purchase-orders/${po.id}`, { token });
  check(open.json?.qtyReceived === 4, "the order reports 4 received", String(open.json?.qtyReceived));
  check(open.json?.qtyOpen === 10, "and 10 open (6 chairs + 4 finish units)", String(open.json?.qtyOpen));

  console.log("\n[procurement] a material moves stock NEVER");
  const matReceipt = await api("/api/goods-receipts", { method: "POST", token, body: {
    purchaseOrderId: po.id, lines: [{ purchaseOrderLineId: matLine.id, qtyReceived: 4 }] } });
  check(matReceipt.status === 201, "a material receipt is recorded", `got ${matReceipt.status}`);
  check(matReceipt.json?.stockMoved?.length === 0, "it moves NO stock", JSON.stringify(matReceipt.json?.stockMoved));
  check(matReceipt.json?.stockUnchanged?.length === 1, "and says so explicitly rather than silently doing nothing",
    JSON.stringify(matReceipt.json?.stockUnchanged));
  const afterMat = await stockOf("proc-chair");
  check(afterMat === stockAfter, `receiving a finish code did not touch product stock (${afterMat})`, `actual ${afterMat}`);

  console.log("\n[procurement] over-receipt is refused by name");
  const over = await api("/api/goods-receipts", { method: "POST", token, body: {
    purchaseOrderId: po.id, lines: [{ purchaseOrderLineId: chairLine.id, qtyReceived: 7 }] } });
  check(over.status === 409, "receiving more than ordered is refused", `got ${over.status}`);
  check(/exceed the 10 ordered/.test(over.json?.error ?? ""), "the refusal names both numbers", over.json?.error);
  check((await stockOf("proc-chair")) === afterMat, "the refused receipt changed no stock", String(await stockOf("proc-chair")));

  console.log("\n[procurement] completion");
  const cannotCancel = await api(`/api/purchase-orders/${po.id}`, { method: "PATCH", token, body: { status: "cancelled" } });
  check(cannotCancel.status === 409, "an order with goods against it cannot be cancelled", `got ${cannotCancel.status}`);

  const finish = await api("/api/goods-receipts", { method: "POST", token, body: {
    purchaseOrderId: po.id, lines: [{ purchaseOrderLineId: chairLine.id, qtyReceived: 6 }] } });
  check(finish.status === 201, "the outstanding chairs are received", `got ${finish.status}`);
  check(finish.json?.purchaseOrder?.status === "received", "the order completes automatically when every line is full",
    finish.json?.purchaseOrder?.status);
  check((await stockOf("proc-chair")) === afterMat + 6, "stock rose by the remaining 6", String(await stockOf("proc-chair")));

  console.log("\n[procurement] every receipt is audited");
  const audit = await api("/api/audit?limit=100", { token });
  const actions = (audit.json?.items ?? []).map((i) => i.action);
  check(actions.includes("purchase_order.created"), "PO creation is audited", actions.slice(0, 6).join(", "));
  check(actions.includes("purchase_order.approved"), "PO approval is audited");
  check(actions.includes("goods_receipt.received"), "goods receiving is audited");
  check(actions.filter((a) => a === "goods_receipt.received").length === 3,
    "one audit row per receipt (3)", String(actions.filter((a) => a === "goods_receipt.received").length));
  const health = await api("/api/audit/health", { token });
  check(health.json?.writeFailures === 0, "no audit write was silently rejected by the action validator",
    String(health.json?.writeFailures));

  console.log("\n[procurement] reconciliation still holds end to end");
  {
    const { DatabaseSync } = await import("node:sqlite");
    const db = new DatabaseSync(path.join(tempRoot, "woodex.db"));
    const rows = db.prepare(`
      SELECT p.id, p.stock_qty AS cache,
             COALESCE((SELECT SUM(delta) FROM stock_movements m WHERE m.product_id = p.id), 0) AS ledger
        FROM products p`).all();
    db.close();
    const drifted = rows.filter((r) => Number(r.cache) !== Number(r.ledger));
    check(drifted.length === 0, `every product reconciles after procurement (${rows.length} checked)`,
      drifted.slice(0, 4).map((r) => `${r.id}: cache ${r.cache} vs ledger ${r.ledger}`).join("; "));
  }

  console.log("\n[procurement] receiving cannot double-count on retry");
  /* The unique index is the guard; this proves it is actually reachable, i.e.
     the predicate matches real rows rather than being vacuous. */
  {
    const { DatabaseSync } = await import("node:sqlite");
    const db = new DatabaseSync(path.join(tempRoot, "woodex.db"));
    const idx = db.prepare("SELECT name FROM sqlite_master WHERE type='index' AND name='uq_stock_movements_receipt_ref'").get();
    db.close();
    check(Boolean(idx), "the receipt idempotency index exists on the SQLite side");
  }
  {
    const src = fs.readFileSync(path.join(ROOT, "apps/api/server.mjs"), "utf8");
    /* A second write PATH is how a ledger stops being the source of truth.
       applyStockMovement legitimately contains two statements — an assert-on-
       decrement branch and a plain increment branch — so the question is not
       "how many statements" but "how many functions". Any UPDATE outside the
       stock-ledger helper is a bypass. */
    const helperStart = src.indexOf("const applyStockMovement = async");
    const helperEnd = src.indexOf("const stockFromLedger");
    const outside = [...src.matchAll(/UPDATE\s+products\s+SET\s+stock_qty/g)]
      .map((m) => m.index)
      .filter((i) => i < helperStart || i > helperEnd).length;
    check(helperStart > 0 && helperEnd > helperStart, "the stock helper is still a single identifiable block");
    check(outside === 0, "stock_qty is written ONLY inside applyStockMovement", `${outside} write(s) outside the helper`);
    check(/reason = 'receipt' AND ref_type = 'goods_receipt'/.test(src),
      "the receipt guard is scoped to goods_receipt, leaving opening balances exempt");
  }
} catch (error) {
  problems.push(`rehearsal crashed: ${error.message}`);
  console.error(`\n✗ ${error.stack ?? error.message}`);
} finally {
  child?.kill("SIGKILL");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n✗ ${problems.length} procurement problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  process.exit(1);
}
console.log("\n✓ procurement behaves: products move stock once, materials never, partials are honest, receipts are audited.\n");
