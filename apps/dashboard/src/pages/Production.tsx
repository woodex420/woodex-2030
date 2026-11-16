import { Fragment, useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate } from "@/lib/api";
import { Plus, RefreshCw, Factory, ClipboardCheck, ShieldCheck, Trash2, Package, AlertTriangle } from "@/icons";

/* Production — recipes, job cards, and the QC gate.
 *
 * The rule the whole module exists for is that a job cannot be marked done
 * without a recorded inspection. This screen does not let you click your way
 * around it: the "Complete" button is disabled until a QC verdict is recorded,
 * said in words, and the server refuses regardless of what any client sends.
 * Where the UI disabled something, it says why — a greyed button with no
 * explanation is the same failure as a lie, just quieter.
 *
 * Consumption and output both write ledger rows; the material list shows what
 * is still outstanding so "done" is never reached with parts unissued. */

type BomLine = { id: number; lineNo: number; itemKind: string; itemId: string; qtyPerOutput: number; unit: string | null };
type Bom = { id: number; ref: string; productId: string; name: string; version: number; outputQty: number; active: boolean; lines: BomLine[] };
type Material = {
  id: number; lineNo: number; itemKind: string; itemId: string;
  qtyRequired: number; qtyIssued: number; qtyOutstanding: number;
};
type Movement = { id: number; productId: string; delta: number; refType: string; note: string | null; at: string };
type JobCard = {
  id: number; ref: string; bomId: number | null; productId: string; qtyToBuild: number; qtyBuilt: number;
  stage: string; assignedTo: string | null; dueAt: string | null; notes: string | null;
  qcPassedAt: string | null; qcBy: string | null; qcNote: string | null;
  materials: Material[]; movements: Movement[];
};
type Product = { id: string; name: string; price: number };
type Mat = { id: string; name: string };

const STAGE_LABEL: Record<string, string> = {
  queued: "Queued", in_progress: "In production", qc: "Awaiting QC", done: "Done", cancelled: "Cancelled",
};
const stageTone = (s: string) =>
  s === "done" ? "success" : s === "qc" ? "warning" : s === "in_progress" ? "primary" : s === "cancelled" ? "neutral" : "muted";

type Draft = { itemKind: "product" | "material"; itemId: string; qtyPerOutput: string };

export default function Production() {
  const { push } = useToast();
  const [tab, setTab] = useState<"jobs" | "recipes">("jobs");

  /* ---- data ---- */
  const jobs = useApi<{ total: number; open: number; awaitingQc: number; items: JobCard[] }>("/api/job-cards", 15000);
  const boms = useApi<{ total: number; active: number; items: Bom[] }>("/api/boms", 30000);
  const products = useApi<{ total: number; items: Product[] }>("/api/products");
  const materials = useApi<Mat[]>("/api/materials");

  const productName = (id: string) => (products.data?.items ?? []).find((p) => p.id === id)?.name ?? id;

  /* ---- create job card ---- */
  const [jobOpen, setJobOpen] = useState(false);
  const [jobErr, setJobErr] = useState<string | null>(null);
  const [jobSaving, setJobSaving] = useState(false);
  const [jcProduct, setJcProduct] = useState("");
  const [jcQty, setJcQty] = useState("1");
  const [jcAssignee, setJcAssignee] = useState("");
  const [jcDue, setJcDue] = useState("");
  const [jcNotes, setJcNotes] = useState("");

  /* ---- create BOM ---- */
  const [bomOpen, setBomOpen] = useState(false);
  const [bomErr, setBomErr] = useState<string | null>(null);
  const [bomSaving, setBomSaving] = useState(false);
  const [bomProduct, setBomProduct] = useState("");
  const [bomOutput, setBomOutput] = useState("1");
  const [bomLines, setBomLines] = useState<Draft[]>([{ itemKind: "product", itemId: "", qtyPerOutput: "1" }]);

  /* ---- QC ---- */
  const [qcFor, setQcFor] = useState<JobCard | null>(null);
  const [qcPass, setQcPass] = useState(true);
  const [qcNote, setQcNote] = useState("");
  const [qcErr, setQcErr] = useState<string | null>(null);
  const [qcBusy, setQcBusy] = useState(false);
  const [expanded, setExpanded] = useState<number | null>(null);

  const activeBomFor = (productId: string) => (boms.data?.items ?? []).find((b) => b.productId === productId && b.active);

  const refreshAll = () => { jobs.reload(); boms.reload(); };

  const createJob = async () => {
    if (jobSaving) return;
    setJobErr(null);
    if (!jcProduct) { setJobErr("Choose the product this job builds."); return; }
    const qty = Number(jcQty);
    if (!Number.isInteger(qty) || qty <= 0) { setJobErr("Quantity must be a whole number above zero."); return; }
    setJobSaving(true);
    try {
      const jc = await mutate<JobCard>("/api/job-cards", {
        productId: jcProduct, qtyToBuild: qty,
        assignedTo: jcAssignee || null, dueAt: jcDue || null, notes: jcNotes || null,
      }, "POST");
      push({
        tone: "success",
        title: `${jc.ref} raised`,
        desc: jc.materials.length
          ? `${jc.materials.length} component(s) snapshotted from the active recipe.`
          : "No active recipe for this product — the job has no components to issue.",
      });
      setJobOpen(false); setJcProduct(""); setJcQty("1"); setJcAssignee(""); setJcDue(""); setJcNotes("");
      jobs.reload();
    } catch (e) { setJobErr(e instanceof Error ? e.message : String(e)); }
    finally { setJobSaving(false); }
  };

  const createBom = async () => {
    if (bomSaving) return;
    setBomErr(null);
    if (!bomProduct) { setBomErr("A recipe has to be for a product."); return; }
    const prepared = bomLines.filter((l) => l.itemId).map((l) => ({
      itemKind: l.itemKind, itemId: l.itemId, qtyPerOutput: Number(l.qtyPerOutput),
    }));
    if (!prepared.length) { setBomErr("Add at least one component."); return; }
    const bad = prepared.find((l) => !Number.isInteger(l.qtyPerOutput) || l.qtyPerOutput <= 0);
    if (bad) { setBomErr(`${bad.itemId}: quantity per output must be a whole number above zero.`); return; }
    setBomSaving(true);
    try {
      const bom = await mutate<Bom>("/api/boms", {
        productId: bomProduct, outputQty: Number(bomOutput) || 1, lines: prepared,
      }, "POST");
      push({ tone: "success", title: `${bom.ref} v${bom.version} saved`, desc: bom.active ? "Now the active recipe for this product." : "Saved." });
      setBomOpen(false); setBomProduct(""); setBomOutput("1"); setBomLines([{ itemKind: "product", itemId: "", qtyPerOutput: "1" }]);
      boms.reload();
    } catch (e) { setBomErr(e instanceof Error ? e.message : String(e)); }
    finally { setBomSaving(false); }
  };

  const issue = async (jc: JobCard, lineIds?: number[]) => {
    try {
      const body = lineIds
        ? { lines: lineIds.map((id) => ({ lineId: id })) }
        : undefined;
      await mutate(`/api/job-cards/${jc.id}/materials/issue`, body, "POST");
      push({ tone: "success", title: `${jc.ref} — materials issued`, desc: "Stock decremented through the ledger, exactly once per line." });
      jobs.reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not issue materials", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  const moveStage = async (jc: JobCard, stage: string, extra: Record<string, unknown> = {}) => {
    try {
      await mutate(`/api/job-cards/${jc.id}`, { stage, ...extra });
      push({ tone: "success", title: `${jc.ref} → ${STAGE_LABEL[stage] ?? stage}` });
      jobs.reload();
    } catch (e) {
      push({ tone: "danger", title: "Stage change refused", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  const recordQc = async () => {
    if (!qcFor || qcBusy) return;
    setQcErr(null); setQcBusy(true);
    try {
      await mutate(`/api/job-cards/${qcFor.id}`, { qcPassed: qcPass, qcNote: qcNote || null });
      push({
        tone: qcPass ? "success" : "warning",
        title: qcPass ? `${qcFor.ref} passed inspection` : `${qcFor.ref} failed inspection`,
        desc: qcPass ? "Completion is now unlocked." : "Sent back to production — rework is work, not a closed state.",
      });
      setQcFor(null); setQcNote(""); jobs.reload();
    } catch (e) { setQcErr(e instanceof Error ? e.message : String(e)); }
    finally { setQcBusy(false); }
  };

  const items = jobs.data?.items ?? [];
  const outstanding = (jc: JobCard) => jc.materials.reduce((n, m) => n + m.qtyOutstanding, 0);

  if (jobs.error || boms.error)
    return (
      <div>
        <PageHeader title="Production" />
        <ErrorState title="Production API unreachable" description="The job card endpoint did not answer." debug={jobs.error || boms.error || undefined} onRetry={refreshAll} />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Operations", to: "/operations" }, { label: "Production" }]}
        title="Production"
        description="Recipes, job cards and inspection. Issuing components consumes stock; a job cannot be completed without a QC pass."
        actions={
          <>
            <Button variant="secondary" onClick={refreshAll}><RefreshCw size={15} /> Refresh</Button>
            <Button variant="secondary" onClick={() => { setBomErr(null); setBomOpen(true); }}><Plus size={15} /> New recipe</Button>
            <Button onClick={() => { setJobErr(null); setJobOpen(true); }}><Factory size={15} /> Raise job card</Button>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Open jobs</p>
          <p className="mt-1 text-h2 text-ink">{jobs.data?.open ?? 0}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Awaiting QC</p>
          <p className="mt-1 text-h2 text-ink">{jobs.data?.awaitingQc ?? 0}</p>
          <p className="mt-1 text-caption text-warning-strong">cannot be completed until inspected</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Active recipes</p>
          <p className="mt-1 text-h2 text-ink">{boms.data?.active ?? 0}</p>
          <p className="mt-1 text-caption text-subtle">of {boms.data?.total ?? 0} versions</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Jobs total</p>
          <p className="mt-1 text-h2 text-ink">{jobs.data?.total ?? 0}</p>
        </Card>
      </div>

      <Card className="mb-3 flex items-center gap-2 py-3">
        {[{ key: "jobs", label: "Job cards" }, { key: "recipes", label: "Recipes (BOM)" }].map((t) => (
          <Button key={t.key} size="sm" variant={tab === (t.key as typeof tab) ? "primary" : "secondary"} onClick={() => setTab(t.key as typeof tab)}>
            {t.label}
          </Button>
        ))}
      </Card>

      {tab === "jobs" && (
        <Card className="overflow-hidden p-0">
          <table className="w-full text-left">
            <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
              <tr>
                <th className="px-4 py-2.5 font-semibold">Job</th>
                <th className="px-4 py-2.5 font-semibold">Builds</th>
                <th className="px-4 py-2.5 font-semibold">Stage</th>
                <th className="px-4 py-2.5 font-semibold">Materials</th>
                <th className="px-4 py-2.5 font-semibold">QC</th>
                <th className="px-4 py-2.5 font-semibold text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {jobs.loading && items.length === 0 && (
                <tr><td colSpan={6} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
              )}
              {items.map((jc) => {
                const out = outstanding(jc);
                const canComplete = jc.stage === "qc" && Boolean(jc.qcPassedAt) && out === 0;
                return (
                  <Fragment key={jc.id}>
                    <tr className="border-b border-slate-100 hover:bg-slate-50/60">
                      <td className="px-4 py-3">
                        <button className="font-mono text-caption font-semibold text-primary-700 hover:underline"
                          onClick={() => setExpanded((c) => (c === jc.id ? null : jc.id))}>{jc.ref}</button>
                        {jc.dueAt && <p className="text-caption text-subtle">due {jc.dueAt.slice(0, 10)}</p>}
                      </td>
                      <td className="px-4 py-3">
                        <p className="text-small font-semibold text-ink">{productName(jc.productId)}</p>
                        <p className="text-caption text-subtle">{jc.qtyBuilt || 0} built of {jc.qtyToBuild}</p>
                      </td>
                      <td className="px-4 py-3"><Badge tone={stageTone(jc.stage) as never} dot={false}>{STAGE_LABEL[jc.stage] ?? jc.stage}</Badge></td>
                      <td className="px-4 py-3 text-caption">
                        {jc.materials.length === 0
                          ? <span className="text-subtle">no components</span>
                          : out > 0
                            ? <span className="text-warning-strong">{out} outstanding</span>
                            : <span className="text-success-strong">fully issued</span>}
                      </td>
                      <td className="px-4 py-3 text-caption">
                        {jc.qcPassedAt
                          ? <span className="text-success-strong">passed</span>
                          : <span className="text-subtle">—</span>}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap justify-end gap-1.5">
                          {jc.stage === "queued" && (
                            <Button size="sm" variant="secondary" onClick={() => moveStage(jc, "in_progress")}>Start</Button>
                          )}
                          {["queued", "in_progress"].includes(jc.stage) && out > 0 && jc.materials.length > 0 && (
                            <Button size="sm" onClick={() => issue(jc)}>Issue materials</Button>
                          )}
                          {jc.stage === "in_progress" && (
                            <Button size="sm" variant="secondary" onClick={() => moveStage(jc, "qc")}>Send to QC</Button>
                          )}
                          {jc.stage === "qc" && !jc.qcPassedAt && (
                            <Button size="sm" onClick={() => { setQcFor(jc); setQcPass(true); setQcNote(""); setQcErr(null); }}>
                              <ClipboardCheck size={13} /> Record QC
                            </Button>
                          )}
                          {jc.stage === "qc" && jc.qcPassedAt && (
                            <Button size="sm" disabled={!canComplete} title={canComplete ? "" : out > 0 ? `${out} component(s) still unissued` : "not ready"}
                              onClick={() => moveStage(jc, "done")}>
                              <ShieldCheck size={13} /> Complete
                            </Button>
                          )}
                          {jc.stage === "done" && <span className="text-caption text-success-strong">output posted</span>}
                        </div>
                      </td>
                    </tr>
                    {expanded === jc.id && (
                      <tr className="border-b border-slate-100 bg-slate-50/70">
                        <td colSpan={6} className="px-4 py-3">
                          {jc.materials.length > 0 ? (
                            <table className="w-full text-left text-caption">
                              <thead className="text-subtle uppercase">
                                <tr>
                                  <th className="py-1 font-semibold">Component</th>
                                  <th className="py-1 font-semibold">Kind</th>
                                  <th className="py-1 font-semibold">Stock effect</th>
                                  <th className="py-1 text-right font-semibold">Required</th>
                                  <th className="py-1 text-right font-semibold">Issued</th>
                                  <th className="py-1 text-right font-semibold">Outstanding</th>
                                  <th className="py-1" />
                                </tr>
                              </thead>
                              <tbody>
                                {jc.materials.map((m) => (
                                  <tr key={m.id} className="border-t border-slate-200">
                                    <td className="py-1.5 font-mono">{m.itemId}</td>
                                    <td className="py-1.5">{m.itemKind}</td>
                                    <td className="py-1.5">
                                      {m.itemKind === "product"
                                        ? <Badge tone="danger" dot={false}>debits stock</Badge>
                                        : <Badge tone="neutral" dot={false}>no stock</Badge>}
                                    </td>
                                    <td className="py-1.5 text-right">{m.qtyRequired}</td>
                                    <td className="py-1.5 text-right">{m.qtyIssued}</td>
                                    <td className="py-1.5 text-right font-semibold">{m.qtyOutstanding}</td>
                                    <td className="py-1.5 text-right">
                                      {m.qtyOutstanding > 0 && !["done", "cancelled"].includes(jc.stage) && (
                                        <Button size="sm" variant="secondary" onClick={() => issue(jc, [m.id])}>Issue {m.qtyOutstanding}</Button>
                                      )}
                                    </td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          ) : (
                            <p className="flex items-center gap-1.5 text-caption text-subtle">
                              <AlertTriangle size={13} /> No active recipe — this job has no components, so nothing is consumed and nothing can be outstanding.
                            </p>
                          )}
                          {jc.movements.length > 0 && (
                            <div className="mt-2 border-t border-slate-200 pt-2">
                              <p className="mb-1 text-caption font-semibold uppercase text-subtle">Ledger rows</p>
                              <ul className="space-y-0.5 text-caption text-muted">
                                {jc.movements.map((mv) => (
                                  <li key={mv.id} className="flex justify-between gap-3">
                                    <span className="font-mono">{mv.productId}</span>
                                    <span className={mv.delta < 0 ? "text-danger-strong" : "text-success-strong"}>{mv.delta > 0 ? "+" : ""}{mv.delta}</span>
                                    <span className="ml-auto text-subtle">{mv.note ?? mv.refType}</span>
                                  </li>
                                ))}
                              </ul>
                            </div>
                          )}
                        </td>
                      </tr>
                    )}
                  </Fragment>
                );
              })}
            </tbody>
          </table>
          {!jobs.loading && items.length === 0 && (
            <EmptyState icon={<Factory size={20} />} title="No job cards yet"
              description="Raise one against a product. If it has an active recipe, its components are snapshotted at that moment."
              action={<Button onClick={() => setJobOpen(true)}><Plus size={15} /> Raise job card</Button>} />
          )}
        </Card>
      )}

      {tab === "recipes" && (
        <Card className="overflow-hidden p-0">
          <table className="w-full text-left">
            <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
              <tr>
                <th className="px-4 py-2.5 font-semibold">Recipe</th>
                <th className="px-4 py-2.5 font-semibold">Product</th>
                <th className="px-4 py-2.5 font-semibold">Version</th>
                <th className="px-4 py-2.5 font-semibold">Components</th>
                <th className="px-4 py-2.5 font-semibold">Status</th>
              </tr>
            </thead>
            <tbody>
              {(boms.data?.items ?? []).map((b) => (
                <tr key={b.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                  <td className="px-4 py-3 font-mono text-caption font-semibold text-ink">{b.ref}</td>
                  <td className="px-4 py-3 text-small text-slate-700">{productName(b.productId)}</td>
                  <td className="px-4 py-3 text-caption text-subtle">v{b.version} · yields {b.outputQty}</td>
                  <td className="px-4 py-3 text-caption text-muted">
                    {b.lines.map((l) => `${l.qtyPerOutput}× ${l.itemId}${l.itemKind === "material" ? " (finish)" : ""}`).join(" · ") || "—"}
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={b.active ? "success" : "neutral"} dot={false}>{b.active ? "Active" : "Superseded"}</Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {!boms.loading && (boms.data?.items ?? []).length === 0 && (
            <EmptyState icon={<Package size={20} />} title="No recipes yet"
              description="A recipe says what one product is made of. Job cards snapshot it at raise time, so editing a recipe never rewrites a job already on the floor."
              action={<Button onClick={() => setBomOpen(true)}><Plus size={15} /> New recipe</Button>} />
          )}
        </Card>
      )}

      {/* ---------- raise a job card ---------- */}
      <Modal open={jobOpen} onClose={() => setJobOpen(false)} title="Raise a job card" className="max-w-xl">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Product to build" required className="sm:col-span-2">
            <Select value={jcProduct} onChange={(e) => setJcProduct(e.target.value)}>
              <option value="">Choose a product…</option>
              {(products.data?.items ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </Select>
          </Field>
          <Field label="Quantity" required>
            <Input type="number" min={1} step={1} value={jcQty} onChange={(e) => setJcQty(e.target.value)} />
          </Field>
          <Field label="Assign to">
            <Input value={jcAssignee} onChange={(e) => setJcAssignee(e.target.value)} placeholder="e.g. Usman" />
          </Field>
          <Field label="Due">
            <Input type="date" value={jcDue} onChange={(e) => setJcDue(e.target.value)} />
          </Field>
          <Field label="Notes">
            <Input value={jcNotes} onChange={(e) => setJcNotes(e.target.value)} />
          </Field>
        </div>
        {jcProduct && (
          <p className="mt-2 text-caption text-subtle">
            {activeBomFor(jcProduct)
              ? `Will snapshot ${activeBomFor(jcProduct)!.ref} v${activeBomFor(jcProduct)!.version} — ${activeBomFor(jcProduct)!.lines.length} component(s), current as of now.`
              : "No active recipe for this product. The job will have no components, so completion will not consume stock."}
          </p>
        )}
        {jobErr && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{jobErr}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setJobOpen(false)} disabled={jobSaving}>Cancel</Button>
          <Button onClick={createJob} disabled={jobSaving}>{jobSaving ? "Raising…" : "Raise job card"}</Button>
        </div>
      </Modal>

      {/* ---------- new recipe ---------- */}
      <Modal open={bomOpen} onClose={() => setBomOpen(false)} title="New recipe" className="max-w-2xl"
        description="Creating a recipe for a product that already has one supersedes it as a new version. Existing job cards keep the version they were raised against.">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Field label="Product" required className="sm:col-span-2">
            <Select value={bomProduct} onChange={(e) => setBomProduct(e.target.value)}>
              <option value="">Choose a product…</option>
              {(products.data?.items ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </Select>
          </Field>
          <Field label="Yield per run">
            <Input type="number" min={1} step={1} value={bomOutput} onChange={(e) => setBomOutput(e.target.value)} />
          </Field>
        </div>

        <div className="mt-4">
          <p className="mb-1.5 text-small font-medium text-slate-700">Components</p>
          <div className="space-y-2">
            {bomLines.map((l, i) => {
              const opts = l.itemKind === "product"
                ? (products.data?.items ?? []).map((p) => ({ id: p.id, name: p.name }))
                : (materials.data ?? []).map((m) => ({ id: m.id, name: m.name }));
              return (
                <div key={i} className="grid grid-cols-12 items-end gap-2 rounded-control border border-line bg-slate-50/60 p-2">
                  <div className="col-span-4">
                    <Select value={l.itemKind} onChange={(e) => setBomLines((c) => c.map((x, idx) => idx === i ? { ...x, itemKind: e.target.value as "product" | "material", itemId: "" } : x))}>
                      <option value="product">Product (stocked)</option>
                      <option value="material">Material / finish</option>
                    </Select>
                  </div>
                  <div className="col-span-5">
                    <Select value={l.itemId} onChange={(e) => setBomLines((c) => c.map((x, idx) => idx === i ? { ...x, itemId: e.target.value } : x))}>
                      <option value="">Choose…</option>
                      {opts.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </Select>
                  </div>
                  <div className="col-span-2">
                    <Input type="number" min={1} step={1} value={l.qtyPerOutput}
                      onChange={(e) => setBomLines((c) => c.map((x, idx) => idx === i ? { ...x, qtyPerOutput: e.target.value } : x))} />
                  </div>
                  <div className="col-span-1 flex justify-end">
                    <Button variant="secondary" size="sm" aria-label="Remove component" disabled={bomLines.length === 1}
                      onClick={() => setBomLines((c) => c.filter((_, idx) => idx !== i))}><Trash2 size={13} /></Button>
                  </div>
                </div>
              );
            })}
          </div>
          <button className="mt-2 inline-flex items-center gap-1.5 text-caption font-semibold text-primary-700 hover:underline"
            onClick={() => setBomLines((c) => [...c, { itemKind: "product", itemId: "", qtyPerOutput: "1" }])}>
            <Plus size={13} /> Add component
          </button>
          <p className="mt-1 text-caption text-subtle">A product component debits stock when issued; a finish records use without moving stock.</p>
        </div>

        {bomErr && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{bomErr}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setBomOpen(false)} disabled={bomSaving}>Cancel</Button>
          <Button onClick={createBom} disabled={bomSaving}>{bomSaving ? "Saving…" : "Save recipe"}</Button>
        </div>
      </Modal>

      {/* ---------- QC verdict ---------- */}
      <Modal open={Boolean(qcFor)} onClose={() => setQcFor(null)} title={qcFor ? `Inspection — ${qcFor.ref}` : ""}
        description="A job cannot be completed without this. Passing unlocks completion; failing sends it back to production.">
        {qcFor && (
          <>
            <p className="mb-3 text-small text-muted">{productName(qcFor.productId)} · {qcFor.qtyBuilt || 0} of {qcFor.qtyToBuild} built</p>
            <div className="mb-3 flex gap-2">
              <Button size="sm" variant={qcPass ? "primary" : "secondary"} onClick={() => setQcPass(true)}>Passed</Button>
              <Button size="sm" variant={!qcPass ? "primary" : "secondary"} onClick={() => setQcPass(false)}>Failed — rework</Button>
            </div>
            <Field label="Inspection note">
              <Textarea value={qcNote} onChange={(e) => setQcNote(e.target.value)} placeholder="What was checked, what was wrong…" />
            </Field>
            {!qcPass && <p className="mt-2 text-caption text-warning-strong">Failing returns this job to production. Any previous pass is cleared.</p>}
            {qcErr && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{qcErr}</p>}
            <div className="mt-4 flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setQcFor(null)} disabled={qcBusy}>Cancel</Button>
              <Button onClick={recordQc} disabled={qcBusy}>{qcBusy ? "Recording…" : "Record verdict"}</Button>
            </div>
          </>
        )}
      </Modal>
    </div>
  );
}
