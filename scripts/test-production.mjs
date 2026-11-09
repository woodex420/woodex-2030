#!/usr/bin/env node
/**
 * Production: bills of materials, job cards, consumption, QC.
 *
 *   node scripts/test-production.mjs
 *
 * A job card is the first place in this system where stock moves for a reason
 * that is not a purchase or a sale, and the direction is easy to get wrong in
 * both directions: consuming nothing (goods from nothing) or consuming twice
 * (a retried click eats the shelf). The assertions that matter:
 *
 *   1. MATERIALS ARE CONSUMED EXACTLY ONCE. Issuing 4 then 6 of a 10-required
 *      component takes stock down by 10, not 4, not 10 twice. A replay of a
 *      request that already succeeded is refused rather than re-applied.
 *
 *   2. A FINISH NEVER MOVES STOCK. Materials are unstocked by design (a veneer
 *      is chosen, not counted). Issuing one must record that it was used and
 *      credit/debit nothing — inventing a quantity would put fiction in the
 *      ledger.
 *
 *   3. DONE IS UNREACHABLE WITHOUT QC. Every path to 'done' is attempted.
 *      The gate is asserted in the route AND in the schema, so a future caller
 *      that bypasses the route still cannot ship un-inspected work.
 *
 *   4. COMPLETING WITHOUT ISSUING THE PARTS IS REFUSED. Otherwise the finished
 *      item appears in stock and the components that made it are still on the
 *      rack — stock created from nothing. The override exists for a real case
 *      and is audited.
 *
 *   5. THE LEDGER AGREES WITH THE SHELF. After all of the above, every product's
 *      stock_qty must equal the sum of its movements. A quantity that moved
 *      without a row is the failure this whole module is built to prevent.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-production-${process.pid}`);
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
    try { json = await res.json(); } catch { /* a non-JSON body is itself a finding */ }
    return { status: res.status, json };
  };

  const login = await api("/api/auth/login", { method: "POST", body: { email: "admin@woodex.pk", password: "admin" } });
  const token = login.json?.token;
  check(Boolean(token), "operator signed in", login.json?.error ?? "");

  /* ---------- fixtures ---------- */
  const mkProduct = (id, stockQty) => api("/api/products", { method: "POST", token, body: {
    id, name: id, price: 100, category: "office-tables", stockQty, inStock: true,
    description: "probe", features: [], images: ["/img/desk-product.jpg"] } });
  await mkProduct("prod-frame", 0);      // the thing being built
  await mkProduct("prod-bracket", 100);  // a stocked component
  const priceOf = async (id) => (await api(`/api/products/${id}`, { token })).json?.stockQty;

  const boms = await api("/api/boms", { token });
  const seededMaterial = (await api("/api/materials", { token })).json?.[0]?.id;
  check(Array.isArray(boms.json?.items), "BOM list responds", `got ${boms.status}`);
  check(Boolean(seededMaterial), "a seeded finish code is available to build with");

  console.log("\n[production] bills of materials");

  const badBom = await api("/api/boms", { method: "POST", token, body: { productId: "prod-frame", lines: [] } });
  check(badBom.status === 400, "a BOM with no components is refused", `got ${badBom.status}`);

  const missingBom = await api("/api/boms", { method: "POST", token, body: {
    productId: "prod-frame", lines: [{ itemKind: "product", itemId: "no-such-thing", qtyPerOutput: 1 }] } });
  check(missingBom.status === 400, "a component that does not exist is refused", `got ${missingBom.status}`);

  const selfBom = await api("/api/boms", { method: "POST", token, body: {
    productId: "prod-frame", lines: [{ itemKind: "product", itemId: "prod-frame", qtyPerOutput: 1 }] } });
  check(selfBom.status === 400, "a product cannot be a component of itself", `got ${selfBom.status}`);

  const zeroBom = await api("/api/boms", { method: "POST", token, body: {
    productId: "prod-frame", lines: [{ itemKind: "product", itemId: "prod-bracket", qtyPerOutput: 0 }] } });
  check(zeroBom.status === 400, "a zero-quantity component is refused", `got ${zeroBom.status}`);

  /* 1 frame = 2 brackets + 3 units of the finish. */
  const bom = await api("/api/boms", { method: "POST", token, body: {
    productId: "prod-frame", outputQty: 1, lines: [
      { itemKind: "product", itemId: "prod-bracket", qtyPerOutput: 2 },
      { itemKind: "material", itemId: seededMaterial, qtyPerOutput: 3 },
    ] } });
  check(bom.status === 201, "BOM created for 2 brackets + 3 finish per frame", `got ${bom.status} ${JSON.stringify(bom.json).slice(0, 160)}`);
  const bomId = bom.json?.id;
  check(bom.json?.lines?.length === 2, "both component lines stored");
  check(bom.json?.active === true, "the new BOM is active");

  /* A second active BOM supersedes the first instead of leaving two in force. */
  /* v2 raises the bracket count and adds a finish; the job card below must
     snapshot THIS recipe, both kinds and all. */
  const bom2 = await api("/api/boms", { method: "POST", token, body: {
    productId: "prod-frame", outputQty: 1, lines: [
      { itemKind: "product", itemId: "prod-bracket", qtyPerOutput: 3 },
      { itemKind: "material", itemId: seededMaterial, qtyPerOutput: 4 },
    ] } });
  check(bom2.status === 201, "a revised BOM is accepted", `got ${bom2.status}`);
  check(bom2.json?.version === 2, "the revision is version 2", `got ${bom2.json?.version}`);
  const afterRev = await api("/api/boms", { token });
  check((afterRev.json?.items ?? []).filter((b) => b.active && b.productId === "prod-frame").length === 1,
    "only one BOM is active per product after the revision");
  const oldBom = await api(`/api/boms/${bomId}`, { token });
  check(oldBom.json?.active === false, "the superseded BOM is retained but inactive");

  console.log("\n[production] job cards");

  const noProduct = await api("/api/job-cards", { method: "POST", token, body: { qtyToBuild: 5 } });
  check(noProduct.status === 400, "a job card must say what it builds", `got ${noProduct.status}`);

  const zeroQty = await api("/api/job-cards", { method: "POST", token, body: { productId: "prod-frame", qtyToBuild: 0 } });
  check(zeroQty.status === 400, "a job card for zero units is refused", `got ${zeroQty.status}`);

  /* 5 frames needs 15 brackets under the live (v2) recipe. */
  const jc = await api("/api/job-cards", { method: "POST", token, body: { productId: "prod-frame", qtyToBuild: 5 } });
  check(jc.status === 201, "job card raised for 5 frames", `got ${jc.status} ${JSON.stringify(jc.json).slice(0, 160)}`);
  const jcId = jc.json?.id;
  const bracketLine = (jc.json?.materials ?? []).find((m) => m.itemId === "prod-bracket");
  check(bracketLine?.qtyRequired === 15, "materials snapshotted from the active BOM (5 × 3 = 15)", `got ${bracketLine?.qtyRequired}`);
  check(jc.json?.stage === "queued", "a new job starts queued");

  /* The snapshot must not follow a later recipe edit. */
  await api("/api/boms", { method: "POST", token, body: {
    productId: "prod-frame", outputQty: 1, lines: [{ itemKind: "product", itemId: "prod-bracket", qtyPerOutput: 9 }] } });
  const stillSnapshotted = await api(`/api/job-cards/${jcId}`, { token });
  check((stillSnapshotted.json?.materials ?? []).find((m) => m.itemId === "prod-bracket")?.qtyRequired === 15,
    "an already-raised job keeps the recipe it was raised against");

  console.log("\n[production] consumption");

  const before = await priceOf("prod-bracket");
  const part1 = await api(`/api/job-cards/${jcId}/materials/issue`, { method: "POST", token, body: {
    lines: [{ lineId: bracketLine.id, qty: 5 }] } });
  check(part1.status === 200, "issue 5 of the 15 required", `got ${part1.status} ${JSON.stringify(part1.json).slice(0, 140)}`);
  check(await priceOf("prod-bracket") === before - 5, "stock fell by exactly 5");

  /* THE PARTIAL-ISSUE CASE. A foreman with 5 brackets must be able to come back
     for the rest; a guard that keys one movement per line would refuse this. */
  const part2 = await api(`/api/job-cards/${jcId}/materials/issue`, { method: "POST", token, body: {
    lines: [{ lineId: bracketLine.id, qty: 10 }] } });
  check(part2.status === 200, "a top-up issue is allowed after a partial one", `got ${part2.status} ${JSON.stringify(part2.json).slice(0, 140)}`);
  check(await priceOf("prod-bracket") === before - 15, "stock fell by 15 in total, not 5 and not 30");

  const overIssue = await api(`/api/job-cards/${jcId}/materials/issue`, { method: "POST", token, body: {
    lines: [{ lineId: bracketLine.id, qty: 1 }] } });
  check(overIssue.status === 409, "issuing beyond the requirement is refused", `got ${overIssue.status}`);
  check(await priceOf("prod-bracket") === before - 15, "the refused over-issue moved no stock");

  /* Replaying an issue that already succeeded must not decrement again. */
  const replay = await api(`/api/job-cards/${jcId}/materials/issue`, { method: "POST", token, body: {
    lines: [{ lineId: bracketLine.id, qty: 10 }] } });
  check(replay.status === 409, "replaying a completed issue is refused", `got ${replay.status}`);
  check(await priceOf("prod-bracket") === before - 15, "the replay decremented nothing");

  const jcAfterIssue = await api(`/api/job-cards/${jcId}`, { token });
  check(jcAfterIssue.json?.stage === "in_progress", "issuing material started the job");
  const bracketMovements = (jcAfterIssue.json?.movements ?? []).filter((m) => m.productId === "prod-bracket");
  check(bracketMovements.length === 2, "two ledger rows for two genuine issues", `got ${bracketMovements.length}`);
  check(bracketMovements.every((m) => m.reason === "production"), "both are filed under the production reason");
  check(bracketMovements.reduce((n, m) => n + m.delta, 0) === -15, "the ledger rows sum to the 15 consumed");

  /* A FINISH MUST NOT MOVE STOCK. */
  const finishLine = (jcAfterIssue.json?.materials ?? []).find((m) => m.itemKind === "material");
  const finishIssue = await api(`/api/job-cards/${jcId}/materials/issue`, { method: "POST", token, body: {
    lines: [{ lineId: finishLine.id, qty: finishLine.qtyRequired }] } });
  check(finishIssue.status === 200, "a finish can be issued", `got ${finishIssue.status}`);
  check(!(finishIssue.json?.movements ?? []).some((m) => m.productId === finishLine.itemId),
    "issuing a finish created no stock movement");
  check((finishIssue.json?.materials ?? []).find((m) => m.id === finishLine.id)?.qtyIssued === finishLine.qtyRequired,
    "the finish is still recorded as used");

  console.log("\n[production] the QC gate");

  /* Every route to 'done' is tried. None may work without an inspection. */
  const skipToDone = await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { stage: "done" } });
  check(skipToDone.status === 409, "in_progress → done is refused", `got ${skipToDone.status}`);

  const toQc = await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { stage: "qc", qtyBuilt: 5 } });
  check(toQc.status === 200, "in_progress → qc is allowed", `got ${toQc.status}`);
  check(toQc.json?.qtyBuilt === 5, "the quantity built is recorded on the way in");

  const doneWithoutQc = await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { stage: "done" } });
  check(doneWithoutQc.status === 409, "qc → done is refused until QC has passed", `got ${doneWithoutQc.status}`);
  check(/QC/.test(doneWithoutQc.json?.error ?? ""), "the refusal says what is missing", doneWithoutQc.json?.error ?? "");

  const overBuilt = await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { stage: "in_progress", qtyBuilt: 6 } });
  check(overBuilt.status === 200, "a failed inspection sends the job back to the floor", `got ${overBuilt.status}`);
  const tooMany = await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { stage: "qc", qtyBuilt: 6 } });
  check(tooMany.status === 400, "more built than were asked for is refused", `got ${tooMany.status}`);

  const passQc = await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: {
    stage: "qc", qtyBuilt: 5, qcPassed: true, qcNote: "inspection passed" } });
  check(passQc.json?.qcPassedAt, "the inspection is recorded with who and when");

  const framesBefore = await priceOf("prod-frame");
  const done = await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { stage: "done" } });
  check(done.status === 200, "qc → done succeeds once QC has passed", `got ${done.status} ${JSON.stringify(done.json).slice(0, 140)}`);
  check(done.json?.stage === "done", "the job is complete");
  check(done.json?.completedAt, "the completion time is recorded");
  check(await priceOf("prod-frame") === framesBefore + 5, "the 5 finished frames entered stock");

  const outputRows = (done.json?.movements ?? []).filter((m) => m.refType === "job_card_output");
  check(outputRows.length === 1, "exactly one output movement was posted", `got ${outputRows.length}`);
  check(outputRows[0]?.delta === 5, "the output movement is the quantity built");

  const finishAgain = await api(`/api/job-cards/${jcId}`, { method: "PATCH", token, body: { stage: "done" } });
  check(finishAgain.status === 409, "a completed job cannot be completed a second time", `got ${finishAgain.status}`);
  check(await priceOf("prod-frame") === framesBefore + 5, "the retry credited no extra stock");

  console.log("\n[production] completing without the parts");

  /* THE GOODS-FROM-NOTHING CASE. A second card for the same product, no
     material issued, QC passed — the only thing standing between this and
     stock that exists nowhere is the consumption gate. */
  const empty = await api("/api/job-cards", { method: "POST", token, body: { productId: "prod-frame", qtyToBuild: 2 } });
  const emptyId = empty.json?.id;
  await api(`/api/job-cards/${emptyId}`, { method: "PATCH", token, body: { stage: "in_progress" } });
  await api(`/api/job-cards/${emptyId}`, { method: "PATCH", token, body: {
    stage: "qc", qtyBuilt: 2, qcPassed: true, qcNote: "inspected" } });
  const emptyFrames = await priceOf("prod-frame");
  const sneaky = await api(`/api/job-cards/${emptyId}`, { method: "PATCH", token, body: { stage: "done" } });
  check(sneaky.status === 409, "completing without issuing the components is refused", `got ${sneaky.status}`);
  check((sneaky.json?.outstanding ?? []).length > 0, "the refusal lists what is outstanding");
  check(await priceOf("prod-frame") === emptyFrames, "the refused completion credited no stock");

  const overridden = await api(`/api/job-cards/${emptyId}`, { method: "PATCH", token, body: {
    stage: "done", allowShortIssue: true } });
  check(overridden.status === 200, "the override completes the job", `got ${overridden.status}`);
  check(await priceOf("prod-frame") === emptyFrames + 2, "the override still posts the output it authorised");

  const trail = await api("/api/audit?limit=200", { token });
  check((trail.json?.items ?? []).some((e) => e.action === "job_card.short_issue_override"),
    "the override is in the audit trail, not silent");

  console.log("\n[production] the ledger agrees with the shelf");

  /* Read the ledger itself rather than a route that summarises it: the claim
     being made is about the database, and going through the API would only
     prove the API agrees with itself. */
  const { DatabaseSync } = await import("node:sqlite");
  const raw = new DatabaseSync(path.join(tempRoot, "woodex.db"));
  const q = (sql, ...params) => raw.prepare(sql).all(...params);
  const probe = q("SELECT id, stock_qty FROM products WHERE id IN ('prod-bracket','prod-frame')");
  check(probe.length === 2, "both probe products exist in the database");
  for (const p of probe) {
    const sum = Number(q("SELECT COALESCE(SUM(delta),0) AS s FROM stock_movements WHERE product_id=?", p.id)[0].s);
    check(Number(p.stock_qty) === sum, `${p.id}: stock_qty (${p.stock_qty}) equals its ledger sum (${sum})`);
  }

  /* Nothing in the ledger came from nowhere: every movement the production
     routes wrote points at a real job card material or a real job card. */
  const orphaned = q(`SELECT COUNT(*) AS n FROM stock_movements sm
                       WHERE sm.reason = 'production'
                         AND ((sm.ref_type = 'job_card_material' AND NOT EXISTS
                                (SELECT 1 FROM job_card_materials m WHERE m.id = sm.ref_id))
                           OR (sm.ref_type = 'job_card_output' AND NOT EXISTS
                                (SELECT 1 FROM job_cards j WHERE j.id = sm.ref_id)))`)[0].n;
  check(Number(orphaned) === 0, "every production movement points at the job it belongs to");

  const zero = q("SELECT COUNT(*) AS n FROM stock_movements WHERE delta = 0")[0].n;
  check(Number(zero) === 0, "no zero-delta movement was written");

  /* THE IDEMPOTENCY INDEX, PROVEN AT THE STORAGE LAYER.
     The HTTP path above cannot reach it: a replayed issue is refused by the
     quantity guard long before the index is consulted, so that test passes
     even with the index dropped. Two requests arriving together do not get
     that luxury — each reads qty_issued before either writes, both conclude
     the same target, and only the index stops the second decrement. Asserted
     here directly, because a guard that is never exercised is not a guard. */
  let idemEnforced = false;
  try {
    const ins = raw.prepare(`INSERT INTO stock_movements(product_id,delta,reason,ref_type,ref_id,note,created_by,created_at,idem_key)
                             VALUES('prod-bracket',-1,'production','job_card_material',1,'probe','test',?,?)`);
    ins.run("2026-01-01T00:00:00.000Z", "probe:concurrent:to5");
    try { ins.run("2026-01-01T00:00:00.000Z", "probe:concurrent:to5"); }
    catch { idemEnforced = true; }
  } catch (error) {
    check(false, "the idempotency probe could be set up", error?.message ?? "");
  }
  check(idemEnforced, "the same idempotency key cannot be recorded twice (concurrency backstop)");

  const distinctOk = (() => {
    try {
      raw.prepare(`INSERT INTO stock_movements(product_id,delta,reason,ref_type,ref_id,note,created_by,created_at,idem_key)
                   VALUES('prod-bracket',-1,'production','job_card_material',1,'probe','test',?,?)`)
        .run("2026-01-01T00:00:00.000Z", "probe:concurrent:to9");
      return true;
    } catch { return false; }
  })();
  check(distinctOk, "a different target for the same line is still allowed (top-ups work)");

  /* The finish codes must appear in the ledger nowhere at all. */
  const finishRows = q("SELECT COUNT(*) AS n FROM stock_movements WHERE product_id IN (SELECT id FROM materials)")[0].n;
  check(Number(finishRows) === 0, "no movement was ever posted against a finish code");

  raw.close();

  console.log(`\n${problems.length ? `✗ ${problems.length} production check(s) failed` : "✓ all production checks passed"}`);
  for (const p of problems) console.log(`  · ${p}`);
  process.exitCode = problems.length ? 1 : 0;
} catch (error) {
  console.error("\n✗ harness failed:", error?.message ?? error);
  process.exitCode = 1;
} finally {
  if (child) { try { child.kill("SIGTERM"); } catch { /* already gone */ } }
  try { fs.rmSync(tempRoot, { recursive: true, force: true }); } catch { /* best effort */ }
}
