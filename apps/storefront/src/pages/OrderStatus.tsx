import { useState } from "react";
import { useSearchParams, Link } from "react-router-dom";
import { Search, PackageCheck, Truck, Wrench, Factory, ClipboardCheck, Store, Receipt, Undo2, CheckCircle2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent } from "@/components/ui/card";
import Header from "@/components/Header";
import Footer from "@/components/Footer";

type Lookup = {
  ref: string; customer: string; status: string; stage: string; stages: string[]; stageIndex: number;
  total: number; due: string | null; placedAt: string; items: { name?: string; qty?: number; price?: number }[];
  invoice: { ref: string; total: number; paid: number; balance: number; status: string; due: string | null } | null;
  returns: { ref: string; state: string; reason?: string }[];
};

const STAGE_META: Record<string, { label: string; icon: typeof Factory }> = {
  production: { label: "In production", icon: Factory },
  qc: { label: "Quality check", icon: ClipboardCheck },
  dispatch: { label: "Dispatched", icon: Truck },
  delivery: { label: "Out for delivery", icon: Store },
  installation: { label: "Installation & sign-off", icon: Wrench },
};

const pkr = (n: number) => "Rs " + Math.round(n).toLocaleString("en-PK");

export default function OrderStatus() {
  const [params] = useSearchParams();
  const [ref, setRef] = useState(params.get("ref") ?? "");
  const [data, setData] = useState<Lookup | null>(null);
  const [error, setError] = useState<string | null>(params.get("ref") ? null : "Enter your order reference — e.g. WX-4308 (shown on your checkout confirmation).");
  const [busy, setBusy] = useState(false);

  const lookup = async (value?: string) => {
    const q = (value ?? ref).trim();
    if (!q) return;
    setBusy(true); setError(null);
    try {
      const res = await fetch("/api/orders/lookup?ref=" + encodeURIComponent(q));
      if (!res.ok) { setData(null); setError(`No order found for “${q}”. Check the reference from your confirmation (format WX-####).`); return; }
      setData(await res.json());
    } catch {
      setError("Tracking service is unreachable right now — please try again in a moment.");
    } finally { setBusy(false); }
  };

  return (
    <div className="min-h-screen bg-background">
      <Header />
      <main className="mx-auto max-w-4xl px-4 py-16 sm:px-6">
        <p className="text-sm font-bold uppercase tracking-widest text-primary">Live tracking</p>
        <h1 className="mt-2 text-3xl font-black text-foreground sm:text-4xl">Where is my order?</h1>
        <p className="mt-3 max-w-xl text-muted-foreground">
          Enter your reference to see production stage, invoice balance and any return (RMA) status —
          updated live from our workshop board. No login needed.
        </p>

        <form className="mt-8 flex max-w-lg gap-2" onSubmit={(e) => { e.preventDefault(); void lookup(); }}>
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input value={ref} onChange={(e) => setRef(e.target.value)} placeholder="WX-4308" className="pl-9 font-semibold uppercase tracking-wide" />
          </div>
          <Button type="submit" disabled={busy || !ref.trim()} className="bg-primary text-primary-foreground hover:bg-primary/90">
            {busy ? "Checking…" : "Track"}
          </Button>
        </form>
        {error && <p className="mt-3 max-w-lg rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-2.5 text-sm text-destructive">{error}</p>}

        {data && (
          <div className="mt-10 space-y-6">
            {/* Order summary */}
            <Card>
              <CardContent className="p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Order {data.ref}</p>
                    <p className="text-lg font-extrabold text-foreground">{data.customer}</p>
                    <p className="text-sm text-muted-foreground">{data.items.length} item{data.items.length !== 1 && "s"} · {pkr(data.total)}{data.due ? ` · target ${data.due}` : ""}</p>
                  </div>
                  <span className={`rounded-full px-3.5 py-1.5 text-sm font-bold ${data.status === "Completed" ? "bg-green-100 text-green-800" : data.status === "Delayed" ? "bg-amber-100 text-amber-800" : "bg-primary/10 text-primary"}`}>
                    {data.status}
                  </span>
                </div>
                <ul className="mt-4 grid gap-1.5 text-sm text-muted-foreground sm:grid-cols-2">
                  {data.items.map((i, n) => <li key={n}>• {i.name} × {i.qty} — {pkr((i.price ?? 0) * (i.qty ?? 1))}</li>)}
                </ul>
              </CardContent>
            </Card>

            {/* Stage timeline */}
            <Card>
              <CardContent className="p-5">
                <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Workshop progress</p>
                <ol className="mt-4 grid gap-3 sm:grid-cols-5">
                  {data.stages.map((s, i) => {
                    const meta = STAGE_META[s]; const done = i < data.stageIndex; const current = i === data.stageIndex;
                    const Icon = done || s !== data.stage ? meta?.icon ?? PackageCheck : PackageCheck;
                    return (
                      <li key={s} className={`rounded-xl border p-3 text-center transition-colors ${current ? "border-primary bg-primary/5" : done ? "border-green-300 bg-green-50" : "border-border opacity-60"}`}>
                        {done ? <CheckCircle2 className="mx-auto h-5 w-5 text-green-600" /> : <Icon className={`mx-auto h-5 w-5 ${current ? "text-primary" : "text-muted-foreground"}`} />}
                        <p className={`mt-1.5 text-xs font-bold ${current ? "text-primary" : done ? "text-green-700" : "text-muted-foreground"}`}>{meta?.label ?? s}</p>
                      </li>
                    );
                  })}
                </ol>
              </CardContent>
            </Card>

            {/* Invoice + returns */}
            <div className="grid gap-6 md:grid-cols-2">
              {data.invoice && (
                <Card>
                  <CardContent className="p-5">
                    <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-muted-foreground"><Receipt className="h-4 w-4" /> Invoice {data.invoice.ref}</p>
                    <div className="mt-3 space-y-1.5 text-sm">
                      <div className="flex justify-between"><span className="text-muted-foreground">Total</span><b>{pkr(data.invoice.total)}</b></div>
                      <div className="flex justify-between"><span className="text-muted-foreground">Paid</span><b className="text-green-700">{pkr(data.invoice.paid)}</b></div>
                      <div className="flex justify-between border-t pt-1.5"><span className="text-muted-foreground">Balance</span><b className={data.invoice.balance > 0 ? "text-amber-700" : "text-green-700"}>{pkr(data.invoice.balance)}</b></div>
                    </div>
                    <p className="mt-3 text-xs text-muted-foreground">Status: <b>{data.invoice.status}</b>{data.invoice.due ? ` · due ${data.invoice.due}` : ""} · 50% advance unlocks production start.</p>
                  </CardContent>
                </Card>
              )}
              {data.returns.length > 0 && (
                <Card>
                  <CardContent className="p-5">
                    <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-muted-foreground"><Undo2 className="h-4 w-4" /> Returns ({data.returns.length})</p>
                    <ul className="mt-3 space-y-2">
                      {data.returns.map((r) => (
                        <li key={r.ref} className="flex items-center justify-between rounded-lg bg-muted/50 px-3 py-2 text-sm">
                          <span><b>{r.ref}</b>{r.reason ? <span className="block text-xs text-muted-foreground">{r.reason}</span> : null}</span>
                          <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${r.state === "Closed" ? "bg-green-100 text-green-800" : "bg-amber-100 text-amber-800"}`}>{r.state}</span>
                        </li>
                      ))}
                    </ul>
                  </CardContent>
                </Card>
              )}
            </div>

            <p className="text-sm text-muted-foreground">
              Need help? <Link to="/contact" className="font-bold text-primary underline-offset-4 hover:underline">Contact our team</Link> or WhatsApp +92 300 0000000 with your reference.
            </p>
          </div>
        )}
      </main>
      <Footer />
    </div>
  );
}
