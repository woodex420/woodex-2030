import { useState } from "react";
import { useLocation, useSearchParams, Link } from "react-router-dom";
import { Search, PackageCheck, Truck, Wrench, Factory, ClipboardCheck, Store, CheckCircle2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent } from "@/components/ui/card";
import Header from "@/components/Header";
import Footer from "@/components/Footer";

type Lookup = {
  ref: string;
  status: string;
  stage: string;
  stages: string[];
  stageIndex: number;
  placedAt: string;
};

type CheckoutNavigationState = { trackingToken?: string };

const STAGE_META: Record<string, { label: string; icon: typeof Factory }> = {
  production: { label: "In production", icon: Factory },
  qc: { label: "Quality check", icon: ClipboardCheck },
  dispatch: { label: "Dispatched", icon: Truck },
  delivery: { label: "Out for delivery", icon: Store },
  installation: { label: "Installation & sign-off", icon: Wrench },
};

export default function OrderStatus() {
  const [params] = useSearchParams();
  const location = useLocation();
  const passedToken = (location.state as CheckoutNavigationState | null)?.trackingToken ?? "";
  const [ref, setRef] = useState(params.get("ref") ?? "");
  const [token, setToken] = useState(passedToken);
  const [data, setData] = useState<Lookup | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const lookup = async () => {
    const orderRef = ref.trim();
    const accessCode = token.trim();
    if (!orderRef || !accessCode) {
      setData(null);
      setError("Enter both the order reference and the private access code shown after checkout.");
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const res = await fetch("/api/orders/lookup", {
        method: "POST",
        headers: { "content-type": "application/json", accept: "application/json" },
        body: JSON.stringify({ ref: orderRef, token: accessCode }),
      });
      if (!res.ok) {
        setData(null);
        setError("Tracking details could not be verified. Check the reference and private access code from your checkout confirmation.");
        return;
      }
      setData(await res.json() as Lookup);
    } catch {
      setError("Tracking service is unreachable right now — please try again in a moment.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="min-h-screen bg-background">
      <Header />
      <main className="mx-auto max-w-4xl px-4 py-16 sm:px-6">
        <p className="text-sm font-bold uppercase tracking-widest text-primary">Order tracking</p>
        <h1 className="mt-2 text-3xl font-black text-foreground sm:text-4xl">Where is my order?</h1>
        <p className="mt-3 max-w-xl text-muted-foreground">
          Use the order reference and private access code provided after checkout. The code is not part of the URL.
        </p>

        <form className="mt-8 grid max-w-lg gap-3 sm:grid-cols-[1fr_auto]" onSubmit={(event) => { event.preventDefault(); void lookup(); }}>
          <div className="relative">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input value={ref} onChange={(event) => setRef(event.target.value)} placeholder="Order reference, e.g. WX-4308" className="pl-9 font-semibold uppercase tracking-wide" autoComplete="off" />
          </div>
          <Button type="submit" disabled={busy || !ref.trim() || !token.trim()} className="bg-primary text-primary-foreground hover:bg-primary/90">
            {busy ? "Checking…" : "Track"}
          </Button>
          <div className="sm:col-span-2">
            <Input
              value={token}
              onChange={(event) => setToken(event.target.value)}
              placeholder="Private tracking access code"
              type="password"
              autoComplete="off"
              spellCheck={false}
              aria-label="Private tracking access code"
            />
            <p className="mt-1.5 text-xs text-muted-foreground">Keep this code private. Woodex cannot recover it if it is lost.</p>
          </div>
        </form>
        {error && <p role="alert" className="mt-3 max-w-lg rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-2.5 text-sm text-destructive">{error}</p>}

        {data && (
          <div className="mt-10 space-y-6">
            <Card>
              <CardContent className="flex flex-wrap items-center justify-between gap-3 p-5">
                <div>
                  <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Order {data.ref}</p>
                  <p className="mt-1 text-sm text-muted-foreground">Request received {new Date(data.placedAt).toLocaleDateString("en-PK", { dateStyle: "medium" })}</p>
                </div>
                <span className={`rounded-full px-3.5 py-1.5 text-sm font-bold ${data.status === "Completed" ? "bg-green-100 text-green-800" : data.status === "Delayed" ? "bg-amber-100 text-amber-800" : "bg-primary/10 text-primary"}`}>
                  {data.status}
                </span>
              </CardContent>
            </Card>

            <Card>
              <CardContent className="p-5">
                <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Workshop progress</p>
                <ol className="mt-4 grid gap-3 sm:grid-cols-5">
                  {data.stages.map((stage, index) => {
                    const meta = STAGE_META[stage];
                    const done = index < data.stageIndex;
                    const current = index === data.stageIndex;
                    const Icon = done ? CheckCircle2 : meta?.icon ?? PackageCheck;
                    return (
                      <li key={stage} className={`rounded-xl border p-3 text-center transition-colors ${current ? "border-primary bg-primary/5" : done ? "border-green-300 bg-green-50" : "border-border opacity-60"}`}>
                        <Icon className={`mx-auto h-5 w-5 ${current ? "text-primary" : done ? "text-green-600" : "text-muted-foreground"}`} />
                        <p className={`mt-1.5 text-xs font-bold ${current ? "text-primary" : done ? "text-green-700" : "text-muted-foreground"}`}>{meta?.label ?? stage}</p>
                      </li>
                    );
                  })}
                </ol>
              </CardContent>
            </Card>

            <p className="text-sm text-muted-foreground">
              Need help? <Link to="/contact" className="font-bold text-primary underline-offset-4 hover:underline">Contact our team</Link> and share your order reference. Never share your private tracking code in a public message.
            </p>
          </div>
        )}
      </main>
      <Footer />
    </div>
  );
}
