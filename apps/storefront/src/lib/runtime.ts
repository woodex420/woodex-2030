import { Fragment, createElement, useEffect, useState } from "react";
import type { ReactElement } from "react";
import { products } from "@/data/products";

/**
 * WOODEX runtime bridge — the storefront and the agency dashboard share one
 * backend (apps/api, SQLite). This module:
 *  1. hydrates live price/stock overrides into the catalog at boot and polls
 *     so dashboard edits appear here without a rebuild;
 *  2. posts quotes, leads and orders to the same DB the dashboard reads.
 */

type Override = { id: string; price?: number; inStock?: boolean; updatedAt?: string };
let lastSync = "";

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
    for (const o of json.items) {
      const p = byId.get(o.id);
      if (!p) continue;
      if (typeof o.price === "number" && o.price !== p.price) {
        p.price = o.price;
        changed++;
      }
      if (typeof o.inStock === "boolean" && o.inStock !== p.inStock) {
        p.inStock = o.inStock;
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
  void syncOverrides();
  window.setInterval(() => void syncOverrides(), intervalMs);
}

/** Re-render wrapper: bumps on `woodex:runtime` so catalog price/stock stay live. */
export function WithRuntimeSync({ render }: { render: () => ReactElement }) {
  const [, setTick] = useState(0);
  useEffect(() => {
    const h = () => setTick((t) => t + 1);
    window.addEventListener("woodex:runtime", h);
    return () => window.removeEventListener("woodex:runtime", h);
  }, []);
  return createElement(Fragment, null, render());
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
export const submitOrder = (payload: unknown) => post("/api/orders", payload);
