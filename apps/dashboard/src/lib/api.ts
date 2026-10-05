import { useCallback, useEffect, useRef, useState } from "react";

/* Runtime API layer — the dashboard reads/writes the same SQLite-backed
   service as the storefront (apps/api). No bundled copies of the data. */

export type ApiProduct = {
  id: string;
  name: string;
  category: string;
  subcategory?: string;
  series?: string;
  price: number;
  originalPrice?: number;
  description: string;
  shortDescription: string;
  images: string[];
  colors: { name: string; hex: string }[];
  specifications: { label: string; value: string }[];
  features: string[];
  inStock: boolean;
  stockQty?: number;
  isNew?: boolean;
  isBestSeller?: boolean;
  rating: number;
  reviews: number;
  updatedAt?: string;
};

export type ApiMaterial = { id: string; name: string; category: string; image: string };

export type ApiLead = {
  id: number;
  ref: string;
  name: string;
  company?: string | null;
  interest?: string;
  source?: string;
  contact?: string | null;
  status: string;
  owner?: string | null;
  note?: string | null;
  createdAt: string;
  updatedAt: string;
};

export type ApiQuote = {
  id: number;
  ref: string;
  customer: string;
  contact?: string | null;
  items: { name: string; price: number; qty: number; material?: string }[];
  total: number;
  status: string;
  source: string;
  note?: string | null;
  audit: { who: string; action: string; time: string }[];
  createdAt: string;
  updatedAt: string;
};

export type ApiOrder = {
  id: number;
  ref: string;
  customer: string;
  items: { name?: string; price?: number; qty?: number }[];
  total: number;
  status: string;
  stage: string;
  owner: string;
  due: string;
};

export type ApiStats = {
  products: { total: number; inStock: number; avgPrice: number };
  leads: { total: number; byStatus: Record<string, number> };
  quotes: { total: number; pipelineValue: number; byStatus: Record<string, { count: number; value: number }> };
  orders: { total: number; revenue: number; byStage: Record<string, { count: number; value: number }> };
  recent: { type: string; title: string; who: string; context: string; time: string }[];
  invoices: { total: number; collected: number; outstanding: number; byStatus: Record<string, { count: number; value: number }> };
  returns: { total: number; open: number };
  crm: { clients: number; review: number; tasksDue: number; hotLeads: number };
  finance: { aov: number; target: { value: number; pct: number }; deals: { won: number; open: number };
    topProducts: { name: string; qty: number; value: number }[];
    sources: { name: string; count: number; pct: number }[];
    payments: { amount: number; method: string; reference: string | null; created_at: string; inv: string }[];
    monthly: { month: string; revenue: number }[] };
  seededAt?: string | null;
};

export const img = (file?: string) => (file ? "/img/" + file : "");

export const fmtPKR = (n: number) => "PKR " + Math.round(n).toLocaleString("en-PK");

export function timeAgo(iso: string) {
  const s = Math.max(1, Math.floor((Date.now() - Date.parse(iso)) / 1000));
  if (s < 60) return s + "s ago";
  if (s < 3600) return Math.floor(s / 60) + "m ago";
  if (s < 86400) return Math.floor(s / 3600) + "h ago";
  return Math.floor(s / 86400) + "d ago";
}

export function useApi<T>(path: string | null, intervalMs = 0) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(!!path);
  const alive = useRef(true);

  const load = useCallback(async () => {
    if (!path) return;
    try {
      const res = await fetch(path, { headers: { accept: "application/json" } });
      if (!res.ok) throw new Error("HTTP " + res.status);
      const j = (await res.json()) as T;
      if (alive.current) {
        setData(j);
        setError(null);
      }
    } catch (e) {
      if (alive.current) setError(e instanceof Error ? e.message : String(e));
    } finally {
      if (alive.current) setLoading(false);
    }
  }, [path]);

  useEffect(() => {
    alive.current = true;
    load();
    const id = intervalMs > 0 ? window.setInterval(load, intervalMs) : undefined;
    // P3: any live mutation refreshes every polling consumer within ~400ms
    let deb: number | undefined;
    const onRt = () => { window.clearTimeout(deb); deb = window.setTimeout(load, 400); };
    if (intervalMs > 0) addEventListener("woodex:rt-refresh", onRt);
    return () => {
      alive.current = false;
      if (id) window.clearInterval(id);
      window.clearTimeout(deb);
      removeEventListener("woodex:rt-refresh", onRt);
    };
  }, [load, intervalMs]);

  return { data, error, loading, reload: load, setData };
}

export async function mutate<T = unknown>(path: string, body?: unknown, method = "PATCH"): Promise<T> {
  const res = await fetch(path, {
    method,
    headers: { "content-type": "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const j = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error((j as { error?: string }).error || "HTTP " + res.status);
  return j as T;
}

/* P5 finance */
export type ApiInvoice = { id: number; ref: string; quoteId: number | null; orderId: number | null;
  customer: string; items: { name?: string; price?: number; qty?: number }[];
  subtotal: number; discount: number; tax: number; shipping: number; total: number; paid: number; balance: number;
  status: string; due: string | null; notes: string | null; createdAt: string; updatedAt: string };
export type ApiPayment = { id: number; invoice_id: number; amount: number; method: string; reference: string | null; note: string | null; created_at: string };
export type ApiInvoiceDetail = ApiInvoice & { payments: ApiPayment[] };
export type ApiReturn = { id: number; ref: string; orderId: number | null; customer: string; item: string | null;
  reason: string; state: string; refundAmount: number; resolution: string | null; createdAt: string; updatedAt: string };

/* ---------- P3 realtime core (SSE) ---------- */
export type RtEvent = { type: string; at?: string; title?: string; who?: string; context?: string };
let es: EventSource | null = null;
let rtStatus: "connecting" | "live" | "down" = "connecting";
const statusListeners = new Set<(v: "connecting" | "live" | "down") => void>();

function setStatus(v: typeof rtStatus) {
  rtStatus = v;
  statusListeners.forEach((f) => f(v));
}

/** One shared EventSource; every message is re-dispatched as `woodex:rt`. */
export function initRealtime() {
  if (es || typeof EventSource === "undefined") return;
  es = new EventSource("/api/events");
  es.onopen = () => setStatus("live");
  es.onerror = () => { setStatus("down"); }; // EventSource auto-retries (3s)
  es.onmessage = () => {}; // unnamed events land here; named ones below
  const bump = (e: MessageEvent) => {
    setStatus("live");
    try {
      dispatchEvent(new CustomEvent<RtEvent>("woodex:rt", { detail: JSON.parse(e.data) as RtEvent }));
      dispatchEvent(new CustomEvent<string>("woodex:rt-refresh", { detail: e.type }));
    } catch { /* noop */ }
  };
  for (const t of ["leads", "quotes", "orders", "products", "invoices", "payments", "returns", "hello"]) es.addEventListener(t, bump as EventListener);
}

/** Subscribe to realtime events; filter by regex, e.g. /invoices|payments/. */
export function useRealtime(types: RegExp, handler: (ev: RtEvent) => void) {
  useEffect(() => {
    initRealtime();
    const h = (e: Event) => {
      const d = (e as CustomEvent<RtEvent>).detail;
      if (d && types.test(d.type)) handler(d);
    };
    addEventListener("woodex:rt", h);
    return () => removeEventListener("woodex:rt", h);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [types.source]);
}

export function useRealtimeStatus() {
  const [v, setV] = useState(rtStatus);
  useEffect(() => {
    initRealtime();
    statusListeners.add(setV);
    return () => { statusListeners.delete(setV); };
  }, []);
  return v;
}
