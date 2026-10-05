import { useMemo, useState } from "react";
import { Link } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, Skeleton } from "@/components/ui/States";
import { ErrorState } from "@/components/ui/States";
import { Select, SearchInput, Field, Input } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, img, fmtPKR, type ApiProduct, type ApiMaterial } from "@/lib/api";
import { ArrowRight, Check, Package, Settings2 } from "@/icons";

type SortKey = "featured" | "price-asc" | "price-desc" | "rating";
const star = (n: number) => "★".repeat(Math.round(n)) + "☆".repeat(5 - Math.round(n));

export default function Catalog() {
  const [query, setQuery] = useState("");
  const [sub, setSub] = useState("all");
  const [material, setMaterial] = useState("all");
  const [sort, setSort] = useState<SortKey>("featured");
  const [inStockOnly, setInStockOnly] = useState(false);
  const [manage, setManage] = useState<ApiProduct | null>(null);
  const [priceDraft, setPriceDraft] = useState("");
  const [stockDraft, setStockDraft] = useState(true);
  const [saving, setSaving] = useState(false);
  const { push } = useToast();

  // Live data from the shared backend (same SQLite both apps read/write)
  const products = useApi<{ total: number; items: ApiProduct[] }>("/api/products", 30000);
  const mats = useApi<ApiMaterial[]>("/api/materials");

  const list = products.data?.items ?? [];

  const subcategories = useMemo(() => {
    const set = new Map<string, string>();
    list.forEach((p) => p.subcategory && set.set(p.subcategory, p.subcategory.replace(/-/g, " ")));
    return Array.from(set.entries()).sort((a, b) => a[1].localeCompare(b[1]));
  }, [list]);

  const filtered = useMemo(() => {
    let l = list.filter(
      (p) =>
        (sub === "all" || p.subcategory === sub) &&
        (material === "all" || p.colors?.some((c) => c.name.toLowerCase().includes(material.toLowerCase()))) &&
        (!inStockOnly || p.inStock) &&
        (query.trim() === "" ||
          (p.name + " " + p.shortDescription + " " + (p.series ?? "")).toLowerCase().includes(query.toLowerCase()))
    );
    if (sort === "price-asc") l = [...l].sort((a, b) => a.price - b.price);
    if (sort === "price-desc") l = [...l].sort((a, b) => b.price - a.price);
    if (sort === "rating") l = [...l].sort((a, b) => b.rating - a.rating);
    return l;
  }, [list, sub, material, query, sort, inStockOnly]);

  const stats = useMemo(() => {
    const inStock = list.filter((p) => p.inStock).length;
    const avg = list.length ? Math.round(list.reduce((n, p) => n + p.price, 0) / list.length) : 0;
    return { total: list.length, inStock, avg };
  }, [list]);

  const openManage = (p: ApiProduct) => {
    setManage(p);
    setPriceDraft(String(p.price));
    setStockDraft(p.inStock);
  };

  const saveManage = async () => {
    if (!manage) return;
    setSaving(true);
    try {
      const updated = await mutate<ApiProduct>("/api/products/" + manage.id, {
        price: Number(priceDraft) || undefined,
        inStock: stockDraft,
        stockQty: stockDraft ? 99 : 0,
      });
      products.setData((d) => (d ? { ...d, total: d.total, items: d.items.map((p) => (p.id === updated.id ? updated : p)) } : d));
      push({ tone: "success", title: "Product updated", desc: `${updated.name} — the storefront reflects this at runtime.` });
      setManage(null);
    } catch (e) {
      push({ tone: "danger", title: "Update failed", desc: e instanceof Error ? e.message : String(e) });
    } finally {
      setSaving(false);
    }
  };

  if (products.error)
    return (
      <div>
        <PageHeader crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Catalog" }]} title="Furniture Catalog" />
        <ErrorState
          title="Catalog API unreachable"
          description="The dashboard streams products from the shared backend (apps/api on :3001 via proxy). Start it with: npm run dev:api"
          debug={products.error}
          onRetry={products.reload}
        />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Catalog" }]}
        title="Furniture Catalog"
        description={
          products.loading
            ? "Loading live catalog from the shared backend…"
            : `${products.data?.total ?? 0} SKUs · live from API · edits sync to the storefront at runtime`
        }
        actions={
          <Link to="/quotations/new" className="inline-flex">
            <Button>Build quotation from catalog</Button>
          </Link>
        }
      />

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 lg:gap-4">
        {[
          { l: "Products", v: String(stats.total || "—") },
          { l: "In stock", v: stats.total ? `${stats.inStock} (${Math.round((stats.inStock / stats.total) * 100)}%)` : "—" },
          { l: "Subcategories", v: String(subcategories.length || "—") },
          { l: "Avg. price", v: stats.avg ? fmtPKR(stats.avg) : "—" },
        ].map((s) => (
          <Card key={s.l} className="py-3.5">
            <p className="text-caption font-medium text-subtle uppercase">{s.l}</p>
            <p className="mt-0.5 text-h3 text-ink">{s.v}</p>
          </Card>
        ))}
      </div>

      <Card className="mb-4 flex flex-wrap items-center gap-3 p-3.5" padded={false}>
        <SearchInput className="w-full sm:max-w-60" value={query} onChange={setQuery} placeholder={products.loading ? "Loading…" : `Search ${products.data?.total ?? ""} products…`} />
        <Select className="h-9 w-52 text-caption" value={sub} onChange={(e) => setSub(e.target.value)} aria-label="Filter by subcategory">
          <option value="all">All subcategories</option>
          {subcategories.map(([id, label]) => (
            <option key={id} value={id} className="capitalize">
              {label}
            </option>
          ))}
        </Select>
        <Select className="h-9 w-48 text-caption" value={material} onChange={(e) => setMaterial(e.target.value)} aria-label="Filter by material finish">
          <option value="all">Any material</option>
          {(mats.data ?? []).map((m) => (
            <option key={m.id} value={m.name.split(" ")[0]}>
              {m.name}
            </option>
          ))}
        </Select>
        <Select className="h-9 w-36 text-caption" value={sort} onChange={(e) => setSort(e.target.value as SortKey)} aria-label="Sort products">
          <option value="featured">Featured</option>
          <option value="price-asc">Price ↑</option>
          <option value="price-desc">Price ↓</option>
          <option value="rating">Top rated</option>
        </Select>
        <label className="flex cursor-pointer items-center gap-2 text-caption font-medium text-slate-700">
          <input type="checkbox" checked={inStockOnly} onChange={(e) => setInStockOnly(e.target.checked)} className="h-4 w-4 accent-[#4F9D21]" />
          In stock only
        </label>
        <span className="ml-auto hidden items-center gap-1.5 text-caption text-subtle md:flex">
          <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-success" /> live · 30s sync
        </span>
      </Card>

      {products.loading && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-80 rounded-card" />
          ))}
        </div>
      )}

      {!products.loading && filtered.length === 0 && (
        <Card>
          <EmptyState
            icon={<Package size={22} />}
            title="No products match these filters"
            description="Try clearing the subcategory or material filter."
            action={
              <Button variant="secondary" onClick={() => { setSub("all"); setMaterial("all"); setQuery(""); setInStockOnly(false); }}>
                Clear filters
              </Button>
            }
          />
        </Card>
      )}

      {!products.loading && filtered.length > 0 && (
        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {filtered.slice(0, 48).map((p: ApiProduct) => (
            <li key={p.id} className="group flex flex-col overflow-hidden rounded-card border border-line bg-surface shadow-card transition-all duration-150 hover:-translate-y-0.5 hover:shadow-pop">
              <div className="relative aspect-[4/3] overflow-hidden bg-slate-100">
                <img src={img(p.images[0])} alt={p.name} loading="lazy" className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.03]" />
                <div className="absolute top-2.5 right-2.5 flex flex-col items-end gap-1">
                  {p.isNew && <Badge tone="info" className="bg-white/90 backdrop-blur-sm">New</Badge>}
                  {p.isBestSeller && <Badge tone="success" className="bg-white/90 backdrop-blur-sm">Bestseller</Badge>}
                  {p.originalPrice && p.originalPrice > p.price && (
                    <Badge tone="danger" dot={false} className="bg-white/90 backdrop-blur-sm">-{Math.round((1 - p.price / p.originalPrice) * 100)}%</Badge>
                  )}
                </div>
                <span className="absolute bottom-2.5 left-2.5 rounded bg-charcoal-950/70 px-1.5 py-0.5 font-mono text-[10px] capitalize text-white/90 backdrop-blur-sm">
                  {p.subcategory?.replace(/-/g, " ") ?? p.category}
                </span>
                <button
                  onClick={() => openManage(p)}
                  title="Manage price & stock"
                  aria-label={`Manage ${p.name}`}
                  className="absolute top-2 left-2 grid h-7 w-7 place-items-center rounded-control bg-white/85 text-slate-600 opacity-0 shadow-card backdrop-blur-sm transition-opacity duration-150 hover:text-ink group-hover:opacity-100"
                >
                  <Settings2 size={14} />
                </button>
              </div>
              <div className="flex flex-1 flex-col p-4">
                <h3 className="line-clamp-1 text-bodylg font-semibold text-ink" title={p.name}>{p.name}</h3>
                <p className="mt-1 line-clamp-2 flex-1 text-caption leading-relaxed text-muted">{p.shortDescription}</p>
                <div className="mt-2 flex items-center justify-between">
                  <span className="text-caption" aria-label={`Rated ${p.rating} out of 5`}>
                    <span className="text-warning">{star(p.rating)}</span>
                    <span className="text-subtle"> ({p.reviews})</span>
                  </span>
                  <span className="flex items-center gap-1">
                    {(p.colors ?? []).slice(0, 4).map((c) => (
                      <span key={c.name} title={c.name} className="h-3.5 w-3.5 rounded-full ring-1 ring-slate-300" style={{ background: c.hex }} />
                    ))}
                  </span>
                </div>
                <div className="mt-3 flex items-end justify-between border-t border-slate-100 pt-3">
                  <div>
                    <p className="text-h3 leading-none tracking-tight text-ink">{fmtPKR(p.price)}</p>
                    {p.originalPrice && p.originalPrice > p.price && (
                      <p className="mt-0.5 text-caption text-subtle line-through">{fmtPKR(p.originalPrice)}</p>
                    )}
                  </div>
                  <span className={cn("text-caption font-medium", p.inStock ? "text-success-strong" : "text-danger-strong")}>
                    {p.inStock ? (<span className="inline-flex items-center gap-1"><Check size={12} /> {p.stockQty ?? "In"} in stock</span>) : "Made to order"}
                  </span>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      {!products.loading && filtered.length > 48 && (
        <p className="mt-4 flex items-center justify-center gap-2 text-caption text-muted">
          Showing first 48 of {filtered.length} matches <ArrowRight size={13} />
        </p>
      )}

      {/* Manage price / stock — writes to the shared DB, storefront sees it live */}
      <Modal
        open={!!manage}
        onClose={() => setManage(null)}
        title={"Manage — " + (manage?.name ?? "")}
        description="Changes persist in the shared backend and appear on the storefront at runtime."
        footer={
          <>
            <Button variant="secondary" onClick={() => setManage(null)}>Cancel</Button>
            <Button onClick={saveManage} disabled={saving}>{saving ? "Saving…" : "Save changes"}</Button>
          </>
        }
      >
        {manage && (
          <div className="space-y-4">
            <Field label="Price (PKR)" hint="Storefront product cards, search and quote lines update automatically.">
              <Input type="number" value={priceDraft} onChange={(e) => setPriceDraft(e.target.value)} />
            </Field>
            <label className="flex items-center justify-between rounded-card border border-line p-3.5">
              <span>
                <span className="block text-small font-semibold text-ink">In stock</span>
                <span className="block text-caption text-muted">Made-to-order hides the stock quantity.</span>
              </span>
              <input type="checkbox" checked={stockDraft} onChange={(e) => setStockDraft(e.target.checked)} className="h-5 w-5 accent-[#4F9D21]" />
            </label>
          </div>
        )}
      </Modal>
    </div>
  );
}
