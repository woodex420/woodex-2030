import { refreshTheme } from "./theme";
import { Fragment, createElement, useEffect, useState } from "react";
import type { ReactElement } from "react";
import { products } from "@/data/products";
import type { Product } from "@/data/products";

/**
 * WOODEX runtime bridge — the storefront and the agency dashboard share one
 * backend (apps/api, SQLite). This module:
 *  1. hydrates the catalog and price/stock changes from the shared API;
 *  2. refreshes product data after dashboard CRUD without a storefront rebuild;
 *  3. posts quotes, leads and orders to the same DB the dashboard reads.
 */
type Override = { id: string; price?: number; inStock?: boolean; updatedAt?: string };
type CatalogReply = { items: Product[] };
let lastSync = "";
let lastCatalogSignature = "";

function storefrontImage(value: string) {
  if (/^(https?:|data:)/i.test(value) || value.startsWith("/")) return value;
  return "/img/" + value;
}

export async function syncCatalog(): Promise<number> {
  try {
    const res = await fetch("/api/products", { headers: { accept: "application/json" } });
    if (!res.ok) return 0;
    const json = (await res.json()) as CatalogReply;
    if (!Array.isArray(json.items)) return 0;
    const next = json.items.map((product) => ({
      ...product,
      images: Array.isArray(product.images) ? product.images.map(storefrontImage) : [],
      colors: Array.isArray(product.colors) ? product.colors : [],
      specifications: Array.isArray(product.specifications) ? product.specifications : [],
      features: Array.isArray(product.features) ? product.features : [],
    }));
    const signature = JSON.stringify(next);
    if (signature === lastCatalogSignature) return 0;
    const previousCount = products.length;
    products.splice(0, products.length, ...next);
    lastCatalogSignature = signature;
    window.dispatchEvent(new CustomEvent("woodex:runtime"));
    return Math.abs(next.length - previousCount) || 1;
  } catch {
    return 0;
  }
}

export async function syncOverrides(): Promise<number> {
  try {
    const url = "/api/products/overrides" + (lastSync ? "?since=" + encodeURIComponent(lastSync) : "");
    const res = await fetch(url, { headers: { accept: "application/json" } });
    if (!res.ok) return 0;
    const json = (await res.json()) as { serverTime: string; items: Override[] };
    lastSync = json.serverTime;
    if (!json.items.length) return 0;
    const byId = new Map(products.map((p) => [p.id, p]));
    let changed = 0;
    for (const override of json.items) {
      const product = byId.get(override.id);
      if (!product) continue;
      if (typeof override.price === "number" && override.price !== product.price) {
        product.price = override.price;
        changed++;
      }
      if (typeof override.inStock === "boolean" && override.inStock !== product.inStock) {
        product.inStock = override.inStock;
        changed++;
      }
    }
    if (changed > 0) window.dispatchEvent(new CustomEvent("woodex:runtime"));
    return changed;
  } catch {
    return 0;
  }
}

export function startRuntimeSync(intervalMs = 15000) {
  void syncCatalog();
  void syncOverrides();
  window.setInterval(() => void syncOverrides(), intervalMs);
  window.setInterval(() => void syncCatalog(), Math.max(intervalMs * 4, 60000));
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

export const submitQuoteRequest = (payload: unknown) => post("/api/quotes", payload);
export const submitLead = (payload: unknown) => post("/api/leads", payload);
export const logManualWhatsAppHandoff = (leadId: number) => post(`/api/leads/${leadId}/whatsapp-handoff`, {});
export const submitOrder = (payload: unknown) => post("/api/orders", payload);
