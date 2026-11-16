#!/usr/bin/env node
/**
 * Regenerates docs/furniture-2030/inventory/*.md from the code, so the inventories cannot drift.
 *
 *   node docs/furniture-2030/evidence/generate-inventory.mjs [--db <path to a seeded woodex.db>] [--screens <check-result.json>] [--out <dir>]
 *
 * API routes, access rules and roles come from apps/api/server.mjs (the access rule is computed by
 * evaluating the real capsFor()/isPublic() functions, not by re-describing them).
 * Tables come from a seeded SQLite database (any local run creates one). Read-only.
 */
import fs from "node:fs"; import path from "node:path"; import { fileURLToPath } from "node:url"; import { execSync } from "node:child_process";
const arg = (n) => { const i = process.argv.indexOf(n); return i > -1 ? process.argv[i + 1] : null; };
const ROOT = path.resolve(arg("--root") || path.join(path.dirname(fileURLToPath(import.meta.url)), "..", "..", ".."));
const OUT = path.resolve(arg("--out") || path.join(ROOT, "docs/furniture-2030/inventory")); fs.mkdirSync(OUT, { recursive: true });
const read = (p) => fs.readFileSync(path.join(ROOT, p), "utf8");
const commit = (() => { try { return execSync("git rev-parse --short HEAD", { cwd: ROOT, encoding: "utf8" }).trim(); } catch { return "unknown"; } })();
const stamp = `Generated from the code at commit \`${commit}\` by \`docs/furniture-2030/evidence/generate-inventory.mjs\`. Do not edit by hand; re-run the script.`;
const server = read("apps/api/server.mjs");

/* ---------- modules ---------- */
const MODULES = [
  ["Platform (auth, users, audit)", /^(auth|users|sessions|password_resets|login|throttle|audit|diagnostics|events|health|settings|schema_migrations)/],
  ["Catalogue", /^(products?|categories|collections|materials|services|product_)/],
  ["CRM", /^(leads?|clients?|companies|contacts?|meetings?|activities|attachments?|tasks?|site[-_]visits?)/],
  ["Inbox and messaging", /^(inbox|conversations?|messages?)/],
  ["Quotations", /^(quotes?|quote[-_])/],
  ["Orders and returns", /^(orders?|returns?|order_)/],
  ["Projects and BOQ", /^(projects?|project_|boq)/],
  ["Inventory", /^(stock|warehouses?|storage[-_]locations?)/],
  ["Procurement", /^(suppliers?$|suppliers\/|purchase[-_]orders?|purchase_order|po_|goods[-_]receipts?|goods_receipt)/],
  ["Payables", /^(supplier[-_]invoices?|supplier_invoice)/],
  ["Production and quality", /^(boms?|bom_|job[-_]cards?|job_card|qc)/],
  ["Delivery and installation", /^(deliver|dispatch|installed[-_]assets?|installations?)/],
  ["Support and warranty", /^(tickets?|ticket_|warranty|service[-_]visits?|support)/],
  ["Finance", /^(invoices?|invoice_|payments?|payment[-_]schedules?|credit[-_]notes?|credit_note|expenses?|accounts?|journal|ledger|tax)/],
  ["Website and CMS", /^(pages?|page_|saved[-_]sections?|blocks|theme|media|sitemap|robots)/],
  ["Marketing and analytics", /^(track|target|marketing|stats|analytics|ops)/],
];
const moduleOf = (name) => (MODULES.find(([, re]) => re.test(name)) || ["Other"])[0];

/* ---------- API routes ---------- */
const grab = (startRe) => { const i = server.search(startRe); if (i < 0) throw new Error("not found: " + startRe); let depth = 0, j = server.indexOf("=>", i) + 2, started = false, end = -1;
  for (; j < server.length; j++) { const c = server[j]; if (c === "{" || c === "(") { depth++; started = true; } else if (c === "}" || c === ")") { depth--; } if (started && depth === 0) { end = j + 1; break; } }
  return server.slice(server.indexOf("=", i) + 1, end).trim(); };
const capsFor = eval("(" + grab(/const capsFor = /) + ")");
const isPublic = eval("(" + /const isPublic = ([\s\S]*?);\nconst requestToken/.exec(server)[1] + ")");
const ROLE_CAPS = eval("(" + /const ROLE_CAPS = (\{[\s\S]*?\n\});/.exec(server)[1] + ")");
const routes = [...server.matchAll(/^app\.(get|post|put|patch|delete)\("([^"]+)"/gm)].map((m) => ({ method: m[1].toUpperCase(), path: m[2] }));
const sample = (p) => p.replace(/:([a-zA-Z]+)/g, (_, n) => (/id$/i.test(n) ? "1" : "x"));
for (const r of routes) { const p = sample(r.path); r.public = !!isPublic(r.method, p); r.cap = r.public ? "public" : capsFor(r.method, p); r.module = moduleOf(r.path.replace(/^\/api\//, "").replace(/^\//, "")); }
const roleReach = (role) => { const caps = ROLE_CAPS[role]; return routes.filter((r) => r.public || caps.includes("*") || caps.includes(r.cap) || r.cap === "auth").length; };
let md = `# API route inventory\n\n${stamp}\n\n**${routes.length} routes** in \`apps/api/server.mjs\`. "Access" is what the server's own \`isPublic()\` and \`capsFor()\` return for the route; \`owner-only\` means no capability is mapped, so only the owner role reaches it. Handlers may apply further checks.\n\n## Summary by module\n\n| Module | Routes | Public | Owner-only |\n|---|---|---|---|\n`;
const mods = [...new Set(routes.map((r) => r.module))].sort();
for (const m of mods) { const rs = routes.filter((r) => r.module === m); md += `| ${m} | ${rs.length} | ${rs.filter((r) => r.public).length} | ${rs.filter((r) => r.cap === "owner-only").length} |\n`; }
md += `\n## Summary by access rule\n\n| Access | Routes |\n|---|---|\n`;
const byCap = {}; for (const r of routes) byCap[r.cap] = (byCap[r.cap] || 0) + 1;
for (const [k, v] of Object.entries(byCap).sort((a, b) => b[1] - a[1])) md += `| \`${k}\` | ${v} |\n`;
md += `\n## All routes\n\n| Module | Method | Path | Access |\n|---|---|---|---|\n`;
for (const r of [...routes].sort((a, b) => a.module.localeCompare(b.module) || a.path.localeCompare(b.path) || a.method.localeCompare(b.method))) md += `| ${r.module} | ${r.method} | \`${r.path}\` | \`${r.cap}\` |\n`;
fs.writeFileSync(path.join(OUT, "api-routes.md"), md);

/* ---------- roles ---------- */
const caps = [...new Set(routes.map((r) => r.cap).filter((c) => !["public", "auth", "owner-only"].includes(c)))].sort();
md = `# Roles and capabilities\n\n${stamp}\n\nRoles are fixed in code (\`ROLE_CAPS\` in \`apps/api/server.mjs\`); they are not yet configurable from the dashboard. A capability guards a group of API paths. "Routes reachable" counts API routes the role passes at the gate, including public and sign-in routes.\n\n| Role | Capabilities | Routes reachable (of ${routes.length}) |\n|---|---|---|\n`;
for (const role of Object.keys(ROLE_CAPS)) md += `| ${role} | ${ROLE_CAPS[role].length ? ROLE_CAPS[role].map((c) => "`" + c + "`").join(", ") : "none"} | ${roleReach(role)} |\n`;
md += `\n## Capability to routes\n\n| Capability | Routes | Held by | Example paths |\n|---|---|---|---|\n`;
for (const c of [...caps, "owner-only"]) { const rs = routes.filter((r) => r.cap === c); const held = Object.keys(ROLE_CAPS).filter((role) => ROLE_CAPS[role].includes("*") || ROLE_CAPS[role].includes(c));
  md += `| \`${c}\` | ${rs.length} | ${held.join(", ") || "owner"} | ${[...new Set(rs.map((r) => "`/" + r.path.split("/").slice(1, 3).join("/") + "`"))].slice(0, 6).join(", ")} |\n`; }
const granted = new Set(Object.values(ROLE_CAPS).flat()); const unused = [...granted].filter((c) => c !== "*" && !caps.includes(c));
md += `\nCapabilities granted to a role but not returned by \`capsFor()\` for any route: ${unused.length ? unused.map((c) => "`" + c + "`").join(", ") + " (checked inside handlers, if at all)" : "none"}.\n`;
fs.writeFileSync(path.join(OUT, "roles-and-capabilities.md"), md);

/* ---------- tables ---------- */
const dbPath = arg("--db");
if (dbPath && fs.existsSync(dbPath)) {
  const { DatabaseSync } = await import("node:sqlite"); const db = new DatabaseSync(dbPath, { readOnly: true });
  const adapters = read("apps/api/db-adapters.mjs"); const listed = new Set([.../RETURN_ID_TABLES\s*=\s*new Set\(\[([\s\S]*?)\]\)/.exec(adapters)[1].replace(/\/\/.*$/gm, "").matchAll(/"([a-z_]+)"/g)].map((m) => m[1]));
  const readBack = new Set(); const lines = server.split("\n"); lines.forEach((ln, i) => { if (!/lastInsertRowid/.test(ln)) return; for (let j = i; j >= Math.max(0, i - 25); j--) { const all = [...lines[j].matchAll(/INSERT\s+(?:OR\s+IGNORE\s+)?INTO\s+["`]?([a-z_]+)/gi)]; if (all.length) { readBack.add(all[all.length - 1][1].toLowerCase()); break; } } });
  const pgSql = read("scripts/schema.postgres.sql") + fs.readdirSync(path.join(ROOT, "scripts/migrations")).filter((f) => f.endsWith(".sql")).map((f) => read("scripts/migrations/" + f)).join("\n");
  const tables = db.prepare("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name").all().map((r) => r.name);
  const MONEY = /(price|amount|total|subtotal|cost|value|paid|balance|tax|discount|shipping|debit|credit|fee|rate)/i;
  const info = tables.map((t) => { const cols = db.prepare(`PRAGMA table_info("${t}")`).all(); const fks = db.prepare(`PRAGMA foreign_key_list("${t}")`).all().length; let rows = "?"; try { rows = db.prepare(`SELECT COUNT(*) c FROM "${t}"`).get().c; } catch {}
    return { t, cols, fks, rows, module: moduleOf(t), money: cols.filter((c) => MONEY.test(c.name) && /REAL|NUM|INT|DOUBLE|FLOAT/i.test(c.type || "")), idGap: readBack.has(t) && !listed.has(t) }; });
  md = `# Database tables\n\n${stamp}\n\nRead from a seeded local SQLite database. **${tables.length} tables.** "FKs (SQLite)" is the number of foreign keys SQLite itself declares; PostgreSQL adds its own through \`scripts/migrations\`. "ID gap" marks tables whose generated id the API reads back but which are missing from \`RETURN_ID_TABLES\`, so PostgreSQL returns 0 (see \`../02-AUDIT-FINDINGS.md\`). "Demo rows" is the row count after the local demo seed.\n\n## Summary by module\n\n| Module | Tables | With ID gap |\n|---|---|---|\n`;
  for (const m of [...new Set(info.map((x) => x.module))].sort()) { const xs = info.filter((x) => x.module === m); md += `| ${m} | ${xs.length} | ${xs.filter((x) => x.idGap).length} |\n`; }
  md += `\nSQLite declares ${info.reduce((s, x) => s + x.fks, 0)} foreign keys across all tables. PostgreSQL schema and migrations contain ${(pgSql.match(/\bREFERENCES\b/gi) || []).length} \`REFERENCES\` clauses and ${(pgSql.match(/\bCHECK\s*\(/gi) || []).length} \`CHECK\` clauses.\n\n## All tables\n\n| Module | Table | Columns | FKs (SQLite) | ID gap | Demo rows | Money-like columns and their SQLite types |\n|---|---|---|---|---|---|---|\n`;
  for (const x of [...info].sort((a, b) => a.module.localeCompare(b.module) || a.t.localeCompare(b.t))) md += `| ${x.module} | \`${x.t}\` | ${x.cols.length} | ${x.fks} | ${x.idGap ? "**yes**" : ""} | ${x.rows} | ${x.money.map((c) => `${c.name} ${c.type}`).join(", ")} |\n`;
  md += `\n## Columns\n\nPrimary-key columns are marked \`PK\`; \`!\` marks NOT NULL.\n\n`;
  for (const x of info) md += `- **\`${x.t}\`** — ${x.cols.map((c) => `${c.name} ${c.type || "ANY"}${c.pk ? " PK" : ""}${c.notnull ? " !" : ""}`).join("; ")}\n`;
  fs.writeFileSync(path.join(OUT, "database-tables.md"), md);
  console.log(`tables: ${tables.length} | with ID gap: ${info.filter((x) => x.idGap).length} | SQLite FKs: ${info.reduce((s, x) => s + x.fks, 0)} | money-like columns: ${info.reduce((s, x) => s + x.money.length, 0)} (${info.reduce((s, x) => s + x.money.filter((c) => /REAL|DOUBLE|FLOAT/i.test(c.type)).length, 0)} REAL)`);
} else console.log("tables: skipped (pass --db <seeded woodex.db>)");

/* ---------- dashboard screens ---------- */
const dash = read("apps/dashboard/src/App.tsx"); const side = read("apps/dashboard/src/components/layout/Sidebar.tsx");
const dRoutes = [...dash.matchAll(/<Route\s+(index|path="([^"]+)")\s+element=\{<([A-Za-z0-9]+)[^}]*\}/g)].map((m) => ({ path: m[1] === "index" ? "/" : m[2], component: m[3] }));
const labels = Object.fromEntries([...side.matchAll(/\{[^{}]*?to:\s*"([^"]+)"[^{}]*?label:\s*"([^"]+)"[^{}]*?\}|\{[^{}]*?label:\s*"([^"]+)"[^{}]*?to:\s*"([^"]+)"[^{}]*?\}/g)].map((m) => (m[1] ? [m[1], m[2]] : [m[4], m[3]])));
let screens = {}; const sp = arg("--screens"); if (sp && fs.existsSync(sp)) { const j = JSON.parse(fs.readFileSync(sp, "utf8")); for (const r of j.B?.rows || []) screens[r.route] = r; }
md = `# Dashboard screens\n\n${stamp}\n\n**${dRoutes.length} routes** declared in \`apps/dashboard/src/App.tsx\` (redirects and the not-found route are not listed). "In menu" is the sidebar label. "Browser check" is the result of loading the route in a headless Chromium after signing in as the demo owner: it shows whether the screen renders, not whether every action on it works.${Object.keys(screens).length ? "" : " Browser results were not supplied to this run."}\n\n| Route | Page component | In menu | Browser check |\n|---|---|---|---|\n`;
for (const r of dRoutes.filter((r) => r.path !== "*")) { const s = screens[r.path]; md += `| \`${r.path}\` | ${r.component} | ${labels[r.path] || ""} | ${s ? (s.status === "ok" ? (s.http?.length ? "renders; " + s.http[0].replace(/\|/g, "/") : "renders") : "**" + s.status + "** " + (s.note || "").replace(/\|/g, "/")) : r.path.includes(":") ? "not checked (needs a record id)" : "not checked"} |\n`; }
fs.writeFileSync(path.join(OUT, "dashboard-screens.md"), md);

/* ---------- storefront routes ---------- */
const shop = read("apps/storefront/src/App.tsx"); const sRoutes = [...shop.matchAll(/<Route\s+path="([^"]+)"\s+element=\{<([A-Za-z0-9]+)/g)].map((m) => ({ path: m[1], component: m[2] }));
const pub = [...read("apps/api/public-routes.mjs").matchAll(/\{\s*path:\s*"([^"]+)",\s*title:\s*"([^"]+)"/g)].map((m) => ({ path: m[1], title: m[2] })); const titles = Object.fromEntries(pub.map((p) => [p.path, p.title]));
md = `# Storefront routes\n\n${stamp}\n\n**${sRoutes.length} routes** declared in \`apps/storefront/src/App.tsx\`; **${pub.length} static public routes** listed in \`apps/api/public-routes.mjs\` feed the sitemap and the prerender (product pages are added from the catalogue).\n\n| Route | Page component | In sitemap list | Title used for search engines |\n|---|---|---|---|\n`;
for (const r of sRoutes) md += `| \`${r.path}\` | ${r.component} | ${titles[r.path] ? "yes" : ""} | ${titles[r.path] || ""} |\n`;
const extra = pub.filter((p) => !sRoutes.some((r) => r.path === p.path)); if (extra.length) md += `\nPublic routes in the sitemap list without a literal route above (served by a dynamic or catch-all route): ${extra.map((p) => "`" + p.path + "`").join(", ")}.\n`;
fs.writeFileSync(path.join(OUT, "storefront-routes.md"), md);
console.log(`routes: ${routes.length} (public ${routes.filter((r) => r.public).length}, owner-only ${routes.filter((r) => r.cap === "owner-only").length}) | roles: ${Object.keys(ROLE_CAPS).length} | dashboard routes: ${dRoutes.length} (menu labels matched: ${Object.keys(labels).length}; browser results: ${Object.keys(screens).length}) | storefront routes: ${sRoutes.length} | public list: ${pub.length}`);
console.log("modules by route count:", mods.map((m) => `${m} ${routes.filter((r) => r.module === m).length}`).join(" · "));
