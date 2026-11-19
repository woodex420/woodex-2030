/**
 * The shop's reader of the catalogue the admin published (master list T-105).
 *
 * It reads two answers — one page of products, and one product — in the shape the rules of `/api/v1` name
 * (docs/woodex-os/29-API-V1-RULES.md), and turns them into the `Product` the pages already use. It is the twin of
 * `nativeCategories.ts`: the same address the shop asked before (`/api/products`), the same last-known-good
 * behaviour, and the same refusal to blank a page because a server was slow.
 *
 * Three rules it keeps, each of which a test holds:
 *
 *   · **Money.** The admin sends whole paisa in `price_minor`; this page prints whole rupees, so the rupees are
 *     made here, once, by `rupees()` — never by dividing in a template.
 *   · **"Ask us".** `on_request` is a switch, not a missing number. Such a product is kept and shown with no
 *     price, and `priceOnRequest` says so, which is the flag the pages already understand (a quote, no cart).
 *   · **An address the shop can use.** The admin knows a product by its slug; the shop's pages link by `id`. The
 *     slug IS the id here, so a product read from the admin and a product read from the shop's own database are
 *     linked the same way.
 *
 * Nothing here runs unless the shop's bridge is switched on (`NATIVE_PRODUCT_SYNC=1`): with it off, the address
 * answers 503 SWITCHED_OFF and this reader leaves the shop's own list exactly as it is.
 */
import type { Product } from "@/data/products";

/** What the admin sends for one product (the contract's `Product`, the keys this reader uses). */
export type NativeProduct = {
  id: string; name: string; slug: string;
  short_description: string; description: string; shipping: string; warranty: string;
  category_ids: number[]; primary_category_id: number | null; series_ids: number[];
  availability: "available" | "unavailable" | "on_request";
  price_minor: number; was_price_minor: number | null; currency: string; on_request: boolean;
  variants: { sku: string; colour: string; material: string; size: string; price_minor: number | null; availability: string }[];
  specifications: { label: string; value: string }[];
  features: string[];
  faqs: { question: string; answer: string }[];
  is_hot: boolean; is_featured: boolean;
  related_mode: "off" | "manual" | "category"; related_ids: number[];
  pictures: { url: string; alt: string }[];
  position: number; revision: number;
  published_at: string | null; updated_at: string | null;
};

/** Whole paisa to whole rupees. Rounded to the nearest rupee: the shop has never printed a fraction of one. */
export const rupees = (minor: number | null | undefined): number => (typeof minor === "number" && Number.isFinite(minor) ? Math.round(minor / 100) : 0);

/** Only what the contract says, and only when it is what the contract says: a claim is not a guarantee. */
const isProduct = (value: unknown): value is NativeProduct => {
  if (!value || typeof value !== "object") return false;
  const p = value as Record<string, unknown>;
  return typeof p.id === "string" && p.id !== "" && typeof p.name === "string" && p.name !== ""
    && typeof p.slug === "string" && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(p.slug)
    && typeof p.price_minor === "number" && Number.isInteger(p.price_minor)
    && typeof p.on_request === "boolean" && Array.isArray(p.pictures);
};

/**
 * One product of the admin, as the shop's pages use it.
 *
 * `categoryAlias` and `seriesAlias` are how this shop names a category on its own pages: the admin's category is
 * a number, the shop's is a slug, and the categories' own reader already knows the mapping.
 */
export function toProduct(
  raw: unknown,
  lookups: { category: (id: number) => string | null; series: (id: number) => string | null },
): Product | null {
  if (!isProduct(raw)) return null;
  const category = raw.primary_category_id === null ? null : lookups.category(raw.primary_category_id);
  const series = raw.series_ids.length > 0 ? lookups.series(raw.series_ids[0]) : null;

  return {
    id: raw.slug,
    name: raw.name,
    category: category ?? "",
    series: series ?? undefined,
    price: rupees(raw.price_minor),
    originalPrice: raw.was_price_minor !== null && raw.was_price_minor > raw.price_minor ? rupees(raw.was_price_minor) : undefined,
    description: raw.description,
    shortDescription: raw.short_description,
    shipping: raw.shipping,
    warranty: raw.warranty,
    images: raw.pictures.map(picture => picture.url),
    colors: [],
    specifications: raw.specifications,
    features: raw.features,
    // A product the editor marked unavailable cannot be ordered; everything else can, by price or by quote.
    inStock: raw.availability !== "unavailable",
    isNew: false,
    isBestSeller: raw.is_hot,
    sku: raw.variants[0]?.sku ?? null,
    priceOnRequest: raw.on_request,
    trackStock: false,
    // The admin's options are priced per option; each one becomes an option of the shop, in rupees.
    //
    // An option's id must not be its position. The shop keeps a chosen option in a basket and a quote as
    // `variantId` (contexts/reconcile.ts matches a saved line back to an option by it), so an id that follows
    // the order of the list would point a saved chair at a different chair the moment the editor reorders its
    // options in the admin — silently, with the right price attached. The contract makes `sku` unique within a
    // product ("Each variant combination needs its own explicit price" and the catalogue's own SKU rule), so the
    // id is derived from the SKU: the same option keeps the same id across reorders and republications.
    variants: raw.variants.map((variant) => ({
      id: skuNumber(variant.sku, raw.variants.map((v) => v.sku)),
      sku: variant.sku,
      label: [variant.colour, variant.material, variant.size].filter(Boolean).join(" · ") || variant.sku,
      attributes: { colour: variant.colour, material: variant.material, size: variant.size },
      price: rupees(variant.price_minor),
      priceOnRequest: variant.price_minor === null || variant.availability === "on_request",
    })),
    updatedAt: raw.updated_at ?? undefined,
  };
}

/** One answer of the list, read as products. Returns null when the answer is not the shape the contract names. */
export function toProducts(
  body: unknown,
  lookups: { category: (id: number) => string | null; series: (id: number) => string | null },
): { products: Product[]; total: number; next: string | null } | null {
  if (!body || typeof body !== "object") return null;
  const answer = body as { data?: unknown; meta?: { total?: unknown }; links?: { next?: unknown } };
  if (!Array.isArray(answer.data)) return null;
  const total = typeof answer.meta?.total === "number" ? answer.meta.total : answer.data.length;
  const next = typeof answer.links?.next === "string" ? answer.links.next : null;
  const products = answer.data.map(item => toProduct(item, lookups)).filter((product): product is Product => product !== null);

  return { products, total, next };
}

/**
 * A stable number for an option, from its SKU alone.
 *
 * The shop's own options carry a database id; the admin's carry a SKU and no id of its own, and the contract
 * promises no `id`. A position in the list is not an identity (see toProduct). This hashes the SKU into a 31-bit
 * positive number — the range the shop's own ids use — and, in the vanishing case of two SKUs colliding, steps
 * to the next free number. Same SKU, same number: reordering or republishing changes nothing.
 */
function skuNumber(sku: string, all: string[]): number {
  let hash = 0;
  for (let i = 0; i < sku.length; i++) hash = (Math.imul(hash, 31) + sku.charCodeAt(i)) | 0;
  const used = new Set(
    all.filter((other) => other !== sku).map((other) => {
      let h = 0;
      for (let i = 0; i < other.length; i++) h = (Math.imul(h, 31) + other.charCodeAt(i)) | 0;
      return (h & 0x7fffffff) || 1;
    }),
  );
  let id = (hash & 0x7fffffff) || 1;
  while (used.has(id)) id = id === 0x7fffffff ? 1 : id + 1;
  return id;
}
