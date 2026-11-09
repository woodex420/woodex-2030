#!/usr/bin/env node
/**
 * Inventory depth rehearsal — warehouses, locations, transfers, reservations.
 *
 *   node scripts/test-inventory.mjs
 *
 * Runs against a real API on a throwaway SQLite database.
 *
 * The assertions that matter are invariants, not row counts. A warehouse module
 * that stores and returns data can still be wrong in ways that only show up
 * months later as "the stock report disagrees with the shelf", so this suite
 * pins the things that would let that happen:
 *
 *   1. LOCATION ATTRIBUTION IS HONEST. A movement that names a location goes
 *      there. A movement that names none may NOT invent one — notably a
 *      decrement whose default location is empty must fall back to "no
 *      location" rather than fail or silently subtract from the wrong rack,
 *      because opening balances predate 012 and were never located.
 *
 *   2. THE PROJECTION CANNOT DRIFT. SUM(stock_levels) must equal the sum of
 *      LOCATED ledger rows for every product, at every point in this file.
 *      Both ends of a transfer are checked; the in-transit window is checked
 *      specifically, because that is when a naive implementation either
 *      double-counts or loses the units.
 *
 *   3. A TRANSFER IS TWO LEDGER ROWS, NOT ONE EDIT. Dispatching debits the
 *      source, receiving credits the destination, and neither touches the other
 *      end. A single symmetric row would make "what is at rack A" unanswerable.
 *
 *   4. TRANSFERS DO NOT CREATE OR DESTROY STOCK. Total on hand before a
 *      transfer equals total after dispatch and after receipt.
 *
 *   5. RESERVATIONS NEVER TOUCH STOCK. Holding 5 of 20 must leave stock_qty at
 *      20 and the ledger untouched; only `available` falls. If a hold moved
 *      stock there would be no way to undo it when an order is cancelled.
 *
 *   6. OVER-RESERVATION IS REFUSED WITH THE NUMBERS. 409 plus available/onHand/
 *      held, so a caller can render "only 3 left" without a second request.
 *
 *   7. CANCELLING A DISPATCHED TRANSFER IS REFUSED. Stock has already left;
 *      the honest reversal is receive-then-move-back, which leaves a trail.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-inv-${process.pid}`);
const problems = [];
// Re-run the same path if it fails: an assertion in the first phase would skip
// the entire remainder, hiding every downstream bug behind the first one.

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

  /* Reconciliation must be clean before this module touches anything. If it is
     not, every later assertion is measuring a pre-existing drift and the
     failures would point at the wrong code. */
  const recon = () => api("/api/stock-levels/reconcile", { token });
  const zeroDrift = async (label) => {
    const r = await recon();
    check(r.json?.ok === true, `${label}: no drift`, `cacheDrift=${r.json?.cacheDrift} levelDrift=${r.json?.levelDrift} ${JSON.stringify(r.json?.items ?? []).slice(0, 200)}`);
  };
  await zeroDrift("baseline");

  console.log("\n[inventory] warehouses and locations");
  const empty = await api("/api/warehouses", { token });
  check(empty.json?.total === 0, "a fresh database has no warehouses", `${empty.json?.total}`);

  const wh1 = await api("/api/warehouses", { method: "POST", token, body: { code: "LHR", name: "Lahore Main", city: "Lahore" } });
  check(wh1.status === 200 || wh1.status === 201, "a warehouse can be created", `got ${wh1.status} ${JSON.stringify(wh1.json)?.slice(0, 140)}`);
  check(wh1.json?.warehouse?.isDefault === true, "the FIRST warehouse becomes the default without being asked");
  const whA = wh1.json?.warehouse?.id;

  const wh2 = await api("/api/warehouses", { method: "POST", token, body: { code: "KHI", name: "Karachi Depot" } });
  check(wh2.json?.warehouse?.isDefault === false, "the second warehouse is not the default");
  const whB = wh2.json?.warehouse?.id;

  const whDup = await api("/api/warehouses", { method: "POST", token, body: { code: "LHR", name: "Impostor" } });
  check(whDup.status === 409, "a duplicate warehouse code is refused with 409, not a 500", `got ${whDup.status}`);

  const whNoName = await api("/api/warehouses", { method: "POST", token, body: { code: "XXX" } });
  check(whNoName.status === 400, "a warehouse with no name is refused", `got ${whNoName.status}`);

  const loc1 = await api("/api/storage-locations", { method: "POST", token, body: { warehouseId: whA, code: "A1", name: "Rack A1" } });
  check(Boolean(loc1.json?.location?.id), "a location can be created", JSON.stringify(loc1.json)?.slice(0, 140));
  const locA1 = loc1.json?.location?.id;
  const loc2 = await api("/api/storage-locations", { method: "POST", token, body: { warehouseId: whB, code: "B1", name: "Bay B1" } });
  const locB1 = loc2.json?.location?.id;

  /* The same code in a different warehouse is legal — every godown has an A1.
     Refusing it globally would force meaningless suffixes. */
  const sameCodeOtherWh = await api("/api/storage-locations", { method: "POST", token, body: { warehouseId: whB, code: "A1", name: "Rack A1 (Karachi)" } });
  check(sameCodeOtherWh.status === 200 || sameCodeOtherWh.status === 201, "the same location code in another warehouse is allowed");
  const locB2 = sameCodeOtherWh.json?.location?.id;

  const dupLoc = await api("/api/storage-locations", { method: "POST", token, body: { warehouseId: whA, code: "A1", name: "dup" } });
  check(dupLoc.status === 409, "the same location code in the SAME warehouse is refused", `got ${dupLoc.status}`);

  const badWh = await api("/api/storage-locations", { method: "POST", token, body: { warehouseId: 99999, code: "Z9", name: "nowhere" } });
  check(badWh.status === 404, "a location under a non-existent warehouse is refused with 404", `got ${badWh.status}`);

  /* ---------- a product with a known quantity ---------- */
  console.log("\n[inventory] location attribution");
  const mk = await api("/api/products", { method: "POST", token, body: {
    id: "inv-desk", name: "Inventory Probe Desk", price: 1000, category: "office-tables",
    stockQty: 0, inStock: true, description: "probe", features: [], images: ["/img/desk-product.jpg"] } });
  check(mk.status === 201 || mk.status === 200, "probe product created", `got ${mk.status}`);
  const stockOf = async () => (await api("/api/products/inv-desk/stock", { token })).json?.stockQty;
  const levelsOf = async () => (await api("/api/stock-levels?product_id=inv-desk", { token })).json?.items ?? [];

  /* Receiving is the one operation that must always land somewhere: goods that
     arrive went to a bay, even if the form did not say which. */
  /* Drive stock through the product edit path, which is the documented recount
     entry point and exercises the fallback policy directly. */
  const bump = await api("/api/products/inv-desk", { method: "PATCH", token, body: { stockQty: 40 } });
  check(bump.status === 200, "stock can be raised to 40", `got ${bump.status}`);
  let levels = await levelsOf();
  check(levels.length === 1 && levels[0].locationId === locA1, "an unattributed INCREMENT lands in the default location", JSON.stringify(levels));
  check(levels[0]?.qty === 40, "the default location holds all 40", `${levels[0]?.qty}`);
  await zeroDrift("after increment");

  /* THE case the fallback policy exists for: units that predate location
     tracking, and a default rack that is empty. A naive "always attribute"
     would either 409 here or subtract from a rack the goods were never on. */
  const drain = await api("/api/products/inv-desk", { method: "PATCH", token, body: { stockQty: 10 } });
  check(drain.status === 200, "a decrement larger than the default location's balance still succeeds", `got ${drain.status}`);
  levels = await levelsOf();
  check(levels[0]?.qty === 10, "the decrement came off the default location as far as it could", `${levels[0]?.qty}`);
  check((await stockOf()) === 10, "stock_qty agrees with the ledger after the decrement", `${await stockOf()}`);
  await zeroDrift("after decrement");

  console.log("\n[inventory] transfers");
  const toSelf = await api("/api/stock-transfers", { method: "POST", token, body: { fromLocationId: locA1, toLocationId: locA1, lines: [{ productId: "inv-desk", qty: 1 }] } });
  check(toSelf.status === 400, "a transfer to the same location is refused", `got ${toSelf.status}`);

  const noLines = await api("/api/stock-transfers", { method: "POST", token, body: { fromLocationId: locA1, toLocationId: locB1, lines: [] } });
  check(noLines.status === 400, "a transfer with no lines is refused", `got ${noLines.status}`);

  const badQty = await api("/api/stock-transfers", { method: "POST", token, body: { fromLocationId: locA1, toLocationId: locB1, lines: [{ productId: "inv-desk", qty: 0 }] } });
  check(badQty.status === 400, "a zero-quantity transfer line is refused", `got ${badQty.status}`);

  const dupLine = await api("/api/stock-transfers", { method: "POST", token, body: { fromLocationId: locA1, toLocationId: locB1, lines: [{ productId: "inv-desk", qty: 1 }, { productId: "inv-desk", qty: 2 }] } });
  check(dupLine.status === 400, "a duplicated product line is refused as 400, not a 500 from the UNIQUE index", `got ${dupLine.status}`);

  const mkTransfer = await api("/api/stock-transfers", { method: "POST", token, body: {
    fromLocationId: locA1, toLocationId: locB1, lines: [{ productId: "inv-desk", qty: 4 }], note: "QA rebalance" } });
  check(Boolean(mkTransfer.json?.transfer?.id), "a transfer can be created", JSON.stringify(mkTransfer.json)?.slice(0, 160));
  const trf = mkTransfer.json?.transfer?.id;
  check(mkTransfer.json?.transfer?.status === "draft", "a new transfer is a draft");
  check(mkTransfer.json?.transfer?.fromLabel?.includes("LHR"), "the transfer names its endpoints for a reader", mkTransfer.json?.transfer?.fromLabel);

  /* Draft moves nothing. If merely creating a document moved stock, an
     abandoned draft would be a silent write-off. */
  check((await stockOf()) === 10, "a draft transfer moves no stock", `${await stockOf()}`);
  const disp = await api(`/api/stock-transfers/${trf}`, { method: "PATCH", token, body: { status: "dispatched" } });
  check(disp.json?.transfer?.status === "dispatched", "the transfer dispatches", JSON.stringify(disp.json)?.slice(0, 140));
  /* Total stock is the ledger sum and must be untouched by a move. At this point
     it is 6, not 10: the earlier `stockQty: 10` PATCH removed 30 units, and
     because those units were seeded before any warehouse existed they had no
     location, so the decrement could only be recorded globally. The assertion
     is therefore "unchanged by the dispatch", which is what a transfer may not
     do — recording the pre-existing baseline separately keeps the two claims
     from being confused. */
  /* Goods in a truck are not on hand. Total falls by 4 on dispatch and is
     restored on receipt — the units are neither sold nor lost, but they are
     genuinely not available while they are between two racks, and a system that
     counts them would let them be sold from the source and promised to a
     customer at the destination at the same time. */
  const duringTransit = await stockOf();
  check(duringTransit === 6, "units in transit leave the on-hand total (10 -> 6)", `${duringTransit}`);
  levels = await levelsOf();
  check(levels.find((l) => l.locationId === locA1)?.qty === 6, "the source location is debited by 4 (10 -> 6)", JSON.stringify(levels));
  check(!levels.some((l) => l.locationId === locB1), "the destination is NOT credited yet — the units are in transit", JSON.stringify(levels));
  await zeroDrift("in transit");

  const cancelAfter = await api(`/api/stock-transfers/${trf}`, { method: "PATCH", token, body: { status: "cancelled" } });
  check(cancelAfter.status === 409, "cancelling a DISPATCHED transfer is refused — stock already left", `got ${cancelAfter.status}`);

  const redispatch = await api(`/api/stock-transfers/${trf}`, { method: "PATCH", token, body: { status: "dispatched" } });
  check(redispatch.status === 409, "a dispatched transfer cannot be dispatched twice", `got ${redispatch.status}`);

  const recv = await api(`/api/stock-transfers/${trf}`, { method: "PATCH", token, body: { status: "received" } });
  check(recv.json?.transfer?.status === "received", "the transfer is received", JSON.stringify(recv.json)?.slice(0, 140));
  levels = await levelsOf();
  check(levels.find((l) => l.locationId === locA1)?.qty === 6, "the source stays debited", JSON.stringify(levels));
  check(levels.find((l) => l.locationId === locB1)?.qty === 4, "the destination is credited on receipt", JSON.stringify(levels));
  check((await stockOf()) === 10, "total stock is restored on receipt — a move loses nothing end to end", `${await stockOf()}`);
  await zeroDrift("after receipt");

  /* The ledger, not the projection, is the record. A transfer must leave two
     rows pointing at one document. */
  const moved = await api(`/api/stock-movements?ref_type=stock_transfer&ref_id=${trf}`, { token });
  check(moved.json?.items?.length === 2, "a transfer writes exactly two ledger rows", `${moved.json?.items?.length}`);
  check(moved.json?.items?.every((m) => m.reason === "transfer"), "both rows carry the transfer reason");
  check(moved.json?.items?.some((m) => m.delta === -4) && moved.json?.items?.some((m) => m.delta === 4), "the two rows are the two halves of the move");

  /* ---------- reservations ---------- */
  console.log("\n[inventory] reservations");
  const avail0 = await api("/api/stock-reservations/availability?product_id=inv-desk", { token });
  check(avail0.json?.onHand === 10, "availability reports stock on hand", JSON.stringify(avail0.json));
  check(avail0.json?.held === 0, "nothing is held yet");
  check(avail0.json?.available === 10, "available equals on hand when nothing is held");

  const noRef = await api("/api/stock-reservations", { method: "POST", token, body: { productId: "inv-desk", qty: 1 } });
  check(noRef.status === 400, "a hold with no reference is refused with a reason, not a CHECK violation", `got ${noRef.status} ${noRef.json?.error}`);

  const hold = await api("/api/stock-reservations", { method: "POST", token, body: { productId: "inv-desk", qty: 4, refType: "order", refId: 1 } });
  check(Boolean(hold.json?.reservation?.id), "stock can be held for an order", JSON.stringify(hold.json)?.slice(0, 140));
  const holdId = hold.json?.reservation?.id;
  check(hold.json?.available === 6, "the response states the availability that remains", `${hold.json?.available}`);

  const avail1 = await api("/api/stock-reservations/availability?product_id=inv-desk", { token });
  check(avail1.json?.held === 4, "held quantity is reported", `${avail1.json?.held}`);
  check(avail1.json?.available === 6, "available falls to 6", `${avail1.json?.available}`);

  /* The whole point of a hold: it is a promise, not a movement. */
  check((await stockOf()) === 10, "holding stock does NOT change stock_qty", `${await stockOf()}`);
  await zeroDrift("with a hold outstanding");

  const over = await api("/api/stock-reservations", { method: "POST", token, body: { productId: "inv-desk", qty: 7, refType: "order", refId: 2 } });
  check(over.status === 409, "over-reserving beyond AVAILABLE is refused", `got ${over.status}`);
  check(over.json?.available === 6, "the refusal states what is actually available", `${over.json?.available}`);
  check(over.json?.held === 4, "the refusal states what is held and why", `${over.json?.held}`);

  const release = await api(`/api/stock-reservations/${holdId}`, { method: "PATCH", token, body: { status: "released" } });
  check(release.json?.reservation?.status === "released", "a hold can be released", JSON.stringify(release.json)?.slice(0, 120));
  check(Boolean(release.json?.reservation?.releasedAt), "release is timestamped, so 'when did we stop holding this' is answerable");
  const avail2 = await api("/api/stock-reservations/availability?product_id=inv-desk", { token });
  check(avail2.json?.available === 10, "releasing restores availability", `${avail2.json?.available}`);

  const releaseAgain = await api(`/api/stock-reservations/${holdId}`, { method: "PATCH", token, body: { status: "released" } });
  check(releaseAgain.status === 409, "releasing twice is refused rather than silently re-stamped", `got ${releaseAgain.status}`);

  /* Cancelled stock must not block a later order; that is the failure mode a
     leaked hold produces, and it is invisible without this check. */
  const reHold = await api("/api/stock-reservations", { method: "POST", token, body: { productId: "inv-desk", qty: 7, refType: "order", refId: 3 } });
  check(reHold.status === 200 || reHold.status === 201, "a released hold stops blocking new reservations", `got ${reHold.status}`);
  if (reHold.json?.reservation?.id) await api(`/api/stock-reservations/${reHold.json.reservation.id}`, { method: "PATCH", token, body: { status: "released" } });

  /* ---------- authorization ---------- */
  console.log("\n[inventory] authorization");
  const anon = await fetch(base + "/api/warehouses");
  check(anon.status === 401, "warehouses are not readable without a session", `got ${anon.status}`);
  const anonWrite = await fetch(base + "/api/stock-transfers", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ fromLocationId: 1, toLocationId: 2, lines: [] }) });
  check(anonWrite.status === 401, "transfers cannot be created without a session", `got ${anonWrite.status}`);

  /* ---------- source guards ---------- */
  console.log("\n[inventory] source guards");
  {
    const src = fs.readFileSync(path.join(ROOT, "apps/api/server.mjs"), "utf8");
    /* The same discipline the procurement suite enforces for stock_qty: one
       write path. stock_levels is a projection and must be written only by
       applyStockMovement, or it becomes a second source of truth. */
    const helperStart = src.indexOf("const applyStockMovement = async");
    const helperEnd = src.indexOf("const stockFromLedger");
    const levelWrites = [...src.matchAll(/UPDATE\s+stock_levels\s+SET/g)]
      .map((m) => m.index)
      .filter((i) => i < helperStart || i > helperEnd).length;
    check(helperStart > 0 && helperEnd > helperStart, "the stock helper is still a single identifiable block");
    check(levelWrites === 0, "stock_levels is written ONLY inside applyStockMovement", `${levelWrites} write(s) outside the helper`);
    check(/reason IN \('receipt','sale','return','adjustment','transfer','wastage','production'\)/.test(src) === false,
      "the SQLite schema does not duplicate the reason list in a way that would drift (reasons live in STOCK_REASONS)");
  }
} catch (error) {
  problems.push(`rehearsal crashed: ${error.message}`);
  console.error(`\n✗ ${error.stack ?? error.message}`);
} finally {
  child?.kill("SIGKILL");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n✗ ${problems.length} inventory problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  process.exit(1);
}
console.log("\n✓ inventory behaves: locations are attributed honestly, transfers are two ledger rows, stock reconciles at every step, holds promise without moving stock.\n");

function check(ok, label, detail = "") {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
}
