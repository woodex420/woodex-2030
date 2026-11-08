#!/usr/bin/env node
/**
 * Stock ledger rehearsal.
 *
 *   node scripts/test-stock-ledger.mjs
 *
 * Runs against a real API on a throwaway SQLite database and asserts the
 * properties the ledger exists to provide. The important ones are not "does it
 * insert a row" — they are:
 *
 *   1. RECONCILIATION. products.stock_qty must equal SUM(delta) for every
 *      product. stock_qty is a cache, so the only defence against it drifting
 *      is a test that recomputes it. If this passes after arbitrary selling,
 *      returning and adjusting, the cache is trustworthy.
 *
 *   2. THE OVERSELLING BUG IS ACTUALLY FIXED. Before the ledger, checkout
 *      validated availability and never decremented, so a 3-unit product could
 *      be sold an unlimited number of times. This test tries to sell out of
 *      stock and requires the sale to fail.
 *
 *   3. NO LOST UPDATES. Editing a product must not write back a stale stock
 *      quantity over sales that happened while the form was open.
 *
 *   4. APPEND-ONLY. There is no update or delete path; the code should not
 *      contain one, and corrections should appear as compensating rows.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-stock-${process.pid}`);
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

  /* Operator login for the admin-only surfaces. */
  const { DatabaseSync } = await import("node:sqlite");
  const dbPath = path.join(tempRoot, "woodex.db");

  /* The local demo seed creates admin@woodex.pk with password "admin". It is
     explicitly a local-only account (the production credentials are different
     and are never written by the seed), so the test uses the local one. */
  const login = await api("/api/auth/login", { method: "POST", body: { email: "admin@woodex.pk", password: "admin" } });
  const token = login.json?.token;
  check(Boolean(token), "operator signed in", login.json?.error ?? "");

  /* A dedicated product with a small, exact quantity. */
  const created = await api("/api/products", {
    method: "POST", token,
    body: { id: "ledger-probe", name: "Ledger Probe Desk", price: 1000, category: "office-tables",
      stockQty: 3, inStock: true, description: "probe", features: [], images: ["/img/desk-product.jpg"] },
  });
  check(created.status === 201, "probe product created with stock 3", `got ${created.status} ${JSON.stringify(created.json)?.slice(0, 120)}`);

  const stockOf = async () => (await api("/api/products/ledger-probe")).json?.stockQty;
  const movementsOf = async () => {
    const rows = await api("/api/products/ledger-probe/stock", { token });
    return rows.json;
  };

  check((await stockOf()) === 3, "opening stock is 3");
  const opening = await movementsOf();
  check(opening?.items?.[0]?.reason === "receipt", "opening quantity recorded as a receipt, not a bare number",
    JSON.stringify(opening?.items?.[0] ?? null));
  check(opening?.items?.length === 1, "exactly one opening movement (not double-counted)", `${opening?.items?.length} rows`);

  console.log("\n[stock] selling consumes stock");
  const sell = (qty, id = "ledger-probe") => api("/api/orders", {
    method: "POST", body: { customer: "Ledger QA", contact: "ledger@qa.pk", items: [{ id, qty }] },
  });

  const ok = await sell(2);
  check(ok.status === 201, "sale of 2 succeeds", `got ${ok.status} ${JSON.stringify(ok.json)?.slice(0, 140)}`);
  check((await stockOf()) === 1, "stock falls 3 → 1 after the sale", `actual ${await stockOf()}`);

  /* THE REGRESSION THIS PR EXISTS FOR. Before the ledger, this succeeded and
     the catalogue never moved. */
  const over = await sell(2);
  check(over.status === 409, "selling 2 when only 1 remains is REFUSED (this used to succeed)", `got ${over.status}`);
  check((await stockOf()) === 1, "the refused sale did not alter stock", `actual ${await stockOf()}`);

  const sellOut = await sell(1);
  check(sellOut.status === 201, "selling the last unit succeeds", `got ${sellOut.status}`);
  check((await stockOf()) === 0, "stock reaches exactly 0, never below", `actual ${await stockOf()}`);

  const anyLeft = await sell(1);
  check(anyLeft.status === 409, "selling from an empty shelf is refused", `got ${anyLeft.status}`);

  const negative = (await api("/api/products", {
    method: "POST", token,
    body: { id: "ledger-neg", name: "Negative", price: 10, category: "chairs", stockQty: -5, inStock: true, features: [], images: ["/img/desk-product.jpg"] },
  }));
  check(negative.status === 400, "a product cannot be created with negative stock", `got ${negative.status}`);

  console.log("\n[stock] the edit form cannot overwrite live stock");
  /* Simulate the lost update: read a product (stock 0), then "save the form"
     with the stale quantity it loaded. Stock must not jump back up. */
  const staleEdit = await api("/api/products/ledger-probe", {
    method: "PATCH", token,
    body: { name: "Ledger Probe Desk", price: 1000, category: "office-tables", stockQty: 0, inStock: true, features: [], images: ["/img/desk-product.jpg"] },
  });
  check(staleEdit.status === 200, "editing product details succeeds", `got ${staleEdit.status}`);
  check((await stockOf()) === 0, "a PATCH restating the current stock changes nothing", `actual ${await stockOf()}`);

  const recount = await api("/api/products/ledger-probe", {
    method: "PATCH", token,
    body: { name: "Ledger Probe Desk", price: 1000, category: "office-tables", stockQty: 10, inStock: true, features: [], images: ["/img/desk-product.jpg"] },
  });
  check(recount.status === 200, "an explicit recount is accepted", `got ${recount.status}`);
  check((await stockOf()) === 10, "recount moves stock to 10", `actual ${await stockOf()}`);
  const afterRecount = await movementsOf();
  check(afterRecount?.items?.[0]?.reason === "adjustment", "the recount is recorded as an adjustment movement, not a silent write",
    JSON.stringify(afterRecount?.items?.[0] ?? null));
  check(afterRecount?.items?.[0]?.delta === 10, "the adjustment delta is +10 (10 − 0)", `delta ${afterRecount?.items?.[0]?.delta}`);

  console.log("\n[stock] returns put stock back");
  const order = await sell(3);
  check(order.status === 201, "sale for the return test succeeds", `got ${order.status}`);
  check((await stockOf()) === 7, "stock 10 → 7", `actual ${await stockOf()}`);
  const orderRef = order.json?.ref;
  const rma = await api("/api/returns", { method: "POST", token, body: { order_ref: orderRef, item: "Ledger Probe Desk", reason: "damaged on arrival" } });
  check(rma.status === 201, "a return can be opened against the order", `got ${rma.status} ${JSON.stringify(rma.json)?.slice(0, 120)}`);
  const closed = await api(`/api/returns/${rma.json?.id}`, { method: "PATCH", token, body: { state: "Closed" } });
  check(closed.status === 200, "the return can be closed", `got ${closed.status}`);
  check((await stockOf()) === 10, "closing the return restores the 3 units (7 → 10)", `actual ${await stockOf()}`);

  /* Closing twice must not restock twice — that would inflate inventory with
     no goods behind it, the mirror of the overselling bug. */
  await api(`/api/returns/${rma.json?.id}`, { method: "PATCH", token, body: { state: "Closed", resolution: "closed again" } });
  check((await stockOf()) === 10, "re-closing the same return does not restock a second time", `actual ${await stockOf()}`);

  console.log("\n[stock] reconciliation: stock_qty must equal SUM(delta)");
  {
    const db = new DatabaseSync(dbPath);
    const rows = db.prepare(`
      SELECT p.id, p.stock_qty AS cache, COALESCE((SELECT SUM(delta) FROM stock_movements m WHERE m.product_id = p.id), 0) AS ledger
        FROM products p`).all();
    db.close();
    const drifted = rows.filter((r) => Number(r.cache) !== Number(r.ledger));
    check(drifted.length === 0, `every product reconciles (${rows.length} checked)`,
      drifted.slice(0, 4).map((r) => `${r.id}: cache ${r.cache} vs ledger ${r.ledger}`).join("; "));
  }

  console.log("\n[stock] append-only");
  {
    const src = fs.readFileSync(path.join(ROOT, "apps/api/server.mjs"), "utf8");
    check(!/UPDATE\s+stock_movements/i.test(src), "no UPDATE against stock_movements anywhere in the API");
    check(!/DELETE\s+FROM\s+stock_movements/i.test(src), "no DELETE against stock_movements anywhere in the API");
  }
} catch (error) {
  problems.push(`rehearsal crashed: ${error.message}`);
  console.error(`\n✗ ${error.stack ?? error.message}`);
} finally {
  child?.kill("SIGKILL");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n✗ ${problems.length} stock-ledger problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  process.exit(1);
}
console.log("\n✓ stock ledger behaves: sales consume, overselling is refused, returns restore once, cache reconciles.\n");
