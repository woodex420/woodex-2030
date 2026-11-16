// Which tables does `pg-migrate.mjs --truncate` silently empty?
// The copy loop runs `TRUNCATE <t> CASCADE` immediately before copying each table, in TABLES order.
// CASCADE also empties every table with a foreign key to <t>. If such a child was copied EARLIER in the loop, its rows are lost.
import path from "node:path"; import { fileURLToPath } from "node:url";
const HERE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
import fs from "node:fs";
const ROOT = process.argv[2] || HERE_ROOT;
const mig = fs.readFileSync(ROOT + "/scripts/pg-migrate.mjs", "utf8");
const order = [.../const TABLES\s*=\s*\[([\s\S]*?)\];/.exec(mig)[1].matchAll(/"([a-z_]+)"/g)].map((m) => m[1]);
const sql = [fs.readFileSync(ROOT + "/scripts/schema.postgres.sql", "utf8"), ...fs.readdirSync(ROOT + "/scripts/migrations").filter((f) => f.endsWith(".sql")).sort().map((f) => fs.readFileSync(ROOT + "/scripts/migrations/" + f, "utf8"))].join("\n").replace(/--.*$/gm, "");
const fks = []; let current = null;
for (const line of sql.split("\n")) {
  const c = /CREATE TABLE(?: IF NOT EXISTS)?\s+"?([a-z_]+)"?/i.exec(line); if (c) current = c[1];
  const a = /ALTER TABLE(?: ONLY)?(?: IF EXISTS)?\s+"?([a-z_]+)"?/i.exec(line); if (a) current = a[1];
  for (const r of line.matchAll(/REFERENCES\s+"?([a-z_]+)"?/gi)) if (current) fks.push({ child: current, parent: r[1] });
}
const uniq = [...new Map(fks.filter((f) => f.child !== f.parent).map((f) => [f.child + ">" + f.parent, f])).values()];
const pos = (t) => order.indexOf(t);
const lost = uniq.filter((f) => pos(f.child) > -1 && pos(f.parent) > -1 && pos(f.child) < pos(f.parent));
console.log(`tables in copy order: ${order.length} | distinct foreign keys parsed from the PostgreSQL schema: ${uniq.length}`);
console.log(`children copied BEFORE a parent they reference (emptied by the parent's TRUNCATE ... CASCADE): ${new Set(lost.map((f) => f.child)).size}`);
for (const f of lost) console.log(`  ${f.child} (copied #${pos(f.child) + 1})  ->  ${f.parent} (truncated #${pos(f.parent) + 1})`);
