#!/usr/bin/env node
/**
 * Woodex pre-commit/CI secret scan (PRD §18/§25 — standing rule after Phase-0 findings).
 * Scans git-tracked files for credential patterns. Exit 1 on hit (values never printed).
 */
import { execSync } from "node:child_process";

const PATTERNS = [
  [/sk_live_[A-Za-z0-9]{8,}/, "Stripe live key"],
  [/sk_test_[A-Za-z0-9]{8,}/, "Stripe test key"],
  [/sbp_[A-Za-z0-9_\-]{16,}/, "Supabase PAT"],
  [/eyJ[A-Za-z0-9_\-]{20,}\.[A-Za-z0-9_\-]{10,}\.?[A-Za-z0-9_\-]*/, "JWT-shaped token"],
  [/["']?service_role["']?\s*[:=]\s*["'][^"']{20,}/, "Supabase service_role key"],
  [/AWS_SECRET_ACCESS_KEY\s*=\s*[A-Za-z0-9/+=]{20,}/, "AWS secret"],
  [/xox[baprs]-[A-Za-z0-9-]{10,}/, "Slack token"],
  [/-----BEGIN (RSA |EC |OPENSSH |)PRIVATE KEY-----/, "private key block"],
];
const ALLOW = new Set(["docs/PHASE0-AUDIT-REPORT.md", "scripts/secret-scan.mjs"]); // files that describe findings, not carry them

let files = [];
try {
  files = execSync("git ls-files", { encoding: "utf8" }).split("\n").filter(Boolean);
} catch { console.error("not a git repo"); process.exit(2); }

const hits = [];
for (const f of files) {
  if (ALLOW.has(f) || f.includes("node_modules") || /\.(jpg|png|webp|gif|ico|woff2?)$/.test(f)) continue;
  let text;
  try { text = execSync(`git show HEAD:${f} 2>/dev/null || cat "${f}"`, { encoding: "utf8", maxBuffer: 20e6 }); }
  catch { continue; }
  if (/eyJ[A-Za-z0-9_\-]{20,}\.eyJ/.test(text) && /\.md$|\.example|LICENSE/.test(f) === false) {
    // allow public anon keys only when marked as anon in the same file
    if (!/anon|publishable/i.test(text)) hits.push([f, "JWT-shaped token"]);
    continue;
  }
  for (const [re, label] of PATTERNS) {
    if (re.test(text)) { hits.push([f, label]); break; }
  }
}
if (hits.length) {
  console.error("⛔ secret-scan: patterns found in:");
  for (const [f, label] of hits) console.error(`   ${f} → ${label}`);
  console.error("   (values not printed) — remove, rotate the credential, then retry.");
  process.exit(1);
}
console.log("✓ secret-scan clean (" + files.length + " tracked files)");
