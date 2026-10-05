import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link, useParams } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge, Badge } from "@/components/ui/Badge";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Field, Input } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { SkeletonRows, EmptyState, ErrorState } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, useRealtime, timeAgo, img } from "@/lib/api";
import { ArrowRight, Check, Eye, FileText, Globe, Lock, Plus, Save, Trash2, Undo2 } from "@/icons";

/* ---------- types ---------- */
type BlockField = { k: string; t: "text" | "textarea" | "image" | "lines"; req?: boolean; help?: string };
type BlockSpec = { label: string; group: string; fields: BlockField[] };
type Block = { type: string; props: Record<string, unknown> };
type SavedSection = { id: number; name: string; category: string; block: Block; usage: number; updatedAt: string };
type Theme = { primary?: string; ink?: string; bg?: string; font?: "sans" | "serif"; announce?: string };
type PageDoc = { id: number; slug: string; title: string; status: string; seoTitle?: string | null; seoDesc?: string | null;
  updatedAt: string; blockCount?: number; blocks?: Block[]; theme?: Theme | null; views?: number; ctas?: number };

const SLUG_RE = /^[a-z0-9][a-z0-9-]{1,60}$/;
const PRESETS: { name: string; t: Theme }[] = [
  { name: "Woodex Green", t: { primary: "#16A34A", ink: "#182230", bg: "#FFFFFF", font: "sans" } },
  { name: "Charcoal Lux", t: { primary: "#0F172A", ink: "#F8FAFC", bg: "#111827", font: "serif" } },
  { name: "Ramadan Gold", t: { primary: "#B45309", ink: "#1C1917", bg: "#FFFBEB", font: "serif" } },
  { name: "B2B Blue", t: { primary: "#1D4ED8", ink: "#0F172A", bg: "#F8FAFC", font: "sans" } },
];

/* ---------- mini renderer ---------- */
const val = (b: Block, k: string) => String(b.props[k] ?? "");
const lines = (b: Block, k: string) => val(b, k).split("\n").map((x) => x.trim()).filter(Boolean);

function BlockPreview({ b, theme }: { b: Block; theme?: Theme | null }) {
  const wrap = "border border-dashed border-line px-4 py-3";
  const bg = theme?.bg ?? "white";
  const ink = theme?.ink ?? undefined;
  const acc = theme?.primary ?? undefined;
  const imgSrc = val(b, "image") ? (val(b, "image").startsWith("http") ? val(b, "image") : img(val(b, "image"))) : null;
  const body = (() => {
    switch (b.type) {
      case "hero": return (
        <div className={cn(wrap, "flex gap-4")} style={{ background: bg }}>
          {imgSrc && <img src={imgSrc} alt="" className="h-20 w-28 shrink-0 rounded-card object-cover" />}
          <div className="min-w-0"><p className="text-[10px] font-bold uppercase tracking-widest" style={{ color: acc ?? "var(--color-primary-600, #16a34a)" }}>{val(b, "kicker") || "kicker"}</p>
            <p className="mt-0.5 truncate text-small font-bold" style={{ color: ink }}>{val(b, "heading") || "Heading"}</p>
            <p className="mt-0.5 line-clamp-2 text-caption text-muted">{val(b, "sub")}</p>
            {val(b, "cta_label") && <span className="mt-1.5 inline-block rounded-control px-2 py-0.5 text-[10px] font-bold text-white" style={{ background: acc ?? "#16a34a" }}>{val(b, "cta_label")}</span>}</div>
        </div>);
      case "product-grid": return (
        <div className={wrap} style={{ background: bg }}><p className="text-caption font-bold" style={{ color: ink }}>{val(b, "heading") || "Products"}</p>
          <p className="mt-1 rounded bg-surface-secondary px-2 py-2 text-[10px] text-muted">▦ live catalog slice — match “{val(b, "match") || val(b, "ids") || "(all)"}” · up to {val(b, "limit") || "4"} cards, live prices</p></div>);
      case "testimonials": case "faq": case "gallery":
        return <div className={wrap} style={{ background: bg }}><p className="text-caption font-bold" style={{ color: ink }}>{val(b, "heading") || b.type}</p>
          <p className="mt-1 text-[10px] text-muted">{lines(b, "items").length || lines(b, "images").length || 0} {b.type === "gallery" ? "images" : "entries"}</p></div>;
      case "lead-form": return (
        <div className={wrap} style={{ background: bg }}><p className="text-caption font-bold" style={{ color: ink }}>{val(b, "heading") || "Lead form"}</p>
          <div className="mt-1.5 grid grid-cols-2 gap-1.5"><span className="rounded border border-line px-2 py-1 text-[9px] text-subtle">Name</span><span className="rounded border border-line px-2 py-1 text-[9px] text-subtle">Phone</span></div>
          <span className="mt-1.5 inline-block rounded px-2 py-0.5 text-[9px] font-bold text-white" style={{ background: acc ?? "#16a34a" }}>{val(b, "submit_label") || "Send"}</span> → CRM</div>);
      case "cta-band": return (
        <div className={cn(wrap, "flex items-center justify-between gap-3")} style={{ background: acc ?? "#166534" }}><p className="text-caption font-bold text-white">{val(b, "heading") || "CTA"}</p>
          <span className="rounded bg-white px-2 py-0.5 text-[9px] font-bold" style={{ color: acc ?? "#166534" }}>{val(b, "primary_label") || "Button"}</span></div>);
      case "global-section": return b.props.section ? <Resolved b={b} /> : (
        <div className={cn(wrap, "border-amber-400 bg-amber-50 text-caption font-semibold text-amber-800")}>⚠ Global section “{val(b, "section_id")}” has no source — re-pick one</div>);
      default: return (
        <div className={wrap} style={{ background: bg }}><p className="text-caption font-bold" style={{ color: ink }}>{val(b, "heading") || val(b, "kicker") || b.type}</p>
          {(val(b, "sub") || val(b, "body")) && <p className="mt-0.5 line-clamp-2 text-[10px] text-muted">{val(b, "sub") || val(b, "body")}</p>}</div>);
    }
  })();
  if (b.type !== "global-section") return body;
  return (
    <div>
      <p className="flex items-center gap-1.5 border-x border-t border-primary-200 bg-primary-50 px-3 py-1 text-[10px] font-bold uppercase tracking-wide text-primary-800">
        <Globe size={11} /> global section · {String(b.props.sectionLabel ?? "")} — edit once, fans out everywhere
      </p>
      {body}
    </div>
  );
}
function Resolved({ b }: { b: Block }) {
  const inner = b.props.section as Block;
  return <BlockPreview b={{ type: inner.type, props: (inner as Block).props }} />;
}

/* ---------- inspector ---------- */
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
    </div>);
}

/* ---------- editor ---------- */
export function PageEditor() {
  const { id } = useParams();
  const { push } = useToast();
  const registry = useApi<{ registry: Record<string, BlockSpec> }>("/api/blocks");
  const media = useApi<{ items: string[] }>("/api/media");
  const sections = useApi<{ items: SavedSection[] }>("/api/saved-sections");
  const { data: page, loading, error, reload } = useApi<PageDoc>(`/api/pages/${id}`);
  const [blocks, setBlocks] = useState<Block[]>([]);
  const [theme, setTheme] = useState<Theme | null>(null);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [openIdx, setOpenIdx] = useState<number | null>(null);
  const [libOpen, setLibOpen] = useState(false);
  const [imgPick, setImgPick] = useState<((v: string) => void) | null>(null);
  const [saveSec, setSaveSec] = useState<{ idx: number; name: string } | null>(null);
  const [editSec, setEditSec] = useState<{ id: number; name: string; block: Block } | null>(null);
  const [valid, setValid] = useState<{ block: number; msg: string }[] | null>(null);
  const [title, setTitle] = useState("");
  const [seo, setSeo] = useState({ seoTitle: "", seoDesc: "" });
  const [drag, setDrag] = useState<number | null>(null);
  const [over, setOver] = useState<number | null>(null);
  const [locked, setLocked] = useState<Set<number>>(new Set());
  const [previewKey, setPreviewKey] = useState(0);
  const saveTimer = useRef<number | undefined>(undefined);

  useEffect(() => { if (page) { setBlocks(page.blocks ?? []); setTitle(page.title); setTheme(page.theme ?? null); setSeo({ seoTitle: page.seoTitle ?? "", seoDesc: page.seoDesc ?? "" }); } }, [page]);

  const persist = useCallback(async (silent = false) => {
    if (!page) return;
    try {
      const res = await fetch(`/api/pages/${page.id}/blocks`, { method: "PUT", headers: { "content-type": "application/json" },
        body: JSON.stringify({ blocks, title, theme: theme ?? undefined, seoTitle: seo.seoTitle || undefined, seoDesc: seo.seoDesc || undefined }) });
      if (!res.ok) throw new Error((await res.json()).error);
      const j = await res.json();
      if (!silent) push({ tone: "success", title: "Draft saved", desc: `${blocks.length} blocks · validation ${j.validation.length ? j.validation.length + " issue(s)" : "clean"}` });
      setValid(j.validation); setDirty(false);
    } catch (e) { if (!silent) push({ tone: "danger", title: "Save failed", desc: e instanceof Error ? e.message : String(e) }); }
  }, [page, blocks, title, theme, seo, push]);

  useEffect(() => { if (!dirty) return; window.clearTimeout(saveTimer.current); saveTimer.current = window.setTimeout(() => void persist(true), 1200); return () => window.clearTimeout(saveTimer.current); }, [dirty, persist]);
  useRealtime(/sections/, () => sections.reload());

  const publish = async () => {
    if (!page) return;
    setBusy(true);
    try {
      await persist(true);
      const res = await fetch(`/api/pages/${page.id}/publish`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ note: "published from studio", title }) });
      if (!res.ok) { const j = await res.json(); setValid(j.validation ?? []); throw new Error(j.error); }
      push({ tone: "success", title: "Published ✓", desc: "Revision saved — live at /p/" + page.slug });
      reload();
    } catch (e) { push({ tone: "danger", title: "Publish blocked", desc: e instanceof Error ? e.message : String(e) }); }
    finally { setBusy(false); }
  };
  const versions = useApi<{ items: { id: number; note: string; who: string; createdAt: string; blocks: number }[] }>(dirty ? null : `/api/pages/${id}/versions`);
  const rollback = async (vid: number) => {
    await fetch(`/api/pages/${page!.id}/rollback`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ version_id: vid }) });
    push({ tone: "primary", title: "Rolled back", desc: "Editing revision #" + vid + " — publish to make it live." });
    reload(); versions.reload();
  };
  const set = (i: number, props: Record<string, unknown>) => { setBlocks((bs) => bs.map((b, j) => (j === i ? { ...b, props } : b))); setDirty(true); };
  const move = (i: number, d: -1 | 1) => { setBlocks((bs) => { const n = [...bs]; const j = i + d; if (!n[j]) return bs; [n[i], n[j]] = [n[j], n[i]]; return n; }); setDirty(true); };
  const drop = (j: number) => { if (drag === null || drag === j) return; setBlocks((bs) => { const n = [...bs]; const [it] = n.splice(drag, 1); n.splice(j > drag ? j - 1 : j, 0, it); return n; }); setDrag(null); setOver(null); setDirty(true); };
  const remove = (i: number) => { setBlocks((bs) => bs.filter((_, j) => j !== i)); setDirty(true); };
  const add = (type: string) => { setBlocks((bs) => [...bs, { type, props: {} }]); setLibOpen(false); setOpenIdx(blocks.length); setDirty(true); };
  const addRef = (sec: SavedSection) => { setBlocks((bs) => [...bs, { type: "global-section", props: { section_id: String(sec.id), sectionLabel: sec.name, section: sec.block } }]); setLibOpen(false); setDirty(true); };
  const addCopy = (sec: SavedSection) => { setBlocks((bs) => [...bs, { type: sec.block.type, props: { ...sec.block.props } }]); setLibOpen(false); setDirty(true); };
  const detach = (i: number) => { const inner = blocks[i]?.props.section as Block | undefined; if (!inner) return;
    setBlocks((bs) => bs.map((b, j) => (j === i ? { type: inner.type, props: { ...inner.props } } : b))); setDirty(true);
    push({ tone: "info", title: "Detached", desc: "Now a normal block — edits stay on this page." }); };
  const saveAsSection = async () => {
    if (!saveSec) return;
    await fetch("/api/saved-sections", { method: "POST", headers: { "content-type": "application/json" },
      body: JSON.stringify({ name: saveSec.name || blocks[saveSec.idx].type, block: blocks[saveSec.idx] }) });
    push({ tone: "success", title: "Saved to section library", desc: "Reuse it anywhere; future edits fan out." });
    setSaveSec(null); sections.reload();
  };
  const patchSection = async () => {
    if (!editSec) return;
    await fetch(`/api/saved-sections/${editSec.id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ block: editSec.block, name: editSec.name }) });
    push({ tone: "success", title: "Source section updated", desc: "All referencing pages re-render with it." });
    setEditSec(null); reload(); sections.reload();
  };

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
          <Button variant="secondary" onClick={() => setPreviewKey((k) => k + 1)}><Eye size={14} /> Reload preview</Button>
          <Button onClick={() => void persist()}><Save size={14} /> Save</Button>
          <Button onClick={() => void publish()} disabled={busy}><Globe size={14} /> {page.status === "Published" ? "Re-publish" : "Publish"}</Button>
        </>}
      />
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div className="space-y-3">
          {valid && valid.length > 0 && (
            <div className="rounded-card border border-danger/30 bg-danger-soft/40 px-4 py-2.5 text-caption">
              <b className="text-danger-strong">Fix before publish:</b>
              <ul className="mt-1 space-y-0.5">{valid.map((v, i) => <li key={i}>· {v.block >= 0 ? `Block ${v.block + 1} — ` : ""}{v.msg}</li>)}</ul>
            </div>)}
          {blocks.length === 0 && (
            <Card><EmptyState icon={<FileText size={20} />} title="Empty page" description="Add blocks from the library — then drag to arrange." action={<Button onClick={() => setLibOpen(true)}><Plus size={14} /> Open library</Button>} /></Card>)}
          {blocks.map((b, i) => (
            <div key={i} id={"wx-blk-" + i}
              onDragOver={(e) => { e.preventDefault(); setOver(i); }}
              onDrop={(e) => { e.preventDefault(); drop(i); }}
              className={cn("transition-transform", over === i && drag !== null && drag !== i && "translate-y-1")}>
              <Card className={cn("p-0 overflow-hidden", drag === i && "opacity-50 ring-2 ring-primary-500/40")}>
                <div
                  draggable={!locked.has(i)}
                  onDragStart={(e) => { setDrag(i); e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/wxblock", String(i)); }}
                  onDragEnd={() => { setDrag(null); setOver(null); }}
                  className={cn("flex items-center justify-between gap-2 border-b border-hairline bg-surface-secondary/60 px-3 py-1.5", !locked.has(i) && "cursor-grab active:cursor-grabbing")}
                  title={locked.has(i) ? "Unlocked blocks can be dragged from here" : "Drag to reorder"}
                >
                  <span className="flex items-center gap-2">
                    <span className={cn("select-none text-subtle", !locked.has(i) && "hidden sm:inline")}>⠿</span>
                    <p className="text-caption font-bold text-ink">#{i + 1} · {b.type === "global-section" ? "🌐 " + (String(b.props.sectionLabel ?? "Global section")) : registryMap[b.type]?.label ?? b.type}</p>
                    {b.type === "global-section" && <Badge tone="primary" dot={false}>fans out</Badge>}
                  </span>
                  <span className="flex items-center gap-1">
                    <button aria-label="lock" onClick={() => setLocked((ls) => { const n = new Set(ls); n.has(i) ? n.delete(i) : n.add(i); return n; })}
                      className={cn("rounded p-1 hover:bg-white", locked.has(i) ? "text-warning-strong" : "text-subtle hover:text-ink")}><Lock size={13} /></button>
                    <button aria-label="save as section" title="Save as reusable section" onClick={() => setSaveSec({ idx: i, name: "" })} className="rounded p-1 text-subtle hover:bg-white hover:text-ink"><Plus size={13} /></button>
                    <button aria-label="move up" onClick={() => move(i, -1)} className="rounded p-1 text-subtle hover:bg-white hover:text-ink"><ArrowRight size={13} className="-rotate-90" /></button>
                    <button aria-label="move down" onClick={() => move(i, 1)} className="rounded p-1 text-subtle hover:bg-white hover:text-ink"><ArrowRight size={13} className="rotate-90" /></button>
                    <button aria-label="delete" onClick={() => remove(i)} className="rounded p-1 text-subtle hover:bg-white hover:text-danger-strong"><Trash2 size={13} /></button>
                    <Button size="sm" variant={openIdx === i ? "primary" : "secondary"} onClick={() => setOpenIdx(openIdx === i ? null : i)}>Edit</Button>
                  </span>
                </div>
                <div style={theme ? { background: theme.bg, color: theme.ink } : undefined}>
                  <BlockPreview b={b} theme={theme} />
                </div>
                {openIdx === i && (
                  <div className="border-t border-hairline bg-white p-4">
                    {b.type === "global-section" ? (
                      <div className="flex flex-wrap items-center gap-2">
                        <p className="mr-auto text-caption text-muted">Editing the <b>source</b> updates every page referencing “{String(b.props.sectionLabel ?? "")}”.</p>
                        {Number(b.props.section_id) > 0 && <Button size="sm" onClick={() => setEditSec({ id: Number(b.props.section_id), name: String(b.props.sectionLabel ?? ""), block: (b.props.section as Block) })}><FileText size={13} /> Edit source section</Button>}
                        <Button size="sm" variant="secondary" onClick={() => detach(i)}><Undo2 size={13} /> Detach copy</Button>
                      </div>)
                      : <BlockEditor b={b} spec={registryMap[b.type]} onChange={(p) => set(i, p)} onImage={(cb) => setImgPick(() => cb)} />}
                    {valid?.some((v) => v.block === i) && <p className="mt-2 text-caption font-semibold text-danger-strong">{valid.filter((v) => v.block === i).map((v) => v.msg).join(" · ")}</p>}
                  </div>)}
              </Card>
            </div>
          ))}
          <Button variant="secondary" onClick={() => setLibOpen(true)}><Plus size={14} /> Add block / section</Button>
        </div>

        {/* right rail */}
        <div className="space-y-4">
          <Card className="p-4">
            <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Navigator</p>
            <ul className="max-h-44 space-y-0.5 overflow-y-auto scrollbar-slim">
              {blocks.map((b, i) => (
                <li key={i}>
                  <button onClick={() => { setOpenIdx(i); document.getElementById("wx-blk-" + i)?.scrollIntoView({ behavior: "smooth", block: "center" }); setPreviewKey((k) => k + 1); }}
                    className={cn("flex w-full items-center gap-2 rounded px-2 py-1 text-left text-caption hover:bg-surface-secondary", openIdx === i && "bg-primary-50 font-semibold text-primary-900")}>
                    <span className="w-4 shrink-0 text-subtle">{i + 1}</span>
                    <span className="min-w-0 flex-1 truncate">{b.type === "global-section" ? "🌐 " + String(b.props.sectionLabel ?? "global") : registryMap[b.type]?.label ?? b.type}</span>
                    {locked.has(i) && <Lock size={10} className="shrink-0 text-warning-strong" />}
                  </button>
                </li>))}
            </ul>
            <div className="mt-2 border-t border-hairline pt-2">
              <iframe key={previewKey} title="Page preview" src={`/p/${page.slug}${page.status !== "Published" ? "?draft=1" : ""}`}
                className="h-40 w-full rounded-control border border-line bg-white" onLoad={() => void 0} />
              <p className="mt-1 text-[10px] text-subtle">Live iframe — save publishes preview state; full page in new tab: <a className="font-semibold text-primary-600 hover:underline" href={`/p/${page.slug}${page.status !== "Published" ? "?draft=1" : ""}`} target="_blank" rel="noreferrer">/p/{page.slug}</a></p>
            </div>
          </Card>

          <Card className="p-4">
            <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Theme (P6 starter)</p>
            <div className="mb-2.5 grid grid-cols-2 gap-1.5">
              {PRESETS.map((p) => (
                <button key={p.name} onClick={() => { setTheme(p.t); setDirty(true); }}
                  className={cn("flex items-center gap-1.5 rounded-control border px-2 py-1.5 text-caption font-semibold transition-colors", theme?.primary === p.t.primary ? "border-primary-500 bg-primary-50 text-primary-900" : "border-hairline text-muted hover:bg-surface-secondary")}>
                  <span className="h-3 w-3 rounded-full" style={{ background: p.t.primary }} />{p.name}
                </button>))}
            </div>
            <div className="grid grid-cols-3 gap-2">
              <Field label="Accent"><Input type="color" value={theme?.primary ?? "#16A34A"} onChange={(e) => { setTheme((t) => ({ ...(t ?? {}), primary: e.target.value })); setDirty(true); }} className="h-9 p-0.5" /></Field>
              <Field label="Ink"><Input type="color" value={theme?.ink ?? "#182230"} onChange={(e) => { setTheme((t) => ({ ...(t ?? {}), ink: e.target.value })); setDirty(true); }} className="h-9 p-0.5" /></Field>
              <Field label="Page bg"><Input type="color" value={theme?.bg ?? "#FFFFFF"} onChange={(e) => { setTheme((t) => ({ ...(t ?? {}), bg: e.target.value })); setDirty(true); }} className="h-9 p-0.5" /></Field>
            </div>
            <div className="mt-2 grid grid-cols-2 gap-2">
              <Field label="Heading font">
                <div className="flex gap-1.5">
                  {(["sans", "serif"] as const).map((f) => (
                    <button key={f} onClick={() => { setTheme((t) => ({ ...(t ?? {}), font: f })); setDirty(true); }}
                      className={cn("flex-1 rounded-control border px-2 py-1.5 text-caption font-bold", theme?.font === f ? "border-primary-500 bg-primary-50 text-primary-900" : "border-hairline text-muted")}>{f}</button>))}
                </div>
              </Field>
              <Field label="Announcement"><Input value={theme?.announce ?? ""} placeholder="e.g. Free install in Lahore" onChange={(e) => { setTheme((t) => ({ ...(t ?? {}), announce: e.target.value })); setDirty(true); }} /></Field>
            </div>
            <button onClick={() => { setTheme(null); setDirty(true); }} className="mt-2 text-caption font-semibold text-primary-600 hover:underline">Reset to site theme</button>
          </Card>

          <Card className="p-4">
            <p className="mb-3 text-caption font-bold uppercase tracking-wide text-subtle">Page settings</p>
            <Field label="Title"><Input value={title} onChange={(e) => { setTitle(e.target.value); setDirty(true); }} /></Field>
            <div className="mt-2.5"><Field label="SEO title"><Input value={seo.seoTitle} onChange={(e) => { setSeo((x) => ({ ...x, seoTitle: e.target.value })); setDirty(true); }} /></Field></div>
            <div className="mt-2.5"><Field label="Meta description"><Input value={seo.seoDesc} onChange={(e) => { setSeo((x) => ({ ...x, seoDesc: e.target.value })); setDirty(true); }} /></Field></div>
          </Card>

          <Card className="p-4">
            <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Published revisions</p>
            {versions.loading ? <SkeletonRows rows={2} /> : (versions.data?.items.length ?? 0) === 0 ? <p className="text-caption text-subtle">No versions yet.</p> : (
              <ul className="space-y-1.5">{versions.data!.items.map((v) => (
                <li key={v.id} className="flex items-center justify-between rounded-control bg-surface-secondary px-2.5 py-1.5 text-caption">
                  <span><b className="text-ink">v{v.id}</b> · {v.blocks} blocks<span className="block text-subtle">{timeAgo(v.createdAt)} · {v.who}</span></span>
                  <button onClick={() => void rollback(v.id)} className="inline-flex items-center gap-1 font-semibold text-primary-600 hover:underline"><Undo2 size={12} /> restore</button>
                </li>))}</ul>)}
          </Card>
        </div>
      </div>

      {/* library with sections on top */}
      <Modal open={libOpen} onClose={() => setLibOpen(false)} title="Block & section library" description="Typed blocks — validated on publish. Insert sections as fan-out references or copies." footer={null} className="max-w-2xl">
        {sections.data && sections.data.items.length > 0 && (
          <div className="mb-3">
            <p className="mb-1.5 text-caption font-bold uppercase tracking-wide text-subtle">Saved sections ({sections.data.items.length})</p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {sections.data.items.map((sec) => (
                <div key={sec.id} className="rounded-card border border-hairline p-2.5">
                  <div className="flex items-center justify-between gap-2"><p className="text-small font-bold text-ink">{sec.name}</p>
                    <span className="flex gap-1"><Button size="sm" onClick={() => addRef(sec)}><Globe size={12} /> Reference</Button>
                    <Button size="sm" variant="secondary" onClick={() => addCopy(sec)}>Copy</Button></span></div>
                  <p className="mb-1.5 text-[10px] text-subtle">{sec.category} · used on {sec.usage} page{sec.usage === 1 ? "" : "s"}</p>
                  <div className="pointer-events-none scale-[0.92] opacity-90"><BlockPreview b={sec.block} /></div>
                </div>))}
            </div>
            <p className="mt-1.5 text-[10px] text-subtle">Reference = edit once, every page updates. Copy = independent.</p>
          </div>)}
        <p className="mb-1.5 text-caption font-bold uppercase tracking-wide text-subtle">Blocks</p>
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
          {Object.entries(registryMap).filter(([t]) => t !== "global-section").map(([type, spec]) => (
            <button key={type} onClick={() => add(type)} className="rounded-card border border-hairline p-3 text-left transition-colors hover:border-primary-400 hover:bg-primary-50/40">
              <p className="text-small font-bold text-ink">{spec.label}</p>
              <p className="mt-0.5 text-[10px] text-subtle">{spec.group}{spec.fields.some((f) => f.req) ? " · req: " + spec.fields.filter((f) => f.req).map((f) => f.k).join(", ") : ""}</p>
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

      {/* save-as-section */}
      <Modal open={!!saveSec} onClose={() => setSaveSec(null)} title="Save block as reusable section" footer={<Button onClick={() => void saveAsSection()}><Check size={14} /> Save to library</Button>}>
        <Field label="Section name"><Input autoFocus value={saveSec?.name ?? ""} onChange={(e) => setSaveSec((x) => (x ? { ...x, name: e.target.value } : x))} placeholder="e.g. Trust strip" /></Field>
      </Modal>

      {/* edit source section */}
      <Modal open={!!editSec} onClose={() => setEditSec(null)} title={"Source section · " + (editSec?.name ?? "")} description="Publishing each referencing page is not needed — sources resolve live." className="max-w-xl"
        footer={<><Button variant="secondary" onClick={() => setEditSec(null)}>Cancel</Button><Button onClick={() => void patchSection()}>Update everywhere</Button></>}>
        {editSec && <BlockEditor b={editSec.block} spec={registryMap[editSec.block.type]}
          onChange={(props) => setEditSec((x) => (x ? { ...x, block: { ...x.block, props } } : x))} onImage={(cb) => setImgPick(() => cb)} />}
      </Modal>

    </div>
  );
}

/* ---------- list ---------- */
export default function Website() {
  const { push } = useToast();
  const { data, loading, error, reload } = useApi<{ total: number; published: number; items: PageDoc[] }>("/api/pages", 30000);
  const sections = useApi<{ total: number; items: SavedSection[] }>("/api/saved-sections", 30000);
  useRealtime(/pages|sections/, () => { reload(); sections.reload(); });
  const [newOpen, setNewOpen] = useState(false);
  const [nf, setNf] = useState<{ slug: string; title: string; template: string }>({ slug: "", title: "", template: "sale-landing" });
  const create = async () => {
    if (!SLUG_RE.test(nf.slug)) return push({ tone: "danger", title: "Bad slug", desc: "lowercase letters/numbers/dashes, 2–61 chars" });
    try {
      const res = await fetch("/api/pages", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(nf) });
      const j = await res.json();
      if (!res.ok) throw new Error(j.error);
      push({ tone: "success", title: "Page created", desc: "/" + j.slug });
      window.location.href = `/website/edit/${j.id}`;
    } catch (e) { push({ tone: "danger", title: "Could not create", desc: e instanceof Error ? e.message : String(e) }); }
  };
  const delSection = async (sec: SavedSection) => {
    const res = await fetch(`/api/saved-sections/${sec.id}`, { method: "DELETE" });
    if (res.status === 409) return push({ tone: "warning", title: "In use", desc: `Referenced by ${sec.usage} page(s) — detach them first (admin-only delete rule).` });
    push({ tone: "success", title: "Section deleted" }); sections.reload();
  };
  const cols: Column<PageDoc>[] = [
    { key: "t", header: "Page", cell: (r) => (
      <span className="flex items-center gap-2.5"><span className="grid h-8 w-8 place-items-center rounded-control bg-primary-50 text-primary-700"><FileText size={15} /></span>
        <span><Link to={`/website/edit/${r.id}`} className="block text-small font-semibold text-ink hover:underline">{r.title}</Link>
        <span className="block text-caption text-subtle">/p/{r.slug} · {r.blockCount} blocks</span></span></span>) },
    { key: "s", header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    { key: "v", header: "Traffic", align: "right", cell: (r) => <span className="text-caption"><b className={cn((r.views ?? 0) > 0 ? "text-ink" : "text-subtle")}>{r.views ?? 0}</b><span className="text-subtle"> · ⚡{r.ctas ?? 0}</span></span> },
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
        description="Drag-and-drop typed blocks with live catalog bindings, reusable global sections and per-page themes. Publish, preview, roll back."
        actions={<><Button variant="secondary" onClick={reload}>Refresh</Button>
          <Button onClick={() => setNewOpen(true)}><Plus size={15} /> New page</Button></>}
      />
      <div className="mb-4 grid grid-cols-3 gap-3 lg:gap-4">
        <Card className="py-4"><p className="text-caption font-medium text-subtle uppercase">Pages</p><p className="mt-1 text-h2 text-ink">{data?.total ?? "—"}</p></Card>
        <Card className="py-4"><p className="text-caption font-medium text-subtle uppercase">Published</p><p className="mt-1 text-h2 text-ink">{data?.published ?? "—"}</p></Card>
        <Card className="py-4"><p className="text-caption font-medium text-subtle uppercase">Reusable sections</p><p className="mt-1 text-h2 text-ink">{sections.data?.total ?? "—"}</p></Card>
      </div>
      <Card>
        {error ? <div className="p-4"><ErrorState title="Pages unavailable" debug="GET /api/pages" onRetry={reload} /></div>
          : loading ? <SkeletonRows rows={4} />
          : (data?.items.length ?? 0) === 0 ? <div className="p-6"><EmptyState icon={<Globe size={20} />} title="No pages yet" description="Create a landing page from a template." action={<Button onClick={() => setNewOpen(true)}><Plus size={14} /> New page</Button>} /></div>
          : <DataTable rows={data!.items} columns={cols} perPage={10} />}
      </Card>
      <Card className="mt-4 p-4">
        <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Section library</p>
        {(sections.data?.items.length ?? 0) === 0 ? <p className="text-caption text-subtle">Save any block “as section” inside the editor — it becomes a reusable fan-out source like the seeded <b>Trust strip</b>.</p> : (
          <ul className="flex flex-wrap gap-2">{sections.data!.items.map((sec) => (
            <li key={sec.id} className="flex items-center gap-2 rounded-control border border-hairline px-3 py-1.5 text-caption">
              <b className="text-ink">{sec.name}</b><span className="text-subtle">{sec.category} · {sec.usage} page{sec.usage === 1 ? "" : "s"}</span>
              <button onClick={() => void delSection(sec)} className="text-subtle hover:text-danger-strong" title="Delete (blocked while in use)"><Trash2 size={12} /></button>
            </li>))}</ul>)}
      </Card>
      <Modal open={newOpen} onClose={() => setNewOpen(false)} title="New page" description="Slug is permanent (rename stays admin-only by rule)." footer={<Button onClick={() => void create()}><Check size={14} /> Create & edit</Button>}>
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
