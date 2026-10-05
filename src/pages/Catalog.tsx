import { useMemo, useState } from "react";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState } from "@/components/ui/States";
import { SearchInput, Select } from "@/components/ui/Field";
import { useToast } from "@/components/ui/Toast";
import { products } from "@/data/mock";
import { ArrowRight, Eye, Package, Plus } from "@/icons";

const categories = ["All", "Sofas", "Beds", "Wardrobes", "Kitchens", "Dining", "Office"] as const;
const materials = ["All materials", "Solid Sheesham", "MDF + Ply Core", "HMR Board", "Ply + Acrylic", "Solid Walnut", "Laminate + Steel", "Beech Frame", "Ply + HMR"];
const finishes = ["All finishes", "Matte Walnut", "Pearl White", "Smoked Oak", "Sage Green", "Natural Oil", "Charcoal Ash", "Caramel Leather", "Arctic Birch"];

export default function Catalog() {
  const [cat, setCat] = useState<(typeof categories)[number]>("All");
  const [material, setMaterial] = useState(materials[0]);
  const [finish, setFinish] = useState(finishes[0]);
  const [query, setQuery] = useState("");
  const { push } = useToast();

  const filtered = useMemo(
    () =>
      products.filter(
        (p) =>
          (cat === "All" || p.category === cat) &&
          (material === materials[0] || p.material === material) &&
          (finish === finishes[0] || p.finish === finish) &&
          (query.trim() === "" || (p.name + " " + p.category).toLowerCase().includes(query.toLowerCase()))
      ),
    [cat, material, finish, query]
  );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Catalog" }]}
        title="Furniture Catalog"
        description="Products, materials and finishes shared across quotations and the storefront."
        actions={
          <>
            <Button variant="secondary" onClick={() => push({ tone: "info", title: "CSV export queued", desc: "catalog-export.xlsx" })}>
              Export
            </Button>
            <Button onClick={() => push({ tone: "primary", title: "Product creation form (Phase 4)" })}>
              <Plus size={15} /> New Product
            </Button>
          </>
        }
      />

      {/* Filters — Woodex furniture attributes */}
      <Card className="mb-4 flex flex-wrap items-center gap-3 p-3.5" padded={false}>
        <SearchInput className="w-full sm:max-w-56" value={query} onChange={setQuery} placeholder="Search products…" />
        <div className="scrollbar-slim flex flex-1 gap-1 overflow-x-auto">
          {categories.map((c) => (
            <button
              key={c}
              onClick={() => setCat(c)}
              className={cn(
                "h-7 shrink-0 rounded-full border px-3 text-caption font-medium transition-colors duration-150",
                cat === c
                  ? "border-primary-500 bg-primary-500 text-white"
                  : "border-line-strong bg-white text-slate-600 hover:border-slate-400"
              )}
            >
              {c}
            </button>
          ))}
        </div>
        <div className="flex gap-2">
          <Select className="h-8 w-40 text-caption" value={material} onChange={(e) => setMaterial(e.target.value)} aria-label="Filter by material">
            {materials.map((m) => (
              <option key={m}>{m}</option>
            ))}
          </Select>
          <Select className="h-8 w-40 text-caption" value={finish} onChange={(e) => setFinish(e.target.value)} aria-label="Filter by finish">
            {finishes.map((f) => (
              <option key={f}>{f}</option>
            ))}
          </Select>
        </div>
      </Card>

      {filtered.length === 0 ? (
        <Card>
          <EmptyState
            icon={<Package size={22} />}
            title="No products match these filters"
            description="Adjust category, material or finish filters — or add a new product to the catalog."
            action={<Button variant="secondary" onClick={() => { setCat("All"); setMaterial(materials[0]); setFinish(finishes[0]); setQuery(""); }}>Clear filters</Button>}
          />
        </Card>
      ) : (
        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {filtered.map((p) => (
            <li
              key={p.id}
              className="group overflow-hidden rounded-card border border-line bg-surface shadow-card transition-all duration-150 hover:-translate-y-0.5 hover:shadow-pop"
            >
              {/* Product visual placeholder — furniture-toned gradient */}
              <div
                className="relative flex h-40 items-end p-3"
                style={{
                  backgroundImage: `linear-gradient(140deg, hsl(${p.hue} 34% 93%) 0%, hsl(${p.hue} 30% 78%) 58%, hsl(${p.hue} 26% 62%) 100%)`,
                }}
                role="img"
                aria-label={`${p.name} preview`}
              >
                <span className="absolute inset-0 opacity-[0.14]" aria-hidden style={{ backgroundImage: "repeating-linear-gradient(90deg, rgba(23,27,30,.5) 0 1px, transparent 1px 22px)" }} />
                <span className="absolute top-3 right-3">
                  {p.badge && (
                    <Badge tone={p.badge === "New" ? "info" : p.badge === "Bestseller" ? "success" : "warning"} className="bg-white/90 backdrop-blur-sm">
                      {p.badge}
                    </Badge>
                  )}
                </span>
                <span className="rounded bg-white/85 px-1.5 py-0.5 font-mono text-[10px] font-medium text-slate-600 backdrop-blur-sm">
                  {p.id}
                </span>
              </div>
              <div className="p-4">
                <p className="text-caption font-medium tracking-wide text-subtle uppercase">{p.category}</p>
                <h3 className="mt-1 line-clamp-1 text-bodylg font-semibold text-ink">{p.name}</h3>
                <p className="mt-1 line-clamp-1 text-caption text-muted">
                  {p.material} · {p.finish}
                </p>
                <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
                  <p className="text-h3 tracking-tight text-ink">Rs {p.price.toLocaleString()}</p>
                  <div className="flex items-center gap-1">
                    <Button
                      variant="ghost"
                      size="sm"
                      aria-label={`Preview ${p.name}`}
                      onClick={() => push({ tone: "info", title: p.name, desc: "3D configurator preview lands with the Business Pack." })}
                    >
                      <Eye size={15} />
                    </Button>
                    <Button
                      size="sm"
                      onClick={() => push({ tone: "success", title: "Added to quotation Q-2295", desc: `${p.name} · Rs ${p.price.toLocaleString()}` })}
                    >
                      Add to Quote
                    </Button>
                  </div>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-line bg-white px-5 py-4 shadow-card">
        <p className="text-small text-muted">
          <strong className="font-semibold text-ink">{filtered.length} products</strong> visible · catalog syncs to website &amp; marketplace channels
        </p>
        <a href="#/ecommerce" className="inline-flex items-center gap-1.5 text-small font-semibold text-primary-600 hover:text-primary-700">
          Open commerce channels <ArrowRight size={14} />
        </a>
      </div>
    </div>
  );
}
