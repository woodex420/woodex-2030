#!/usr/bin/env node
/**
 * Unit test for the dashboard's router-path normalisation.
 *
 * Regression: a signed-in visit to /admin rendered a blank white screen. The
 * router is basenamed "/dashboard", /admin is only REWRITTEN (not redirected)
 * onto it, and React Router matches nothing outside its basename — so it
 * rendered nothing, with no error in the console. These cases pin the rule set.
 */
import { build } from "esbuild";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const out = await build({
  entryPoints: [path.join(ROOT, "apps/dashboard/src/lib/router-paths.ts")],
  bundle: true, write: false, format: "esm", platform: "node", logLevel: "silent",
});
const mod = await import("data:text/javascript," + encodeURIComponent(out.outputFiles[0].text));
const { canonicalDashboardPath: canon, isOutsideDashboard: outside } = mod;

const OK = [
  // [basename, pathname, expected canonical | null, why]
  ["/dashboard", "/dashboard", null, "already canonical"],
  ["/dashboard", "/dashboard/", null, "already canonical, trailing slash"],
  ["/dashboard", "/dashboard/crm", null, "already canonical, nested"],
  ["/dashboard", "/admin", "/dashboard", "the reported blank screen"],
  ["/dashboard", "/admin/", "/dashboard/", "alias with trailing slash"],
  ["/dashboard", "/admin/catalog", "/dashboard/catalog", "alias, nested"],
  ["/dashboard", "/admin/catalog/new", "/dashboard/catalog/new", "alias, deep"],
  ["/dashboard", "/ADMIN", "/dashboard", "alias, case-insensitive"],
  ["/app", "/admin", null, "no alias when the base is not .../dashboard"],
  ["/app", "/app/crm", null, "canonical under a custom base"],
  ["/", "/anything", null, "unbasenamed builds are never rewritten"],
  ["/dashboard", "/about", null, "unrelated path is not an alias"],
  ["/dashboard", "/administrator", null, "prefix must not match a longer word"],
  ["/dashboard", "/", null, "root is not the alias"],
];

const OUT = [
  ["/dashboard", "/dashboard", false],
  ["/dashboard", "/dashboard/x", false],
  ["/dashboard", "/admin", true],
  ["/dashboard", "/", true],
  ["/dashboard", "/administrator", true],
  ["/", "/anything", false],
  ["/app", "/admin", true],
];

let failed = 0;
for (const [base, p, want, why] of OK) {
  const got = canon(base, p);
  const ok = got === want;
  if (!ok) failed++;
  console.log(`${ok ? "PASS" : "FAIL"}  canon(${JSON.stringify(base)}, ${JSON.stringify(p)}) = ${JSON.stringify(got)}${ok ? "" : ` expected ${JSON.stringify(want)}`} · ${why}`);
}
for (const [base, p, want] of OUT) {
  const got = outside(base, p);
  const ok = got === want;
  if (!ok) failed++;
  console.log(`${ok ? "PASS" : "FAIL"}  outside(${JSON.stringify(base)}, ${JSON.stringify(p)}) = ${got}${ok ? "" : ` expected ${want}`}`);
}
console.log(failed ? `\n${failed} case(s) failed` : "\nAll router paths correct");
process.exit(failed ? 1 : 0);
