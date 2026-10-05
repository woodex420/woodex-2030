import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link, useParams } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Field, Input } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { SkeletonRows, EmptyState, ErrorState } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, useRealtime, timeAgo, img } from "@/lib/api";
import { ArrowRight, Check, Eye, FileText, Globe, Plus, Save, Trash2, Undo2 } from "@/icons";

/* ---------- shared types ---------- */
type BlockField = { k: string; t: "text" | "textarea" | "image" | "lines"; req?: boolean; help?: string };
type BlockSpec = { label: string; group: string; fields: BlockField[] };
type Block = { type: string; props: Record<string, unknown> };
type PageDoc = { id: number; slug: string; title: string; status: string; seoTitle?: string | null; seoDesc?: string | null;
  updatedAt: string; blockCount?: number; blocks?: Block[] };

const SLUG_RE = /^[a-z0-9][a-z0-9-]{1,60}$/;

/* ---------- mini renderer (matches storefront rules, simplified) ---------- */
const val = (b: Block, k: string) => String(b.props[k] ?? "");
const lines = (b: Block, k: string) => val(b, k).split("\n").map((x) => x.trim()).filter(Boolean);

function BlockPreview({ b }: { b: Block }) {
  const wrap = "border border-dashed border-line bg-white px-4 py-3";
  const imgSrc = val(b, "image") ? (val(b, "image").startsWith("http") ? val(b, "image") : img(val(b, "image"))) : null;
  switch (b.type) {
    case "hero": return (
      <div className={cn(wrap, "flex gap-4")}>
        {imgSrc && <img src={imgSrc} alt="" className="h-20 w-28 shrink-0 rounded-card object-cover" />}
        <div className="min-w-0"><p className="text-[10px] font-bold uppercase tracking-widest text-primary-600">{val(b, "kicker") || "kicker"}</p>
          <p className="mt-0.5 truncate text-small font-bold text-ink">{val(b, "heading") || "Heading"}</p>
          <p className="mt-0.5 line-clamp-2 text-caption text-muted">{val(b, "sub")}</p>
          {val(b, "cta_label") && <span className="mt-1.5 inline-block rounded-control bg-primary-600 px-2 py-0.5 text-[10px] font-bold text-white">{val(b, "cta_label")}</span>}</div>
      </div>);
    case "product-grid": return (
      <div className={wrap}><p className="text-caption font-bold text-ink">{val(b, "heading") || "Products"}</p>
        <p className="mt-1 rounded bg-surface-secondary px-2 py-2 text-[10px] text-muted">▦ live catalog slice — match “{val(b, "match") || val(b, "ids") || "(all)"}” · up to {val(b, "limit") || "4"} cards (rendered with live prices on the published page)</p></div>);
    case "testimonials": case "faq": case "gallery":
      return <div className={wrap}><p className="text-caption font-bold text-ink">{val(b, "heading") || b.type}</p>
        <p className="mt-1 text-[10px] text-muted">{lines(b, "items").length || lines(b, "images").length || 0} {b.type === "gallery" ? "images" : "entries"} · {b.type === "faq" ? "accordion" : b.type === "gallery" ? "grid" : "cards"}</p></div>;
    case "lead-form": return (
      <div className={wrap}><p className="text-caption font-bold text-ink">{val(b, "heading") || "Lead form"}</p>
        <div className="mt-1.5 grid grid-cols-2 gap-1.5"><span className="rounded border border-line px-2 py-1 text-[9px] text-subtle">Name</span><span className="rounded border border-line px-2 py-1 text-[9px] text-subtle">Phone</span></div>
        <span className="mt-1.5 inline-block rounded bg-primary-600 px-2 py-0.5 text-[9px] font-bold text-white">{val(b, "submit_label") || "Send"}</span>
        <p className="mt-1 text-[9px] text-subtle">→ POSTs to the shared CRM as “Landing: page”</p></div>);
    case "cta-band": return (
      <div className={cn(wrap, "bg-primary-50/40")}><p className="text-caption font-bold text-ink">{val(b, "heading") || "CTA"}</p>
        <p className="mt-1 flex gap-1.5">{val(b, "primary_label") && <span className="rounded bg-primary-600 px-2 py-0.5 text-[9px] font-bold text-white">{val(b, "primary_label")}</span>}
        {val(b, "secondary_label") && <span className="rounded border border-line px-2 py-0.5 text-[9px] font-bold text-ink">{val(b, "secondary_label")}</span>}</p></div>);
    default: return (
      <div className={wrap}><p className="text-caption font-bold text-ink">{val(b, "heading") || val(b, "kicker") || b.type}</p>
        {(val(b, "sub") || val(b, "body")) && <p className="mt-0.5 line-clamp-2 text-[10px] text-muted">{val(b, "sub") || val(b, "body")}</p>}</div>);
  }
}

/* ---------- block form ---------- */
function BlockEditor({ b, spec, onChange, onImage }: { b: Block; spec?: BlockSpec;
  onChange: (props: Record<string, unknown>) => void; onImage: (pick: (v: string) => void) => void }) {
  const fields: BlockField[] = useMemo(() => {
    if (spec) return spec.fields;
    const known = ["kicker", "heading", "sub", "body", "image", "items", "images", "bullets"];
    return Object.keys(b.props).filter((k) => known.includes(k)).map((k): BlockField => ({
      k, t: ["items", "images", "bullets"].includes(k) ? "lines" : ["sub", "body"].includes(k) ? "textarea" : "text" }));
  }, [spec, b]);
  return (
    <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
      {fields.map((f) => (
        <Field key={f.k} label={f.k.replace(/_/g, " ")} className={f.t === "textarea" || f.t === "lines" ? "sm:col-span-2" : undefined}>
          {f.t === "textarea" || f.t === "lines" ? (
            <textarea value={Array.isArray(b.props[f.k]) ? (b.props[f.k] as string[]).join("\n") : String(b.props[f.k] ?? "")}
              rows={f.t === "lines" ? 3 : 2} placeholder={f.t === "lines" ? f.help : undefined}
              onChange={(e) => onChange({ ...b.props, [f.k]: e.target.value })}
              className={cn("w-full rounded-control border border-line-strong bg-white px-3 py-2 text-small text-ink focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20", f.t === "lines" && "font-mono text-caption")} />
          ) : f.t === "image" ? (
            <span className="flex gap-1.5">
              <Input value={String(b.props[f.k] ?? "")} onChange={(e) => onChange({ ...b.props, [f.k]: e.target.value })} placeholder="file from library or URL" />
              <Button size="sm" variant="secondary" onClick={() => onImage((v) => onChange({ ...b.props, [f.k]: v }))}><Eye size={13} /></Button>
            </span>
          ) : <Input value={String(b.props[f.k] ?? "")} onChange={(e) => onChange({ ...b.props, [f.k]: e.target.value })} placeholder={f.help} />}
        </Field>
      ))}
      {fields.length === 0 && <p className="text-caption text-subtle">This block has no editable fields.</p>}
    </div>);
}

/* ---------- editor page ---------- */
export function PageEditor() {
  const { id } = useParams();
  const { push } = useToast();
  const registry = useApi<{ registry: Record<string, BlockSpec> }>("/api/blocks");
  const media = useApi<{ items: string[] }>("/api/media");
  const { data: page, loading, error, reload } = useApi<PageDoc>(`/api/pages/${id}`);
  const [blocks, setBlocks] = useState<Block[]>([]);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [openIdx, setOpenIdx] = useState<number | null>(null);
  const [libOpen, setLibOpen] = useState(false);
  const [imgPick, setImgPick] = useState<((v: string) => void) | null>(null);
  const [valid, setValid] = useState<{ block: number; msg: string }[] | null>(null);
  const [title, setTitle] = useState("");
  const [seo, setSeo] = useState({ seoTitle: "", seoDesc: "" });
  const saveTimer = useRef<number | undefined>(undefined);

  useEffect(() => { if (page) { setBlocks(page.blocks ?? []); setTitle(page.title); setSeo({ seoTitle: page.seoTitle ?? "", seoDesc: page.seoDesc ?? "" }); } }, [page]);

  const persist = useCallback(async (silent = false) => {
    if (!page) return;
    try {
      const res = await fetch(`/api/pages/${page.id}/blocks`, { method: "PUT", headers: { "content-type": "application/json" },
        body: JSON.stringify({ blocks, title, seoTitle: seo.seoTitle || undefined, seoDesc: seo.seoDesc || undefined }) });
      if (!res.ok) throw new Error((await res.json()).error);
      const j = await res.json();
      if (!silent) push({ tone: "success", title: "Draft saved", desc: `${j.blocks?.length ?? blocks.length} blocks · validation ${j.validation.length ? j.validation.length + " issue(s)" : "clean"}` });
      setValid(j.validation); setDirty(false);
    } catch (e) { if (!silent) push({ tone: "danger", title: "Save failed", desc: e instanceof Error ? e.message : String(e) }); }
  }, [page, blocks, title, seo, push]);

  // autosave, 1.2s after last change
  useEffect(() => { if (!dirty) return; window.clearTimeout(saveTimer.current); saveTimer.current = window.setTimeout(() => void persist(true), 1200); return () => window.clearTimeout(saveTimer.current); }, [dirty, persist]);

  const publish = async () => {
    if (!page) return;
    setBusy(true);
    try {
      await persist(true);
      const res = await fetch(`/api/pages/${page.id}/publish`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ note: "published from studio", title }) });
      if (!res.ok) { const j = await res.json(); setValid(j.validation ?? []); throw new Error(j.error); }
      push({ tone: "success", title: "Published ✓", desc: "Immutable revision saved — live at /p/" + page.slug });
      reload();
    } catch (e) { push({ tone: "danger", title: "Publish blocked", desc: e instanceof Error ? e.message : String(e) }); }
    finally { setBusy(false); }
  };
  const versions = useApi<{ items: { id: number; note: string; who: string; createdAt: string; blocks: number }[] }>(dirty ? null : `/api/pages/${id}/versions`);
  const rollback = async (vid: number) => {
    await fetch(`/api/pages/${page!.id}/rollback`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ version_id: vid }) });
    push({ tone: "primary", title: "Rolled back", desc: "Editing a copy of revision #" + vid + " — publish to make it live." });
    reload(); versions.reload();
  };
  const set = (i: number, props: Record<string, unknown>) => { setBlocks((bs) => bs.map((b, j) => (j === i ? { ...b, props } : b))); setDirty(true); };
  const move = (i: number, d: -1 | 1) => { setBlocks((bs) => { const n = [...bs]; const j = i + d; if (!n[j]) return bs; [n[i], n[j]] = [n[j], n[i]]; return n; }); setDirty(true); };
  const remove = (i: number) => { setBlocks((bs) => bs.filter((_, j) => j !== i)); setDirty(true); };
  const add = (type: string) => { setBlocks((bs) => [...bs, { type, props: {} }]); setLibOpen(false); setOpenIdx(blocks.length); setDirty(true); };

  if (error) return <ErrorState title="Page not found" debug={"GET /api/pages/" + id} onRetry={reload} />;
  if (loading || !page) return <SkeletonRows rows={6} />;
  const registryMap = registry.data?.registry ?? {};

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Website", to: "/website" }, { label: "Studio" }, { label: page.slug }]}
        title={title || page.slug}
        description={`/p/${page.slug} — ${page.status} · updated ${timeAgo(page.updatedAt)}${dirty ? " · unsaved changes" : ""}`}
        actions={<>
          <a href={`/p/${page.slug}${page.status !== "Published" ? "?draft=1" : ""}`} target="_blank" rel="noreferrer" className="inline-flex"><Button variant="secondary"><Eye size={14} /> Preview</Button></a>
          <Button onClick={() => void persist()}><Save size={14} /> Save</Button>
          <Button onClick={() => void publish()} disabled={busy}><Globe size={14} /> {page.status === "Published" ? "Re-publish" : "Publish"}</Button>
        </>}
      />
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_300px]">
        <div className="space-y-3">
          {valid && valid.length > 0 && (
            <div className="rounded-card border border-danger/30 bg-danger-soft/40 px-4 py-2.5 text-caption">
              <b className="text-danger-strong">Fix before publish:</b>
              <ul className="mt-1 space-y-0.5">{valid.map((v, i) => <li key={i}>· {v.block >= 0 ? `Block ${v.block + 1} — ` : ""}{v.msg}</li>)}</ul>
            </div>)}
          {blocks.length === 0 && (
            <Card><EmptyState icon={<FileText size={20} />} title="Empty page" description="Add your first block from the library." action={<Button onClick={() => setLibOpen(true)}><Plus size={14} /> Open library</Button>} /></Card>)}
          {blocks.map((b, i) => (
            <Card key={i} className="p-0 overflow-hidden">
              <div className="flex items-center justify-between gap-2 border-b border-hairline bg-surface-secondary/60 px-3 py-1.5">
                <p className="text-caption font-bold text-ink">#{i + 1} · {registryMap[b.type]?.label ?? b.type}</p>
                <span className="flex items-center gap-1">
                  <button aria-label="Move up" onClick={() => move(i, -1)} className="rounded p-1 text-subtle hover:bg-white hover:text-ink"><ArrowRight size={13} className="-rotate-90" /></button>
                  <button aria-label="Move down" onClick={() => move(i, 1)} className="rounded p-1 text-subtle hover:bg-white hover:text-ink"><ArrowRight size={13} className="rotate-90" /></button>
                  <button aria-label="Delete block" onClick={() => remove(i)} className="rounded p-1 text-subtle hover:bg-white hover:text-danger-strong"><Trash2 size={13} /></button>
                  <Button size="sm" variant={openIdx === i ? "primary" : "secondary"} onClick={() => setOpenIdx(openIdx === i ? null : i)}>Edit</Button>
                </span>
              </div>
              <BlockPreview b={b} />
              {openIdx === i && (
                <div className="border-t border-hairline bg-white p-4">
                  <BlockEditor b={b} spec={registryMap[b.type]} onChange={(p) => set(i, p)} onImage={(cb) => setImgPick(() => cb)} />
                  {valid?.some((v) => v.block === i) && <p className="mt-2 text-caption font-semibold text-danger-strong">{valid.filter((v) => v.block === i).map((v) => v.msg).join(" · ")}</p>}
                </div>)}
            </Card>
          ))}
          <Button variant="secondary" onClick={() => setLibOpen(true)}><Plus size={14} /> Add block from library</Button>
        </div>

        {/* right rail: settings + versions */}
        <div className="space-y-4">
          <Card className="p-4">
            <p className="mb-3 text-caption font-bold uppercase tracking-wide text-subtle">Page settings</p>
            <Field label="Title"><Input value={title} onChange={(e) => { setTitle(e.target.value); setDirty(true); }} /></Field>
            <div className="mt-2.5"><Field label="SEO title"><Input value={seo.seoTitle} onChange={(e) => { setSeo((x) => ({ ...x, seoTitle: e.target.value })); setDirty(true); }} /></Field></div>
            <div className="mt-2.5"><Field label="Meta description"><Input value={seo.seoDesc} onChange={(e) => { setSeo((x) => ({ ...x, seoDesc: e.target.value })); setDirty(true); }} /></Field></div>
            <p className="mt-2 text-[10px] text-subtle">Slug is locked once created (redirect-safe by rule).</p>
          </Card>
          <Card className="p-4">
            <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Published revisions</p>
            {versions.loading ? <SkeletonRows rows={2} /> : (versions.data?.items.length ?? 0) === 0 ? <p className="text-caption text-subtle">No versions yet — publishing saves an immutable snapshot.</p> : (
              <ul className="space-y-1.5">{versions.data!.items.map((v) => (
                <li key={v.id} className="flex items-center justify-between rounded-control bg-surface-secondary px-2.5 py-1.5 text-caption">
                  <span><b className="text-ink">v{v.id}</b> · {v.blocks} blocks<span className="block text-subtle">{timeAgo(v.createdAt)} · {v.who}</span></span>
                  <button onClick={() => void rollback(v.id)} title="Restore as draft" className="inline-flex items-center gap-1 font-semibold text-primary-600 hover:underline"><Undo2 size={12} /> restore</button>
                </li>))}</ul>)}
          </Card>
        </div>
      </div>

      {/* library */}
      <Modal open={libOpen} onClose={() => setLibOpen(false)} title="Block library" description="Typed blocks — CSS-class renderer, validated on publish. Furniture v1." footer={null} className="max-w-xl">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
          {Object.entries(registryMap).map(([type, spec]) => (
            <button key={type} onClick={() => add(type)} className="rounded-card border border-hairline p-3 text-left transition-colors hover:border-primary-400 hover:bg-primary-50/40">
              <p className="text-small font-bold text-ink">{spec.label}</p>
              <p className="mt-0.5 text-caption text-subtle">{spec.group} · {spec.fields.filter((f) => f.req).length ? "required: " + spec.fields.filter((f) => f.req).map((f) => f.k).join(", ") : "all fields optional"}</p>
              <span className="mt-1.5 inline-flex items-center gap-1 text-caption font-semibold text-primary-600"><Plus size={12} /> Add</span>
            </button>))}
        </div>
      </Modal>

      {/* media picker */}
      <Modal open={!!imgPick} onClose={() => setImgPick(null)} title="Media library" description="Shared product photography — served from the API" footer={null} className="max-w-2xl">
        <div className="grid max-h-[50vh] grid-cols-4 gap-2 overflow-y-auto scrollbar-slim sm:grid-cols-6">
          {(media.data?.items ?? []).map((f) => (
            <button key={f} onClick={() => { imgPick?.(f); setImgPick(null); }} className="group overflow-hidden rounded-control border border-hairline">
              <img src={img(f)} alt={f} loading="lazy" className="aspect-square w-full object-cover transition-transform group-hover:scale-105" />
              <span className="block truncate px-1 py-0.5 text-left text-[9px] text-subtle">{f}</span>
            </button>))}
        </div>
      </Modal>
    </div>
  );
}

/* ---------- list page ---------- */
export default function Website() {
  const { push } = useToast();
  const { data, loading, error, reload } = useApi<{ total: number; published: number; items: PageDoc[] }>("/api/pages", 30000);
  useRealtime(/pages/, () => reload());
  const [newOpen, setNewOpen] = useState(false);
  const [nf, setNf] = useState<{ slug: string; title: string; template: string }>({ slug: "", title: "", template: "sale-landing" });
  const create = async () => {
    if (!SLUG_RE.test(nf.slug)) return push({ tone: "danger", title: "Bad slug", desc: "lowercase letters/numbers/dashes, 2–61 chars, must start with a letter/digit" });
    try {
      const res = await fetch("/api/pages", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(nf) });
      const j = await res.json();
      if (!res.ok) throw new Error(j.error);
      push({ tone: "success", title: "Page created", desc: "/" + j.slug + (nf.template !== "blank" ? " from template" : "") });
      setNewOpen(false); window.location.href = `/website/edit/${j.id}`;
    } catch (e) { push({ tone: "danger", title: "Could not create", desc: e instanceof Error ? e.message : String(e) }); }
  };
  const cols: Column<PageDoc>[] = [
    { key: "t", header: "Page", cell: (r) => (
      <span className="flex items-center gap-2.5"><span className="grid h-8 w-8 place-items-center rounded-control bg-primary-50 text-primary-700"><FileText size={15} /></span>
        <span><Link to={`/website/edit/${r.id}`} className="block text-small font-semibold text-ink hover:underline">{r.title}</Link>
        <span className="block text-caption text-subtle">/p/{r.slug} · {r.blockCount} blocks</span></span></span>) },
    { key: "s", header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    { key: "u", header: "Updated", cell: (r) => <span className="text-caption text-muted">{timeAgo(r.updatedAt)}</span> },
    { key: "a", header: "", align: "right", cell: (r) => (
      <span className="flex justify-end gap-1.5">
        <a href={`/p/${r.slug}${r.status !== "Published" ? "?draft=1" : ""}`} target="_blank" rel="noreferrer"><Button size="sm" variant="secondary"><Eye size={13} /> View</Button></a>
        <Link to={`/website/edit/${r.id}`}><Button size="sm"><FileText size={13} /> Edit</Button></Link>
      </span>) },
  ];
  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Website", to: "/website" }, { label: "Studio" }]}
        title="Website Studio"
        description="Typed-block landing pages on the shared renderer — draft, publish, roll back. Live product/lead blocks bind to the same DB as the storefront."
        actions={<><Button variant="secondary" onClick={reload}>Refresh</Button>
          <Button onClick={() => setNewOpen(true)}><Plus size={15} /> New page</Button></>}
      />
      <div className="mb-4 grid grid-cols-2 gap-3 lg:gap-4">
        <Card className="py-4"><p className="text-caption font-medium text-subtle uppercase">Pages</p><p className="mt-1 text-h2 text-ink">{data?.total ?? "—"}</p></Card>
        <Card className="py-4"><p className="text-caption font-medium text-subtle uppercase">Published</p><p className="mt-1 text-h2 text-ink">{data?.published ?? "—"}</p><p className="mt-1 text-caption text-success-strong"><Check size={11} className="inline" /> static-render + live blocks</p></Card>
      </div>
      <Card>
        {error ? <div className="p-4"><ErrorState title="Pages unavailable" debug="GET /api/pages" onRetry={reload} /></div>
          : loading ? <SkeletonRows rows={4} />
          : (data?.items.length ?? 0) === 0 ? <div className="p-6"><EmptyState icon={<Globe size={20} />} title="No pages yet" description="Create a landing page from a template — it publishes at /p/… on the storefront." action={<Button onClick={() => setNewOpen(true)}><Plus size={14} /> New page</Button>} /></div>
          : <DataTable rows={data!.items} columns={cols} perPage={10} />}
      </Card>
      <Modal open={newOpen} onClose={() => setNewOpen(false)} title="New page" description="Slug is permanent (edit/rename stays admin-only by rule)." footer={<Button onClick={() => void create()}><Check size={14} /> Create & edit</Button>}>
        <div className="space-y-3">
          <Field label="Title"><Input value={nf.title} onChange={(e) => setNf((f) => ({ ...f, title: e.target.value }))} placeholder="Winter Lounge Collection" /></Field>
          <Field label="Slug" hint="URL becomes /p/<slug>"><Input value={nf.slug} onChange={(e) => setNf((f) => ({ ...f, slug: e.target.value }))} placeholder="winter-lounge" /></Field>
          <Field label="Start from">
            <div className="grid grid-cols-3 gap-2">
              {[["sale-landing", "Sale landing (3 blocks)"], ["collection-intro", "Collection intro (3 blocks)"], ["blank", "Blank"]].map(([v, l]) => (
                <button key={v} onClick={() => setNf((f) => ({ ...f, template: v }))}
                  className={cn("rounded-control border px-2 py-2 text-caption font-semibold transition-colors", nf.template === v ? "border-primary-500 bg-primary-50 text-primary-800" : "border-hairline text-muted hover:bg-surface-secondary")}>{l}</button>))}
            </div>
          </Field>
        </div>
      </Modal>
    </div>
  );
}
