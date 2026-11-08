#!/usr/bin/env node
/**
 * All-in-one (Hostinger Shape B) smoke test.
 *
 *   npm run build && npm run smoke:hostinger
 *
 * Builds nothing itself — it starts `WOODEX_SERVE_STATIC=dist` on a throwaway
 * port with its OWN empty database, then asserts the single-process deployment
 * behaves like production:
 *
 *   1. every prerendered public route returns 200 HTML with a real <title>
 *   2. /dashboard serves the dashboard shell, not the storefront
 *   3. /api/* still answers as JSON (the static layer must never swallow it)
 *   4. /sitemap.xml and /robots.txt come from the API, not from dist/
 *   5. the storefront never returns the "Loading page…" fallback
 *
 * Point 5 is the one that matters: a static server can happily serve a build
 * whose prerender silently failed, and nothing else would notice.
 */
import { spawn } from "node:child_process";
import { mkdtempSync, rmSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const DIST = path.join(ROOT, "dist");
const PORT = Number(process.env.SMOKE_PORT ?? 4180);
const BASE = `http://127.0.0.1:${PORT}`;

if (!existsSync(path.join(DIST, "index.html"))) {
  console.error("[smoke:hostinger] dist/index.html missing — run `npm run build` first.");
  process.exit(1);
}

const failures = [];
const check = (label, ok, detail = "") => {
  if (ok) console.log(`PASS  ${label}${detail ? " · " + detail : ""}`);
  else { console.log(`FAIL  ${label}${detail ? " · " + detail : ""}`); failures.push(label); }
};

const dataDir = mkdtempSync(path.join(tmpdir(), "wx-hostinger-"));
const child = spawn(process.execPath, ["--experimental-sqlite", "apps/api/server.mjs"], {
  cwd: ROOT,
  env: { ...process.env, PORT: String(PORT), WOODEX_SERVE_STATIC: DIST, WOODEX_DATA_DIR: dataDir, NODE_ENV: "test" },
  stdio: ["ignore", "pipe", "pipe"],
});
let log = "";
child.stdout.on("data", (b) => { log += b; });
child.stderr.on("data", (b) => { log += b; });

const stop = () => new Promise((resolve) => {
  if (child.exitCode !== null) return resolve();
  child.once("exit", resolve);
  child.kill("SIGTERM");
  setTimeout(() => { try { child.kill("SIGKILL"); } catch { /* already gone */ } resolve(); }, 4000).unref();
});

async function waitForServer() {
  const deadline = Date.now() + 20000;
  while (Date.now() < deadline) {
    try {
      const r = await fetch(BASE + "/api/health");
      if (r.ok) return true;
    } catch { /* not up yet */ }
    await new Promise((r) => setTimeout(r, 200));
  }
  return false;
}

const get = async (p) => {
  const res = await fetch(BASE + p, { redirect: "manual" });
  const body = await res.text();
  return { status: res.status, type: res.headers.get("content-type") ?? "", body };
};

try {
  if (!(await waitForServer())) {
    console.error("[smoke:hostinger] server did not start\n" + log.slice(-1200));
    process.exit(1);
  }
  check("server serves the static build", /serving static build from/.test(log));

  /* ---- 1 & 5: prerendered routes ---- */
  const routes = ["/", "/about", "/shop", "/contact", "/quotation", "/b2b", "/series", "/warranty"];
  let okRoutes = 0;
  for (const route of routes) {
    const r = await get(route);
    const hasTitle = /<title>[^<]{3,}<\/title>/.test(r.body);
    const fallback = /Loading page/.test(r.body);
    const isHtml = r.type.includes("text/html");
    if (r.status === 200 && isHtml && hasTitle && !fallback) okRoutes += 1;
    else failures.push(`${route} (status ${r.status}${fallback ? ", shows the prerender fallback" : ""}${!hasTitle ? ", no <title>" : ""})`);
  }
  check("prerendered public routes serve real HTML", okRoutes === routes.length, `${okRoutes}/${routes.length} clean`);

  /* ---- 2: dashboard shell ---- */
  const dash = await get("/dashboard");
  check("/dashboard serves the dashboard shell", dash.status === 200 && /<div id="root">/.test(dash.body) && !/Gulberg III/.test(dash.body));
  const dashDeep = await get("/dashboard/orders");
  check("/dashboard/orders falls through to the SPA", dashDeep.status === 200 && /<div id="root">/.test(dashDeep.body));

  /* ---- 3: API untouched ---- */
  const health = await get("/api/health");
  check("/api/health is JSON", health.status === 200 && health.type.includes("application/json"));
  const missing = await get("/api/definitely-not-a-route");
  check("unknown /api path answers JSON, not HTML", missing.type.includes("application/json"));

  /* ---- 4: API owns sitemap + robots ---- */
  const sitemap = await get("/sitemap.xml");
  const locs = (sitemap.body.match(/<loc>/g) ?? []).length;
  check("/sitemap.xml served by the API with URLs", sitemap.status === 200 && sitemap.type.includes("xml") && locs > 40, `${locs} URLs`);
  const robots = await get("/robots.txt");
  check("/robots.txt served with a sitemap line", robots.status === 200 && /Sitemap: https?:\/\/.+sitemap\.xml/.test(robots.body));

  /* ---- static assets ---- */
  const img = await get("/img/hero-office.jpg");
  check("shared imagery is served from the API copy", img.status === 200 && img.type.startsWith("image/"));

  /* ---- deep link that was never prerendered ---- */
  const deep = await get("/this-route-does-not-exist");
  check("unknown page returns the SPA shell (200, not a crash)", deep.status === 200 && deep.type.includes("text/html"));
} finally {
  await stop();
  rmSync(dataDir, { recursive: true, force: true });
}

if (failures.length) {
  console.error(`\n[smoke:hostinger] ${failures.length} failure(s):`);
  for (const f of failures) console.error("  · " + f);
  process.exit(1);
}
console.log("\n[smoke:hostinger] all-in-one deployment verified");
