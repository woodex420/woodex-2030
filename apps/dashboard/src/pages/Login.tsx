import { useEffect, useRef, useState } from "react";
import { login } from "@/lib/auth";
import { Button } from "@/components/ui/Button";
import { Field, Input } from "@/components/ui/Field";
import { Eye, EyeOff, Lock } from "@/icons";

function Spinner() {
  return <span aria-hidden className="mr-1 inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white" />;
}

export default function Login() {
  /* Seeded demo credentials are a LOCAL convenience, not a fact about every
     deployment. Production deliberately never creates the demo owner, so
     prefilling admin/admin there guaranteed a failed first attempt and the
     hint beneath the form advertised a login that could not work. Ask the API
     whether the demo account really exists (and really uses the demo password)
     before showing or prefilling any of it. */
  const [demo, setDemo] = useState<boolean | null>(null);
  const [user, setUser] = useState("");
  const [pw, setPw] = useState("");
  const [show, setShow] = useState(false);
  const [caps, setCaps] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const pwRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    let alive = true;
    fetch("/api/auth/demo-available", { headers: { accept: "application/json" } })
      .then((r) => (r.ok ? r.json() : { demo: false }))
      .then((j: { demo?: boolean }) => {
        if (!alive) return;
        const on = j?.demo === true;
        setDemo(on);
        if (on) { setUser("admin"); setPw("admin"); }
      })
      .catch(() => { if (alive) setDemo(false); });
    return () => { alive = false; };
  }, []);

  const go = async () => {
    if (busy || !user.trim()) return;
    setBusy(true); setErr(null);
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        await login(user.trim(), pw);
        return; // success unmounts this screen
      } catch (e) {
        if (e instanceof TypeError) {
          // API likely hot-restarting (watcher) — retry quietly, then speak up.
          setErr(attempt < 2 ? "API is restarting — retrying…" : null);
          if (attempt < 2) { await new Promise((r) => setTimeout(r, 900)); continue; }
          /* Two audiences, two sentences. "Use Run server on the API card in the
             preview panel" is meaningless to someone on the deployed site, and
             it is the sort of leftover that makes a real outage look like a
             setup mistake on the user's side. */
          setErr(import.meta.env.DEV
            ? "Can’t reach the Woodex API — use “Run server” on the API card, then retry."
            : "Can’t reach the Woodex API right now. Please retry in a moment.");
          setBusy(false);
          window.setTimeout(() => pwRef.current?.focus(), 0);
          return;
        }
        setErr(e instanceof Error ? e.message : String(e)); setBusy(false);
        window.setTimeout(() => pwRef.current?.focus(), 0);
        return;
      }
    }
  };
  return (
    <div className="grid min-h-screen place-items-center bg-surface-secondary px-4 py-8" style={{ backgroundImage: "radial-gradient(700px 380px at 50% -10%, var(--color-primary-50, #f0fdf4), transparent)" }}>
      <div className="w-full max-w-sm">
        <div className="mb-5 text-center">
          <span className="mx-auto grid h-12 w-12 place-items-center rounded-card bg-primary-600 text-white shadow-cta"><Lock size={20} /></span>
          <h1 className="mt-3 text-h2 font-black text-ink">Woodex Workspace</h1>
          <p className="text-caption text-muted">Sign in — the storefront stays public, this doesn&rsquo;t.</p>
        </div>
        <div className="rounded-card border border-hairline bg-white p-5 shadow-card">
          <form onSubmit={(e) => { e.preventDefault(); void go(); }} noValidate>
            <Field label="Username" required>
              <Input autoFocus value={user} onChange={(e) => { setUser(e.target.value); setErr(null); }} placeholder="admin" autoComplete="username" spellCheck={false} disabled={busy} />
            </Field>
            <div className="mt-3">
              <Field label="Password" required>
                <span className="relative block">
                  <Input ref={pwRef} type={show ? "text" : "password"} value={pw} onChange={(e) => { setPw(e.target.value); setErr(null); }} onKeyUp={(e) => setCaps(e.getModifierState?.("CapsLock") ?? false)} autoComplete="current-password" className="pr-10" disabled={busy} />
                  <button type="button" tabIndex={-1} onClick={() => setShow((v) => !v)} aria-label={show ? "Hide password" : "Show password"}
                    className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-1 text-subtle transition-colors hover:bg-surface-secondary hover:text-ink">
                    {show ? <EyeOff size={15} /> : <Eye size={15} />}
                  </button>
                </span>
              </Field>
              {caps && <p className="mt-1 text-[11px] font-semibold text-warning-strong">⌫ Caps Lock is on</p>}
            </div>
            {err && (
              <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>
            )}
            <Button type="submit" className="mt-4 h-11 w-full text-small font-bold" disabled={busy || !user.trim()}>
              {busy ? <><Spinner /> Signing in…</> : "Sign in"}
            </Button>
          </form>
          {demo === true && (
            <p className="mt-3 text-center text-[11px] text-subtle">Demo access — user <b className="font-mono text-ink">admin</b> · pass <b className="font-mono text-ink">admin</b> · team &amp; roles in Settings</p>
          )}
          {demo === false && (
            <p className="mt-3 text-center text-[11px] text-subtle">Sign in with the owner account for this deployment.</p>
          )}
        </div>
        <p className="mt-3 text-center text-[10px] text-subtle">Sessions live 7 days · works inside the preview iframe (no storage needed)</p>
      </div>
    </div>
  );
}
