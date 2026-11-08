import { useCallback, useEffect, useRef, useState } from "react";
import { getToken } from "@/lib/auth";

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
  email?: string | null;
  phone?: string | null;
  preferredChannel?: "email" | "phone" | "whatsapp" | null;
  contactConsent?: boolean | null;
  returning?: boolean | null;
  identityReview?: boolean | null;
  consentAt?: string | null;
  consentVersion?: string | null;
  landingPath?: string | null;
  referrer?: string | null;
  utmSource?: string | null;
  utmMedium?: string | null;
  utmCampaign?: string | null;
  status: string;
  owner?: string | null;
  note?: string | null;
  clientId?: number | null;
  score?: number;
  scoreWhy?: string[];
  createdAt: string;
  updatedAt: string;
};

export type ApiSiteVisit = {
  id: number;
  ref: string;
  leadId: number;
  clientId: number;
  leadName: string | null;
  leadRef: string | null;
  scheduledAt: string;
  timezone: string;
  address: string;
  owner: string;
  status: "Scheduled" | "Confirmed" | "Completed" | "Cancelled";
  notes: string | null;
  outcome: string | null;
  reminderAt: string | null;
  reminderTaskId: number | null;
  audit: { who: string; action: string; fields: string[]; time: string }[];
  createdAt: string;
  updatedAt: string;
};

export type ApiQuoteItem = {
  id?: string;
  productId?: string;
  name: string;
  category?: string;
  series?: string;
  price: number;
  qty: number;
  lineTotal?: number;
  material?: string;
  finish?: string;
  width?: number;
  height?: number;
  depth?: number;
  dimensionUnit?: "mm" | "cm" | "in" | string;
  customNotes?: string;
};
export type ApiQuoteTemplateBlockType = "brand" | "title" | "meta" | "customer" | "terms" | "payment-note" | "contact" | "custom";
export type ApiQuoteTemplateBlock = {
  id: string;
  type: ApiQuoteTemplateBlockType;
  label: string;
  content?: string;
  visible: boolean;
};
export type ApiQuoteTemplate = {
  id: string;
  name: string;
  brandColor: string;
  logoText?: string;
  companyName: string;
  companyAddress: string;
  companyPhone: string;
  companyEmail: string;
  header: ApiQuoteTemplateBlock[];
  footer: ApiQuoteTemplateBlock[];
  updatedAt?: string;
};
export type ApiQuoteDetails = {
  company?: string;
  email?: string;
  phone?: string;
  siteAddress?: string;
  quoteTemplateId?: string;
  quoteTemplateSnapshot?: ApiQuoteTemplate;
  issueDate?: string;
  validUntil?: string;
  currency?: "PKR" | string;
  discountPercent?: number;
  taxPercent?: number;
  shipping?: number;
  terms?: string;
  calculation?: { currency: string; subtotal: number; discount: number; tax: number; shipping: number; total: number };
};
export type ApiQuoteVersion = {
  id: number;
  quoteId: number;
  version: number;
  snapshot: Pick<ApiQuote, "customer" | "contact" | "items" | "total" | "source" | "note" | "details" | "leadId">;
  actor: string | null;
  action: string | null;
  createdAt: string;
};
export type ApiQuote = {
  id: number;
  ref: string;
  customer: string;
  contact?: string | null;
  leadId?: number | null;
  clientId?: number | null;
  items: ApiQuoteItem[];
  total: number;
  status: string;
  source: string;
  note?: string | null;
  details: ApiQuoteDetails;
  version: number;
  audit: { who: string; action: string; time: string }[];
  createdAt: string;
  updatedAt: string;
};

export type ApiOrderItem = { id?: string; productId?: string; name?: string; price?: number; qty?: number; color?: string; image?: string };
export type ApiOrder = {
  id: number;
  ref: string;
  customer: string;
  items: ApiOrderItem[];
  total: number;
  status: string;
  stage: string;
  owner: string;
  due: string;
  createdAt?: string;
  updatedAt?: string;
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

export const img = (file?: string) => {
  if (!file) return "";
  if (/^(https?:|data:)/i.test(file) || file.startsWith("/")) return file;
  return "/img/" + file;
};

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
export type ApiOrderTask = { id: number; title: string; due: string | null; owner: string; priority: string; done: boolean };
export type ApiOrderClient = { id: number; name: string; company?: string | null; email?: string | null; phone?: string | null; city?: string | null };
export type ApiOrderDetail = ApiOrder & {
  client: ApiOrderClient | null;
  invoices: ApiInvoiceDetail[];
  returns: ApiReturn[];
  tasks: ApiOrderTask[];
};

/* ---------- Serverless-compatible realtime refresh (short polling) ---------- */
export type RtEvent = { type: string; at?: string; title?: string; who?: string; context?: string };
type RtStatus = "connecting" | "live" | "polling" | "down";
let rtStatus: RtStatus = "connecting";
let pollTimer: number | null = null;
const statusListeners = new Set<(value: RtStatus) => void>();
const REFRESH_MS = 15_000;

function setStatus(value: RtStatus) {
  rtStatus = value;
  statusListeners.forEach((listener) => listener(value));
}

/** Poll fallback period. Used only where SSE is unavailable (Vercel serverless). */
const SSE_RETRY_MS = 5_000;

const dispatchRt = (event: RtEvent) => {
  window.dispatchEvent(new CustomEvent<RtEvent>("woodex:rt", { detail: event }));
  window.dispatchEvent(new CustomEvent<string>("woodex:rt-refresh", { detail: event.type }));
};

/**
 * Realtime transport.
 *
 * The API has always exposed a working SSE bus (`GET /api/events`, 39 emit sites),
 * but the dashboard never subscribed to it: it only ever fired a synthetic "poll"
 * event, which useRealtime then filtered out. Net effect — a colleague's write
 * appeared only after a manual reload.
 *
 * This attaches the real stream and keeps polling underneath for the two cases
 * SSE cannot cover: the Vercel serverless runtime (where /api/events returns 410
 * by design) and a connection that closes.
 */
let source: EventSource | null = null;
let retryTimer: number | null = null;
let started = false;
let usingSse = false;
const SSE_EVENTS = ["leads", "quotes", "orders", "tasks", "invoices", "inbox", "projects", "pages", "theme", "site-visits", "track"];

function startPolling() {
  if (pollTimer !== null || typeof window === "undefined") return;
  pollTimer = window.setInterval(() => {
    dispatchRt({ type: "poll", at: new Date().toISOString() });
  }, REFRESH_MS);
}
function stopPolling() {
  if (pollTimer !== null) { window.clearInterval(pollTimer); pollTimer = null; }
}

export function initRealtime() {
  if (started || typeof window === "undefined") return;
  started = true;

  // Polling is the floor: it works everywhere, including serverless.
  startPolling();
  if (typeof EventSource === "undefined") { setStatus("polling"); return; }

  const connect = () => {
    const token = getToken();
    if (!token) { setStatus("polling"); return; }
    try {
      // EventSource cannot send an Authorization header; the stream route accepts
      // ?token= and nothing else does.
      source = new EventSource("/api/events?token=" + encodeURIComponent(token));
    } catch {
      setStatus("polling");
      return;
    }
    setStatus("connecting");

    source.addEventListener("hello", () => {
      usingSse = true;
      setStatus("live");
      stopPolling(); // while the stream is open it is authoritative
    });

    for (const type of SSE_EVENTS) {
      source.addEventListener(type, (e) => {
        try {
          const data = JSON.parse((e as MessageEvent).data) as RtEvent;
          dispatchRt({ ...data, type: data.type || type });
        } catch {
          dispatchRt({ type });
        }
      });
    }

    source.addEventListener("error", () => {
      if (source && source.readyState === EventSource.CLOSED) {
        usingSse = false;
        source = null;
        setStatus("polling");
        startPolling();
        if (retryTimer === null) {
          retryTimer = window.setTimeout(() => { retryTimer = null; connect(); }, SSE_RETRY_MS);
        }
      }
    });
  };

  connect();
}

/** True when the live stream is attached (as opposed to polling). */
export const isLiveStream = () => usingSse;

/** Subscribe to realtime signals: server events by type, plus a poll heartbeat. */
export function useRealtime(types: RegExp, handler: (event: RtEvent) => void) {
  useEffect(() => {
    initRealtime();
    const listener = (event: Event) => {
      const detail = (event as CustomEvent<RtEvent>).detail;
      if (!detail) return;
      // "poll" is the fallback heartbeat, delivered so screens still refresh when
      // SSE is unavailable. Real events are filtered by the caller's regex.
      if (detail.type === "poll" || types.test(detail.type)) handler(detail);
    };
    addEventListener("woodex:rt", listener);
    return () => removeEventListener("woodex:rt", listener);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [types.source]);
}

export function useRealtimeStatus() {
  const [value, setValue] = useState(rtStatus);
  useEffect(() => {
    initRealtime();
    statusListeners.add(setValue);
    return () => { statusListeners.delete(setValue); };
  }, []);
  return value;
}
