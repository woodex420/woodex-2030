import { Link, useParams } from "react-router";
import { PageHeader } from "@/components/layout/PageHeader";
import { Avatar } from "@/components/ui/Avatar";
import { Badge } from "@/components/ui/Badge";
import { buttonCls } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { useApi, fmtPKR, img, type ApiProduct } from "@/lib/api";
import { Check, ChevronLeft, Eye, Package, Pencil, ShoppingBag } from "@/icons";

export default function ProductDetail() {
  const { id = "" } = useParams();
  const product = useApi<ApiProduct>(`/api/products/${encodeURIComponent(id)}`, 30000);

  if (product.loading && !product.data) {
    return <div><PageHeader title="Loading product…" /><Skeleton className="h-[520px]" /></div>;
  }
  if (product.error || !product.data) {
    return (
      <div>
        <PageHeader crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Products", to: "/catalog" }, { label: "Product details" }]} title="Product unavailable" />
        <ErrorState title="Product could not be loaded" description="It may have been removed from the catalog or the API is unavailable." debug={product.error ?? `GET /api/products/${id}`} onRetry={() => void product.reload()} />
      </div>
    );
  }

  const item = product.data;
  const photoList = item.images ?? [];
  const savings = item.originalPrice && item.originalPrice > item.price ? Math.round((1 - item.price / item.originalPrice) * 100) : 0;

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Products", to: "/catalog" }, { label: item.name }]}
        title={item.name}
        description={`${item.category}${item.subcategory ? ` · ${item.subcategory}` : ""} · ${item.id}`}
        actions={
          <>
            <Link to="/catalog" className={buttonCls("secondary", "md")}><ChevronLeft size={14} /> Products</Link>
            <Link to={`/catalog/${encodeURIComponent(item.id)}/edit`} className={buttonCls("primary", "md")}><Pencil size={14} /> Edit product</Link>
          </>
        }
      />

      <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(340px,0.8fr)]">
        <Card padded={false} className="overflow-hidden">
          <div className="grid gap-1 bg-slate-100 sm:grid-cols-2">
            {(photoList.length ? photoList : [""]).slice(0, 4).map((file, index) => (
              <div key={`${file}-${index}`} className="aspect-[4/3] overflow-hidden bg-slate-100">
                {file ? <img src={img(file)} alt={`${item.name} — image ${index + 1}`} className="h-full w-full object-cover" /> : <div className="grid h-full place-items-center text-small text-subtle"><Package size={24} /></div>}
              </div>
            ))}
          </div>
          <div className="p-5 lg:p-6">
            <CardHeader title="Product description" description="This content is used by the shared API and storefront catalog." />
            <p className="whitespace-pre-line text-small leading-relaxed text-muted">{item.description || item.shortDescription || "No description has been added."}</p>
          </div>
        </Card>

        <div className="space-y-4">
          <Card>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-caption font-semibold tracking-wide text-subtle uppercase">Selling price</p>
                <p className="mt-1 text-h1 text-ink">{fmtPKR(item.price)}</p>
                {item.originalPrice && item.originalPrice > item.price && <p className="mt-1 text-small text-muted"><span className="line-through">{fmtPKR(item.originalPrice)}</span><span className="ml-2 text-danger-strong">Save {savings}%</span></p>}
              </div>
              <Badge tone={item.inStock ? "success" : "muted"}>{item.inStock ? "In stock" : "Made to order"}</Badge>
            </div>
            <div className="mt-5 grid grid-cols-2 gap-3 border-t border-line pt-4">
              <div><p className="text-caption text-subtle">Available quantity</p><p className="mt-0.5 text-small font-semibold text-ink">{item.inStock ? `${item.stockQty ?? "—"} units` : "Not stocked"}</p></div>
              <div><p className="text-caption text-subtle">Rating</p><p className="mt-0.5 text-small font-semibold text-ink">{item.rating ? `${item.rating.toFixed(1)} / 5` : "Not rated"} <span className="font-normal text-muted">({item.reviews} reviews)</span></p></div>
              <div><p className="text-caption text-subtle">Series</p><p className="mt-0.5 text-small font-semibold text-ink">{item.series || "—"}</p></div>
              <div><p className="text-caption text-subtle">Storefront flags</p><div className="mt-1 flex flex-wrap gap-1.5">{item.isNew && <Badge tone="info">New</Badge>}{item.isBestSeller && <Badge tone="success">Bestseller</Badge>}{!item.isNew && !item.isBestSeller && <span className="text-caption text-muted">Standard listing</span>}</div></div>
            </div>
          </Card>

          <Card>
            <CardHeader title="Colors & finishes" description="Available product options." />
            {item.colors?.length ? <div className="flex flex-wrap gap-2">{item.colors.map((color) => <span key={`${color.name}-${color.hex}`} className="inline-flex items-center gap-2 rounded-full border border-line bg-white px-2.5 py-1.5 text-caption text-slate-700"><span className="h-4 w-4 rounded-full border border-slate-300" style={{ backgroundColor: color.hex }} />{color.name}</span>)}</div> : <p className="text-small text-muted">No color options recorded.</p>}
          </Card>

          <Card padded={false} className="overflow-hidden">
            <div className="p-5 pb-0"><CardHeader title="Specifications" /></div>
            {item.specifications?.length ? <dl className="divide-y divide-slate-100">{item.specifications.map((spec, index) => <div key={`${spec.label}-${index}`} className="grid grid-cols-[minmax(110px,0.7fr)_1fr] gap-3 px-5 py-2.5 text-small"><dt className="text-muted">{spec.label}</dt><dd className="text-right font-medium text-ink">{spec.value}</dd></div>)}</dl> : <div className="px-5 pb-5"><EmptyState icon={<Package size={18} />} title="No specifications" description="Add dimensions and materials in the product editor." className="py-6" /></div>}
          </Card>

          <Card>
            <CardHeader title="Features" />
            {item.features?.length ? <ul className="space-y-2">{item.features.map((feature) => <li key={feature} className="flex items-start gap-2 text-small text-slate-700"><span className="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full bg-success-soft text-success-strong"><Check size={11} /></span>{feature}</li>)}</ul> : <p className="text-small text-muted">No product features recorded.</p>}
          </Card>
        </div>
      </div>

      <div className="mt-4 grid gap-3 sm:grid-cols-3">
        <Card className="flex items-center gap-3 p-4"><span className="grid h-9 w-9 place-items-center rounded-control bg-primary-50 text-primary-700"><ShoppingBag size={16} /></span><span><span className="block text-caption text-muted">Product ID</span><span className="block font-mono text-small font-semibold text-ink">{item.id}</span></span></Card>
        <Card className="flex items-center gap-3 p-4"><span className="grid h-9 w-9 place-items-center rounded-control bg-info-soft text-info-strong"><Eye size={16} /></span><span><span className="block text-caption text-muted">Catalog category</span><span className="block text-small font-semibold text-ink">{item.category}{item.subcategory ? ` / ${item.subcategory}` : ""}</span></span></Card>
        <Card className="flex items-center gap-3 p-4"><Avatar initials="WX" size="sm" tone="primary" /><span><span className="block text-caption text-muted">Live sync</span><span className="block text-small font-semibold text-ink">Changes publish to the storefront catalog</span></span></Card>
      </div>
    </div>
  );
}
