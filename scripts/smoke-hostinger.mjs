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
 *   2. /dashboard serves the dashboard shell, not the storefront — and every
 *      script and stylesheet either shell names is delivered as a script or a
 *      stylesheet
 *   3. /api/* still answers as JSON (the static layer must never swallow it)
 *   4. /sitemap.xml and /robots.txt come from the API, not from dist/
 *   5. the storefront never returns the "Loading page…" fallback
 *
 * Point 5 is the one that matters: a static server can happily serve a build
 * whose prerender silently failed, and nothing else would notice.
 *
 * The second half of point 2 was added after this test passed for a deployment
 * in which the dashboard could not start: the server answered every path under
 * /dashboard/ with the shell, including the dashboard's own bundle, so the
 * browser received HTML where it had asked for JavaScript and showed a blank
 * page. A shell is only half a page; this test now follows what it references.
 */
import { spawn } from "node:child_process";
import { mkdtempSync, rmSync, existsSync, readFileSync } from "node:fs";
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
  // "FurnitureStore" is what the shop's own shell tells a search engine about the business: the dashboard's must not carry it.
  check("the shop's shell is told apart from the dashboard's", /"@type":\s*"FurnitureStore"/.test((await get("/")).body));
  check("/dashboard serves the dashboard shell", dash.status === 200 && /<div id="root">/.test(dash.body) && !/"@type":\s*"FurnitureStore"/.test(dash.body));
  const dashDeep = await get("/dashboard/orders");
  check("/dashboard/orders falls through to the SPA", dashDeep.status === 200 && /<div id="root">/.test(dashDeep.body));

  /* A shell is not a page until its bundle arrives. Fetch what each shell
     references and require the right kind of file back. */
  const referenced = (html) => ({
    scripts: [...html.matchAll(/<script\b[^>]*\bsrc="([^"]+)"/g)].map((m) => m[1]).filter((u) => u.startsWith("/")),
    styles: [...html.matchAll(/<link\b[^>]*\brel="stylesheet"[^>]*\bhref="([^"]+)"/g)].map((m) => m[1]).filter((u) => u.startsWith("/")),
  });
  const home = await get("/");
  for (const [label, shell] of [["dashboard", dash.body], ["storefront", home.body]]) {
    const { scripts, styles } = referenced(shell);
    check(`the ${label} shell names a script to load`, scripts.length > 0);
    for (const url of scripts) {
      const file = await get(url);
      check(`${label} script ${url} is served as JavaScript`, file.status === 200 && /javascript/.test(file.type) && !/<div id="root">/.test(file.body), `${file.status} ${file.type}`);
    }
    for (const url of styles) {
      const file = await get(url);
      check(`${label} stylesheet ${url} is served as CSS`, file.status === 200 && /text\/css/.test(file.type), `${file.status} ${file.type}`);
    }
  }
  const staleBundle = await get("/dashboard/assets/index-0000000.js");
  check("a dashboard bundle that does not exist is a 404, not the shell", staleBundle.status === 404 && !/<div id="root">/.test(staleBundle.body), `${staleBundle.status} ${staleBundle.type}`);
  const dashIcon = await get("/dashboard/favicon.svg");
  check("the dashboard's favicon is an image", dashIcon.status === 200 && dashIcon.type.startsWith("image/"), `${dashIcon.status} ${dashIcon.type}`);

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

  /* ---- an address that was never prerendered ---- */
  /* A page of one thing (a product, a series, a published page) is made by the shop in the browser: the server
     hands it the shell with 200. An address the shop's router has no page for gets the same shell, so that the
     visitor sees the shop's "page not found", and 404, so that a machine is told the truth. Until 25 November
     2026 every address there is answered 200 (audit finding AU-11). */
  for (const known of ["/shop/a-product-nobody-prerendered", "/series/some-series", "/p/a-published-page", "/order-status", "/checkout"]) {
    const page = await get(known);
    check(`${known} gets the shop's shell with 200`, page.status === 200 && page.type.includes("text/html") && /<div id="root">/.test(page.body), `${page.status} ${page.type}`);
  }
  for (const unknown of ["/this-route-does-not-exist", "/shop/a/b", "/wp-login", "/series/x/y/z"]) {
    const page = await get(unknown);
    check(`${unknown} answers 404 with the shop's shell`, page.status === 404 && page.type.includes("text/html") && /<div id="root">/.test(page.body), `${page.status} ${page.type}`);
  }
  /* The list the server answers from (apps/api/public-routes.mjs) and the shop's own router are one list. */
  {
    const { SHOP_ADDRESSES } = await import("../apps/api/public-routes.mjs");
    const router = [...readFileSync(path.join(ROOT, "apps/storefront/src/App.tsx"), "utf8").matchAll(/<Route\s+path="([^"]+)"/g)].map((match) => match[1]).filter((address) => address !== "*");
    const missing = router.filter((address) => !SHOP_ADDRESSES.includes(address));
    const extra = SHOP_ADDRESSES.filter((address) => !router.includes(address));
    check("every page of the shop's router is an address the server knows, and no other", router.length > 20 && missing.length === 0 && extra.length === 0, `router ${router.length}; not in the server's list: ${missing.join(", ") || "none"}; only in the server's list: ${extra.join(", ") || "none"}`);
  }
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
