import { useToast } from "@/components/ui/Toast";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/States";
import { useApi, mutate, fmtPKR, type ApiReturn } from "@/lib/api";
import { Undo2 } from "@/icons";

const STATES = ["Requested", "Inspecting", "Refunding", "Closed"] as const;
const TONE: Record<string, "warning" | "primary" | "success" | "danger"> = {
  Requested: "warning", Inspecting: "primary", Refunding: "danger", Closed: "success",
};

/** P5 — RMA queue fed by storefront /order-status and staff intake. */
export function ReturnsPanel() {
  const { data, reload } = useApi<{ open: number; items: ApiReturn[] }>("/api/returns", 20000);
  const { push } = useToast();
  const items = data?.items ?? [];

  const advance = async (r: ApiReturn) => {
    const next = STATES[Math.min(STATES.indexOf(r.state as (typeof STATES)[number]) + 1, 3)];
    try {
      await mutate(`/api/returns/${r.id}`, { state: next });
      push({ tone: "primary", title: `${r.ref} → ${next}`, desc: `${r.customer} — storefront tracker updates live.` });
      reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not move RMA", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  return (
    <Card className="mt-4">
      <div className="flex items-center justify-between border-b border-hairline px-4 py-3">
        <p className="flex items-center gap-2 text-small font-semibold text-ink">
          <Undo2 size={15} className="text-primary-700" /> Returns &amp; RMA queue
          {data && data.open > 0 && <Badge tone="warning">{data.open} open</Badge>}
        </p>
        <Button size="sm" variant="secondary" onClick={reload}>Refresh</Button>
      </div>
      {items.length === 0 ? (
        <div className="p-6"><EmptyState icon={<Undo2 size={20} />} title="No returns" description="RMAs opened from the storefront order tracker or here will queue up in ~20s." /></div>
      ) : (
        <ul className="divide-y divide-hairline">
          {items.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
              <span className="min-w-0">
                <span className="block truncate text-small font-semibold text-ink">{r.ref} · {r.customer}</span>
                <span className="block truncate text-caption text-subtle">{r.item ? `${r.item} — ` : ""}{r.reason}{r.refundAmount ? ` · refund ${fmtPKR(r.refundAmount)}` : ""}{r.resolution ? ` · ${r.resolution}` : ""}</span>
              </span>
              <span className="flex shrink-0 items-center gap-2">
                <Badge tone={TONE[r.state] ?? "primary"} dot>{r.state}</Badge>
                {r.state !== "Closed" && <Button size="sm" onClick={() => void advance(r)}>Move to {STATES[STATES.indexOf(r.state as (typeof STATES)[number]) + 1]}</Button>}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
