/* P9 first-party analytics pixel — localStorage visitor id, sendBeacon, no cookies.
   Views fire once per session per slug; CTA clicks via [data-wx-cta] delegation;
   scroll depth fires 50% then 90% once per view. */
type Kind = "view" | "cta" | "scroll";

const LS_CID = "wx_cid";
export function visitorId(): string {
  try {
    let c = localStorage.getItem(LS_CID);
    if (!c) {
      c = typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : Math.random().toString(36).slice(2) + Date.now().toString(36);
      localStorage.setItem(LS_CID, c);
    }
    return c;
  } catch { return "anon"; }
}

function utm(): Record<string, string> | undefined {
  const p = new URLSearchParams(location.search);
  const pick = (k: string) => p.get("utm_" + k) ?? undefined;
  const o = { source: pick("source"), medium: pick("medium"), campaign: pick("campaign") };
  return o.source || o.medium || o.campaign ? o : undefined;
}

function post(payload: Record<string, unknown>) {
  const body = new Blob([JSON.stringify(payload)], { type: "application/json" });
  if (navigator.sendBeacon?.("/api/track", body)) return;
  void fetch("/api/track", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(payload), keepalive: true }).catch(() => {});
}

export function trackPage(slug: string) {
  const seenKey = "wx_seen_" + slug;
  try { if (sessionStorage.getItem(seenKey)) return; sessionStorage.setItem(seenKey, "1"); } catch { /* private mode → count every load */ }
  post({ slug, kind: "view", cid: visitorId(), ref: document.referrer || undefined, utm: utm() });
}

/** Fire the moment lead-form actually sends (separate from data-wx-cta clicks). */
export function trackCta(slug: string, label: string, href?: string) {
  post({ slug, kind: "cta", label: label.slice(0, 60), href: href?.slice(0, 300), cid: visitorId() });
}

/** Click delegation + scroll depth for one page; returns detach(). */
export function attachTrackers(slug: string) {
  const onClick = (e: MouseEvent) => {
    const el = (e.target as HTMLElement | null)?.closest?.("[data-wx-cta]") as HTMLElement | null;
    if (!el) return;
    trackCta(slug, el.getAttribute("data-wx-cta") || el.textContent?.trim().slice(0, 40) || "cta", el.getAttribute("href") ?? undefined);
  };
  document.addEventListener("click", onClick);
  let f50 = false, f90 = false;
  const onScroll = () => {
    const h = document.documentElement;
    const pct = (h.scrollTop + h.clientHeight) / h.scrollHeight;
    if (!f50 && pct >= 0.5) { f50 = true; post({ slug, kind: "scroll", pct: 50, cid: visitorId() }); }
    if (!f90 && pct >= 0.9) { f90 = true; post({ slug, kind: "scroll", pct: 90, cid: visitorId() }); }
  };
  window.addEventListener("scroll", onScroll, { passive: true });
  return () => { document.removeEventListener("click", onClick); window.removeEventListener("scroll", onScroll); };
}
