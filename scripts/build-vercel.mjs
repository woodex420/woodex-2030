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
