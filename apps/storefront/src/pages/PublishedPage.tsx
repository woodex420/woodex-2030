import { useEffect, useMemo, useState } from "react";
import { useParams, useSearchParams, Link } from "react-router-dom";
import { products } from "@/data/products";
import { materials } from "@/data/materials";
import { submitLead } from "@/lib/runtime";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { ArrowRight, CheckCircle2, Star, Send, ChevronDown } from "lucide-react";
import { toast } from "sonner";
import { Link2, MessageCircle } from "lucide-react";
import { attachTrackers, trackPage } from "@/lib/track";

type Block = { type: string; props: Record<string, unknown> };
type Theme = { primary?: string; ink?: string; bg?: string; font?: "sans" | "serif"; announce?: string };
type PageDoc = { slug: string; title: string; status: string; seoTitle?: string; seoDesc?: string; publishedAt?: string; blocks: Block[]; theme?: Theme | null };

const S = (b: Block, k: string) => String(b.props[k] ?? "");
const L = (b: Block, k: string) => S(b, k).split("\n").map((x) => x.trim()).filter(Boolean);
const imgSrc = (v: string) => (v.startsWith("http") || v.startsWith("/img/") ? v : "/img/" + v);
const pkr = (n: number) => "Rs " + Math.round(n).toLocaleString("en-PK");
const hexA = (h: string, a: number) => { const m = /^#?([0-9a-f]{6})$/i.exec(h.trim()); if (!m) return h; const v = parseInt(m[1], 16); return `rgba(${(v >> 16) & 255}, ${(v >> 8) & 255}, ${v & 255}, ${a})`; };

function LeadForm({ b, slug, th }: { b: Block; slug: string; th?: Theme }) {
  const [f, setF] = useState({ name: "", contact: "", need: "" });
  const [done, setDone] = useState(false);
  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!f.name.trim()) return toast.error("Please tell us your name");
    const ok = await submitLead({ name: f.name, interest: f.need || S(b, "heading"), source: "Landing: " + slug, contact: f.contact, note: "From published page /p/" + slug });
    if (ok) { setDone(true); toast.success("Sent — sales will confirm within one working day"); }
    else toast.error("Could not reach the server — please try WhatsApp instead");
  };
  if (done)
    return (
      <div className="rounded-2xl bg-green-50 p-8 text-center">
        <CheckCircle2 className="mx-auto h-10 w-10 text-green-600" />
        <h3 className="mt-3 text-xl font-extrabold">{S(b, "heading")}</h3>
        <p className="mt-1 text-sm text-muted-foreground">Request received — it landed directly in the sales pipeline.</p>
      </div>
    );
  return (
    <form onSubmit={send} className="grid gap-3 rounded-2xl border bg-card p-6 shadow-sm sm:grid-cols-[1fr_1fr_auto]">
      <div className="sm:col-span-2">
        <h3 className="text-2xl font-black">{S(b, "heading")}</h3>
        {S(b, "sub") && <p className="mt-1 text-sm text-muted-foreground">{S(b, "sub")}</p>}
      </div>
      <Input placeholder="Your name *" value={f.name} onChange={(e) => setF((x) => ({ ...x, name: e.target.value }))} />
      <Input placeholder="Phone or email" value={f.contact} onChange={(e) => setF((x) => ({ ...x, contact: e.target.value }))} />
      <Input className="sm:col-span-2" placeholder="What do you need? e.g. 6-seater dining in Sheesham" value={f.need} onChange={(e) => setF((x) => ({ ...x, need: e.target.value }))} />
      <Button type="submit" data-wx-cta="lead-form-submit" className="bg-primary text-primary-foreground hover:bg-primary/90 sm:col-span-3" style={th?.primary ? { background: th.primary, borderColor: th.primary } : undefined}>
        <Send className="mr-1.5 h-4 w-4" /> {S(b, "submit_label") || "Send to sales"}
      </Button>
      {S(b, "consent") && <p className="text-[11px] text-muted-foreground sm:col-span-3">{S(b, "consent")}</p>}
    </form>
  );
}

function ProductGrid({ b }: { b: Block }) {
  const list = useMemo(() => {
    const ids = S(b, "ids").split(",").map((x) => x.trim()).filter(Boolean);
    const limit = Number(S(b, "limit")) || 4;
    if (ids.length) return products.filter((p) => ids.includes(p.id)).slice(0, limit);
    const words = S(b, "match").toLowerCase().split(/\s+/).filter(Boolean);
    const pool = words.length ? products.filter((p) => words.some((w) => (p.name + " " + p.category + " " + (p.series ?? "")).toLowerCase().includes(w))) : products;
    return pool.sort((x, y) => Number(!!y.isBestSeller) - Number(!!x.isBestSeller)).slice(0, limit);
  }, [b]);
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {list.map((p) => (
        <Link key={p.id} to={"/shop/" + p.id} data-wx-cta={"product:" + p.id} className="group overflow-hidden rounded-2xl border bg-card shadow-sm transition-shadow hover:shadow-md">
          <div className="relative aspect-[4/3] overflow-hidden bg-muted">
            {p.images[0] && <img src={p.images[0]} alt={p.name} loading="lazy" className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105" />}
            {!p.inStock && <Badge className="absolute top-2 left-2 bg-destructive text-destructive-foreground">Out of stock</Badge>}
            {typeof p.originalPrice === "number" && p.originalPrice > p.price && (
              <Badge className="absolute top-2 right-2 bg-accent text-accent-foreground">−{Math.round((1 - p.price / p.originalPrice) * 100)}%</Badge>)}
          </div>
          <div className="p-3.5">
            <p className="line-clamp-1 text-sm font-bold">{p.name}</p>
            <p className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"><Star className="h-3 w-3 fill-amber-400 text-amber-400" />{p.rating.toFixed(1)} · {p.category}</p>
            <p className="mt-1.5 font-black text-primary" style={th?.primary ? { color: th.primary } : undefined}>{pkr(p.price)}{typeof p.originalPrice === "number" && p.originalPrice > p.price && <s className="ml-1.5 text-xs font-normal text-muted-foreground">{pkr(p.originalPrice)}</s>}</p>
          </div>
        </Link>
      ))}
    </div>
  );
}

function BlockView({ b, slug, th }: { b: Block; slug: string; th?: Theme }) {
  switch (b.type) {
    case "hero":
      return (
        <section className="relative overflow-hidden rounded-3xl bg-primary/5" style={th?.primary ? { background: hexA(th.primary, 0.06) } : undefined}>
          <div className="grid items-center gap-6 p-8 sm:p-12 lg:grid-cols-2">
            <div>
              {S(b, "kicker") && <p className="text-xs font-black uppercase tracking-[0.2em] text-primary" style={th?.primary ? { color: th.primary } : undefined}>{S(b, "kicker")}</p>}
              <h1 className="mt-3 text-3xl font-black leading-tight sm:text-4xl lg:text-5xl">{S(b, "heading")}</h1>
              {S(b, "sub") && <p className="mt-4 max-w-lg text-muted-foreground">{S(b, "sub")}</p>}
              <div className="mt-6 flex flex-wrap gap-3">
                {S(b, "cta_label") && <Button asChild data-wx-cta="hero-cta" className="bg-primary text-primary-foreground hover:bg-primary/90" style={th?.primary ? { background: th.primary, borderColor: th.primary } : undefined}><Link to={S(b, "cta_href") || "/shop"}>{S(b, "cta_label")} <ArrowRight className="ml-1.5 h-4 w-4" /></Link></Button>}
                {S(b, "cta2") && <Button asChild data-wx-cta="hero-secondary" variant="outline"><Link to="/shop">{S(b, "cta2")}</Link></Button>}
              </div>
            </div>
            {S(b, "image") && <img src={imgSrc(S(b, "image"))} alt={S(b, "heading")} className="aspect-[4/3] w-full rounded-2xl object-cover shadow-lg" />}
          </div>
        </section>
      );
    case "text-section":
      return (
        <section className="grid items-center gap-8 py-4 lg:grid-cols-2">
          <div>
            {S(b, "kicker") && <p className="text-xs font-black uppercase tracking-[0.2em] text-primary" style={th?.primary ? { color: th.primary } : undefined}>{S(b, "kicker")}</p>}
            <h2 className="mt-2 text-2xl font-black sm:text-3xl">{S(b, "heading")}</h2>
            {S(b, "body") && <p className="mt-3 text-muted-foreground">{S(b, "body")}</p>}
            <ul className="mt-4 space-y-2">
              {L(b, "bullets").map((x, i) => <li key={i} className="flex items-start gap-2 text-sm"><CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-primary" style={th?.primary ? { color: th.primary } : undefined} />{x}</li>)}
            </ul>
          </div>
          {S(b, "image") && <img src={imgSrc(S(b, "image"))} alt="" className="aspect-video w-full rounded-2xl object-cover" />}
        </section>
      );
    case "product-grid":
      return <section className="py-4"><h2 className="mb-4 text-2xl font-black">{S(b, "heading") || "From the catalog"}</h2><ProductGrid b={b} /></section>;
    case "product-feature": {
      const p = products.find((x) => x.id === S(b, "productId") || x.name.toLowerCase().includes(S(b, "productId").toLowerCase()));
      if (!p) return null;
      return (
        <section className="grid items-center gap-6 rounded-3xl border bg-card p-6 py-8 sm:p-10 lg:grid-cols-2">
          <img src={p.images[0]} alt={p.name} className="aspect-[4/3] w-full rounded-2xl object-cover" />
          <div>
            <h2 className="text-2xl font-black">{S(b, "heading") || p.name}</h2>
            <p className="mt-2 text-muted-foreground">{S(b, "body") || p.shortDescription}</p>
            <p className="mt-4 text-2xl font-black text-primary" style={th?.primary ? { color: th.primary } : undefined}>{pkr(p.price)}</p>
            <Button asChild data-wx-cta={"product-feature:" + p.id} className="mt-4 bg-primary text-primary-foreground hover:bg-primary/90" style={th?.primary ? { background: th.primary, borderColor: th.primary } : undefined}><Link to={"/shop/" + p.id}>View product <ArrowRight className="ml-1.5 h-4 w-4" /></Link></Button>
          </div>
        </section>
      );
    }
    case "materials":
      return (
        <section className="py-4">
          <h2 className="text-2xl font-black">{S(b, "heading") || "Materials & craft"}</h2>
          {S(b, "sub") && <p className="mt-1 text-sm text-muted-foreground">{S(b, "sub")}</p>}
          <div className="mt-5 grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {materials.slice(0, 8).map((m) => (
              <div key={m.id} className="overflow-hidden rounded-2xl border bg-card">
                <img src={m.image} alt={m.name} loading="lazy" className="aspect-[3/2] w-full object-cover" />
                <p className="p-3 text-sm font-bold">{m.name}</p>
                <p className="px-3 pb-3 text-xs text-muted-foreground capitalize">{m.category}</p>
              </div>
            ))}
          </div>
        </section>
      );
    case "gallery":
      return (
        <section className="py-4">
          {S(b, "heading") && <h2 className="mb-4 text-2xl font-black">{S(b, "heading")}</h2>}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {L(b, "images").map((im, i) => <img key={i} src={imgSrc(im)} alt="" loading="lazy" className="aspect-[4/3] w-full rounded-xl object-cover" />)}
          </div>
        </section>
      );
    case "testimonials":
      return (
        <section className="py-4">
          <h2 className="mb-4 text-2xl font-black">{S(b, "heading") || "What clients say"}</h2>
          <div className="grid gap-4 md:grid-cols-3">
            {L(b, "items").map((row, i) => {
              const [quote, name, role] = row.split("|").map((x) => x.trim());
              return (
                <figure key={i} className="rounded-2xl border bg-card p-5">
                  <div className="flex gap-0.5 text-amber-400">{[...Array(5)].map((_, k) => <Star key={k} className="h-3.5 w-3.5 fill-current" />)}</div>
                  <blockquote className="mt-2.5 text-sm font-medium">“{quote}”</blockquote>
                  <figcaption className="mt-3 text-xs text-muted-foreground">{name}{role ? " · " + role : ""}</figcaption>
                </figure>
              );
            })}
          </div>
        </section>
      );
    case "faq": {
      const [openI, setOpenI] = useState<number | null>(0);
      return (
        <section className="mx-auto max-w-2xl py-4">
          <h2 className="mb-4 text-2xl font-black">{S(b, "heading") || "FAQ"}</h2>
          <div className="divide-y rounded-2xl border bg-card">
            {L(b, "items").map((row, i) => {
              const [q, a] = row.split("|").map((x) => x.trim());
              const open = openI === i;
              return (
                <div key={i}>
                  <button onClick={() => setOpenI(open ? null : i)} className="flex w-full items-center justify-between gap-3 p-4 text-left text-sm font-bold">
                    {q}<ChevronDown className={"h-4 w-4 shrink-0 text-muted-foreground transition-transform " + (open ? "rotate-180" : "")} />
                  </button>
                  {open && <p className="px-4 pb-4 text-sm text-muted-foreground">{a}</p>}
                </div>
              );
            })}
          </div>
        </section>
      );
    }
    case "global-section": {
      const inner = b.props.section as Block | undefined;
      return inner ? <BlockView b={inner} slug={slug} th={th} /> : null;
    }
    case "lead-form": return <section className="py-6"><LeadForm b={b} slug={slug} th={th} /></section>;
    case "cta-band":
      return (
        <section className="flex flex-wrap items-center justify-between gap-4 rounded-3xl bg-primary px-8 py-10 text-primary-foreground" style={th?.primary ? { background: th.primary } : undefined}>
          <div><h2 className="text-2xl font-black text-white">{S(b, "heading")}</h2>{S(b, "sub") && <p className="mt-1 max-w-md text-sm text-white/80">{S(b, "sub")}</p>}</div>
          <span className="flex gap-3">
            {S(b, "primary_label") && <Button asChild data-wx-cta="cta-band-primary" className="bg-white text-primary hover:bg-white/90"><Link to={S(b, "primary_href") || "/contact"}>{S(b, "primary_label")}</Link></Button>}
            {S(b, "secondary_label") && <Button asChild data-wx-cta="cta-band-secondary" variant="outline" className="border-white/40 bg-transparent text-white hover:bg-white/10"><Link to={S(b, "secondary_href") || "/shop"}>{S(b, "secondary_label")}</Link></Button>}
          </span>
        </section>
      );
    default: return null;
  }
}

export default function PublishedPage() {
  const { slug } = useParams();
  const [params] = useSearchParams();
  const draft = params.get("draft") === "1";
  const [doc, setDoc] = useState<PageDoc | null>(null);
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => {
    setDoc(null); setErr(null);
    document.documentElement.classList.remove("wx-pagebar");
    void (async () => {
      try {
        const res = await fetch("/api/pages/" + encodeURIComponent(slug ?? "") + "/public" + (draft ? "?draft=1" : ""));
        if (!res.ok) { setErr((await res.json().catch(() => ({}))).error ?? "Page not found"); return; }
        const j = (await res.json()) as PageDoc;
        setDoc(j);
        document.documentElement.classList.toggle("wx-pagebar", !!(j.theme as { announce?: unknown } | null | undefined)?.announce);
        document.title = j.seoTitle || j.title + " — Woodex Furniture";
        let meta = document.querySelector<HTMLMetaElement>('meta[name="description"]');
        if (!meta) { meta = document.createElement("meta"); meta.name = "description"; document.head.appendChild(meta); }
        if (j.seoDesc) meta.content = j.seoDesc;
        /* P9: Open Graph + Twitter + canonical for shareable landings */
        const setM = (attr: "property" | "name", k: string, v: string) => {
          let m = document.querySelector<HTMLMetaElement>(`meta[${attr}="${k}"]`);
          if (!m) { m = document.createElement("meta"); m.setAttribute(attr, k); document.head.appendChild(m); }
          m.content = v;
        };
        const hero = j.blocks.find((x) => x.type === "hero" || (x.props.section as Block | undefined)?.type === "hero");
        const rawImg = hero ? String(hero.props.image ?? (hero.props.section as Block | undefined)?.props.image ?? "") : "";
        const ogImg = rawImg ? (rawImg.startsWith("http") || rawImg.startsWith("/img/") ? rawImg : "/img/" + rawImg) : "";
        const url = location.origin + "/p/" + j.slug;
        setM("property", "og:title", j.seoTitle || j.title);
        setM("property", "og:description", j.seoDesc || "");
        setM("property", "og:type", "website");
        setM("property", "og:url", url);
        if (ogImg) setM("property", "og:image", ogImg);
        setM("name", "twitter:card", ogImg ? "summary_large_image" : "summary");
        let can = document.querySelector<HTMLLinkElement>('link[rel="canonical"]');
        if (!can) { can = document.createElement("link"); can.rel = "canonical"; document.head.appendChild(can); }
        can.href = url;
      } catch { setErr("Tracking service unreachable — try again"); }
    })();
  }, [slug, draft]);

  useEffect(() => {
    if (!doc) return;
    trackPage(doc.slug);
    return attachTrackers(doc.slug);
  }, [doc]);

  return (
    <div className="min-h-screen bg-background" style={doc?.theme ? { background: doc.theme.bg, color: doc.theme.ink } : undefined}>
      {doc?.theme?.announce && (
        <div className="px-4 py-1.5 text-center text-[12px] font-bold tracking-wide text-white" style={{ background: doc.theme.primary ?? "#166534" }}>
          {doc.theme.announce}
        </div>
      )}
      {doc?.theme?.font === "serif" && <style>{".wx-themed h1,.wx-themed h2,.wx-themed h3{font-family:Georgia,'Times New Roman',serif}"}</style>}
      <Header />
      <main className="wx-themed mx-auto max-w-5xl px-4 py-10 sm:px-6">
        {err && (
          <div className="mx-auto max-w-md rounded-2xl border bg-card p-10 text-center">
            <p className="text-lg font-black">Nothing published here</p>
            <p className="mt-2 text-sm text-muted-foreground">{err}{!draft && doc === null && " — this page may still be a draft."}</p>
            <Button asChild className="mt-5 bg-primary text-primary-foreground hover:bg-primary/90"><Link to="/shop">Browse the catalog</Link></Button>
          </div>
        )}
        {!err && !doc && <div className="animate-pulse space-y-4">{[...Array(4)].map((_, i) => <div key={i} className="h-32 rounded-2xl bg-muted" />)}</div>}
        {doc && (
          <>
            {draft && doc.status !== "Published" && (
              <p className="mb-6 rounded-xl border border-dashed border-amber-400 bg-amber-50 px-4 py-2.5 text-center text-xs font-bold text-amber-800">
                Draft preview — visitors see only published blocks. Publish id {doc.slug} from the dashboard studio.
              </p>
            )}
            <div className="space-y-10">
              {doc.blocks.map((b, i) => <BlockView key={i} b={b} slug={doc.slug} th={doc.theme ?? undefined} />)}
            </div>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 text-[12px]">
              <span className="mr-1 font-black uppercase tracking-wider text-muted-foreground">Share this</span>
              <a data-wx-cta="share:whatsapp" href={"https://wa.me/?text=" + encodeURIComponent(doc.title + " " + location.href)} target="_blank" rel="noreferrer"
                className="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 font-bold transition-colors hover:bg-accent hover:text-white">
                <MessageCircle className="h-3.5 w-3.5" /> WhatsApp
              </a>
              <a data-wx-cta="share:facebook" href={"https://www.facebook.com/sharer/sharer.php?u=" + encodeURIComponent(location.href)} target="_blank" rel="noreferrer"
                className="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 font-bold transition-colors hover:bg-accent hover:text-white">
                Facebook
              </a>
              <button type="button" data-wx-cta="share:copy" onClick={() => { void navigator.clipboard?.writeText(location.href).then(() => toast("Link copied — paste anywhere.")); }}
                className="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 font-bold transition-colors hover:bg-accent hover:text-white">
                <Link2 className="h-3.5 w-3.5" /> Copy link
              </button>
            </div>
            {doc.publishedAt && <p className="mt-10 text-center text-[11px] text-muted-foreground">Published {new Date(doc.publishedAt).toLocaleDateString("en-PK", { day: "numeric", month: "long", year: "numeric" })} · prices in PKR, live from our catalog</p>}
          </>
        )}
      </main>
      <Footer />
    </div>
  );
}
