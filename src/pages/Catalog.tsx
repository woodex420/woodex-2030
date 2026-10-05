import { useMemo, useState } from "react";
import { Link } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState } from "@/components/ui/States";
import { Select, SearchInput } from "@/components/ui/Field";
import { useToast } from "@/components/ui/Toast";
import { products, formatPKR } from "@/data/products";
import type { Product } from "@/data/products";
import { materials } from "@/data/materials";
import { ArrowRight, Check, Package } from "@/icons";

type SortKey = "featured" | "price-asc" | "price-desc" | "rating";

const star = (n: number) => "★".repeat(Math.round(n)) + "☆".repeat(5 - Math.round(n));

export default function Catalog() {
  const [query, setQuery] = useState("");
  const [sub, setSub] = useState("all");
  const [material, setMaterial] = useState("all");
  const [sort, setSort] = useState<SortKey>("featured");
  const [inStockOnly, setInStockOnly] = useState(false);
  const { push } = useToast();

  const subcategories = useMemo(() => {
    const set = new Map<string, string>();
    products.forEach((p) => p.subcategory && set.set(p.subcategory, p.subcategory.replace(/-/g, " ")));
    return Array.from(set.entries()).sort((a, b) => a[1].localeCompare(b[1]));
  }, []);

  const filtered = useMemo(() => {
    let list = products.filter(
      (p) =>
        (sub === "all" || p.subcategory === sub) &&
        (material === "all" ||
          p.colors.some((c) => c.name.toLowerCase().includes(material.split(":")[1]?.toLowerCase() ?? ""))) &&
        (!inStockOnly || p.inStock) &&
        (query.trim() === "" ||
          (p.name + " " + p.shortDescription + " " + p.series).toLowerCase().includes(query.toLowerCase()))
    );
    if (sort === "price-asc") list = [...list].sort((a, b) => a.price - b.price);
    if (sort === "price-desc") list = [...list].sort((a, b) => b.price - a.price);
    if (sort === "rating") list = [...list].sort((a, b) => b.rating - a.rating);
    return list;
  }, [sub, material, query, sort, inStockOnly]);

  const stats = useMemo(() => {
    const inStock = products.filter((p) => p.inStock).length;
    const avg = Math.round(products.reduce((n, p) => n + p.price, 0) / products.length);
    return { total: products.length, inStock, avg };
  }, []);

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Catalog" }]}
        title="Furniture Catalog"
        description="Live catalog imported from woodex-reimagined — 205 SKUs with images, materials, colours and PKR pricing."
        actions={
          <>
            <Button variant="secondary" onClick={() => push({ tone: "info", title: "CSV export queued", desc: "catalog-" + products.length + "-skus.xlsx" })}>
              Export
            </Button>
            <Link to="/quotations/new" className="inline-flex">
              <Button>Build quotation from catalog</Button>
            </Link>
          </>
        }
      />

      {/* Live data strip */}
      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 lg:gap-4">
        {[
          { l: "Products", v: stats.total.toString() },
          { l: "In stock", v: `${stats.inStock} (${Math.round((stats.inStock / stats.total) * 100)}%)` },
          { l: "Categories", v: (subcategories.length + "").toString() },
          { l: "Avg. price", v: formatPKR(stats.avg) },
        ].map((s) => (
          <Card key={s.l} className="py-3.5">
            <p className="text-caption font-medium text-subtle uppercase">{s.l}</p>
            <p className="mt-0.5 text-h3 text-ink">{s.v}</p>
          </Card>
        ))}
      </div>

      {/* Filters */}
      <Card className="mb-4 flex flex-wrap items-center gap-3 p-3.5" padded={false}>
        <SearchInput className="w-full sm:max-w-60" value={query} onChange={setQuery} placeholder="Search 205 products…" />
        <Select
          className="h-9 w-52 text-caption"
          value={sub}
          onChange={(e) => setSub(e.target.value)}
          aria-label="Filter by subcategory"
        >
          <option value="all">All subcategories</option>
          {subcategories.map(([id, label]) => (
            <option key={id} value={id} className="capitalize">
              {label}
            </option>
          ))}
        </Select>
        <Select
          className="h-9 w-48 text-caption"
          value={material}
          onChange={(e) => setMaterial(e.target.value)}
          aria-label="Filter by finish colour name"
        >
          <option value="all">Any material</option>
          {materials.map((m) => (
            <option key={m.id} value={`mat:${m.name}`}>
              {m.name} ({m.category})
            </option>
          ))}
        </Select>
        <Select
          className="h-9 w-36 text-caption"
          value={sort}
          onChange={(e) => setSort(e.target.value as SortKey)}
          aria-label="Sort products"
        >
          <option value="featured">Featured</option>
          <option value="price-asc">Price ↑</option>
          <option value="price-desc">Price ↓</option>
          <option value="rating">Top rated</option>
        </Select>
        <label className="flex cursor-pointer items-center gap-2 text-caption font-medium text-slate-700">
          <input
            type="checkbox"
            checked={inStockOnly}
            onChange={(e) => setInStockOnly(e.target.checked)}
            className="h-4 w-4 accent-[#4F9D21]"
          />
          In stock only
        </label>
        <span className="ml-auto hidden text-caption text-subtle md:block">
          {filtered.length} of {products.length} shown
        </span>
      </Card>

      {filtered.length === 0 ? (
        <Card>
          <EmptyState
            icon={<Package size={22} />}
            title="No products match these filters"
            description="Try clearing the subcategory or material filter — the catalog holds 205 SKUs across office and home furniture."
            action={
              <Button
                variant="secondary"
                onClick={() => {
                  setSub("all");
                  setMaterial("all");
                  setQuery("");
                  setInStockOnly(false);
                }}
              >
                Clear filters
              </Button>
            }
          />
        </Card>
      ) : (
        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {filtered.slice(0, 48).map((p: Product) => (
            <li
              key={p.id}
              className="group flex flex-col overflow-hidden rounded-card border border-line bg-surface shadow-card transition-all duration-150 hover:-translate-y-0.5 hover:shadow-pop"
            >
              <div className="relative aspect-[4/3] overflow-hidden bg-slate-100">
                <img
                  src={p.images[0]}
                  alt={p.name}
                  loading="lazy"
                  className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
                />
                <div className="absolute top-2.5 right-2.5 flex flex-col items-end gap-1">
                  {p.isNew && <Badge tone="info" className="bg-white/90 backdrop-blur-sm">New</Badge>}
                  {p.isBestSeller && <Badge tone="success" className="bg-white/90 backdrop-blur-sm">Bestseller</Badge>}
                  {p.originalPrice && p.originalPrice > p.price && (
                    <Badge tone="danger" dot={false} className="bg-white/90 backdrop-blur-sm">
                      -{Math.round((1 - p.price / p.originalPrice) * 100)}%
                    </Badge>
                  )}
                </div>
                <span className="absolute bottom-2.5 left-2.5 rounded bg-charcoal-950/70 px-1.5 py-0.5 font-mono text-[10px] text-white/90 backdrop-blur-sm">
                  {p.subcategory ?? p.category}
                </span>
              </div>
              <div className="flex flex-1 flex-col p-4">
                <h3 className="line-clamp-1 text-bodylg font-semibold text-ink" title={p.name}>{p.name}</h3>
                <p className="mt-1 line-clamp-2 flex-1 text-caption leading-relaxed text-muted">{p.shortDescription}</p>
                <div className="mt-2 flex items-center justify-between">
                  <span className="flex items-center gap-1 text-caption" aria-label={`Rated ${p.rating} out of 5`}>
                    <span className="text-warning">{star(p.rating)}</span>
                    <span className="text-subtle">({p.reviews})</span>
                  </span>
                  <span className="flex items-center gap-1">
                    {p.colors.slice(0, 4).map((c) => (
                      <span
                        key={c.name}
                        title={c.name}
                        className="h-3.5 w-3.5 rounded-full ring-1 ring-slate-300"
                        style={{ background: c.hex }}
                      />
                    ))}
                  </span>
                </div>
                <div className="mt-3 flex items-end justify-between border-t border-slate-100 pt-3">
                  <div>
                    <p className="text-h3 leading-none tracking-tight text-ink">{formatPKR(p.price)}</p>
                    {p.originalPrice && p.originalPrice > p.price && (
                      <p className="mt-0.5 text-caption text-subtle line-through">{formatPKR(p.originalPrice)}</p>
                    )}
                  </div>
                  <span className={cn("text-caption font-medium", p.inStock ? "text-success-strong" : "text-danger-strong")}>
                    {p.inStock ? (
                      <span className="inline-flex items-center gap-1"><Check size={12} /> In stock</span>
                    ) : (
                      "Made to order"
                    )}
                  </span>
                </div>
                <div className="mt-3 flex gap-1.5">
                  <Button
                    size="sm"
                    className="flex-1"
                    onClick={() =>
                      push({
                        tone: "success",
                        title: "Added to quotation",
                        desc: `${p.name} · ${formatPKR(p.price)} — open the E-Quotation editor to send for approval.`,
                      })
                    }
                  >
                    Add to Quote
                  </Button>
                  <Button
                    variant="secondary"
                    size="sm"
                    aria-label={`Specs of ${p.name}`}
                    onClick={() => push({ tone: "info", title: p.name, desc: p.specifications.map((s) => `${s.label}: ${s.value}`).join(" · ") })}
                  >
                    Specs
                  </Button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      {filtered.length > 48 && (
        <p className="mt-4 flex items-center justify-center gap-2 text-caption text-muted">
          Showing first 48 of {filtered.length} matches — export for the full list
          <ArrowRight size={13} />
        </p>
      )}
    </div>
  );
}
