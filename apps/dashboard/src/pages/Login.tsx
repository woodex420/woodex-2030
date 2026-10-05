import { useState } from "react";
import { login } from "@/lib/auth";
import { Button } from "@/components/ui/Button";
import { Field, Input } from "@/components/ui/Field";
import { Lock, Sparkles } from "@/icons";

const DEMO = [
  ["usman@woodex.pk", "Owner — everything incl. team + purge"],
  ["ayesha@woodex.pk", "Editor — website, theme, catalog"],
  ["bilal@woodex.pk", "Sales — CRM, inbox, quotes, orders"],
  ["farhan@woodex.pk", "Finance — invoices, payments, returns"],
  ["guest@woodex.pk", "Viewer — read-only"],
];

export default function Login() {
  const [email, setEmail] = useState("usman@woodex.pk");
  const [pw, setPw] = useState("woodex123");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const go = async () => {
    setBusy(true); setErr(null);
    try { await login(email.trim(), pw); } catch (e) { setErr(e instanceof Error ? e.message : String(e)); }
    finally { setBusy(false); }
  };
  return (
    <div className="grid min-h-screen place-items-center bg-surface-secondary px-4" style={{ backgroundImage: "radial-gradient(700px 380px at 50% -10%, var(--color-primary-50, #f0fdf4), transparent)" }}>
      <div className="w-full max-w-sm">
        <div className="mb-5 text-center">
          <span className="mx-auto grid h-12 w-12 place-items-center rounded-card bg-primary-600 text-white"><Lock size={20} /></span>
          <h1 className="mt-3 text-h2 font-black text-ink">Woodex Workspace</h1>
          <p className="text-caption text-muted">Sign in — the storefront stays public, this doesn't.</p>
        </div>
        <div className="rounded-card border border-hairline bg-white p-5 shadow-card">
          <form onSubmit={(e) => { e.preventDefault(); void go(); }}>
            <Field label="Work email"><Input autoFocus value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@woodex.pk" autoComplete="username" /></Field>
            <div className="mt-3"><Field label="Password"><Input type="password" value={pw} onChange={(e) => setPw(e.target.value)} autoComplete="current-password" /></Field></div>
            {err && <p className="mt-3 rounded-control bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}
            <Button className="mt-4 w-full justify-center" type="submit" disabled={busy}>{busy ? "Checking…" : "Sign in"}</Button>
          </form>
          <p className="mt-4 mb-1.5 flex items-center gap-1 text-[10px] font-bold uppercase tracking-wide text-subtle"><Sparkles size={10} /> demo accounts · password “woodex123”</p>
          <ul className="space-y-1">
            {DEMO.map(([e, w]) => (
              <li key={e}>
                <button onClick={() => { setEmail(e); setPw("woodex123"); }} className="flex w-full items-baseline justify-between gap-2 rounded-control px-2 py-1 text-left text-caption transition-colors hover:bg-surface-secondary">
                  <b className="text-ink">{e.split("@")[0]}</b><span className="truncate text-[10px] text-subtle">{w}</span>
                </button>
              </li>))}
          </ul>
        </div>
        <p className="mt-3 text-center text-[10px] text-subtle">Tokens live 7 days · accounts &amp; roles managed in Settings → Team</p>
      </div>
    </div>
  );
}
