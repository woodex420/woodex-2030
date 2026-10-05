import { useState } from "react";
import { cn } from "@/lib/cn";
import { Card } from "@/components/ui/Card";
import { CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { Input } from "@/components/ui/Field";
import { useToast } from "@/components/ui/Toast";
import { useApi, useRealtime } from "@/lib/api";
import { can } from "@/lib/auth";
import { Check, Lock, Plus, Shield, User } from "@/icons";

type TeamUser = { id: number; email: string; name: string; role: string; active: boolean; updatedAt: string };

export function TeamCard() {
  const { push } = useToast();
  const owner = can("users.manage") || can("*");
  const { data, loading, reload } = useApi<{ items: TeamUser[]; roles: string[] }>(owner ? "/api/users" : null);
  useRealtime(/users/, () => reload());
  const [nf, setNf] = useState<{ name: string; email: string; role: string; password: string } | null>(null);
  const [pwFor, setPwFor] = useState<number | null>(null);
  const [pw, setPw] = useState("");
  if (!owner) return null;

  const patch = async (id: number, body: Record<string, unknown>, what: string) => {
    const r = await fetch(`/api/users/${id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify(body) });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) return push({ tone: "danger", title: what + " blocked", desc: j.error ?? String(r.status) });
    push({ tone: "success", title: what + " ✓" });
    reload();
  };
  const add = async () => {
    if (!nf) return;
    const r = await fetch("/api/users", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(nf) });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) return push({ tone: "danger", title: "Could not add", desc: j.error ?? String(r.status) });
    push({ tone: "success", title: "Teammate added", desc: j.name + " · " + j.role });
    setNf(null); reload();
  };
  const roles = data?.roles ?? ["owner", "editor", "sales", "finance", "viewer"];
  return (
    <Card>
      <CardHeader title="Team & access" description="Roles gate every write on the platform (RBAC on the API, not just the UI)."
        action={<Button size="sm" variant="secondary" onClick={() => setNf({ name: "", email: "", role: "sales", password: "" })}><Plus size={13} /> Add member</Button>} />
      <div className="p-4 pt-3">
        {loading ? <p className="text-caption text-subtle">Loading roster…</p> : (
          <ul className="divide-y divide-hairline">{(data?.items ?? []).map((u) => (
            <li key={u.id} className={cn("flex flex-wrap items-center gap-2 py-2", !u.active && "opacity-50")}>
              <span className="grid h-7 w-7 place-items-center rounded-full bg-surface-secondary text-[10px] font-black text-muted">{u.name.split(" ").map((w) => w[0]).slice(0, 2).join("")}</span>
              <span className="min-w-0 flex-1"><b className="block truncate text-small text-ink">{u.name}</b><span className="block truncate text-[10px] text-subtle">{u.email}</span></span>
              {!u.active && <Badge tone="danger" dot={false}>disabled</Badge>}
              <select value={u.role} onChange={(e) => void patch(u.id, { role: e.target.value }, "Role")} className="rounded-control border border-line-strong bg-white px-2 py-1 text-caption font-semibold text-ink">
                {roles.map((r) => <option key={r} value={r}>{r}</option>)}
              </select>
              <button onClick={() => void patch(u.id, { active: !u.active }, u.active ? "Sign-out" : "Re-activation")}
                className={cn("inline-flex items-center gap-1 rounded-control border px-2 py-1 text-[10px] font-bold", u.active ? "border-hairline text-muted hover:border-danger/40 hover:text-danger-strong" : "border-primary-400 bg-primary-50 text-primary-800")}>
                <Lock size={10} /> {u.active ? "disable" : "enable"}
              </button>
              {pwFor === u.id ? (
                <span className="flex items-center gap-1">
                  <Input type="password" value={pw} onChange={(e) => setPw(e.target.value)} placeholder="new password ≥6" className="w-32" />
                  <Button size="sm" onClick={() => { void patch(u.id, { password: pw }, "Password"); setPwFor(null); setPw(""); }}><Check size={12} /></Button>
                  <Button size="sm" variant="secondary" onClick={() => setPwFor(null)}>×</Button>
                </span>
              ) : <button onClick={() => setPwFor(u.id)} className="text-[10px] font-bold text-primary-600 hover:underline">reset pw</button>}
            </li>))}
          </ul>)}
        {nf && (
          <div className="mt-3 rounded-card border border-primary-200 bg-primary-50/40 p-3">
            <p className="mb-2 flex items-center gap-1.5 text-caption font-bold text-primary-900"><Shield size={12} /> New teammate</p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-4">
              <Input placeholder="Name" value={nf.name} onChange={(e) => setNf({ ...nf, name: e.target.value })} />
              <Input placeholder="email@woodex.pk" value={nf.email} onChange={(e) => setNf({ ...nf, email: e.target.value })} />
              <select value={nf.role} onChange={(e) => setNf({ ...nf, role: e.target.value })} className="rounded-control border border-line-strong bg-white px-2 text-caption">
                {roles.map((r) => <option key={r} value={r}>{r}</option>)}
              </select>
              <Input type="password" placeholder="password ≥6" value={nf.password} onChange={(e) => setNf({ ...nf, password: e.target.value })} />
            </div>
            <div className="mt-2 flex justify-end gap-1.5">
              <Button size="sm" variant="secondary" onClick={() => setNf(null)}>Cancel</Button>
              <Button size="sm" onClick={() => void add()}><User size={12} /> Create account</Button>
            </div>
          </div>)}
        <p className="mt-2 text-[10px] text-subtle">Disabling a member instantly kills their active sessions. The last enabled owner can’t be demoted; you can’t disable yourself.</p>
      </div>
    </Card>
  );
}
