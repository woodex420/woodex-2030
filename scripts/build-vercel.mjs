#!/usr/bin/env node
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const npm = process.platform === "win32" ? "npm.cmd" : "npm";

function run(args, env = process.env) {
  const result = spawnSync(npm, args, { cwd: ROOT, env, stdio: "inherit", shell: process.platform === "win32" });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status ?? 1);
}

run(["-w", "@woodex/dashboard", "run", "build"], { ...process.env, VITE_BASE_PATH: "/dashboard/" });
run(["-w", "@woodex/storefront", "run", "build"]);

/* Fail the build, not production. Two defects shipped as a READY deployment and
   left every /api request answering 405 while the database stayed empty:
   the seed snapshot drifting from the storefront sources, and /api not being
   rewritten to the function. Both are cheap to check here. */
run(["run", "seed:data:check"]);
run(["run", "check:deploy"]);
/* The dashboard is served under /dashboard but is also reached at /admin via a
   rewrite. A path outside the router's basename renders nothing at all — zero
   DOM, no error — which shipped as a blank white screen after a valid sign-in. */
run(["run", "test:router"]);
/* A SQLite→PostgreSQL cutover must not silently skip a table. Four project
   tables were missing from the migrator's list until this check existed. */
run(["run", "check:migration"]);
/* Documentation that disagrees with the code gets acted on, and misleads. The
   previous drift claimed 87 routes, 96 checks and a single-file main. */
run(["run", "docs:verify"]);
/* The editor offers block types that three files must agree on. A registry
   entry with no storefront case publishes a silently empty section. */
run(["run", "test:blocks"]);
/* Constraints and the migration ledger, rehearsed on a throwaway Postgres.
   Proves the constraints exist AND that each one rejects the data it is
   supposed to reject — an unenforced constraint looks identical to a correct
   one until something tries to break it. */
run(["run", "test:integrity"]);
/* The paths a signed-out visitor and a crawler must reach, tested at the path
   production actually delivers them on. /sitemap.xml passed every local check
   for weeks while returning 401 in production, because production rewrites it
   to /api/sitemap and nothing exercised that spelling. */
run(["run", "test:public"]);
/* Stock must reconcile against its ledger, and selling must actually consume
   it. Before Phase B, checkout checked availability and never decremented, so a
   99-unit product could be sold without limit. */
run(["run", "test:stock"]);

const output = path.join(ROOT, "dist");
fs.rmSync(output, { recursive: true, force: true });
fs.mkdirSync(output, { recursive: true });
fs.cpSync(path.join(ROOT, "apps/storefront/dist"), output, { recursive: true });
fs.cpSync(path.join(ROOT, "apps/dashboard/dist"), path.join(output, "dashboard"), { recursive: true });
fs.cpSync(path.join(ROOT, "apps/api/public/img"), path.join(output, "img"), { recursive: true });

for (const required of ["index.html", "dashboard/index.html", "robots.txt", "img"]) {
  if (!fs.existsSync(path.join(output, required))) throw new Error(`Vercel output is missing ${required}`);
}
/* Prerender the public storefront routes so crawlers get real HTML.
   Runs after the dist assembly (it needs the shell + the copied catalogue). */
{
  const pr = spawnSync(process.execPath, ["scripts/prerender.mjs"], { cwd: ROOT, env: process.env, stdio: "inherit" });
  if (pr.error) throw pr.error;
  if (pr.status !== 0) process.exit(pr.status ?? 1);
}

console.log("[vercel-build] Storefront at /, Dashboard at /dashboard, shared API imagery at /img. Public routes prerendered.");
