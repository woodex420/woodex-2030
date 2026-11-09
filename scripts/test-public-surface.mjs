#!/usr/bin/env node
/**
 * Public-surface regression test: the paths a signed-out visitor or a crawler
 * must reach, and the paths that must stay locked.
 *
 *   node scripts/test-public-surface.mjs
 *
 * Why this exists: /sitemap.xml sat on the acceptance list for weeks and failed
 * the whole time. The route was fine — the handler serves correct XML at
 * /sitemap.xml. What failed was the path the request arrives on in production:
 * vercel.json rewrites /sitemap.xml to /api/sitemap, the auth gate compared
 * that against its allow-list, found no match, and answered 401. Every local
 * check passed because every local check used /sitemap.xml — the one spelling
 * production never sees.
 *
 * So this test exercises both spellings against a real server, and asserts the
 * private paths stay private. A fix that opens everything is not a fix.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-public-${process.pid}`);
const problems = [];

/* Every spelling of the sitemap, because three separate production bugs came
   from testing only the spelling someone assumed Vercel delivers. The rewrite
   sends /sitemap.xml to /api/index; Vercel strips the /api prefix for the
   catch-all; api/index.mjs puts it back. Which spelling Express sees is a
   consequence of those two behaviours, so all of them are public and routed —
   the sitemap must not depend on getting that chain right. */
const mustBePublic = [
  ["/sitemap.xml", "application/xml", "sitemap at the root path"],
  ["/api/sitemap", "application/xml", "sitemap, short API spelling"],
  ["/api/sitemap.xml", "application/xml", "the path the catch-all actually delivers"],
  ["/api/health", "application/json", "liveness probe"],
  ["/api/products?limit=1", "application/json", "public catalogue"],
  ["/api/materials", "application/json", "public materials"],
  ["/api/services", "application/json", "public services"],
];

const mustBePrivate = [
  ["/api/leads", "unauthenticated lead list must not be readable"],
  ["/api/users", "user list must require a session"],
  ["/api/invoices", "finance data must require a session"],
  ["/api/stats", "dashboard aggregates must require a session"],
  ["/api/pages", "draft CMS pages must require a session"],
  ["/api/tasks", "internal tasks must require a session"],
  ["/api/clients", "customer book must require a session"],
];

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
  const port = await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`server did not start in 45s:\n${log.slice(-800)}`)), 45_000);
    const onData = (buf) => {
      log += String(buf);
      const m = log.match(/http:\/\/localhost:(\d+)/);
      if (m) { clearTimeout(timer); resolve(Number(m[1])); }
    };
    child.stdout.on("data", onData);
    child.stderr.on("data", onData);
    child.on("exit", (code) => { clearTimeout(timer); reject(new Error(`server exited ${code}:\n${log.slice(-800)}`)); });
  });

  const get = async (p) => {
    const res = await fetch(`http://127.0.0.1:${port}${p}`, { headers: { accept: "*/*" } });
    return { status: res.status, type: res.headers.get("content-type") ?? "", body: await res.text() };
  };

  console.log("\n[public] paths that must be reachable");
  for (const [p, wantType, why] of mustBePublic) {
    let r;
    try { r = await get(p); }
    catch (e) { problems.push(`${p} — request failed: ${e.message}`); console.log(`  ✗ ${p} — request failed`); continue; }
    const okStatus = r.status === 200;
    const okType = r.type.includes(wantType.split(";")[0]);
    if (okStatus && okType) console.log(`  ✓ ${p} → 200 ${wantType} (${why})`);
    else {
      problems.push(`${p} → ${r.status} ${r.type || "(no content-type)"} — expected 200 ${wantType} (${why})`);
      console.log(`  ✗ ${p} → ${r.status} ${r.type || "(none)"}${r.body ? ` · ${r.body.slice(0, 90)}` : ""}`);
    }
  }

  /* The sitemap must be a real sitemap, not an error document with an XML
     content-type — which is how a "passing" check still ships a broken URL.
     Every spelling must also return the SAME document: three paths that each
     pass individually could still be three different pages. */
  const bodies = [];
  for (const p of ["/sitemap.xml", "/api/sitemap", "/api/sitemap.xml"]) {
    const r = await get(p);
    bodies.push([p, r.body]);
    if (!/<urlset[\s>]/.test(r.body)) problems.push(`${p} did not return a <urlset> document`);
    else if (!/<loc>/.test(r.body)) problems.push(`${p} returned a urlset with no <loc> entries`);
    else console.log(`  ✓ ${p} is a real urlset (${(r.body.match(/<loc>/g) ?? []).length} locations)`);
  }
  const [first, ...rest] = bodies;
  for (const [p, body] of rest) {
    if (body !== first[1]) problems.push(`${p} returned a different sitemap than ${first[0]} — one document, three spellings`);
  }
  if (rest.length && rest.every(([, b]) => b === first[1])) {
    console.log(`  ✓ all ${bodies.length} sitemap spellings return an identical document`);
  }

  console.log("\n[public] paths that must stay locked");
  for (const [p, why] of mustBePrivate) {
    const r = await get(p);
    if (r.status === 401 || r.status === 403) console.log(`  ✓ ${p} → ${r.status} (${why})`);
    else {
      problems.push(`${p} → ${r.status} without a token — must be 401/403 (${why})`);
      console.log(`  ✗ ${p} → ${r.status} — EXPOSED (${why})`);
    }
  }
} catch (error) {
  problems.push(`harness failed: ${error.message}`);
  console.error(`\n✗ ${error.message}`);
} finally {
  child?.kill("SIGKILL");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n✗ ${problems.length} public-surface problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  process.exit(1);
}
console.log("\n✓ public surface correct — reachable where it should be, locked where it must be.\n");
