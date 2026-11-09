#!/usr/bin/env node
/**
 * Proves `audit-live-schema.mjs` can actually detect drift.
 *
 * Why this exists
 * ---------------
 * The audit shipped with a pglite driver specifically so it would be "testable
 * without a hosted database". Nothing ever tested it. So when its foreign-key
 * patterns missed the inline `CONSTRAINT … FOREIGN KEY` style that 003 uses, it
 * declared 26 keys instead of 28 — and with `stock_movements_product_fkey`
 * physically dropped from a fully migrated database it still printed "all 26
 * declared foreign keys present" and exited 0.
 *
 * That is the worst failure a guard can have. It does not fall over; it reports
 * agreement it has not earned, and it is precisely the tool somebody consults to
 * decide whether production can be trusted.
 *
 * Every other guard in this repo is mutation-tested — the constraint tests drop
 * a constraint and assert the gate fails, `docs:verify` is checked by writing a
 * wrong number into the docs. This does the same for the audit, and it is the
 * only thing that would have caught the bug above.
 *
 *   ✓ a correct database passes (exit 0)
 *   ✓ a dropped CHECK fails      (exit 1)
 *   ✓ a dropped FK fails         (exit 1)  — the case that was silently missed
 *   ✓ a NOT VALID FK fails       (exit 1)
 *   ✓ an edited migration fails  (exit 1)  — checksum drift
 *   ✓ a missing table fails      (exit 1)
 *
 * Run: npm run test:audit
 */
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import crypto from "node:crypto";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const AUDIT = path.join(ROOT, "scripts", "audit-live-schema.mjs");
const read = (p) => fs.readFileSync(path.join(ROOT, p), "utf8");

const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-audit-test-"));
let failures = 0;
const check = (ok, label, detail = "") => {
  console.log(`  ${ok ? "✓" : "✗"} ${label}${ok || !detail ? "" : ` — ${detail}`}`);
  if (!ok) failures++;
};

const { PGlite } = await import("@electric-sql/pglite");

/* Build a database that the audit MUST consider clean: base schema, every
   migration, and a ledger carrying the real sha256 of each file. */
const seed = async (dir) => {
  const db = new PGlite(dir);
  await db.exec(read("scripts/schema.postgres.sql"));
  await db.exec(`CREATE TABLE IF NOT EXISTS schema_migrations(
    version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL,
    applied_at TEXT NOT NULL, duration_ms INTEGER NOT NULL);`);
  const files = fs.readdirSync(path.join(ROOT, "scripts", "migrations"))
    .filter((f) => f.endsWith(".sql")).sort();
  for (const f of files) {
    const sql = read(path.join("scripts", "migrations", f));
    await db.exec(sql);
    const checksum = crypto.createHash("sha256").update(sql).digest("hex");
    await db.query(
      "INSERT INTO schema_migrations(version,name,checksum,applied_at,duration_ms) VALUES($1,$2,$3,$4,$5)",
      [Number(f.slice(0, 3)), f, checksum, new Date().toISOString(), 0],
    );
  }
  return db;
};

const runAudit = (dir) => {
  const r = spawnSync(process.execPath, [AUDIT], {
    env: { ...process.env, DATABASE_URL: `pglite:${dir}` },
    encoding: "utf8",
  });
  return { code: r.status, out: `${r.stdout ?? ""}${r.stderr ?? ""}` };
};

let dir = path.join(tempRoot, "clean");
console.log("\n[audit-test] a correctly migrated database");
let db = await seed(dir);
await db.close();
let r = runAudit(dir);
check(r.code === 0, "audit exits 0 on a clean database", `exit ${r.code}`);
check(/all 28 declared foreign keys present/.test(r.out), "clean database reports all 28 foreign keys",
  "the declared count is wrong — check the inline CONSTRAINT … FOREIGN KEY pattern");
check(/all 28 declared CHECK constraints present/.test(r.out), "clean database reports all 28 CHECK constraints");

/* The regression this test was written for. 003 declares stock_movements'
   foreign key inline; the old patterns never collected it, so dropping it was
   invisible. Assert on the NAME, not the count: a count of 26 could still be
   "26/26" while the wrong 26 are present. */
dir = path.join(tempRoot, "no-fk");
db = await seed(dir);
await db.exec("ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_product_fkey;");
await db.close();
r = runAudit(dir);
check(r.code === 1, "audit fails when an inline-declared foreign key is dropped", `exit ${r.code}`);
check(/stock_movements_product_fkey/.test(r.out), "the failure names the missing foreign key",
  "a count-only report cannot tell you which one went missing");

dir = path.join(tempRoot, "no-check");
db = await seed(dir);
await db.exec("ALTER TABLE invoices DROP CONSTRAINT invoices_status_known;");
await db.close();
r = runAudit(dir);
check(r.code === 1, "audit fails when a CHECK constraint is dropped", `exit ${r.code}`);
check(/invoices_status_known/.test(r.out), "the failure names the missing CHECK constraint");

dir = path.join(tempRoot, "unvalidated");
db = await seed(dir);
await db.exec(`ALTER TABLE tasks DROP CONSTRAINT tasks_client_id_fkey;
  ALTER TABLE tasks ADD CONSTRAINT tasks_client_id_fkey FOREIGN KEY (client_id) REFERENCES clients(id) NOT VALID;`);
await db.close();
r = runAudit(dir);
check(r.code === 1, "audit fails on a NOT VALID foreign key", `exit ${r.code}`);
check(/NOT VALID/.test(r.out), "the failure says the key is declared but unenforced");

/* An applied migration that was later edited is the thing the ledger exists to
   catch. Simulate by recording a checksum that no longer matches the file. */
dir = path.join(tempRoot, "checksum");
db = await seed(dir);
await db.query("UPDATE schema_migrations SET checksum='deadbeef' WHERE version=7");
await db.close();
r = runAudit(dir);
check(r.code === 1, "audit fails when an applied migration's checksum no longer matches", `exit ${r.code}`);
check(/checksum/i.test(r.out), "the failure mentions checksum drift");

dir = path.join(tempRoot, "no-table");
db = await seed(dir);
await db.exec("DROP TABLE stock_reservations CASCADE;");
await db.close();
r = runAudit(dir);
check(r.code === 1, "audit fails when an expected table is missing", `exit ${r.code}`);
check(/stock_reservations/.test(r.out), "the failure names the missing table");

/* A database with no migrations at all must not be mistaken for a clean one. */
dir = path.join(tempRoot, "empty");
db = new PGlite(dir);
await db.exec("SELECT 1");
await db.close();
r = runAudit(dir);
check(r.code === 1, "an empty database is not reported as clean", `exit ${r.code}`);

fs.rmSync(tempRoot, { recursive: true, force: true });

if (failures) {
  console.error(`\n✗ ${failures} audit self-test problem(s).`);
  console.error("The drift audit must be able to fail, or its approval means nothing.\n");
  process.exit(1);
}
console.log("\n✓ audit self-test passed — the drift detector detects drift, by name.\n");
