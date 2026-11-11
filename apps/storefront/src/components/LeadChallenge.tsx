import { useEffect, useRef, useState } from "react";

type Turnstile = {
  render: (element: HTMLElement, options: Record<string, unknown>) => string;
  remove: (id: string) => void;
  reset: (id: string) => void;
};
declare global { interface Window { turnstile?: Turnstile } }
let loading: Promise<void> | undefined;
function loadChallenge(): Promise<void> {
  if (window.turnstile) return Promise.resolve();
  if (!loading) loading = new Promise<void>((resolve, reject) => {
    const script = document.createElement("script");
    script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    script.async = true;
    const fail = () => { clearTimeout(timeout); script.remove(); loading = undefined; reject(new Error("Challenge unavailable")); };
    const timeout = window.setTimeout(fail, 15000);
    script.onload = () => { clearTimeout(timeout); if (window.turnstile) resolve(); else fail(); };
    script.onerror = fail;
    document.head.appendChild(script);
  });
  return loading;
}

/** V1 only. Site key is public; the verification secret must never enter the storefront bundle. */
export default function LeadChallenge({ mode = import.meta.env.VITE_LEAD_API_MODE }: { mode?: string }) {
  const host = useRef<HTMLDivElement>(null);
  const [token, setToken] = useState("");
  const [error, setError] = useState("");
  const [attempt, setAttempt] = useState(0);
  const enabled = mode === "v1";
  const siteKey = import.meta.env.VITE_TURNSTILE_SITE_KEY || "";
  useEffect(() => {
    if (!enabled || !siteKey) return;
    let active = true;
    let widget: string | undefined;
    const reset = () => {
      setToken("");
      if (widget !== undefined) window.turnstile?.reset(widget);
    };
    window.addEventListener("woodex:lead-attempt", reset);
    loadChallenge().then(() => {
      if (!active || !host.current) return;
      widget = window.turnstile!.render(host.current, {
        sitekey: siteKey,
        callback: (value: string) => { if (active) { setToken(value); setError(""); } },
        "expired-callback": () => { if (active) { setToken(""); setError("Verification expired. Please verify again."); } },
        "error-callback": () => { if (active) { setToken(""); setError("Verification failed. Please retry."); } },
      });
    }).catch(() => { if (active) setError("Verification could not load. Check your connection and retry."); });
    return () => {
      active = false;
      window.removeEventListener("woodex:lead-attempt", reset);
      if (widget !== undefined) window.turnstile?.remove(widget);
    };
  }, [enabled, siteKey, attempt]);
  if (!enabled) return null;
  return <div className="space-y-2 sm:col-span-full">
    <input type="hidden" name="turnstile_token" value={token} />
    <div ref={host} />
    {!siteKey && <p role="status" className="text-sm">{import.meta.env.PROD ? "Enquiry verification is not configured. Please contact the team directly." : "Local rehearsal: verification provider is not configured."}</p>}
    {error && <div role="alert" className="text-sm"><p>{error}</p><button type="button" onClick={() => { setError(""); setToken(""); setAttempt(value => value + 1); }}>Retry verification</button></div>}
  </div>;
}
