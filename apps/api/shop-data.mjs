/**
 * Loads the storefront's typed data modules (products, materials, services) so
 * the API DB can be seeded from them — one source of truth for storefront and
 * dashboard.
 *
 * TWO SOURCES, on purpose:
 *
 *   1. apps/api/seed-data.json — a committed snapshot, generated at build time
 *      by `npm run seed:data` (scripts/build-seed-data.mjs). This is what runs
 *      in production. A Vercel function only receives files its tracer can see
 *      statically, and the storefront's .ts sources sit behind a computed path,
 *      so they are absent from the bundle. Bundling them at runtime therefore
 *      threw "Could not resolve /var/task/apps/storefront/src/data/products.ts"
 *      during initialisation, which stopped the function from booting at all.
 *
 *   2. The live .ts sources, bundled with esbuild — only when the snapshot is
 *      missing, which in practice means local development. Keeps the dev loop
 *      honest without shipping a TypeScript toolchain to production.
 *
 * `npm run seed:data:check` fails if the snapshot drifts from the sources.
 */
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { createRequire } from "node:module";

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const ROOT = path.resolve(HERE, "../..");
export const SHOP_SRC = path.join(ROOT, "apps/storefront/src");
export const SEED_FILE = path.join(HERE, "seed-data.json");

const require_ = createRequire(import.meta.url);

async function getEsbuild() {
  try {
    return await import("esbuild");
  } catch {
    let p = path.join(ROOT, "node_modules/esbuild/lib/main.js");
    if (!fs.existsSync(p)) p = path.join(HERE, "node_modules/esbuild/lib/main.js");
    try {
      return await import(pathToFileURL(p).href);
    } catch {
      throw new Error(
        "seed-data.json is missing and the storefront sources cannot be bundled " +
          "(esbuild is a dev dependency). Run `npm run seed:data` to generate the snapshot.",
      );
    }
  }
}

async function bundleModule(entryAbs, outName) {
  const esbuild = await getEsbuild();
  const outDir = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-seed-"));
  const outfile = path.join(outDir, outName);
  await esbuild.build({
    entryPoints: [entryAbs],
    bundle: true,
    format: "esm",
    platform: "node",
    outfile,
    logLevel: "silent",
    alias: { "@": SHOP_SRC },
    plugins: [
      {
        /* Photographs live once, in apps/api/public/img, and the storefront
           references them as `/img/<file>`. Data modules seeded into the DB must
           carry the same absolute path so the dashboard and storefront render
           identical URLs. A bare filename is still tolerated for older rows. */
        name: "assets-as-public-url",
        setup(build) {
          build.onResolve({ filter: /\.(jpg|jpeg|png|svg|webp)$/ }, (args) => ({
            path: args.path,
            namespace: "public-url",
          }));
          build.onLoad({ filter: /.*/, namespace: "public-url" }, (args) => ({
            contents: `export default ${JSON.stringify("/img/" + path.basename(args.path))}`,
            loader: "js",
          }));
        },
      },
    ],
  });
  const mod = await import(pathToFileURL(outfile).href);
  fs.rmSync(outDir, { recursive: true, force: true });
  return mod;
}

export async function loadShopData({ fromSource = false } = {}) {
  /* Snapshot first: present in production, and lets a fresh clone seed without
     a TypeScript build step. Fall through to the sources only for dev. */
  const snapshot = fromSource ? null : readSeedSnapshot();
  if (snapshot) return snapshot;

  const [prod, mats, serv] = await Promise.all([
    bundleModule(path.join(SHOP_SRC, "data/products.ts"), "products.mjs"),
    bundleModule(path.join(SHOP_SRC, "data/materials.ts"), "materials.mjs"),
    bundleModule(path.join(SHOP_SRC, "data/services.ts"), "services.mjs"),
  ]);
  return {
    products: prod.products,
    categoryGroups: prod.categoryGroups,
    materials: mats.materials,
    services: serv.services,
  };
}

function readSeedSnapshot() {
  let text;
  try {
    text = fs.readFileSync(SEED_FILE, "utf8");
  } catch {
    return null;
  }
  const parsed = JSON.parse(text);
  if (!Array.isArray(parsed.products) || parsed.products.length === 0) {
    throw new Error(
      `${path.relative(ROOT, SEED_FILE)} contains no products. ` +
        "Regenerate it with `npm run seed:data` rather than booting with an empty catalogue.",
    );
  }
  return {
    products: parsed.products,
    materials: parsed.materials ?? [],
    services: parsed.services ?? [],
    categoryGroups: parsed.categoryGroups ?? [],
  };
}
void require_;
