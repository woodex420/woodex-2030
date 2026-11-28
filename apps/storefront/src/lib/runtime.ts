import { syncNativeCategories } from "./nativeCategories";
import { captureBasketRequest } from "./quoteCapture";
import { refreshTheme } from "./theme";
import { captureStorefrontLead } from "./leadCapture";
import { Fragment, createElement, useEffect, useState } from "react";
import type { ReactElement } from "react";
import { products, catalog, hasCategoryLabel } from "@/data/products";
import type { CatalogSource, Product, ProductVariant } from "@/data/products";
import { nativeCategoryPath, nativeCatalogue } from "./nativeCategories";
import { readWhole } from "./nativeProducts";
import type { Lookups } from "./nativeProducts";

/**
 * WOODEX runtime bridge — the storefront and the dashboard share one backend
 * (apps/api). This module:
 *  1. loads the catalogue from the API — the ONLY place the site's products
 *     come from; nothing is compiled in, so nothing is shown that the database
 *     does not hold;
 *  2. refreshes price and availability after a dashboard edit, without a rebuild;
 *  3. posts quotes, leads and orders to the same database the dashboard reads.
 */
type Override = { id: string; price?: number; priceOnRequest?: boolean; inStock?: boolean; updatedAt?: string };
let lastSync = "";
let lastCatalogSignature = "";

const announce = () => window.dispatchEvent(new CustomEvent("woodex:runtime"));

function storefrontImage(value: string) {
  if (/^(https?:|data:)/i.test(value) || value.startsWith("/")) return value;
  return "/img/" + value;
}

const list = <T,>(value: unknown): T[] => (Array.isArray(value) ? (value as T[]) : []);

/* The API's reply is a claim, not a guarantee: a product without an id or a
   name is dropped here rather than rendered as a blank card. */
function toProduct(raw: unknown): Product | null {
  if (!raw || typeof raw !== "object") return null;
  const p = raw as Record<string, unknown>;
  if (typeof p.id !== "string" || !p.id || typeof p.name !== "string" || !p.name) return null;
  const variants = list<Record<string, unknown>>(p.variants)
    .filter((v) => v && typeof v.id === "number" && typeof v.label === "string" && v.active !== false)
    .map((v): ProductVariant => ({
      id: v.id as number,
      sku: String(v.sku ?? ""),
      label: v.label as string,
      attributes: v.attributes && typeof v.attributes === "object" ? (v.attributes as Record<string, string>) : {},
      price: Number(v.price) || 0,
      priceOnRequest: v.priceOnRequest === true,
    }));
  return {
    id: p.id,
    name: p.name,
    category: String(p.category ?? ""),
    subcategory: typeof p.subcategory === "string" && p.subcategory ? p.subcategory : undefined,
    series: typeof p.series === "string" && p.series ? p.series : undefined,
    price: Number(p.price) || 0,
    originalPrice: typeof p.originalPrice === "number" && p.originalPrice > 0 ? p.originalPrice : undefined,
    description: String(p.description ?? ""),
    shortDescription: String(p.shortDescription ?? ""),
    images: list<string>(p.images).filter((image) => typeof image === "string" && image).map(storefrontImage),
    colors: list<Product["colors"][number]>(p.colors),
    specifications: list<Product["specifications"][number]>(p.specifications),
    features: list<string>(p.features),
    inStock: p.inStock !== false,
    isNew: p.isNew === true,
    isBestSeller: p.isBestSeller === true,
    sku: typeof p.sku === "string" && p.sku ? p.sku : null,
    priceOnRequest: p.priceOnRequest === true,
    trackStock: p.trackStock !== false,
    variants,
    updatedAt: typeof p.updatedAt === "string" ? p.updatedAt : undefined,
  };
}

/**
 * Reads the published catalogue. Returns how many products changed.
 *
 * A failure before anything was loaded turns the catalogue "unavailable", and
 * the pages say so. A failure after a successful load changes nothing: the
 * last good list stays on screen.
 */
export async function syncCatalog(): Promise<number> {
  try {
    const res = await fetch("/api/products", { headers: { accept: "application/json" } });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const json = (await res.json()) as Record<string, unknown>;
    /* Two answers can come from this one address, and the page must read both without being rebuilt:
       the shop's own (a list in `items`), and — where the connection to the Laravel admin is switched on —
       the admin's, in the shape its rules name (`data`, `meta`, `links`). The second carries money in paisa
       and "ask us" as a switch, which ./nativeProducts.ts turns into the product the pages use. */
    const own = Array.isArray(json?.items);
    const next = own
      ? (json.items as unknown[]).map(toProduct).filter((product): product is Product => product !== null)
      : await nativeCatalogueWhole(json);
    /* Who answered is part of what the catalogue is: an empty list of this shop's own and an empty list of the
       admin's are not the same catalogue, because a basket goes to whoever answered. */
    const source: CatalogSource = own ? "shop" : "admin";
    const signature = source + JSON.stringify(next);
    if (catalog.status === "ready" && signature === lastCatalogSignature) return 0;
    const previousCount = products.length;
    products.splice(0, products.length, ...next);
    lastCatalogSignature = signature;
    catalog.source = source;
    catalog.status = "ready";
    announce();
    return Math.abs(next.length - previousCount) || 1;
  } catch {
    if (catalog.status === "loading") {
      catalog.status = "unavailable";
      announce();
    }
    return 0;
  }
}

/**
 * Where a category of the admin stands on this shop's pages.
 *
 * The admin keeps categories in a tree of any depth, under two segments (office, home); this shop's sidebar knows
 * two levels, a heading and its parts. The heading is the first category on the way down, below the segment,
 * that this shop has a heading for ("Office Tables", "Office Chairs"); a level that only groups a menu
 * ("Seating & more") is passed by. Where the shop has a heading for none of them, the first below the segment
 * is the heading. The part is the category itself, when it is not the heading.
 */
const nativeLookups: Lookups = {
  category: id => {
    const path = nativeCategoryPath(id);
    if (path.length === 0) return null;
    const below = path.length > 1 ? path.slice(1) : path;
    const heading = below.find(category => hasCategoryLabel(category.slug)) ?? below[0];
    const own = path[path.length - 1];
    return own === heading ? { category: heading.slug } : { category: heading.slug, subcategory: own.slug };
  },
  series: id => nativeCatalogue()?.series.find(series => series.id === String(id))?.id ?? null,
};

/**
 * Every published product of the admin, as the shop's products: the first answer and every page after it
 * (./nativeProducts.ts reads them). The products name their categories by number, so the categories are read
 * first; a page is asked of this shop's own server, which asks the admin.
 */
async function nativeCatalogueWhole(first: Record<string, unknown>): Promise<Product[]> {
  if (!nativeCatalogue()) await syncNativeCategories();

  return readWhole(first, nativeLookups, async (page, perPage) => {
    const res = await fetch(`/api/products?per_page=${perPage}&page=${page}`, { headers: { accept: "application/json" } });
    if (!res.ok) throw new Error("HTTP " + res.status);
    return res.json();
  });
}

/** The "Try again" button of the unavailable message. */
export async function retryCatalog(): Promise<void> {
  if (catalog.status === "ready") return;
  catalog.status = "loading";
  announce();
  await syncCatalog();
}

export async function syncOverrides(): Promise<number> {
  try {
    const url = "/api/products/overrides" + (lastSync ? "?since=" + encodeURIComponent(lastSync) : "");
    const res = await fetch(url, { headers: { accept: "application/json" } });
    if (!res.ok) return 0;
    const json = (await res.json()) as { serverTime: string; items: Override[] };
    lastSync = json.serverTime;
    if (!Array.isArray(json.items) || !json.items.length) return 0;
    const byId = new Map(products.map((p) => [p.id, p]));
    let changed = 0;
    for (const override of json.items) {
      const product = byId.get(override.id);
      if (!product) continue;
      if (typeof override.priceOnRequest === "boolean" && override.priceOnRequest !== !!product.priceOnRequest) {
        product.priceOnRequest = override.priceOnRequest;
        changed++;
      }
      if (typeof override.price === "number" && override.price !== product.price) {
        product.price = override.price;
        changed++;
      }
      if (typeof override.inStock === "boolean" && override.inStock !== product.inStock) {
        product.inStock = override.inStock;
        changed++;
      }
    }
    if (changed > 0) announce();
    return changed;
  } catch {
    return 0;
  }
}

export function startRuntimeSync(intervalMs = 15000) {
  /* The build renders each page to static HTML in a window with no API. Leave
     the catalogue "loading" there: the static page then carries the loading
     placeholder, not a message that the shop is unavailable. */
  if ((window as unknown as { __WOODEX_PRERENDER__?: boolean }).__WOODEX_PRERENDER__) return;

  /* Categories kept in the Laravel admin, where that connection is switched
     on (the API answers "disabled" where it is not). */
  void syncNativeCategories();
  window.setInterval(() => void syncNativeCategories(), intervalMs);
  window.addEventListener("focus", () => void syncNativeCategories());

  /* Until the catalogue has loaded once, ask again soon; after that, once a
     minute is enough — price and availability arrive through the overrides. */
  const refreshCatalog = async () => {
    await syncCatalog();
    window.setTimeout(() => void refreshCatalog(), catalog.status === "ready" ? Math.max(intervalMs * 4, 60000) : 8000);
  };
  void refreshCatalog();
  void syncOverrides();
  window.setInterval(() => void syncOverrides(), intervalMs);
  // The serverless runtime has no cross-instance event bus; refresh public settings on a bounded poll.
  window.setInterval(() => void refreshTheme(), Math.max(intervalMs * 4, 60000));
}

/** Subscribe to catalog mutations so memoized storefront filters recalculate. */
export function useRuntimeRevision() {
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const handler = () => setRevision((value) => value + 1);
    window.addEventListener("woodex:runtime", handler);
    return () => window.removeEventListener("woodex:runtime", handler);
  }, []);
  return revision;
}

/** Where the catalogue stands — loading, ready or unavailable — kept current. */
export function useCatalogStatus() {
  useRuntimeRevision();
  return catalog.status;
}

/** Re-render wrapper: bumps on `woodex:runtime` so catalog price/stock stay live. */
export function WithRuntimeSync({ render }: { render: () => ReactElement }) {
  useRuntimeRevision();
  return createElement(Fragment, null, render());
}

/** Capture only attribution fields needed to tie a submitted lead to its landing page. */
export function getLeadAttribution() {
  const params = new URLSearchParams(window.location.search);
  const utm = (key: string) => params.get(key)?.trim().slice(0, 200) || null;
  return {
    landingPath: window.location.pathname.slice(0, 300),
    referrer: document.referrer.slice(0, 1000) || null,
    utmSource: utm("utm_source"),
    utmMedium: utm("utm_medium"),
    utmCampaign: utm("utm_campaign"),
  };
}

async function post(path: string, body: unknown) {
  try {
    const res = await fetch(path, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    });
    return res.ok ? await res.json() : null;
  } catch {
    return null;
  }
}

/**
 * Like post(), for the two requests whose refusal the customer can act on.
 *
 * post() answers null for every failure, so "this product is priced on
 * request" and "the server is down" looked the same on the page: "could not be
 * saved, please try again" — and trying again never helps the first one. Here
 * a refusal (4xx) throws the reason the API gave; a failure to reach it, or a
 * server error, throws a message that says so.
 */
async function postOrExplain(path: string, body: unknown): Promise<Record<string, unknown>> {
  let res: Response;
  try {
    res = await fetch(path, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    });
  } catch {
    throw new Error("We could not reach the shop. Check your connection and try again; nothing was sent.");
  }
  let data: unknown = null;
  try { data = await res.json(); } catch { /* not JSON: handled below */ }
  const record = data && typeof data === "object" ? (data as Record<string, unknown>) : null;
  if (res.ok && record) return record;
  const reason = record && typeof record.error === "string" ? record.error.trim() : "";
  if (res.status >= 400 && res.status < 500 && reason) {
    /* The API writes its reasons as sentences without a capital or a full stop. */
    throw new Error(reason.charAt(0).toUpperCase() + reason.slice(1) + (/[.!?]$/.test(reason) ? "" : "."));
  }
  throw new Error("The request could not be saved right now. Please try again in a moment.");
}

/**
 * Where a basket is sent. Three destinations:
 *
 *   "admin"   the catalogue on the page came from the Laravel admin (master list T-191). Nobody else can price
 *             such a basket: this shop's own database does not hold the products, and its own address refused
 *             every one of them (defect D-060). So it goes to the admin whatever the site was built with — the
 *             page reads where its catalogue came from and cannot be built to disagree with it. Each line names
 *             its product by its address, and its option by its code.
 *   "v1"      chosen when the site is built (VITE_QUOTE_API_MODE=v1): this shop's own products, sent to the
 *             Laravel API, which reads each through a line of its rate book that names the shop's own key.
 *   "legacy"  the default: this repository's API. A refusal carries the API's reason (postOrExplain).
 */
export type BasketDestination = "legacy" | "v1" | "admin";
export const basketDestination = (): BasketDestination =>
  catalog.source === "admin" ? "admin" : ((import.meta.env.VITE_QUOTE_API_MODE || "legacy") as BasketDestination);

/**
 * Is the visitor asked for the robot check before a request to the Laravel API is sent? Where the site was built
 * with the check's public key, yes: the page then has the box that makes the token. Where it was not, the
 * request is sent without one and the admin decides: it refuses, unless the shop's own server carried the
 * request and vouches for it with the key the two share (apps/api/native-enquiry-bridge.mjs). The page cannot
 * know which of the two holds, and until 27 November it refused every such request itself in a built site.
 */
const challengeRequired = (): boolean => !!import.meta.env.VITE_TURNSTILE_SITE_KEY;

export const submitQuoteRequest = (payload: unknown) => {
  const mode = basketDestination();
  if (mode === "legacy") return postOrExplain("/api/quotes", payload);
  return captureBasketRequest(payload as Record<string, unknown>, mode, fetch, challengeRequired())
    .finally(() => window.dispatchEvent(new Event("woodex:lead-attempt")));
};
export const submitLead = (payload: unknown) => {
  const mode = import.meta.env.VITE_LEAD_API_MODE || "legacy";
  return captureStorefrontLead(payload as Record<string, unknown>, mode, fetch, challengeRequired())
    .finally(() => { if (mode === "v1") window.dispatchEvent(new Event("woodex:lead-attempt")); });
};
export const logManualWhatsAppHandoff = (leadId: number) => post(`/api/leads/${leadId}/whatsapp-handoff`, {});
export const submitOrder = (payload: unknown) => postOrExplain("/api/orders", payload);
