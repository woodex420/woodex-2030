/**
 * Extracts the storefront's typed data modules (products, materials, services)
 * into plain JSON by bundling with esbuild and turning asset imports into
 * basename strings. The API DB is then seeded from this — one source of truth
 * for storefront + dashboard at runtime.
 */
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { createRequire } from "node:module";

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const ROOT = path.resolve(HERE, "../..");
export const SHOP_SRC = path.join(ROOT, "apps/storefront/src");

const require_ = createRequire(import.meta.url);

async function getEsbuild() {
  try {
    return await import("esbuild");
  } catch {
    const p = path.join(ROOT, "node_modules/esbuild/lib/main.js");
    return import(pathToFileURL(p).href);
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

export async function loadShopData() {
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
void require_;
