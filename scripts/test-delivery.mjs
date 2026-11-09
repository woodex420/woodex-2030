#!/usr/bin/env node
/**
 * Delivery and installation rehearsal — dispatch runs, deliveries with proof,
 * delivery lines, installed assets and the warranty clock.
 *
 *   node scripts/test-delivery.mjs
 *
 * The assertions that matter are not "it saved the row". They are:
 *
 *   1. A DELIVERY THAT SAYS IT ARRIVED NAMES WHO SIGNED. `signed_by` is a name
 *      typed by a person standing in a kitchen. A delivery marked delivered
 *      with no signature is refused, because "delivered" with no evidence is
 *      exactly the claim a dispute turns on — and a system that accepts the
 *      claim is worse than one with no delivery record at all, because it looks
 *      authoritative.
 *
 *   2. A DELIVERY WITH NO LINES IS REFUSED. Nothing says what arrived, so the
 *      record cannot be checked against the order.
 *
 *   3. A DEPARTURE TIME IS STAMPED ONCE. Rewriting it would make a late run
 *      look punctual, which destroys the only thing the record is for.
 *      Enforced by trigger, not by a route — proven by opening SQLite directly.
 *
 *   4. DAMAGE CARRIES A NOTE. `condition='damaged'` with no notes is a claim
 *      nobody can act on.
 *
 *   5. THE WARRANTY RUNS FROM INSTALLATION, AND IS STORED. Not from the order
 *      date: a worktop that sat in a warehouse for six weeks has not been in
 *      use for six weeks. And it is not recomputed later, so a change to a
 *      product's warranty term cannot silently shorten a warranty already
 *      promised.
 *
 *   6. PARTIAL DELIVERY IS NOT FAILURE. A kitchen arrives in three runs. The
 *      shortfall is a number computed from the lines, not a stage someone set.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-deliv-${process.pid}`);
const problems = [];
function check(ok, label, detail = "") {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
}

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

  /* An order to deliver. The seed gives us one; assert rather than assume. */
  const orders = await api("/api/orders", { token });
  const order = (orders.json?.items ?? orders.json ?? [])[0];
  check(Boolean(order?.id), "there is an order to deliver", `got ${JSON.stringify(orders.json)?.slice(0, 120)}`);

  /* ─────────────────────── dispatch runs ─────────────────────── */
  console.log("\n[delivery] the run is a vehicle leaving the building");
  const run = await api("/api/dispatch-runs", { method: "POST", token, body: {
    vehicle: "LEB-4471", driver: "Nadeem", crew: "Nadeem + 1",
    scheduledFor: "2026-11-16T07:00:00Z", address: "House 12, Gulberg III", city: "Lahore" } });
  check(run.status === 201, "a run is planned", `got ${run.status} ${JSON.stringify(run.json)?.slice(0, 140)}`);
  check(/^RUN-/.test(run.json?.run?.ref ?? ""), "with a reference", run.json?.run?.ref);
  check(run.json?.run?.status === "planned", "and starts as planned");
  check(run.json?.run?.departedAt === null, "with no departure time yet");
  const runId = run.json?.run?.id;

  const badStatus = await api(`/api/dispatch-runs/${runId}`, { method: "PATCH", token, body: { status: "teleported" } });
  check(badStatus.status === 400, "an unknown run status is refused", `got ${badStatus.status}`);

  const completeEarly = await api(`/api/dispatch-runs/${runId}`, { method: "PATCH", token, body: { status: "completed" } });
  check(completeEarly.status === 409, "a run cannot complete without having departed", `got ${completeEarly.status}`);
  check(completeEarly.json?.code === "not_dispatched", "and says which rule it broke", completeEarly.json?.code);

  const departed = await api(`/api/dispatch-runs/${runId}`, { method: "PATCH", token, body: { status: "dispatched" } });
  check(departed.json?.run?.status === "dispatched", "it dispatches");
  const departureStamp = departed.json?.run?.departedAt;
  check(Boolean(departureStamp), "departure is timestamped", departureStamp);

  /* ───────────────────────── deliveries ───────────────────────── */
  console.log("\n[delivery] a delivery that arrived names who signed");
  const del = await api("/api/deliveries", { method: "POST", token, body: {
    customer: "Gulberg Interiors", orderId: order.id, dispatchRunId: runId,
    address: "House 12, Gulberg III",
    lines: [{ description: "Base cabinets", qty: 2, unit: "pcs" }, { description: "Worktop", qty: 1, unit: "pcs" }] } });
  check(del.status === 201, "a delivery is created with its contents", `got ${del.status} ${JSON.stringify(del.json)?.slice(0, 160)}`);
  const delId = del.json?.delivery?.id;
  check(/^DLV-/.test(del.json?.delivery?.ref ?? ""), "with a reference", del.json?.delivery?.ref);
  /* The POST response and the GET response must agree about what this delivery
     belongs to. They did not: the create path read the bare row while the list
     path joined, so the order reference was null from one and correct from the
     other. A screen that creates a record and then cannot name what it belongs
     to looks broken and, worse, is not obviously wrong in code review. */
  check(del.json?.delivery?.orderRef === order.ref,
    "and the create response names the order, not just the list response", `got ${del.json?.delivery?.orderRef}`);
  /* The response said 0 lines and 0 units to the screen that had just sent
     two, because the row was read back before the lines were written. It looks
     exactly like "my line was not saved". */
  check(del.json?.delivery?.lineCount === 2,
    "and reports the lines it was given", `lineCount=${del.json?.delivery?.lineCount}`);
  check(del.json?.delivery?.units === 3,
    "and their units", `units=${del.json?.delivery?.units}`);

  const listed = await api(`/api/deliveries?order_id=${order.id}`, { token });
  const listedRow = (listed.json?.items ?? []).find((d) => d.id === delId);
  check(listedRow?.orderRef === order.ref,
    "the list reports the order it belongs to, not the delivery's own ref", `${listedRow?.orderRef} vs ${order.ref}`);
  check(listedRow?.runRef === run.json?.run?.ref, "and the run it went out on", listedRow?.runRef);

  const noSig = await api(`/api/deliveries/${delId}`, { method: "PATCH", token, body: { status: "delivered" } });
  check(noSig.status === 400, "marking it delivered with no signature is refused", `got ${noSig.status}`);
  check(noSig.json?.code === "signature_required", "with a code the UI can act on", noSig.json?.code);

  const damaged = await api(`/api/deliveries/${delId}`, { method: "PATCH", token, body: { condition: "damaged" } });
  check(damaged.status === 400, "damage with no note is refused", `got ${damaged.status}`);
  check(damaged.json?.code === "damage_note_required", "and asks for the note", damaged.json?.code);
  const damagedOk = await api(`/api/deliveries/${delId}`, { method: "PATCH", token, body: {
    condition: "damaged", damageNotes: "Worktop corner chipped in transit" } });
  check(damagedOk.json?.delivery?.condition === "damaged", "with a note it is recorded");
  /* ...and it can still be delivered, because a chipped corner is not a
     non-delivery. */
  const delivered = await api(`/api/deliveries/${delId}`, { method: "PATCH", token, body: {
    status: "delivered", signedBy: "Sana Iqbal" } });
  check(delivered.json?.delivery?.status === "delivered", "it can still be marked delivered", `got ${delivered.status}`);
  check(delivered.json?.delivery?.signedBy === "Sana Iqbal", "with the signature kept");
  check(Boolean(delivered.json?.delivery?.deliveredAt), "and an arrival timestamp");

  const signedLines = await api(`/api/deliveries/${delId}/lines`, { method: "POST", token, body: {
    description: "Sneaked-in chair", qty: 1 } });
  check(signedLines.status === 409, "a signed delivery keeps its contents", `got ${signedLines.status}`);
  check(signedLines.json?.code === "delivery_signed", "and says why", signedLines.json?.code);

  /* A delivery with no lines at all cannot be signed off — nothing says what
     arrived, so it proves nothing. */
  const empty = await api("/api/deliveries", { method: "POST", token, body: {
    customer: "Empty Van Ltd", orderId: order.id } });
  const emptyId = empty.json?.delivery?.id;
  const emptyDeliver = await api(`/api/deliveries/${emptyId}`, { method: "PATCH", token, body: {
    status: "delivered", signedBy: "Someone" } });
  check(emptyDeliver.status === 409, "a delivery with no lines cannot be signed off", `got ${emptyDeliver.status}`);
  check(emptyDeliver.json?.code === "no_lines", "with a reason a person can act on", emptyDeliver.json?.code);

  /* ───────────────────── partial delivery ───────────────────── */
  console.log("\n[delivery] partial delivery is three runs out of five");
  const short1 = await api(`/api/deliveries/shortfall/${order.id}`, { token });
  check(typeof short1.json?.expected === "number", "the shortfall is computed from the order's items", JSON.stringify(short1.json));
  check(short1.json?.arrived >= 3, "and counts what has arrived", `arrived=${short1.json?.arrived}`);

  const second = await api("/api/deliveries", { method: "POST", token, body: {
    customer: "Gulberg Interiors", orderId: order.id,
    lines: [{ description: "Wall units", qty: 4 }] } });
  const secondId = second.json?.delivery?.id;
  const short2 = await api(`/api/deliveries/shortfall/${order.id}`, { token });
  check(short2.json?.arrived === short1.json.arrived, "an unsigned run does not count as arrived", `arrived=${short2.json?.arrived}`);
  await api(`/api/deliveries/${secondId}`, { method: "PATCH", token, body: { status: "delivered", signedBy: "Sana Iqbal" } });
  const short3 = await api(`/api/deliveries/shortfall/${order.id}`, { token });
  check(short3.json?.arrived === short1.json.arrived + 4, "once signed, the second run counts", `arrived=${short3.json?.arrived}`);

  /* ───────────────────── installed assets ───────────────────── */
  console.log("\n[delivery] the warranty starts at installation");
  const asset = await api("/api/installed-assets", { method: "POST", token, body: {
    name: "Walnut worktop", room: "Kitchen", location: "House 12, Gulberg III",
    orderId: order.id, deliveryId: delId, warrantyMonths: 24, installedOn: "2026-11-20" } });
  check(asset.status === 201, "an asset is installed", `got ${asset.status} ${JSON.stringify(asset.json)?.slice(0, 140)}`);
  check(/^AST-/.test(asset.json?.asset?.tag ?? ""), "it gets a tag a technician can read out", asset.json?.asset?.tag);
  check(asset.json?.asset?.warrantyUntil === "2028-11-20",
    "24 months from the install date, not from the order", asset.json?.asset?.warrantyUntil);
  check(asset.json?.asset?.inWarranty === true, "and it reports itself in warranty");
  const assetId = asset.json?.asset?.id;

  const badMonths = await api("/api/installed-assets", { method: "POST", token, body: {
    name: "Negative", warrantyMonths: -6 } });
  check(badMonths.status === 400, "a negative warranty term is refused", `got ${badMonths.status}`);
  const noMonths = await api("/api/installed-assets", { method: "POST", token, body: {
    name: "Unwarranted shelf", room: "Pantry" } });
  check(noMonths.status === 201, "an asset with no warranty is allowed", `got ${noMonths.status}`);
  check(noMonths.json?.asset?.warrantyUntil === null, "and has no end date rather than a guessed one");

  /* The promise is stored, so it cannot be edited afterwards. */
  const rewrite = await api(`/api/installed-assets/${assetId}`, { method: "PATCH", token, body: {
    warrantyUntil: "2027-01-01" } });
  check(rewrite.status === 409, "a promised warranty cannot be renegotiated by editing it", `got ${rewrite.status}`);
  check(rewrite.json?.code === "warranty_locked", "and says so", rewrite.json?.code);
  check(/2028-11-20/.test(rewrite.json?.error ?? ""), "naming the date already promised", rewrite.json?.error);

  const same = await api(`/api/installed-assets/${assetId}`, { method: "PATCH", token, body: {
    warrantyUntil: "2028-11-20", room: "Kitchen (island)" } });
  check(same.status === 200, "re-sending the same date is not a change", `got ${same.status}`);
  check(same.json?.asset?.room === "Kitchen (island)", "and the room can still be corrected");

  const faulty = await api(`/api/installed-assets/${assetId}`, { method: "PATCH", token, body: { status: "faulty" } });
  check(faulty.json?.asset?.status === "faulty", "it can be marked faulty");
  const inWarranty = await api("/api/installed-assets?in_warranty=1", { token });
  check((inWarranty.json?.items ?? []).some((a) => a.id === assetId),
    "and still appears in the in-warranty list — faulty is not out of warranty");

  /* ───────────────── the departure stamp, at the database ───────────────── */
  console.log("\n[delivery] a departure time is stamped once");
  const dbFile = path.join(tempRoot, "woodex.db");
  const db = new DatabaseSync(dbFile);
  try {
    let blocked = false, message = "";
    try {
      db.prepare("UPDATE dispatch_runs SET departed_at = ? WHERE id = ?").run("2026-11-16T05:00:00.000Z", runId);
    } catch (e) { blocked = /departure/i.test(String(e.message)); message = String(e.message); }
    check(blocked, "the database refuses to rewrite a departure time", message.slice(0, 80));
    const kept = db.prepare("SELECT departed_at FROM dispatch_runs WHERE id = ?").get(runId).departed_at;
    check(kept === departureStamp, "so the original stamp survives");
    /* The fields a dispatcher may still correct stay writable. */
    db.prepare("UPDATE dispatch_runs SET vehicle = ? WHERE id = ?").run("LEB-9999", runId);
    check(true, "but the vehicle can still be corrected");
  } finally { db.close(); }

  const timeline = await api(`/api/deliveries/${delId}`, { token });
  const kinds = (timeline.json?.timeline ?? []).map((a) => a.kind);
  check(kinds.includes("system") && kinds.includes("status_change"),
    "the delivery has a history of its own", kinds.join(","));
  check((timeline.json?.lines ?? []).length === 2, "and its lines are listed");

} catch (e) {
  problems.push(`suite crashed: ${e.message}`);
  console.error(e);
} finally {
  child?.kill("SIGTERM");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

console.log("");
if (problems.length) {
  console.error(`${problems.length} problem(s):`);
  for (const p of problems) console.error(`  - ${p}`);
  process.exit(1);
}
console.log("Delivery rehearsal passed.");
