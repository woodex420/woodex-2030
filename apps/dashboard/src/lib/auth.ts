/* Foundation auth: bearer session + capability checks for the dashboard.
   One global fetch patch attaches the token to every /api/ call and turns any
   401 into a signed-out state — pages never handle tokens themselves. */
import { useSyncExternalStore } from "react";

export type User = { id: number; email: string; name: string; role: string; active: boolean };
type AuthState = { status: "loading" | "in" | "out"; user: User | null; caps: string[] };

const KEY = "wx_token";
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
  const r = await fetch("/api/auth/login", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ email, password }) });
  const j = await r.json().catch(() => ({} as { token?: string; user?: User; caps?: string[] }));
  if (!r.ok) throw new Error(j.error ?? "login failed");
  persistToken(j.token ?? null);
  takeReturn(); // land back where an expired session yanked us from
  set({ status: "in", user: j.user, caps: j.caps ?? [] });
  return j.user as User;
}
export async function logout() {
  try { await fetch("/api/auth/logout", { method: "POST" }); } catch { /* offline — local sign-out anyway */ }
  persistToken(null);
  try { sessionStorage.removeItem(RET_KEY); } catch { /* noop */ }
  if (location.pathname !== "/") window.history.replaceState(null, "", "/");
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
    const url = typeof input === "string" ? input : input instanceof URL ? input.pathname : (input as Request).url;
    const t = getToken();
    const headers = { ...((init.headers as Record<string, string>) ?? (input instanceof Request ? Object.fromEntries(input.headers) : {})) };
    if (t && url.startsWith("/api/") && !headers.authorization) headers.authorization = "Bearer " + t;
    const res = await orig(input as never, { ...init, headers });
    if (res.status === 401 && !url.includes("/api/auth/")) {
      persistToken(null); saveReturn();
      set({ status: "out", user: null, caps: [] });
    }
    return res;
  };
}
