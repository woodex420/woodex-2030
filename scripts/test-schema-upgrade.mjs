#!/usr/bin/env node
/**
 * Schema upgrade: an existing SQLite database from before 011 must still boot.
 *
 *   node scripts/test-schema-upgrade.mjs
 *
 * WHY THIS EXISTS
 *
 * 011 adds stock_movements.idem_key, and a unique index on it. The column was
 * first put inside the `CREATE TABLE IF NOT EXISTS stock_movements(...)` block,
 * which is a no-op on a database that already has the table — so every
 * statement referencing idem_key then failed with "no such column", and the API
 * refused to start. Every test passed, because every test built its database
 * from scratch where the CREATE did create the column.
 *
 * That is the exact shape of bug that only appears on a real upgrade, which is
 * also the only situation a deployment has. This test manufactures the old
 * schema, boots the API against it, and asserts the API both starts and keeps
 * the rows it found.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-upgrade-${process.pid}`);
const problems = [];
const check = (ok, label, detail = "") => {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
};

let child;
try {
  fs.mkdirSync(tempRoot, { recursive: true });

  /* ---------- build a pre-011 database ---------- */
  console.log("[upgrade] manufacturing a database from before 011");
  const dbPath = path.join(tempRoot, "woodex.db");
  const seed = new DatabaseSync(dbPath);
  seed.exec(`
    CREATE TABLE products(
      id TEXT PRIMARY KEY, json TEXT NOT NULL, price REAL NOT NULL,
      in_stock INTEGER NOT NULL DEFAULT 1, stock_qty INTEGER NOT NULL DEFAULT 99,
      updated_at TEXT NOT NULL
    );
    /* The pre-011 shape: no idem_key. */
    CREATE TABLE stock_movements(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      product_id TEXT NOT NULL, delta INTEGER NOT NULL CHECK (delta <> 0),
      reason TEXT NOT NULL, ref_type TEXT, ref_id INTEGER, note TEXT,
      created_by TEXT, created_at TEXT NOT NULL
    );
  `);
  const t = "2026-01-01T00:00:00.000Z";
  seed.prepare("INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at) VALUES(?,?,?,?,?,?)")
    .run("legacy-desk", JSON.stringify({ id: "legacy-desk", name: "Legacy Desk" }), 5000, 1, 40, t);
  const ins = seed.prepare("INSERT INTO stock_movements(product_id,delta,reason,note,created_by,created_at) VALUES(?,?,?,?,?,?)");
  ins.run("legacy-desk", 50, "receipt", "opening balance", "seed", t);
  ins.run("legacy-desk", -10, "sale", "sold", "seed", t);
  /* The wrong-predicate index an early revision of 011 created. It must not
     survive: it forbids the partial-issue-then-top-up a real build needs. */
  seed.exec(`CREATE UNIQUE INDEX uq_stock_movements_production_issue
               ON stock_movements(ref_type, ref_id, product_id)
             WHERE reason = 'production' AND ref_type = 'job_card_material' AND ref_id IS NOT NULL;`);
  const before = { row: seed.prepare("SELECT stock_qty FROM products WHERE id=?").get("legacy-desk"),
    movements: seed.prepare("SELECT COUNT(*) n FROM stock_movements").get().n };
  check(before.movements === 2, "pre-011 database seeded with 2 movements");
  seed.close();

  /* ---------- boot the API against it ---------- */
  console.log("[upgrade] starting the API against the old database");
  child = spawn(process.execPath, ["apps/api/server.mjs"], {
    cwd: ROOT,
    env: { ...process.env, NODE_ENV: "test", PORT: "0", WOODEX_DATA_DIR: tempRoot,
      DATABASE_URL: "", VERCEL: "", VERCEL_ENV: "", WOODEX_DEMO_SEED: "" },
    stdio: ["ignore", "pipe", "pipe"],
  });

  let log = "";
  const started = await new Promise((resolve) => {
    const timer = setTimeout(() => resolve(null), 45_000);
    const onData = (buf) => {
      log += String(buf);
      const m = log.match(/http:\/\/localhost:(\d+)/);
      if (m) { clearTimeout(timer); resolve(`http://127.0.0.1:${m[1]}`); }
    };
    child.stdout.on("data", onData);
    child.stderr.on("data", onData);
    child.on("exit", () => { clearTimeout(timer); resolve(null); });
  });

  check(Boolean(started), "the API starts against a pre-011 database",
    started ? "" : `it did not. Last output:\n${log.slice(-600)}`);
  check(/no such column/i.test(log) === false, "no 'no such column' error on boot");

  if (started) {
    /* ---------- the column exists and the rows survived ---------- */
    const after = new DatabaseSync(dbPath);
    const cols = after.prepare("PRAGMA table_info(stock_movements)").all().map((c) => c.name);
    check(cols.includes("idem_key"), "stock_movements.idem_key was added in place");
    check(cols.includes("created_at"), "the existing columns are untouched");

    check(after.prepare("SELECT COUNT(*) n FROM stock_movements").get().n === 2,
      "the pre-existing movements were not lost");
    check(Number(after.prepare("SELECT stock_qty FROM products WHERE id=?").get("legacy-desk").stock_qty) === 40,
      "the pre-existing product kept its stock");

    const idx = after.prepare("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'uq_stock_movements%'")
      .all().map((r) => r.name);
    check(idx.includes("uq_stock_movements_idem_key"), "the idempotency index exists after the upgrade");
    check(!idx.includes("uq_stock_movements_production_issue"),
      "the superseded one-movement-per-line index was dropped", idx.join(", "));

    /* Idempotency enforced on the upgraded database, not just a fresh one. */
    let enforced = false;
    try {
      const p = after.prepare(`INSERT INTO stock_movements(product_id,delta,reason,ref_type,ref_id,note,created_by,created_at,idem_key)
                               VALUES('legacy-desk',-1,'production','job_card_material',1,'probe','test',?,?)`);
      p.run(t, "probe:same:to1");
      try { p.run(t, "probe:same:to1"); } catch { enforced = true; }
    } catch (error) { check(false, "idempotency probe could run", error?.message ?? ""); }
    check(enforced, "idempotency is enforced on the upgraded database");

    /* The API is genuinely serving, not merely listening. */
    const health = await fetch(started + "/api/health").then((r) => r.json()).catch(() => null);
    check(health?.ok === true, "the upgraded API answers /api/health");
    const login = await fetch(started + "/api/auth/login", { method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ email: "admin@woodex.pk", password: "admin" }) }).then((r) => r.json()).catch(() => null);
    check(Boolean(login?.token), "login works on the upgraded database", login?.error ?? "");
    after.close();
  }

  console.log(`\n${problems.length ? `✗ ${problems.length} upgrade check(s) failed` : "✓ the upgrade path works"}`);
  for (const p of problems) console.log(`  · ${p}`);
  process.exitCode = problems.length ? 1 : 0;
} catch (error) {
  console.error("\n✗ harness failed:", error?.message ?? error);
  process.exitCode = 1;
} finally {
  if (child) { try { child.kill("SIGTERM"); } catch { /* already gone */ } }
  try { fs.rmSync(tempRoot, { recursive: true, force: true }); } catch { /* best effort */ }
}
