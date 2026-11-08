#!/usr/bin/env node
/**
 * Every block type the editor offers must render somewhere.
 *
 *   node scripts/test-block-coverage.mjs
 *
 * The page builder has three places that must agree:
 *
 *   1. `BLOCKS` in apps/api/server.mjs — the registry the editor's inspector
 *      reads. Its keys are the block types an author can insert.
 *   2. apps/storefront/src/pages/PublishedPage.tsx — the real renderer. A type
 *      with no case here is a block that silently renders nothing on the live
 *      site, which is the worst kind of bug: the author sees their content in
 *      the editor and publishes an empty section.
 *   3. BlockPreview in apps/dashboard/src/pages/Website.tsx — the editor's
 *      miniature. It is a *simplified* second implementation, which is a known
 *      and accepted trade-off (see 01-ARCHITECTURE.md), but "simplified" must
 *      not mean "missing": a fall-through to the generic default box looks like
 *      a successful render while showing the wrong thing.
 *
 * Known limitation this test does NOT solve: a preview that exists but has
 * drifted from the real renderer still passes. That risk is documented rather
 * than papered over; eliminating it means one renderer, not two.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (p) => fs.readFileSync(path.join(ROOT, p), "utf8");
const rel = (p) => path.relative(ROOT, p);

/* 1. Registry keys. Scoped to the BLOCKS literal so unrelated `"foo": {` object
      properties elsewhere in the 3,000-line server file cannot be mistaken for
      block types. */
const serverSrc = read("apps/api/server.mjs");
const start = serverSrc.indexOf("const BLOCKS = {");
if (start < 0) {
  console.error("✗ could not find `const BLOCKS = {` in apps/api/server.mjs");
  process.exit(1);
}
/* Walk braces to find the end of the literal rather than guessing a length. */
let depth = 0;
let end = start;
for (let i = serverSrc.indexOf("{", start); i < serverSrc.length; i++) {
  if (serverSrc[i] === "{") depth++;
  else if (serverSrc[i] === "}") { depth--; if (depth === 0) { end = i; break; } }
}
const registrySrc = serverSrc.slice(start, end);
const blockTypes = [...registrySrc.matchAll(/^\s{2}"([a-z][a-z0-9-]*)":\s*\{/gm)].map((m) => m[1]);

if (blockTypes.length === 0) {
  console.error("✗ parsed zero block types from the BLOCKS registry — the parser is wrong, not the code");
  process.exit(1);
}

/* 2 & 3. `case "type":` labels, including grouped `case "a": case "b":`. */
const casesIn = (src) => new Set([...src.matchAll(/case\s+"([a-z][a-z0-9-]*)"/g)].map((m) => m[1]));
const storefrontSrc = read("apps/storefront/src/pages/PublishedPage.tsx");
const previewSrc = read("apps/dashboard/src/pages/Website.tsx");
const storefrontCases = casesIn(storefrontSrc);
const previewCases = casesIn(previewSrc);

const problems = [];
for (const t of blockTypes) {
  if (!storefrontCases.has(t)) problems.push(`"${t}" is in the editor registry but has no case in apps/storefront/src/pages/PublishedPage.tsx — it renders nothing on the live site`);
  if (!previewCases.has(t)) problems.push(`"${t}" is in the editor registry but has no case in BlockPreview (apps/dashboard/src/pages/Website.tsx) — the editor falls back to a generic box`);
}

console.log(`\n[blocks] registry   ${blockTypes.length} type(s): ${blockTypes.join(", ")}`);
console.log(`[blocks] storefront ${storefrontCases.size} case(s)`);
console.log(`[blocks] preview    ${previewCases.size} case(s)`);

if (problems.length) {
  console.error(`\n✗ ${problems.length} block coverage problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  console.error("\nAdd the missing case, or remove the type from the registry.");
  process.exit(1);
}
console.log("\n✓ every registered block type renders in both the storefront and the editor preview.\n");
