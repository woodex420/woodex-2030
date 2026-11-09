#!/usr/bin/env node
/**
 * Integrity rehearsal: apply the schema and every migration to a throwaway
 * embedded Postgres, then prove the constraints actually do their job.
 *
 *   node scripts/test-integrity.mjs
 *
 * Two things are being tested, and the second matters more:
 *
 *   1. The migrations apply cleanly. Necessary but weak — a migration that
 *      silently adds nothing also "applies cleanly".
 *
 *   2. The constraints are present *and bite*. Every check below inserts data
 *      that the schema is supposed to forbid and asserts the insert fails. A
 *      constraint that exists but was written with the wrong predicate looks
 *      identical to a correct one until something tries to break it.
 *
 * This caught two real bugs during development:
 *   - The FK guard tested `t2.relname = child`, so on tables with several
 *     foreign keys (tasks has five) every FK after the first was skipped,
 *     silently adding 19 of the intended 26.
 *   - Inside a `DO $$ … $$` block, a nested `$$`-quoted literal terminates the
 *     block early. The enum predicates are written with standard '' escaping.
 *
 * Runs entirely against a temporary directory; no hosted database is touched.
 */
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (p) => fs.readFileSync(path.join(ROOT, p), "utf8");
const tempRoot = path.join(os.tmpdir(), `woodex-integrity-${process.pid}`);
const problems = [];
const check = (ok, label, detail = "") => {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
};

/* Expectations, derived from the migration files rather than hardcoded. */
/* Expected counts come from the migration FILES, not hardcoded numbers, so
   adding a constraint cannot silently disagree with this test. Migration 003
   adds two foreign keys of its own, so this reads every migration. */
const allMigrationSql = fs.readdirSync(path.join(ROOT, "scripts", "migrations"))
  .filter((f) => f.endsWith(".sql")).sort()
  .map((f) => read(path.join("scripts", "migrations", f))).join("\n");
const fkExpected = [...allMigrationSql
  .matchAll(/'(\w+)',\s*'(\w+)',\s*'(\w+)',\s*'(id)',\s*'(?:NO ACTION|CASCADE|SET NULL|RESTRICT)',/gm)].length
  + [...allMigrationSql.matchAll(/FOREIGN KEY\s*\(\w+\)\s*REFERENCES\s+\w+/gi)].length;
/* Compare constraint NAMES, not counts.

   This used to assert `checks >= chkExpected` — a floor. A migration that
   declared 21 checks and added 20 passed, which is exactly what happened in
   production: `tasks_done_boolean` was declared in 002 and absent from the
   hosted database for weeks, and nothing detected it because 20 >= 20. A count
   cannot distinguish "the right twenty" from "twenty of twenty-one".

   Names are collected from both shapes the migrations use: the VALUES list in
   002, and inline CONSTRAINT ... CHECK definitions in later files. */
const declaredChecks = new Set([
  /* The FK block uses the identical ('table','column','parent',...) shape, so
     the pattern must also require a CHECK-style name — otherwise every foreign
     key column (client_id, lead_id, …) is collected as a "declared check".
     That produced a false 36/36 with 11 phantom names missing. */
  ...[...read("scripts/migrations/002_integrity_constraints.sql")
    .matchAll(/^\s*\('\w+',\s*'(\w+_(?:known|boolean|positive|non_negative|range))',\s*'/gm)]
    .map((m) => m[1]),
  ...[...allMigrationSql.matchAll(/CONSTRAINT\s+(\w+)\s+CHECK/gi)].map((m) => m[1]),
]);
const chkExpected = declaredChecks.size;

let db;
try {
  fs.rmSync(tempRoot, { recursive: true, force: true });
  const { PGlite } = await import("@electric-sql/pglite");
  const pgDir = path.join(tempRoot, "pg");
  fs.mkdirSync(pgDir, { recursive: true });
  db = new PGlite(pgDir);
  const q = async (sql, params) => (await db.query(sql, params)).rows;

  console.log("\n[integrity] applying base schema …");
  await db.exec(read("scripts/schema.postgres.sql"));

  console.log("[integrity] applying migrations …");
  const files = fs.readdirSync(path.join(ROOT, "scripts", "migrations")).filter((f) => f.endsWith(".sql")).sort();
  /* The ledger belongs to the runner, not to any individual migration file, so
     it is created here exactly as scripts/migrate.mjs creates it. */
  await db.exec(`CREATE TABLE IF NOT EXISTS schema_migrations(
      version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL,
      applied_at TEXT NOT NULL, duration_ms INTEGER NOT NULL);`);
  for (const f of files) {
    await db.exec(read(path.join("scripts", "migrations", f)));
    await db.query("INSERT INTO schema_migrations(version,name,checksum,applied_at,duration_ms) VALUES($1,$2,$3,$4,$5)",
      [Number(f.slice(0, 3)), f, "rehearsal", new Date().toISOString(), 0]);
    console.log(`  applied ${f}`);
  }

  console.log("\n[integrity] constraint inventory");
  const inv = (await q(`
    SELECT
      (SELECT count(*)::int FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE c.contype='f' AND n.nspname='public') fks,
      (SELECT count(*)::int FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE c.contype='c' AND n.nspname='public') checks,
      (SELECT count(*)::int FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE c.contype='p' AND n.nspname='public') pks,
      (SELECT count(*)::int FROM pg_class t JOIN pg_namespace n ON n.oid=t.relnamespace WHERE t.relkind='r' AND n.nspname='public') tables,
      (SELECT count(*)::int FROM pg_constraint WHERE contype='f' AND NOT convalidated) unvalidated
  `))[0];

  check(inv.fks === fkExpected, `foreign keys present (${inv.fks}/${fkExpected})`, inv.fks !== fkExpected ? "a table with several FKs only got the first" : "");
  /* Exact set membership. A missing constraint is reported by name so the
     failure says which one, instead of a count that cannot name it. */
  const actualChecks = new Set((await q(`
    SELECT c.conname FROM pg_constraint c
      JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace
     WHERE c.contype='c' AND n.nspname='public'`)).map((r) => r.conname));
  const missingChecks = [...declaredChecks].filter((n) => !actualChecks.has(n));
  check(missingChecks.length === 0,
    `every declared CHECK exists (${chkExpected}/${chkExpected})`,
    missingChecks.length ? `missing: ${missingChecks.join(", ")}` : "");
  check(inv.unvalidated === 0, "every foreign key is validated", `${inv.unvalidated} left NOT VALID`);
  /* Every table carries a primary key — the exit condition for Phase A. */
  const noPk = await q(`SELECT t.relname FROM pg_class t JOIN pg_namespace n ON n.oid=t.relnamespace
    WHERE t.relkind='r' AND n.nspname='public' AND t.relname <> 'schema_migrations'
      AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conrelid=t.oid AND c.contype='p')`);
  check(noPk.length === 0, `every table has a primary key (${inv.tables} tables)`, noPk.map((r) => r.relname).join(", "));

  /* Seed the parents the FK probes need. */
  await db.exec(`
    INSERT INTO clients(id,name) VALUES (1,'Probe Client');
    INSERT INTO invoices(id,ref,customer,items,total) VALUES (1,'T-INV','c','[]',100);
    INSERT INTO quotes(id,ref,customer,items) VALUES (1,'T-Q','c','[]');
    INSERT INTO pages(id,slug,title) VALUES (1,'probe','P');
  `);

  console.log("\n[integrity] constraints reject bad data");
  const rejects = [
    ["FK · task referencing a non-existent client", `INSERT INTO tasks(client_id,title) VALUES (987654,'x')`],
    ["FK · task referencing a non-existent order", `INSERT INTO tasks(order_id,title) VALUES (987654,'x')`],
    ["FK · payment against a non-existent invoice", `INSERT INTO payments(invoice_id,amount) VALUES (987654,10)`],
    ["FK · message in a non-existent conversation", `INSERT INTO messages(conversation_id,channel,body) VALUES (987654,'web','x')`],
    ["FK · project for a non-existent client", `INSERT INTO projects(ref,name,client_id) VALUES ('T-P','p',987654)`],
    ["FK · session for a non-existent user", `INSERT INTO sessions(token,user_id) VALUES ('t-tok',987654)`],
    ["CHECK · negative product price", `INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at) VALUES ('t','{}',-1,1,0,'x')`],
    ["CHECK · negative stock quantity", `INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at) VALUES ('t2','{}',10,1,-5,'x')`],
    ["CHECK · non-boolean in_stock", `INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at) VALUES ('t3','{}',10,7,0,'x')`],
    ["CHECK · zero payment amount", `INSERT INTO payments(invoice_id,amount) VALUES (1,0)`],
    ["CHECK · negative payment amount", `INSERT INTO payments(invoice_id,amount) VALUES (1,-50)`],
    ["CHECK · unknown order stage", `INSERT INTO orders(customer,items,stage) VALUES ('c','[]','teleported')`],
    ["CHECK · unknown order status", `INSERT INTO orders(customer,items,status) VALUES ('c','[]','Vibing')`],
    ["CHECK · unknown user role", `INSERT INTO users(email,name,role) VALUES ('r@x.pk','N','emperor')`],
    ["CHECK · unknown task priority (the column that was free text for months)", `INSERT INTO tasks(title,priority) VALUES ('t','URGENT-ish')`],
    ["CHECK · capitalised task priority ('High' was a real caller value)", `INSERT INTO tasks(title,priority) VALUES ('t2','High')`],
    /* These two are the bug 006 exists to stop. Before normalisation they were
       accepted verbatim, and "closed" in particular looked resolved while the
       restock branch — which compares against the literal "Closed" — never ran. */
    ["CHECK · lowercase return state ('closed' silently skipped the restock)", `INSERT INTO returns(ref,customer,reason,state) VALUES ('T-R1','c','r','closed')`],
    ["CHECK · unknown return state (the value the seed never actually wrote)", `INSERT INTO returns(ref,customer,reason,state) VALUES ('T-R2','c','r','Approved')`],
    /* The invoice statuses that must never reach the column. 'Overdue' is the
       interesting one: it is a legitimate status to a reader, derived on read,
       and storing it would freeze a value that has to stay recomputed. */
    ["CHECK · lowercase invoice status ('cancelled' is what a client sends)", `INSERT INTO invoices(ref,customer,items,status) VALUES ('T-I1','c','[]','cancelled')`],
    ["CHECK · stored invoice status 'Overdue' (derived on read, never stored)", `INSERT INTO invoices(ref,customer,items,status) VALUES ('T-I2','c','[]','Overdue')`],
    ["CHECK · stored invoice status 'Draft' (no code path creates one)", `INSERT INTO invoices(ref,customer,items,status) VALUES ('T-I3','c','[]','Draft')`],
    ["CHECK · project progress above 100", `INSERT INTO projects(ref,name,client_id,progress) VALUES ('T-P2','p',1,140)`],
    ["CHECK · negative BOQ quantity", `INSERT INTO boq_lines(project_id,description,qty) VALUES (1,'d',-2)`],
    ["CHECK · quote version below 1", `INSERT INTO quotes(ref,customer,items,version) VALUES ('T-Q2','c','[]',0)`],
    ["PK  · duplicate client link", `INSERT INTO client_links(client_id,entity,entity_id) VALUES (1,'lead',1);
                                     INSERT INTO client_links(client_id,entity,entity_id) VALUES (1,'lead',1)`],
  ];
  for (const [label, sql] of rejects) {
    let rejected = false;
    try { await db.exec(sql); } catch { rejected = true; }
    check(rejected, label, rejected ? "" : "insert was accepted — constraint missing or wrong");
  }

  /* Vocabulary agreement: a CHECK allow-list that omits a value the code
     writes turns a working endpoint into a 500. This is not hypothetical — an
     allow-list of inbound/outbound broke every public lead submission because
     the app also writes direction="system". Where the constraint and the code
     disagree, the code is the specification. */
  console.log("\n[integrity] constraint vocabulary matches what the code writes");
  const serverSrc = read("apps/api/server.mjs");
  const vocab = [
    { constraint: "messages_direction_known", table: "messages", column: "direction",
      code: new Set([...serverSrc.matchAll(/direction\s*[:=]\s*"([a-z]+)"/g)].map((m) => m[1])
        .concat([...serverSrc.matchAll(/\["(inbound)", "(outbound)"\]/g)].flatMap((m) => [m[1], m[2]]))) },
    { constraint: "orders_stage_known", table: "orders", column: "stage",
      code: new Set([...serverSrc.matchAll(/stages? = \[([^\]]+)\]/g)]
        .flatMap((m) => [...m[1].matchAll(/"([a-z]+)"/g)].map((x) => x[1]))) },
    { constraint: "users_role_known", table: "users", column: "role",
      code: new Set((serverSrc.match(/const ROLE_CAPS = \{([\s\S]*?)\};/) ?? ["", ""])[1]
        .split("\n").map((l) => (l.match(/^\s{2}([a-z]+):/) ?? [])[1]).filter(Boolean)) },
    { constraint: "site_visits_status_known", table: "site_visits", column: "status",
      code: new Set([...(serverSrc.match(/const SITE_VISIT_STATUSES = new Set\(\[([^\]]+)\]/) ?? ["", ""])[1]
        .matchAll(/"([A-Za-z]+)"/g)].map((m) => m[1])) },
    /* tasks.priority is the reason this whole check exists. The column was free
       text for months because the code wrote low/normal/high while callers sent
       "High" and "medium" — a constraint over any one casing would have rejected
       working requests. The API now normalises at the boundary, so the set it can
       produce and the set the constraint allows must be the same set. */
    { constraint: "tasks_priority_known", table: "tasks", column: "priority",
      code: new Set([...(serverSrc.match(/const TASK_PRIORITIES = new Set\(\[([^\]]+)\]/) ?? ["", ""])[1]
        .matchAll(/"([a-z]+)"/g)].map((m) => m[1])) },
    /* returns.state is the inverse lesson. The column was open for a reason 002
       invented — that the seed wrote Approved/Rejected/Completed — and nothing
       ever did. Meanwhile PATCH keys its restock on the literal string "Closed",
       so an off-vocabulary state resolved the RMA and skipped the inventory
       write. The set the normaliser can produce and the set this CHECK allows
       must be identical, or the restock silently stops again. */
    { constraint: "returns_state_known", table: "returns", column: "state",
      code: new Set([...(serverSrc.match(/const RETURN_STATES = \[([^\]]+)\]/) ?? ["", ""])[1]
        .matchAll(/"([A-Za-z ]+)"/g)].map((m) => m[1])) },
    /* invoices.status is the only one where the column holds less than the API
       exposes. "Overdue" is derived at read time by decorateInvoice and must
       never be stored; "Draft" is offered by the dashboard's union but no code
       path creates one. Deriving this set from INVOICE_STORED_STATUSES — the
       list the normaliser validates against — is what keeps the constraint and
       the code from drifting apart. */
    { constraint: "invoices_status_known", table: "invoices", column: "status",
      code: new Set([...(serverSrc.match(/const INVOICE_STORED_STATUSES = \[([^\]]+)\]/) ?? ["", ""])[1]
        .matchAll(/"([A-Za-z ]+)"/g)].map((m) => m[1])) },
  ];
  for (const v of vocab) {
    const def = (await q(`SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = $1`, [v.constraint]))[0]?.def;
    if (!def) { check(false, `${v.constraint} exists`); continue; }
    const allowed = new Set([...def.matchAll(/'([^']+)'/g)].map((m) => m[1]));
    const missing = [...v.code].filter((val) => val && !allowed.has(val));
    check(missing.length === 0, `${v.constraint} allows every value the code writes`, missing.length ? `code writes ${missing.join(", ")} but the constraint forbids it` : "");
  }

  console.log("\n[integrity] cascade and history behaviour");
  await db.exec(`INSERT INTO page_blocks(page_id,type) VALUES (1,'hero'); INSERT INTO page_versions(page_id,snapshot) VALUES (1,'{}')`);
  await db.exec(`DELETE FROM pages WHERE id=1`);
  const orphans = (await q(`SELECT (SELECT count(*)::int FROM page_blocks)+(SELECT count(*)::int FROM page_versions) n`))[0].n;
  check(orphans === 0, "deleting a page cascades to its blocks and versions", `${orphans} orphaned row(s)`);

  console.log("\n[integrity] migration ledger");
  const ledger = await q(`SELECT version FROM schema_migrations ORDER BY version`);
  check(ledger.length === files.length, `ledger records all ${files.length} migrations`, `recorded ${ledger.length}`);

  /* Re-running must be a no-op: the guards are idempotent. */
  for (const f of files) await db.exec(read(path.join("scripts", "migrations", f)));
  const fkAfter = (await q(`SELECT count(*)::int n FROM pg_constraint WHERE contype='f' AND connamespace='public'::regnamespace`))[0].n;
  check(fkAfter === inv.fks, "re-applying migrations is idempotent", `${inv.fks} → ${fkAfter} foreign keys`);
} catch (error) {
  problems.push(`rehearsal crashed: ${error instanceof Error ? error.message : String(error)}`);
  console.error(`\n✗ ${error instanceof Error ? error.stack : String(error)}`);
} finally {
  try { await db?.close(); } catch { /* nothing to close */ }
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n✗ ${problems.length} integrity problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  process.exit(1);
}
console.log("\n✓ integrity rehearsal passed — schema, constraints and ledger all behave.\n");
