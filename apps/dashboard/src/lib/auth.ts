/* Foundation auth: bearer session + capability checks for the dashboard.
   One global fetch patch attaches the token to every /api/ call and turns any
   401 into a signed-out state — pages never handle tokens themselves. */
import { useSyncExternalStore } from "react";

export type User = { id: number; email: string; name: string; role: string; active: boolean };
type AuthState = { status: "loading" | "in" | "out"; user: User | null; caps: string[] };

const KEY = "wx_token";
const DASHBOARD_BASE = import.meta.env.BASE_URL.replace(/\/$/, "");
const DASHBOARD_HOME = DASHBOARD_BASE ? `${DASHBOARD_BASE}/` : "/";
/* The preview runs in an iframe where localStorage can be silently unavailable.
   The token's source of truth is this module variable; storage is best-effort
   persistence for surviving reloads. */
let memToken: string | null = (() => { try { return localStorage.getItem(KEY); } catch { return null; } })();
function persistToken(t: string | null) {
  memToken = t;
  try { t ? localStorage.setItem(KEY, t) : localStorage.removeItem(KEY); } catch { /* partitioned storage — in-tab session still works */ }
}
let state: AuthState = { status: memToken ? "loading" : "out", user: null, caps: [] };
const RET_KEY = "wx_return";
const saveReturn = () => { try { const p = location.pathname + location.search; if (p && p !== "/") sessionStorage.setItem(RET_KEY, p); } catch { /* noop */ } };
const takeReturn = () => { try { const p = sessionStorage.getItem(RET_KEY); if (p) { sessionStorage.removeItem(RET_KEY); window.history.replaceState(null, "", p); } } catch { /* noop */ } };
const subs = new Set<() => void>();
const set = (patch: Partial<AuthState>) => { state = { ...state, ...patch }; subs.forEach((f) => f()); };

export const getToken = () => memToken;

export async function login(email: string, password: string) {
  let r: Response;
  try {
    r = await fetch("/api/auth/login", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ email, password }),
      signal: AbortSignal.timeout(15_000),
    });
  } catch (e) {
    if (e instanceof Error && (e.name === "TimeoutError" || e.name === "AbortError"))
      throw new Error("Sign-in timed out. Check that the dashboard and API are running, then try again.");
    throw e;
  }

  type LoginReply = { token?: unknown; user?: unknown; caps?: unknown; error?: unknown };
  const body = await r.json().catch(() => null) as LoginReply | null;
  if (!r.ok) {
    throw new Error(typeof body?.error === "string" ? body.error : `Sign-in failed (HTTP ${r.status})`);
  }

  // Do not treat a 200 HTML page (for example, a misrouted API proxy fallback)
  // as a successful login. That used to mount the dashboard without a token,
  // then immediately bounce the user back to this form on the first API call.
  const replyUser = body?.user;
  if (!body || typeof body.token !== "string" || !body.token.trim() || !replyUser ||
      typeof replyUser !== "object" || Array.isArray(replyUser) ||
      typeof (replyUser as { name?: unknown }).name !== "string" ||
      typeof (replyUser as { role?: unknown }).role !== "string") {
    throw new Error("The sign-in service returned an unexpected response. Check the dashboard/API connection and try again.");
  }

  const user = replyUser as User;
  const caps = Array.isArray(body.caps) ? body.caps.filter((cap): cap is string => typeof cap === "string") : [];
  persistToken(body.token);
  // Keep auth redirects inside the dashboard subpath when it is served alongside the Storefront.
  const appPath = DASHBOARD_BASE && location.pathname.startsWith(DASHBOARD_BASE)
    ? location.pathname.slice(DASHBOARD_BASE.length) || "/"
    : location.pathname;
  if (["/", "/login", "/signin", "/dashboard", "/home", "/overview"].includes(appPath))
    history.replaceState(null, "", DASHBOARD_HOME);
  takeReturn(); // land back where an expired session yanked us from
  set({ status: "in", user, caps });
  return user;
}
export async function logout() {
  try { await fetch("/api/auth/logout", { method: "POST" }); } catch { /* offline — local sign-out anyway */ }
  persistToken(null);
  try { sessionStorage.removeItem(RET_KEY); } catch { /* noop */ }
  if (location.pathname !== DASHBOARD_HOME) window.history.replaceState(null, "", DASHBOARD_HOME);
  set({ status: "out", user: null, caps: [] });
}
export async function restoreSession() {
  const t = getToken();
  if (!t) return set({ status: "out" });
  try {
    const r = await fetch("/api/auth/me");
    if (!r.ok) { persistToken(null); return set({ status: "out" }); }
    const j = await r.json();
    set({ status: "in", user: j.user, caps: j.caps ?? [] });
  } catch { set({ status: "out" }); }
}

export function can(cap: string): boolean {
  return state.status === "in" && (state.caps.includes("*") || state.caps.includes(cap));
}
export const authState = () => state;

export function useAuth() {
  return useSyncExternalStore(
    (cb) => { subs.add(cb); return () => subs.delete(cb); },
    authState,
    authState
  );
}

let patched = false;
export function installFetchPatch() {
  if (patched) return;
  patched = true;
  const orig = window.fetch.bind(window);
  window.fetch = async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const url = typeof input === "string" ? input : input instanceof URL ? input.href : input.url;
    let path = url;
    let sameOrigin = false;
    try {
      const resolved = new URL(url, window.location.href);
      path = resolved.pathname;
      sameOrigin = resolved.origin === window.location.origin;
    } catch { /* keep the raw URL for diagnostics */ }

    // Headers() preserves Request/Headers inputs (object spread silently drops them).
    const headers = new Headers(input instanceof Request ? input.headers : undefined);
    if (init.headers) new Headers(init.headers).forEach((value, key) => headers.set(key, value));
    const t = getToken();
    if (t && sameOrigin && path.startsWith("/api/")) {
      if (!headers.has("authorization")) headers.set("Authorization", "Bearer " + t);
      // Some preview/reverse proxies remove Authorization. This same-origin
      // bearer fallback lets the API validate the same session without cookies.
      if (!headers.has("x-woodex-session")) headers.set("X-Woodex-Session", t);
    }

    const res = await orig(input as never, { ...init, headers });
    if (res.status === 401 && sameOrigin && path.startsWith("/api/") && !path.startsWith("/api/auth/")) {
      persistToken(null); saveReturn();
      set({ status: "out", user: null, caps: [] });
    }
    return res;
  };
}
