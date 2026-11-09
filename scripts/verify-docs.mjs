#!/usr/bin/env node
/**
 * Keeps documentation honest about numbers.
 *
 * The previous documentation drifted badly enough to mislead a full architecture
 * review: README claimed 87 routes and 96 QA checks, the master plan opened by
 * declaring that main contained "exactly one file", and docs/README.md pointed at
 * a path that does not exist. Every one of those was true once.
 *
 * This script re-derives each fact from source and compares it with what the docs
 * say. It fails when they disagree, the same way a test does.
 *
 * Run: node scripts/verify-docs.mjs
 *      node scripts/verify-docs.mjs --fix   (rewrite the state file's counts)
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (p) => fs.readFileSync(path.join(ROOT, p), "utf8");
const count = (re, src) => (src.match(re) ?? []).length;

/* ---------- derive the truth from source ---------- */
const serverSrc = read("apps/api/server.mjs");
const pgSrc = read("scripts/schema.postgres.sql");
const dashApp = read("apps/dashboard/src/App.tsx");
const shopApp = read("apps/storefront/src/App.tsx");
const seed = JSON.parse(read("apps/api/seed-data.json"));

/* Strip SQL comments before counting. Without this, the sentence
   "-- … must come after every CREATE TABLE above" matched the CREATE TABLE
   pattern and yielded a fictional table called "above", inflating the count
   by one. A guard that a comment can fool is worse than no guard: it reports
   agreement with confidence it has not earned. */
const stripSqlComments = (src) => src.replace(/\/\*[\s\S]*?\*\//g, "").replace(/--[^\n]*/g, "");

/* Count DISTINCT table names. apps/api/server.mjs creates quote_versions and
   site_visits twice (base schema, then an idempotent upgrade block), so a raw
   match count overstated the SQLite side by two. */
const tableNames = (src, re) => new Set([...stripSqlComments(src).matchAll(re)].map((m) => m[m.length - 1].toLowerCase()));

/* The PostgreSQL schema is the base file PLUS every numbered migration. New
   tables are added by migration, never by editing the base schema, so comparing
   only the base file against the API reports every new table as a phantom. */
const migrationFiles = (() => {
  const dir = path.join(ROOT, "scripts", "migrations");
  return fs.existsSync(dir) ? fs.readdirSync(dir).filter((f) => f.endsWith(".sql")).sort() : [];
})();
const migrationSources = migrationFiles
  .map((f) => fs.readFileSync(path.join(ROOT, "scripts", "migrations", f), "utf8"))
  .join("\n");
const pgTableSet = tableNames(pgSrc + "\n" + migrationSources, /CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-z_][a-z0-9_]*)/gi);
const sqliteTableSet = tableNames(serverSrc, /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([a-z_][a-z0-9_]*)/gi);

/* Constraint names, derived exactly the way test-integrity.mjs derives them —
   which needs BOTH declaration styles in this repo:

     002 declares its constraints as ('table', 'name', 'expr') tuples, and the
     pattern must require a CHECK-style suffix or every foreign-key column is
     collected as a constraint.

     003+ declares them inline as `CONSTRAINT <name> CHECK (...)` on a table
     definition.

   Counting only the first style reported 24 when the truth is 28. The four
   missing names were all in the stock ledger, which uses the second style. */
const checkNames = (src) => new Set([
  ...[...src.matchAll(/'\w+',\s*'(\w+_(?:known|boolean|positive|non_negative|range))'/g)].map((m) => m[1]),
  ...[...src.matchAll(/CONSTRAINT\s+(\w+)\s+CHECK/gi)].map((m) => m[1]),
]);

const facts = {
  migrations: migrationFiles.length,
  checkConstraints: checkNames(migrationSources).size,
  apiRoutes: count(/^app\.(get|post|patch|put|delete)\(/gm, serverSrc),
  pgTables: pgTableSet.size,
  sqliteTables: sqliteTableSet.size,
  dashboardRoutes: count(/<Route[\s>]/g, dashApp),
  storefrontRoutes: count(/<Route[\s>]/g, shopApp),
  products: seed.products.length,
  materials: seed.materials.length,
  services: seed.services.length,
};

/* ---------- compare with the documented numbers ---------- */
const STATE = "docs/agency-os/00-CURRENT-STATE.md";
const doc = read(STATE);
const problems = [];

/** Pull "| label | **N** |" style numbers out of the markdown table. */
function docNumber(pattern, label) {
  const m = doc.match(pattern);
  if (!m) problems.push(`${STATE}: could not find the "${label}" figure`);
  return m ? Number(m[1].replace(/[^\d]/g, "")) : null;
}

const documented = {
  migrations: docNumber(/\|\s*Migrations applied\s*\|\s*\*{0,2}(\d+)/, "Migrations applied"),
  checkConstraints: docNumber(/\|\s*CHECK constraints\s*\|\s*\*{0,2}(\d+)/, "CHECK constraints"),
  apiRoutes: docNumber(/\|\s*API route handlers\s*\|\s*\*{0,2}(\d+)/, "API route handlers"),
  dashboardRoutes: docNumber(/\|\s*Dashboard routes\s*\|\s*\*{0,2}(\d+)/, "Dashboard routes"),
  storefrontRoutes: docNumber(/\|\s*Storefront routes\s*\|\s*\*{0,2}(\d+)/, "Storefront routes"),
  pgTables: docNumber(/\|\s*PostgreSQL tables\s*\|\s*\*{0,2}(\d+)/, "PostgreSQL tables"),
  sqliteTables: docNumber(/\|\s*SQLite tables\s*\|\s*\*{0,2}(\d+)/, "SQLite tables"),
};

for (const [key, was] of Object.entries(documented)) {
  if (was === null) continue;
  if (was !== facts[key]) problems.push(`${STATE}: ${key} documented as ${was}, actual ${facts[key]}`);
}

/* The seed line is prose, so use a looser pattern. */
const seedLine = doc.match(/\*\*(\d+) products · (\d+) materials · (\d+) services\*\*/);
if (!seedLine) problems.push(`${STATE}: could not find the seed catalogue figures`);
else {
  const [p, m, s] = seedLine.slice(1).map(Number);
  if (p !== facts.products) problems.push(`${STATE}: seed products documented as ${p}, actual ${facts.products}`);
  if (m !== facts.materials) problems.push(`${STATE}: seed materials documented as ${m}, actual ${facts.materials}`);
  if (s !== facts.services) problems.push(`${STATE}: seed services documented as ${s}, actual ${facts.services}`);
}

/* ---------- the specific drift the review found ---------- */
const README = "README.md";
const readme = read(README);
const stale = [
  [/\b87 routes\b/, "README claims 87 API routes"],
  [/\b96 checks\b/, "README claims 96 QA checks"],
  [/React 18 · Vite 5/, "README claims React 18 / Vite 5"],
];
for (const [re, why] of stale) {
  if (re.test(readme)) problems.push(`${README}: ${why} (stale)`);
}

/* The two schemas are maintained by hand, so they drift. They must describe the
   same tables: scripts/pg-migrate.mjs copies between them by name, and a table
   present in one only is either data that silently will not migrate, or a
   PostgreSQL table the API will never read. */
for (const t of pgTableSet) if (!sqliteTableSet.has(t)) problems.push(`scripts/schema.postgres.sql defines "${t}", which apps/api/server.mjs never creates`);
for (const t of sqliteTableSet) if (!pgTableSet.has(t)) problems.push(`apps/api/server.mjs creates "${t}", which scripts/schema.postgres.sql does not define`);

const PLAN = "docs/product/MASTER-PLAN.md";
if (/main.? contains exactly one file/i.test(read(PLAN))) {
  problems.push(`${PLAN}: still claims main contains only one file`);
}

/* Relative markdown links must resolve. Broken links are how the audits index
   ended up pointing at audits/docs/audits/... */
const docsRoot = path.join(ROOT, "docs");
for (const file of walk(docsRoot)) {
  if (!file.endsWith(".md")) continue;
  const rel = path.relative(ROOT, file);
  const body = fs.readFileSync(file, "utf8");
  for (const m of body.matchAll(/\[[^\]]*\]\(([^)\s]+)\)/g)) {
    const href = m[1];
    if (/^[a-z]+:/i.test(href) || href.startsWith("#")) continue;
    const target = path.resolve(path.dirname(file), href.split("#")[0]);
    if (!fs.existsSync(target)) problems.push(`${rel}: broken link → ${href}`);
  }
}

function* walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) yield* walk(p);
    else yield p;
  }
}

/* ---------- report ---------- */
console.log("Derived from source:");
for (const [k, v] of Object.entries(facts)) console.log(`  ${k.padEnd(18)} ${v}`);

if (problems.length) {
  console.error(`\n${problems.length} documentation problem(s):`);
  for (const p of problems) console.error("  ✗ " + p);
  console.error("\nFix the documents, or the numbers they quote. Documentation that");
  console.error("disagrees with the code is worse than none — it gets acted on.");
  process.exit(1);
}
console.log("\nDocumentation matches the code.");
