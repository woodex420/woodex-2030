#!/usr/bin/env node
/**
 * Snapshots the storefront's typed catalogue into apps/api/seed-data.json.
 *
 * Why this exists: the API used to derive its seed catalogue at *runtime* by
 * bundling apps/storefront/src/data/*.ts with esbuild. That works on a normal
 * server, where the whole repo is on disk, but a Vercel function only receives
 * the files its tracer can see statically. The storefront sources are reached
 * through a computed path, so they were never shipped:
 *
 *   Build failed with 1 error:
 *     error: Could not resolve "/var/task/apps/storefront/src/data/products.ts"
 *
 * That threw inside loadShopData(), which ran during module initialisation, so
 * the function never booted and answered every request with a 503. The catalog
 * bootstrap therefore never executed and every table stayed empty.
 *
 * Resolving the same data at BUILD time and committing the result removes the
 * runtime esbuild, the dev-only dependency and the hidden file dependency from
 * the serverless path. The storefront's .ts files stay the single source of
 * truth; this file is a derivative. `npm run seed:data:check` fails the build
 * if the snapshot drifts from the sources.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
export const SEED_FILE = path.join(ROOT, "apps/api/seed-data.json");

/** Stable serialisation: fixed key order, one product per line, so diffs are readable. */
export function serialise(data) {
  const stable = (value) => {
    if (Array.isArray(value)) return value.map(stable);
    if (value && typeof value === "object") {
      return Object.fromEntries(Object.keys(value).sort().map((k) => [k, stable(value[k])]));
    }
    return value;
  };
  const body = {
    products: stable(data.products ?? []),
    materials: stable(data.materials ?? []),
    services: stable(data.services ?? []),
    categoryGroups: stable(data.categoryGroups ?? []),
  };
  const wrap = (rows) =>
    rows.length ? `[\n${rows.map((r) => "    " + JSON.stringify(r)).join(",\n")}\n  ]` : "[]";
  return (
    "{\n" +
    `  "products": ${wrap(body.products)},\n` +
    `  "materials": ${wrap(body.materials)},\n` +
    `  "services": ${wrap(body.services)},\n` +
    `  "categoryGroups": ${wrap(body.categoryGroups)}\n` +
    "}\n"
  );
}

/** Bundles the storefront modules and returns the seed payload. */
export async function buildSeedData() {
  const { loadShopData } = await import("../apps/api/shop-data.mjs");
  const { products, materials, services, categoryGroups } = await loadShopData();
  return { products, materials, services, categoryGroups };
}

export async function writeSeedData() {
  const data = await buildSeedData();
  fs.writeFileSync(SEED_FILE, serialise(data));
  return data;
}

/* CLI: write the snapshot (default) or verify it is current (--check). */
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const check = process.argv.includes("--check");

  if (check) {
    const expected = serialise(await buildSeedData());
    if (!fs.existsSync(SEED_FILE)) {
      console.error(`[seed-data] missing ${path.relative(ROOT, SEED_FILE)} — run: npm run seed:data`);
      process.exit(1);
    }
    const actual = fs.readFileSync(SEED_FILE, "utf8");
    if (actual !== expected) {
      console.error(
        `[seed-data] ${path.relative(ROOT, SEED_FILE)} is stale — the storefront catalogue changed.\n` +
          "            Run `npm run seed:data` and commit the result.",
      );
      process.exit(1);
    }
    console.log("[seed-data] snapshot matches the storefront sources.");
    process.exit(0);
  }

  const data = await writeSeedData();
  const bytes = fs.statSync(SEED_FILE).size;
  console.log(
    `[seed-data] wrote ${path.relative(ROOT, SEED_FILE)} — ` +
      `${data.products.length} products, ${data.materials.length} materials, ` +
      `${data.services.length} services, ${data.categoryGroups.length} category groups (${bytes} bytes)`,
  );
}
