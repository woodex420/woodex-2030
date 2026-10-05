import { useEffect, useState } from "react";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Field, Input } from "@/components/ui/Field";
import { Badge } from "@/components/ui/Badge";
import { SkeletonRows } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, useRealtime } from "@/lib/api";
import { Check, Eye, Moon, Sparkles, Sun } from "@/icons";

type SiteTheme = {
  brand: string; darkBrand: string | null; radius: number;
  font: "sans" | "serif"; mode: "light" | "dark" | "auto";
  tintNav: boolean; announce: { text: string; href: string | null } | null; siteUrl?: string | null;
};
const DEFAULTS: SiteTheme = { brand: "#16A34A", darkBrand: null, radius: 4, font: "sans", mode: "light", tintNav: false, announce: null };
const SWATCHES = ["#16A34A", "#1D4ED8", "#7C3AED", "#B45309", "#E11D48", "#0D9488", "#334155", "#9A3412"];

/* tiny hex helpers for the preview only (real tokens are derived server-consumer side) */
const hexRgb = (h: string) => { const v = parseInt(h.replace("#", ""), 16); return [(v >> 16) & 255, (v >> 8) & 255, v & 255] as const; };
const mix = (h: string, amt: number) => { const [r, g, b] = hexRgb(h); const t = amt < 0 ? 0 : 255, p = Math.abs(amt); const f = (c: number) => Math.round(c + (t - c) * p); return `rgb(${f(r)},${f(g)},${f(b)})`; };

export default function ThemeStudio() {
  const { push } = useToast();
  const { data, loading, reload } = useApi<SiteTheme>("/api/theme");
  const [d, setD] = useState<SiteTheme>(DEFAULTS);
  const [dirty, setDirty] = useState(false);
  useEffect(() => { if (data) { setD({ ...DEFAULTS, ...data }); setDirty(false); } }, [data]);
  useRealtime(/theme/, () => reload());
  const set = (patch: Partial<SiteTheme>) => { setD((x) => ({ ...x, ...patch })); setDirty(true); };
  const save = async () => {
    try {
      const res = await fetch("/api/theme", { method: "PUT", headers: { "content-type": "application/json" }, body: JSON.stringify(d) });
      if (!res.ok) throw new Error((await res.json()).error);
      push({ tone: "success", title: "Site theme live", desc: "Storefronts re-tokenize instantly via SSE — no rebuild." });
      reload();
    } catch (e) { push({ tone: "danger", title: "Rejected", desc: e instanceof Error ? e.message : String(e) }); }
  };

  const fontStack = d.font === "serif" ? "Georgia, 'Times New Roman', serif" : "Inter, system-ui, sans-serif";
  const accentDark = d.darkBrand ?? mix(d.brand, -0.18);

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Website", to: "/website" }, { label: "Theme Studio" }]}
        title="Theme Studio"
        description="Site-wide design tokens for the public storefront — brand color, radius, typeface, dark mode and announcement bar. Saved once, applied everywhere (P6)."
        actions={<>
          <Button variant="secondary" onClick={() => { setD(DEFAULTS); setDirty(true); }}>Reset defaults</Button>
          <Button onClick={() => void save()} disabled={!dirty}><Check size={14} /> Apply to site</Button>
        </>}
      />
      {loading || !data ? <SkeletonRows rows={5} /> : (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-[380px_minmax(0,1fr)]">
          <div className="space-y-4">
            <Card className="p-4">
              <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Brand color</p>
              <div className="flex items-center gap-2">
                <Input type="color" value={d.brand} onChange={(e) => set({ brand: e.target.value })} className="h-10 w-14 cursor-pointer p-1" />
                <Input value={d.brand} onChange={(e) => set({ brand: e.target.value })} className="w-28 font-mono" />
                <Badge tone={dirty ? "warning" : "success"} dot={false}>{dirty ? "unsaved" : "live"}</Badge>
              </div>
              <div className="mt-2.5 flex gap-1.5">
                {SWATCHES.map((c) => (
                  <button key={c} onClick={() => set({ brand: c })} aria-label={"brand " + c}
                    className={cn("h-7 w-7 rounded-full border-2 transition-transform hover:scale-110", d.brand.toLowerCase() === c.toLowerCase() ? "border-ink" : "border-transparent")}
                    style={{ background: c }} />))}
              </div>
              <div className="mt-3 grid grid-cols-2 gap-2">
                <Field label="Dark-mode accent" hint="blank = auto-brighten brand">
                  <span className="flex gap-1.5">
                    <Input type="color" value={d.darkBrand ?? d.brand} onChange={(e) => set({ darkBrand: e.target.value })} className="h-9 w-12 shrink-0 cursor-pointer p-0.5" />
                    <Button size="sm" variant="secondary" onClick={() => set({ darkBrand: null })}>Auto</Button>
                  </span>
                </Field>
                <Field label={"Corner radius · " + d.radius + "px"}>
                  <input type="range" min={0} max={28} step={1} value={d.radius} onChange={(e) => set({ radius: Number(e.target.value) })} className="mt-3 w-full accent-[var(--color-primary-600,#16a34a)]" />
                </Field>
              </div>
            </Card>

            <Card className="p-4">
              <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Behaviour</p>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Heading font">
                  <div className="flex gap-1.5">
                    {(["sans", "serif"] as const).map((f) => (
                      <button key={f} onClick={() => set({ font: f })}
                        className={cn("flex-1 rounded-control border px-2 py-1.5 text-caption font-bold capitalize", d.font === f ? "border-primary-500 bg-primary-50 text-primary-900" : "border-hairline text-muted hover:bg-surface-secondary")}>{f}</button>))}
                  </div>
                </Field>
                <Field label="Default mode">
                  <div className="flex gap-1.5">
                    {([["light", Sun], ["dark", Moon], ["auto", Sparkles]] as const).map(([m, Ico]) => (
                      <button key={m} onClick={() => set({ mode: m })}
                        className={cn("flex flex-1 items-center justify-center gap-1 rounded-control border px-2 py-1.5 text-caption font-bold capitalize", d.mode === m ? "border-primary-500 bg-primary-50 text-primary-900" : "border-hairline text-muted hover:bg-surface-secondary")}>
                        <Ico size={12} /> {m}</button>))}
                  </div>
                </Field>
              </div>
              <button onClick={() => set({ tintNav: !d.tintNav })}
                className={cn("mt-1 flex w-full items-center gap-2 rounded-control border px-3 py-2 text-left text-caption transition-colors", d.tintNav ? "border-primary-500 bg-primary-50" : "border-hairline hover:bg-surface-secondary")}>
                <span className={cn("grid h-4 w-4 place-items-center rounded border", d.tintNav ? "border-primary-600 bg-primary-600 text-white" : "border-line-strong")}>{d.tintNav && <Check size={10} />}</span>
                <span><b className="text-ink">Tint header &amp; nav with brand</b><span className="block text-subtle">Off = HON-style charcoal utility bar. Visitors can still toggle dark/light; this only sets the default.</span></span>
              </button>
            </Card>

            <Card className="p-4">
              <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Announcement bar</p>
              {d.announce ? (
                <div className="space-y-2.5">
                  <Field label="Text (shows above the header)"><Input value={d.announce.text} maxLength={160} onChange={(e) => set({ announce: { ...d.announce!, text: e.target.value } })} /></Field>
                  <div className="flex gap-2">
                    <div className="flex-1"><Field label="Link (optional)"><Input value={d.announce.href ?? ""} placeholder="/shop?sale=1" onChange={(e) => set({ announce: { ...d.announce!, href: e.target.value || null } })} /></Field></div>
                    <Button size="sm" variant="secondary" className="mt-5" onClick={() => set({ announce: null })}>Remove</Button>
                  </div>
                </div>
              ) : (
                <Button size="sm" variant="secondary" onClick={() => set({ announce: { text: "Free installation in Lahore — this month only", href: "/shop" } })}><Eye size={12} /> Add announcement</Button>
              )}
              <p className="mt-2 text-[10px] text-subtle">Landing pages with their own theme announcement override this on /p/* (verified via wx-pagebar).</p>
            </Card>

            <Card className="p-4">
              <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">SEO plumbing</p>
              <Field label="Public site URL" hint="Used for sitemap.xml <loc>, robots.txt and canonical/OG urls"><Input value={d.siteUrl ?? ""} placeholder="https://woodexfurniture.pk" onChange={(e) => set({ siteUrl: e.target.value || null })} /></Field>
              <p className="mt-2 text-[10px] text-subtle">After applying: <a className="font-semibold text-primary-600 hover:underline" href="/sitemap.xml" target="_blank" rel="noreferrer">/sitemap.xml ↗</a> and <a className="font-semibold text-primary-600 hover:underline" href="/robots.txt" target="_blank" rel="noreferrer">/robots.txt ↗</a> serve every published landing automatically.</p>
            </Card>
          </div>

          {/* preview */}
          <Card className="overflow-hidden p-0">
            <p className="border-b border-hairline px-4 py-2.5 text-caption font-bold uppercase tracking-wide text-subtle">Live token preview — mirrors hsl(var(--…)) overrides the storefront injects</p>
            <div className="p-4">
              <div className={cn("overflow-hidden rounded-card border border-line", d.mode === "dark" && "opacity-95")} style={{ fontFamily: fontStack, background: d.mode === "dark" ? "#171c26" : "#ffffff", color: d.mode === "dark" ? "#eef1f5" : "#1f2937" }}>
                {d.announce?.text && <div className="px-4 py-1.5 text-center text-[11px] font-bold text-white" style={{ background: d.mode === "dark" ? accentDark : d.brand }}>{d.announce.text}</div>}
                <div className="flex items-center justify-between px-4 py-1.5 text-[10px] text-white/70" style={{ background: d.tintNav ? mix(d.brand, -0.35) : "#21262e" }}>
                  <span className="flex gap-3"><b>Showrooms</b><span>Materials</span><span>Warranty</span></span>
                  <span className="flex items-center gap-2">English · PKR <span className="grid h-4 w-4 place-items-center rounded-full bg-white/10">{d.mode === "dark" ? <Sun size={10} /> : <Moon size={10} />}</span></span>
                </div>
                <div className="flex items-center justify-between border-b px-4 py-2.5" style={{ borderColor: d.mode === "dark" ? "#2a3140" : "#e5e7eb" }}>
                  <b className="text-small tracking-tight" style={{ color: d.tintNav ? d.brand : undefined }}>WOODEX</b>
                  <span className="flex gap-1.5 text-[10px] font-medium opacity-80"><span>Shop</span><span>Collections</span><span>Projects</span><span>Contact</span></span>
                  <span className="rounded px-2.5 py-1 text-[10px] font-bold text-white" style={{ background: d.mode === "dark" ? accentDark : d.brand, borderRadius: d.radius }}>Get quote</span>
                </div>
                <div className="grid gap-3 p-4 sm:grid-cols-[1.4fr_1fr]">
                  <div className="flex flex-col justify-center gap-2 p-4" style={{ background: d.mode === "dark" ? "#1c2331" : mix(d.brand, 0.93), borderRadius: Math.max(d.radius, 4) * 2 }}>
                    <p className="text-[9px] font-black uppercase tracking-[0.18em]" style={{ color: d.mode === "dark" ? accentDark : d.brand }}>Workspace sale · up to 35% off</p>
                    <h3 className="text-h3 leading-tight" style={{ fontFamily: fontStack }}>{d.font === "serif" ? "Desks that mean business." : "Desks that mean business."}</h3>
                    <p className="text-[11px] opacity-70">Lao teak-finish workstations, engineered for Lahore humidity.</p>
                    <span className="flex gap-2">
                      <span className="px-3 py-1.5 text-[10px] font-bold text-white" style={{ background: d.mode === "dark" ? accentDark : d.brand, borderRadius: d.radius }}>Shop the sale</span>
                      <span className="rounded border px-3 py-1.5 text-[10px] font-bold" style={{ borderColor: d.mode === "dark" ? "#39414f" : "#d1d5db" }}>Book a showroom visit</span>
                    </span>
                  </div>
                  <div className="border p-3" style={{ borderColor: d.mode === "dark" ? "#2a3140" : "#e5e7eb", borderRadius: Math.max(d.radius, 4) * 2 }}>
                    <div className="h-20 rounded" style={{ background: d.mode === "dark" ? "#232b3a" : "#f3f4f6", borderRadius: d.radius }} />
                    <p className="mt-2 text-[11px] font-bold">Meridian Task Chair</p>
                    <p className="text-[11px] font-black" style={{ color: d.mode === "dark" ? accentDark : d.brand }}>Rs 74,900 <s className="text-[9px] font-normal opacity-50">Rs 89,000</s></p>
                    <p className="mt-1 inline-block px-1.5 py-0.5 text-[8px] font-bold text-white" style={{ background: d.mode === "dark" ? accentDark : d.brand, borderRadius: d.radius }}>In stock</p>
                  </div>
                </div>
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-2 text-[10px] text-subtle">
                <span className="flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-full" style={{ background: d.brand }} /> --accent / --ring / --hon-green</span>
                <span className="flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-full border" style={{ background: d.mode === "dark" ? "#171c26" : "#fff", borderColor: "#94a3b8" }} /> bg-background · dark via .dark</span>
                <span>radius → var(--radius)</span><span>font → wx-serif class</span>
                <a href="/api/theme" target="_blank" rel="noreferrer" className="ml-auto font-semibold text-primary-600 hover:underline">GET /api/theme ↗</a>
              </div>
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}
