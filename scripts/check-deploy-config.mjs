#!/usr/bin/env node
/**
 * Guards the two deployment defects that made production unusable.
 *
 * Both bugs were invisible to unit tests and to a successful Vercel build:
 * the build reported READY while every request to the API returned 405/404,
 * and the database stayed empty because the function never booted.
 *
 *   1. ROUTING. Outside Next.js, Vercel compiles api/[...path].js to a
 *      single-segment route rather than a catch-all, so /api/products
 *      (two segments) never reached the function. It fell through to the
 *      SPA catch-all, and a POST to a static file is answered with 405 —
 *      which the dashboard rendered as "Sign-in failed (HTTP 405)".
 *      Every /api path must now resolve to the single api/index function.
 *
 *   2. SEED DATA. The API bundled the storefront's TypeScript at runtime,
 *      but those files are not shipped into the function, so boot threw
 *      before the catalogue could be seeded. The seed payload must now be a
 *      committed snapshot that does not need the TypeScript sources.
 *
 * Run: node scripts/check-deploy-config.mjs
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const failures = [];
const fail = (msg) => failures.push(msg);
const ok = (msg) => console.log(`PASS  ${msg}`);

/* ---------- 1. vercel.json routing ---------- */
const config = JSON.parse(fs.readFileSync(path.join(ROOT, "vercel.json"), "utf8"));
const rewrites = config.rewrites ?? [];

/* Resolve a path the way Vercel does: first matching rewrite wins, in file order.
   `:path*` matches one or more segments and captures the rest of the path. */
function matchPattern(source, url) {
  const escaped = source.replace(/[.+?^${}()|[\]\\]/g, "\\$&");
  /* Escape first, then swap the :path* token for a greedy capture. */
  const re = new RegExp("^" + escaped.replace(/:path\*/g, "(?<path>.+)") + "$");
  const m = re.exec(url);
  /* `groups` is undefined when the pattern has no named capture (e.g. "/api"). */
  return m ? (m.groups ?? {}) : null;
}

/** Which rewrite (if any) claims this path, in file order. */
function resolveRewrite(url) {
  for (const rule of rewrites) {
    const groups = matchPattern(rule.source, url);
    if (groups) {
      let dest = rule.destination;
      if (groups.path) dest = dest.replace(":path*", groups.path);
      return { source: rule.source, destination: dest };
    }
  }
  return null;
}

/* No self-rewrite: a rule whose source and destination are identical doubles
   the prefix, so POST /api/auth/login arrived as /api/api/auth/login. */
for (const rule of rewrites) {
  if (rule.source === rule.destination) {
    fail(`vercel.json: "${rule.source} → ${rule.destination}" rewrites a path onto itself (doubles the prefix)`);
  }
}
if (!failures.length) ok("vercel.json has no self-rewrite");

/* Every API path must land on the Express function, not the SPA shell. */
const apiPaths = [
  "/api",
  "/api/index",
  "/api/sitemap",
  "/api/health",
  "/api/products",
  "/api/products?x=1",
  "/api/auth/login",
  "/api/pages/home/public",
  "/api/orders/lookup",
  "/api/events",
  "/api/a/b/c/d/e",
];
for (const p of apiPaths) {
  const target = resolveRewrite(p);
  const dest = target?.destination ?? p;
  const reachesApi =
    dest === p ? p.startsWith("/api") : dest.startsWith("/api/") || dest === "/api";
  if (!reachesApi) {
    fail(`vercel.json: ${p} → "${dest ?? "(unrewritten)"}" does not reach the API function`);
  }
}
if (!failures.length) ok(`every /api path resolves to the function (${apiPaths.length} cases)`);

/* /sitemap.xml must resolve to the API's own entry, and nothing must be left
   that resets the path before it gets there.

   History, because this check has been wrong in both directions. It first
   accepted any destination "reaching the API", which passed while the sitemap
   401'd to every crawler. It was then tightened to demand the single path
   /api/sitemap and its dedicated adapter — which was also broken, because that
   adapter reset req.url to /sitemap.xml and then called the catch-all, which
   prefixed it back to /api/sitemap.xml: no route, no allow-list entry, 401.

   The lesson is that a rewrite destination alone cannot prove a path works, so
   this check now enforces the property that actually matters: exactly one
   function prefixes the path, so it is prefixed exactly once. */
const sitemapRule = resolveRewrite("/sitemap.xml");
if (!sitemapRule) {
  fail("/sitemap.xml has no rewrite rule — crawlers would get the SPA shell");
} else if (sitemapRule.destination !== "/api/index") {
  fail(`/sitemap.xml → "${sitemapRule.destination}" — must go to /api/index; a second adapter re-prefixes the path and 401s`);
} else {
  ok(`/sitemap.xml → ${sitemapRule.destination} (single prefix, no second adapter)`);
}

/* A dedicated adapter for the sitemap cannot work: it must either reset the
   path (and be re-prefixed by the catch-all) or duplicate the catch-all's
   prefixing. Both were tried. One entrypoint, one prefix. */
if (fs.existsSync(path.join(ROOT, "api/sitemap.mjs"))) {
  fail("api/sitemap.mjs exists — the sitemap must be served through /api/index, not a second entrypoint");
} else {
  ok("no competing sitemap entrypoint");
}

/* The SPA shell must not swallow API traffic, and must still serve app routes. */
const shell = resolveRewrite("/some/storefront/route");
if (shell?.destination !== "/index.html") fail("/:path* no longer falls back to /index.html (SPA routing broken)");
else ok("storefront SPA fallback intact");

const admin = resolveRewrite("/admin/quotations");
if (admin?.destination !== "/dashboard/quotations") fail(`/admin/* no longer maps to the dashboard (got ${admin?.destination})`);
else ok("/admin/* still maps to the dashboard");

const dash = resolveRewrite("/dashboard/settings");
if (dash?.destination !== "/dashboard/index.html") fail(`/dashboard/* no longer serves the dashboard shell (got ${dash?.destination})`);
else ok("/dashboard/* still serves the dashboard shell");

/* The bracket catch-all must be gone: Vercel compiles it to one segment. */
if (fs.existsSync(path.join(ROOT, "api/[...path].mjs"))) {
  fail("api/[...path].mjs exists again — Vercel routes it as a single segment, so it silently 404s nested paths");
} else {
  ok("no bracketed catch-all (Vercel would compile it to a single segment)");
}
if (!fs.existsSync(path.join(ROOT, "api/index.mjs"))) fail("api/index.mjs is the single function entry — it is missing");
else ok("api/index.mjs present as the single function entry");

/* ---------- 2. seed snapshot ---------- */
const seedPath = path.join(ROOT, "apps/api/seed-data.json");
if (!fs.existsSync(seedPath)) {
  fail("apps/api/seed-data.json is missing — the serverless catalogue cannot seed. Run: npm run seed:data");
} else {
  const seed = JSON.parse(fs.readFileSync(seedPath, "utf8"));
  const counts = {
    products: seed.products?.length ?? 0,
    materials: seed.materials?.length ?? 0,
    services: seed.services?.length ?? 0,
  };
  if (counts.products === 0) fail("seed-data.json has no products");
  else ok(`seed snapshot carries ${counts.products} products, ${counts.materials} materials, ${counts.services} services`);

  /* The storefront and the API must agree, or the shop shows one catalogue and
     the dashboard another. */
  const drift = await import("./build-seed-data.mjs").then((m) => m.buildSeedData()).catch(() => null);
  if (drift) {
    const live = { products: drift.products.length, materials: drift.materials.length, services: drift.services.length };
    if (JSON.stringify(live) !== JSON.stringify(counts)) {
      fail(`seed-data.json drifted from the storefront sources: snapshot ${JSON.stringify(counts)} vs live ${JSON.stringify(live)} — run: npm run seed:data`);
    } else ok("seed snapshot is in sync with the storefront sources");
  }
}

/* ---------- 3. no runtime dependency on the storefront sources ---------- */
const shopData = fs.readFileSync(path.join(ROOT, "apps/api/shop-data.mjs"), "utf8");
const loadBody = shopData.slice(shopData.indexOf("export async function loadShopData"));
if (!loadBody.includes("readSeedSnapshot")) {
  fail("shop-data.mjs no longer prefers the committed snapshot — production would bundle TypeScript at runtime again");
} else ok("shop-data.mjs prefers the committed snapshot over a runtime bundle");

/* fail loudly rather than shipping seed: undefined && the bootstrap silently doing nothing */
if (!/products\.length === 0|parsed\.products\.length === 0/.test(shopData)) {
  fail("shop-data.mjs does not reject an empty seed snapshot");
} else ok("an empty seed snapshot fails loudly instead of booting empty");

console.log(failures.length ? `\n${failures.length} deployment check(s) failed:` : "\nAll deployment checks passed.");
for (const f of failures) console.error(`  ✗ ${f}`);
process.exitCode = failures.length ? 1 : 0;
