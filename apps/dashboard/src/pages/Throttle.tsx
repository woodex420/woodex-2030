import { useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate } from "@/lib/api";
import { RefreshCw, ShieldCheck, Trash2 } from "@/icons";

/* Login locks.
 *
 * The lockout is a fact about an account, not about the process that served the
 * request — which is why it lives in the database and survives a cold start.
 * That also means a person can be locked out and nobody can see why from the
 * code, so it needs a screen.
 *
 * The screen exists to answer three questions an operator actually has:
 *   - is anybody locked right now, and for how much longer?
 *   - which address is producing the failures?
 *   - and, when somebody is standing in front of me and has proved who they
 *     are, can I lift it — with the lifting on the record?
 */

type Bucket = {
  key: string; kind: "login" | "ip"; failures: number;
  windowStartedAt: string; lastSeenAt: string;
  locked: boolean; retryAfterSeconds: number;
};
type Payload = { total: number; locked: number; items: Bucket[] };

const AGE = (iso: string) => {
  const mins = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 60_000));
  if (mins < 1) return "just now";
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.round(mins / 60);
  return hrs < 24 ? `${hrs}h ago` : `${Math.round(hrs / 24)}d ago`;
};

export default function Throttle() {
  const { push } = useToast();
  const [onlyLocked, setOnlyLocked] = useState(true);
  const [busy, setBusy] = useState<string | null>(null);
  const q = useApi<Payload>(`/api/ops/throttle${onlyLocked ? "?locked=1" : ""}`, 15000);

  const reload = () => q.reload();

  const clear = async (b: Bucket) => {
    if (busy) return;
    const who = b.key.replace(/^(login|ip):/, "");
    if (!window.confirm(`Clear the lock for ${who}?\n\nThis is recorded in the audit log.`)) return;
    setBusy(b.key);
    try {
      await mutate(`/api/ops/throttle/${encodeURIComponent(b.key)}`, undefined, "DELETE");
      push({ tone: "success", title: "Lock cleared", desc: `${who} can try again now — and the clearing is on the record.` });
      reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not clear", desc: e instanceof Error ? e.message : String(e) });
    } finally { setBusy(null); }
  };

  const sweep = async () => {
    if (busy) return;
    setBusy("sweep");
    try {
      const out = await mutate<{ removed: number; remaining: number }>("/api/ops/throttle/sweep", undefined, "POST");
      push({ tone: "info", title: `Swept ${out.removed} stale bucket${out.removed === 1 ? "" : "s"}`,
        desc: `${out.remaining} remain.` });
      reload();
    } catch (e) {
      push({ tone: "danger", title: "Sweep failed", desc: e instanceof Error ? e.message : String(e) });
    } finally { setBusy(null); }
  };

  const data = q.data;

  return (
    <>
      <PageHeader
        title="Login locks"
        description="Who is locked out right now, and why. These locks live in the database, so a cold start does not forget them."
        actions={
          <>
            <Button variant="ghost" onClick={reload}><RefreshCw size={15} /> Refresh</Button>
            <Button variant="ghost" onClick={() => void sweep()} disabled={busy === "sweep"}>
              {busy === "sweep" ? "Sweeping…" : "Sweep stale"}
            </Button>
          </>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <label className="flex items-center gap-2 text-caption text-muted">
          <input type="checkbox" checked={onlyLocked} onChange={(e) => setOnlyLocked(e.target.checked)} />
          Only locked
        </label>
        {data && (
          <span className="text-caption text-muted">
            {data.locked} locked · {data.total} bucket{data.total === 1 ? "" : "s"} shown
          </span>
        )}
      </div>

      <Card className="overflow-hidden">
        <CardHeader title="Throttle buckets"
          description="An account lock stops a targeted guess. An address lock stops one source spraying many accounts." />
        {q.loading && !data ? (
          <div className="space-y-2 p-4"><Skeleton className="h-12" /><Skeleton className="h-12" /><Skeleton className="h-12" /></div>
        ) : q.error ? (
          <ErrorState title="Throttle API unreachable" debug={q.error} onRetry={q.reload} />
        ) : (data?.items ?? []).length === 0 ? (
          <EmptyState icon={<ShieldCheck size={20} />}
            title={onlyLocked ? "Nobody is locked out" : "No attempts recorded"}
            description={onlyLocked ? "Every login is going through. This is the normal state." : undefined} />
        ) : (
          <ul className="divide-y divide-line">
            {(data?.items ?? []).map((b) => {
              const who = b.key.replace(/^(login|ip):/, "");
              return (
                <li key={b.key} className="flex flex-wrap items-center gap-3 px-4 py-3">
                  <Badge tone={b.kind === "login" ? "info" : "warning"}>{b.kind === "login" ? "account" : "address"}</Badge>
                  <span className="min-w-0 flex-1">
                    <b className="block truncate font-mono text-small text-ink">{who}</b>
                    <span className="block text-[11px] text-subtle">
                      {b.failures} failed attempt{b.failures === 1 ? "" : "s"} · last seen {AGE(b.lastSeenAt)}
                    </span>
                  </span>
                  {b.locked ? (
                    <span className="text-caption font-semibold text-danger-strong">
                      locked · {b.retryAfterSeconds}s left
                    </span>
                  ) : (
                    <span className="text-caption text-subtle">counting</span>
                  )}
                  <Button variant="ghost" onClick={() => void clear(b)} disabled={busy === b.key}>
                    <Trash2 size={13} /> {busy === b.key ? "Clearing…" : "Clear"}
                  </Button>
                </li>
              );
            })}
          </ul>
        )}
        <p className="border-t border-line px-4 py-2.5 text-[11px] text-subtle">
          Five failed attempts on one account within a minute lock it for a minute — and a cold start no longer resets that count.
          Clearing a lock is a security action and is written to the audit log.
        </p>
      </Card>
    </>
  );
}
