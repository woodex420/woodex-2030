// Static derivation: which tables have an INSERT whose generated id is read back (lastInsertRowid),
// and which of those are missing from RETURN_ID_TABLES (so PostgreSQL returns 0 for them)?
import path from "node:path"; import { fileURLToPath } from "node:url";
const HERE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
import fs from "node:fs";
const ROOT = process.argv[2] || HERE_ROOT;
const adapters = fs.readFileSync(ROOT + "/apps/api/db-adapters.mjs", "utf8");
const listSrc = /RETURN_ID_TABLES\s*=\s*new Set\(\[([\s\S]*?)\]\)/.exec(adapters)[1].replace(/\/\/.*$/gm, "");
const listed = new Set([...listSrc.matchAll(/"([a-z_]+)"/g)].map((m) => m[1]));
const files = ["apps/api/server.mjs", "apps/api/public-routes.mjs", "apps/api/shop-data.mjs"].filter((f) => fs.existsSync(ROOT + "/" + f));
const reads = []; // {file, line, table}
for (const f of files) {
  const lines = fs.readFileSync(ROOT + "/" + f, "utf8").split("\n");
  lines.forEach((ln, i) => {
    if (!/lastInsertRowid/.test(ln)) return;
    // nearest preceding INSERT INTO within 25 lines (same line first)
    let table = null;
    for (let j = i; j >= Math.max(0, i - 25) && !table; j--) { const all = [...lines[j].matchAll(/INSERT\s+(?:OR\s+IGNORE\s+)?INTO\s+["`]?([a-z_]+)/gi)]; if (all.length) table = all[all.length - 1][1].toLowerCase(); }
    reads.push({ file: f, line: i + 1, table: table || "?" });
  });
}
const byTable = new Map(); for (const r of reads) { if (!byTable.has(r.table)) byTable.set(r.table, []); byTable.get(r.table).push(r.line); }
const missing = [...byTable.keys()].filter((t) => t !== "?" && !listed.has(t)).sort();
const covered = [...byTable.keys()].filter((t) => listed.has(t)).sort();
const out = { commit: process.argv[3] || "", listedCount: listed.size, readSites: reads.length, tablesRead: byTable.size, unresolved: (byTable.get("?") || []).length, coveredTables: covered, missingTables: missing, missingDetail: Object.fromEntries(missing.map((t) => [t, byTable.get(t)])) };
if (process.argv.includes("--json")) console.log(JSON.stringify(out, null, 1));
else { console.log(`RETURN_ID_TABLES lists ${out.listedCount} tables | lastInsertRowid read at ${out.readSites} sites across ${out.tablesRead} tables (${out.unresolved} sites unresolved)`);
  console.log(`covered (${covered.length}): ${covered.join(", ")}`); console.log(`MISSING (${missing.length}): ${missing.map((t) => `${t}[${byTable.get(t).length}]`).join(", ")}`); }
