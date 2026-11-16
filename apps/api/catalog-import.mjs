/**
 * The prepared catalogue and the plan for loading it.
 *
 * `catalog-data.json` is written by `scripts/catalog-build.mjs` from the capture
 * of the live shop. This module reads it and decides, against the products a
 * database already holds, what loading it would do. It decides only: nothing
 * here writes. The write lives in server.mjs, next to the validation every
 * other product write goes through, so the import cannot store a product the
 * API itself would refuse.
 *
 * Read with fs from a path built off this file's own location — the same way
 * shop-data.mjs reads seed-data.json. A Vercel function only receives the
 * files its tracer can see statically, and that spelling is the one already
 * proven to be seen.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const CATALOG_DATA_FILE = path.join(HERE, "catalog-data.json");

let cached;
/** The prepared catalogue, or null when this build ships none. */
export function loadCatalogData(file = CATALOG_DATA_FILE) {
  if (file === CATALOG_DATA_FILE && cached !== undefined) return cached;
  let data = null;
  try {
    const parsed = JSON.parse(fs.readFileSync(file, "utf8"));
    if (parsed && typeof parsed.dataset === "string" && Array.isArray(parsed.products)) data = parsed;
  } catch { /* no prepared catalogue in this build */ }
  if (file === CATALOG_DATA_FILE) cached = data;
  return data;
}

/**
 * What loading `dataset` into a database would do.
 *
 * @param dataset   the prepared catalogue
 * @param existing  Map of product id → { name, sourceSite } for every product in the database
 * @param skuOwners Map of lower-case SKU → { productId, name } for every SKU in use, products and variants
 * @param options   demoIds: Set of the demo catalogue's ids; replaceDemo: whether a demo
 *                  product standing on an address may be replaced by the real one
 *
 * Four outcomes per product, and no fifth:
 *   create   — the address and the SKU are both free;
 *   present  — a product with this address is already there AND came from this
 *              same source. Left exactly as it is: a second run must never undo
 *              what staff corrected after the first;
 *   replace  — the address is held by a DEMO product and the caller asked for
 *              demo products to give way. The row keeps its id and its history;
 *              its content becomes the real product, as a draft;
 *   conflict — the address or the SKU belongs to something else. Left out and
 *              reported with the reason; a person decides.
 *
 * Why "replace" exists at all: a demo product cannot simply be deleted to free
 * its address. It has stock history, and the database rightly refuses to
 * delete a product its ledger still names.
 */
export function planImport(dataset, existing, skuOwners, { demoIds = new Set(), replaceDemo = false } = {}) {
  const site = dataset.source?.site ?? null;
  const items = [];
  const claimed = new Map(); // SKUs this run itself hands out
  for (const product of dataset.products) {
    const base = {
      id: product.id, name: product.name, sku: product.sku,
      price: product.price, priceOnRequest: !!product.priceOnRequest,
      category: product.category, subcategory: product.subcategory ?? null,
      review: Array.isArray(product.review) ? product.review.length : 0,
    };
    const there = existing.get(product.id);
    let action = "create";
    const extra = {};
    if (there) {
      if (site && there.sourceSite === site) { items.push({ ...base, action: "present" }); continue; }
      const heldByDemo = demoIds.has(product.id) && !there.sourceSite;
      if (!(heldByDemo && replaceDemo)) {
        items.push({ ...base, action: "conflict", heldByDemo,
          reason: heldByDemo
            ? `The address "${product.id}" is used by the demo product ${there.name}.`
            : `The address "${product.id}" is already used by ${there.name}, which did not come from this import.` });
        continue;
      }
      action = "replace";
      extra.replaces = there.name;
    }
    const key = String(product.sku ?? "").toLowerCase();
    const owner = key ? skuOwners.get(key) ?? claimed.get(key) : null;
    if (owner && owner.productId !== product.id) {
      items.push({ ...base, action: "conflict", heldByDemo: false, reason: `The SKU ${product.sku} is already used by ${owner.name}.` });
      continue;
    }
    if (key) claimed.set(key, { productId: product.id, name: product.name });
    items.push({ ...base, action, ...extra });
  }
  const count = (action) => items.filter((item) => item.action === action).length;
  return {
    dataset: {
      name: dataset.dataset,
      site,
      capturedOn: dataset.source?.capturedOn ?? null,
      products: dataset.products.length,
      priceOnRequest: dataset.products.filter((p) => p.priceOnRequest).length,
      needingReview: dataset.products.filter((p) => Array.isArray(p.review) && p.review.length).length,
    },
    summary: {
      create: count("create"), replace: count("replace"), present: count("present"), conflict: count("conflict"),
      heldByDemo: items.filter((item) => item.action === "conflict" && item.heldByDemo).length,
    },
    items,
    skipped: Array.isArray(dataset.skipped) ? dataset.skipped : [],
  };
}
