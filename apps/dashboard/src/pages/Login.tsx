import { useRef, useState } from "react";
import { login } from "@/lib/auth";
import { Button } from "@/components/ui/Button";
import { Field, Input } from "@/components/ui/Field";
import { Eye, EyeOff, Lock } from "@/icons";

function Spinner() {
  return <span aria-hidden className="mr-1 inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white" />;
}

export default function Login() {
  const [user, setUser] = useState("admin");
  const [pw, setPw] = useState("admin");
  const [show, setShow] = useState(false);
  const [caps, setCaps] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const pwRef = useRef<HTMLInputElement>(null);
  const go = async () => {
    if (busy || !user.trim()) return;
    setBusy(true); setErr(null);
    try {
      await login(user.trim(), pw);
      return; // success unmounts this screen
    } catch (e) {
      const msg = e instanceof TypeError
        ? "Can’t reach the Woodex API — it’s booting or offline; try again in a moment."
        : e instanceof Error ? e.message : String(e);
      setErr(msg); setBusy(false);
      window.setTimeout(() => pwRef.current?.focus(), 0);
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
          <p className="mt-3 text-center text-[11px] text-subtle">Demo access — user <b className="font-mono text-ink">admin</b> · pass <b className="font-mono text-ink">admin</b> · team &amp; roles in Settings</p>
        </div>
        <p className="mt-3 text-center text-[10px] text-subtle">Sessions live 7 days · works inside the preview iframe (no storage needed)</p>
      </div>
    </div>
  );
}
