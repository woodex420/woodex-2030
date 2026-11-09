import { useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, fmtPKR } from "@/lib/api";
import { Plus, RefreshCw, FileText, ShieldCheck, ChevronDown, ChevronRight, Undo2, Lock } from "@/icons";

/* Ledger — the books, as a double-entry journal.
 *
 * Every other finance screen shows a document. This one shows the thing the
 * documents mean: what each account holds and why, with debits and credits that
 * must agree.
 *
 * Two properties are visible here rather than merely claimed:
 *
 *   The trial balance adds up. `balanced` is computed from the totals, not
 *   printed as a reassuring constant, and the difference is shown when it is
 *   not zero.
 *
 *   Nothing is edited. There is no edit button anywhere on this page because
 *   the database refuses UPDATE and DELETE on journal lines. The only
 *   correction offered is Reverse, which posts a mirror entry and leaves both
 *   in place — so the reason a book needed correcting survives the correction.
 *
 * No mock rows: an empty journal means nothing has been posted. */

type Account = { id: number; code: string; name: string; kind: string; normalSide: "debit" | "credit"; balance: number; debit: number; credit: number };
type TrialBalance = {
  from: string | null; to: string | null; items: Account[];
  totalDebit: number; totalCredit: number; balanced: boolean; difference: number; entries: number;
};
type JournalLine = { id: number; accountCode: string; accountName: string; debit: number; credit: number; description: string | null };
type JournalEntry = {
  id: number; ref: string; entryDate: string; memo: string | null;
  sourceType: string | null; sourceId: number | null; reversesId: number | null;
  postedBy: string | null; lines: JournalLine[];
  totalDebit: number; totalCredit: number; balanced: boolean;
};

type DraftLine = { account: string; debit: string; credit: string; description: string };
const blankLine = (): DraftLine => ({ account: "", debit: "", credit: "", description: "" });

export default function Ledger() {
  const { push } = useToast();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [tab, setTab] = useState<"trial" | "journal">("trial");

  const [entryDate, setEntryDate] = useState(new Date().toISOString().slice(0, 10));
  const [memo, setMemo] = useState("");
  const [lines, setLines] = useState<DraftLine[]>([blankLine(), blankLine()]);

  const tb = useApi<TrialBalance>("/api/finance/trial-balance", 20000);
  const journal = useApi<{ total: number; items: JournalEntry[] }>("/api/journal?limit=100", 20000);
  const accounts = useApi<{ total: number; items: { code: string; name: string; active: boolean }[] }>("/api/accounts?active=true", 120000);

  /* Live totals for the draft, so an unbalanced entry is visible while it is
     being typed rather than as a 422 after submitting. The server checks the
     same thing; this is the difference between a form that helps and a form
     that grades. */
  const draftDebit = lines.reduce((a, l) => a + (Number(l.debit) || 0), 0);
  const draftCredit = lines.reduce((a, l) => a + (Number(l.credit) || 0), 0);
  const draftDiff = Math.round((draftDebit - draftCredit) * 100) / 100;

  const reset = () => {
    setEntryDate(new Date().toISOString().slice(0, 10));
    setMemo("");
    setLines([blankLine(), blankLine()]);
    setErr(null);
  };

  const post = async () => {
    if (saving) return;
    setErr(null);
    const clean = lines.filter((l) => l.account && (Number(l.debit) > 0 || Number(l.credit) > 0));
    if (clean.length < 2) { setErr("An entry needs at least two lines."); return; }
    if (draftDiff !== 0) { setErr(`Debits and credits differ by ${fmtPKR(Math.abs(draftDiff))}.`); return; }
    setSaving(true);
    try {
      await mutate<{ entry: JournalEntry }>("/api/journal", {
        entryDate, memo: memo || null,
        lines: clean.map((l) => ({
          account: l.account,
          debit: Number(l.debit) || 0,
          credit: Number(l.credit) || 0,
          description: l.description || null,
        })),
      }, "POST");
      push({ tone: "success", title: "Entry posted", desc: "It cannot be edited — only reversed." });
      setOpen(false); reset(); tb.reload(); journal.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  const reverse = async (e: JournalEntry) => {
    if (!confirm(`Reverse ${e.ref}?\n\nA mirror entry will be posted for the same accounts and amounts. ${e.ref} stays in the ledger — nothing is deleted.`)) return;
    try {
      const out = await mutate<{ entry: JournalEntry }>(`/api/journal/${e.id}/reverse`, {}, "POST");
      push({ tone: "info", title: `Reversed by ${out.entry.ref}`, desc: `${e.ref} remains in the ledger, as it must.` });
      tb.reload(); journal.reload();
    } catch (error) {
      push({ tone: "danger", title: "Could not reverse", desc: error instanceof Error ? error.message : String(error) });
    }
  };

  if (tb.error)
    return (
      <div>
        <PageHeader title="Ledger" />
        <ErrorState title="Finance API unreachable" description="The accounting backend did not answer." debug={tb.error} onRetry={tb.reload} />
      </div>
    );

  const accountsList = accounts.data?.items ?? [];
  const entries = journal.data?.items ?? [];

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Operations", to: "/operations" }, { label: "Ledger" }]}
        title="Ledger"
        description="Every financial event, as debits and credits that must agree. Corrections are reversals; nothing here can be edited or deleted."
        actions={
          <>
            <Button variant="secondary" onClick={() => { tb.reload(); journal.reload(); }}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { reset(); setOpen(true); }}><Plus size={15} /> Post entry</Button>
          </>
        }
      />

      {/* The one number that decides whether the books can be trusted. */}
      <Card className={`mb-4 border-l-4 py-4 ${tb.data?.balanced === false ? "border-danger" : "border-success"}`}>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <ShieldCheck size={20} className={tb.data?.balanced === false ? "text-danger" : "text-success-strong"} />
            <div>
              <p className="text-small font-semibold text-ink">
                {tb.loading ? "Checking the books…" : tb.data?.balanced ? "The books balance" : "The books do NOT balance"}
              </p>
              <p className="text-caption text-subtle">
                {tb.loading
                  ? "comparing debits and credits"
                  : `${fmtPKR(tb.data?.totalDebit ?? 0)} debits · ${fmtPKR(tb.data?.totalCredit ?? 0)} credits · ${tb.data?.entries ?? 0} entries`}
              </p>
            </div>
          </div>
          {tb.data && !tb.data.balanced && (
            <Badge tone="danger" dot={false}>off by {fmtPKR(Math.abs(tb.data.difference))}</Badge>
          )}
        </div>
      </Card>

      <div className="mb-4 flex gap-2">
        <Button variant={tab === "trial" ? "primary" : "secondary"} size="sm" onClick={() => setTab("trial")}>
          Trial balance
        </Button>
        <Button variant={tab === "journal" ? "primary" : "secondary"} size="sm" onClick={() => setTab("journal")}>
          Journal ({entries.length})
        </Button>
      </div>

      {tab === "trial" && (
        <Card className="overflow-hidden p-0">
          <table className="w-full text-left">
            <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
              <tr>
                <th className="px-4 py-2.5 font-semibold">Code</th>
                <th className="px-4 py-2.5 font-semibold">Account</th>
                <th className="px-4 py-2.5 font-semibold">Type</th>
                <th className="px-4 py-2.5 text-right font-semibold">Debit</th>
                <th className="px-4 py-2.5 text-right font-semibold">Credit</th>
                <th className="px-4 py-2.5 text-right font-semibold">Balance</th>
              </tr>
            </thead>
            <tbody>
              {tb.loading && (
                <tr><td colSpan={6} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
              )}
              {(tb.data?.items ?? []).map((a) => (
                <tr key={a.id} className={`border-b border-slate-100 last:border-0 ${a.balance === 0 && a.debit === 0 ? "opacity-50" : "hover:bg-slate-50/60"}`}>
                  <td className="px-4 py-3 font-mono text-caption text-subtle">{a.code}</td>
                  <td className="px-4 py-3 text-small text-ink">{a.name}</td>
                  <td className="px-4 py-3">
                    <Badge tone={a.kind === "revenue" ? "success" : a.kind === "expense" ? "warning" : a.kind === "liability" ? "danger" : "neutral"} dot={false}>
                      {a.kind}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right text-caption tabular-nums text-slate-700">
                    {a.debit ? fmtPKR(a.debit) : "—"}
                  </td>
                  <td className="px-4 py-3 text-right text-caption tabular-nums text-slate-700">
                    {a.credit ? fmtPKR(a.credit) : "—"}
                  </td>
                  <td className={`px-4 py-3 text-right text-small font-semibold tabular-nums ${a.balance < 0 ? "text-danger-strong" : "text-ink"}`}>
                    {fmtPKR(a.balance)}
                    <span className="ml-1 text-caption font-normal text-subtle">{a.normalSide === "debit" ? "Dr" : "Cr"}</span>
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot className="border-t-2 border-line-strong bg-slate-50">
              <tr>
                <td colSpan={3} className="px-4 py-3 text-small font-semibold text-ink">Totals</td>
                <td className="px-4 py-3 text-right text-small font-semibold tabular-nums text-ink">{fmtPKR(tb.data?.totalDebit ?? 0)}</td>
                <td className="px-4 py-3 text-right text-small font-semibold tabular-nums text-ink">{fmtPKR(tb.data?.totalCredit ?? 0)}</td>
                <td className="px-4 py-3 text-right text-caption text-subtle">
                  {tb.data?.balanced ? "balanced" : `off by ${fmtPKR(Math.abs(tb.data?.difference ?? 0))}`}
                </td>
              </tr>
            </tfoot>
          </table>
        </Card>
      )}

      {tab === "journal" && (
        <Card className="overflow-hidden p-0">
          <table className="w-full text-left">
            <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
              <tr>
                <th className="w-8 px-3 py-2.5" />
                <th className="px-4 py-2.5 font-semibold">Ref</th>
                <th className="px-4 py-2.5 font-semibold">Date</th>
                <th className="px-4 py-2.5 font-semibold">Memo</th>
                <th className="px-4 py-2.5 text-right font-semibold">Amount</th>
                <th className="px-4 py-2.5 text-right font-semibold">Action</th>
              </tr>
            </thead>
            <tbody>
              {journal.loading && entries.length === 0 && (
                <tr><td colSpan={6} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
              )}
              {entries.map((e) => {
                const isOpen = expanded === e.id;
                return (
                  <>
                    <tr key={e.id} className="border-b border-slate-100 hover:bg-slate-50/60">
                      <td className="px-3 py-3">
                        <button
                          className="flex h-6 w-6 items-center justify-center rounded-control text-subtle hover:bg-slate-100"
                          onClick={() => setExpanded(isOpen ? null : e.id)}
                          aria-label={isOpen ? "Collapse" : "Expand"}
                        >
                          {isOpen ? <ChevronDown size={15} /> : <ChevronRight size={15} />}
                        </button>
                      </td>
                      <td className="px-4 py-3">
                        <p className="font-mono text-caption font-semibold text-ink">{e.ref}</p>
                        {e.reversesId && <Badge tone="warning" dot={false}>reversal</Badge>}
                      </td>
                      <td className="px-4 py-3 text-caption text-slate-700">{e.entryDate}</td>
                      <td className="px-4 py-3 text-small text-slate-700">
                        {e.memo ?? "—"}
                        {e.sourceType && <span className="block text-caption text-subtle">from {e.sourceType}{e.sourceId ? ` #${e.sourceId}` : ""}</span>}
                      </td>
                      <td className="px-4 py-3 text-right text-small font-semibold tabular-nums text-ink">{fmtPKR(e.totalDebit)}</td>
                      <td className="px-4 py-3 text-right">
                        {!e.reversesId && e.sourceType !== "reversal" ? (
                          <Button variant="secondary" size="sm" onClick={() => reverse(e)}>
                            <Undo2 size={14} /> Reverse
                          </Button>
                        ) : (
                          <span className="text-caption text-subtle">—</span>
                        )}
                      </td>
                    </tr>
                    {isOpen && (
                      <tr key={`${e.id}-lines`} className="border-b border-slate-100 bg-slate-50/40">
                        <td colSpan={6} className="px-4 py-3">
                          <table className="w-full text-left">
                            <thead className="text-caption uppercase text-subtle">
                              <tr>
                                <th className="py-1.5 font-semibold">Account</th>
                                <th className="py-1.5 font-semibold">Description</th>
                                <th className="py-1.5 text-right font-semibold">Debit</th>
                                <th className="py-1.5 text-right font-semibold">Credit</th>
                              </tr>
                            </thead>
                            <tbody>
                              {e.lines.map((l) => (
                                <tr key={l.id}>
                                  <td className="py-1.5 pr-4 text-caption text-slate-700">
                                    <span className="font-mono text-subtle">{l.accountCode}</span> {l.accountName}
                                  </td>
                                  <td className="py-1.5 pr-4 text-caption text-muted">{l.description ?? "—"}</td>
                                  <td className="py-1.5 text-right text-caption tabular-nums text-slate-700">{l.debit ? fmtPKR(l.debit) : "—"}</td>
                                  <td className="py-1.5 text-right text-caption tabular-nums text-slate-700">{l.credit ? fmtPKR(l.credit) : "—"}</td>
                                </tr>
                              ))}
                              <tr className="border-t border-line">
                                <td colSpan={2} className="py-1.5 text-caption font-semibold text-ink">Total</td>
                                <td className="py-1.5 text-right text-caption font-semibold tabular-nums text-ink">{fmtPKR(e.totalDebit)}</td>
                                <td className="py-1.5 text-right text-caption font-semibold tabular-nums text-ink">{fmtPKR(e.totalCredit)}</td>
                              </tr>
                            </tbody>
                          </table>
                          <p className="mt-2 text-caption text-subtle">
                            Posted by {e.postedBy ?? "—"} · journal lines cannot be edited or deleted; correct by reversing.
                          </p>
                        </td>
                      </tr>
                    )}
                  </>
                );
              })}
            </tbody>
          </table>
          {!journal.loading && entries.length === 0 && (
            <EmptyState
              icon={<FileText size={20} />}
              title="Nothing posted yet"
              description="Expenses and issued credit notes post here automatically. A manual entry covers opening balances and anything the business does without a document."
              action={<Button onClick={() => { reset(); setOpen(true); }}><Plus size={15} /> Post entry</Button>}
            />
          )}
        </Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="Post a journal entry">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Entry date" required>
            <Input type="date" value={entryDate} onChange={(e) => setEntryDate(e.target.value)} />
          </Field>
          <Field label="Memo">
            <Input value={memo} onChange={(e) => setMemo(e.target.value)} placeholder="What is this for?" />
          </Field>
        </div>

        <div className="mt-4">
          <p className="mb-2 text-caption font-semibold uppercase text-subtle">Lines — exactly one side each</p>
          <div className="space-y-2">
            {lines.map((line, i) => (
              <div key={i} className="grid grid-cols-12 gap-2">
                <div className="col-span-5">
                  <Select
                    value={line.account}
                    onChange={(e) => { const n = [...lines]; n[i] = { ...n[i], account: e.target.value }; setLines(n); }}
                  >
                    <option value="">Account…</option>
                    {accountsList.map((a) => <option key={a.code} value={a.code}>{a.code} · {a.name}</option>)}
                  </Select>
                </div>
                <Input
                  type="number" placeholder="Debit" className="col-span-3"
                  value={line.debit}
                  onChange={(e) => { const n = [...lines]; n[i] = { ...n[i], debit: e.target.value, credit: "" }; setLines(n); }}
                />
                <Input
                  type="number" placeholder="Credit" className="col-span-3"
                  value={line.credit}
                  onChange={(e) => { const n = [...lines]; n[i] = { ...n[i], credit: e.target.value, debit: "" }; setLines(n); }}
                />
                <Button
                  variant="secondary" size="sm" className="col-span-1"
                  onClick={() => setLines(lines.length <= 2 ? [blankLine(), blankLine()] : lines.filter((_, j) => j !== i))}
                  aria-label="Remove line"
                >×</Button>
              </div>
            ))}
          </div>
          <Button variant="secondary" size="sm" className="mt-2" onClick={() => setLines([...lines, blankLine()])}>
            <Plus size={14} /> Add line
          </Button>
        </div>

        <div className={`mt-3 flex items-center justify-between rounded-control px-3 py-2 ${draftDiff === 0 && draftDebit > 0 ? "bg-success-soft" : "bg-slate-50"}`}>
          <span className="text-caption text-slate-700">Dr {fmtPKR(draftDebit)} · Cr {fmtPKR(draftCredit)}</span>
          <span className={`text-caption font-semibold ${draftDiff === 0 ? (draftDebit > 0 ? "text-success-strong" : "text-subtle") : "text-danger-strong"}`}>
            {draftDiff === 0 ? (draftDebit > 0 ? "balanced" : "nothing to post") : `off by ${fmtPKR(Math.abs(draftDiff))}`}
          </span>
        </div>

        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}
        <div className="mt-4 flex items-center justify-between gap-2">
          <p className="flex items-center gap-1.5 text-caption text-subtle">
            <Lock size={13} /> An entry cannot be edited once posted.
          </p>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={post} disabled={saving || draftDiff !== 0}>{saving ? "Posting…" : "Post entry"}</Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
