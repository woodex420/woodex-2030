import { useState } from "react";
import { Link, useParams } from "react-router";
import { PageHeader } from "@/components/layout/PageHeader";
import { Badge } from "@/components/ui/Badge";
import { Button, buttonCls } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import {
  useApi, mutate, fmtPKR, img, priceLabel, stockLabel, PRODUCT_STATUS_LABEL, productStatusTone,
  type ApiProduct, type ProductStatus,
} from "@/lib/api";
import { can } from "@/lib/auth";
import { AlertTriangle, Check, ChevronLeft, Eye, Package, Pencil, ShoppingBag } from "@/icons";

const STATUS_NOTE: Record<ProductStatus, string> = {
  draft: "Not on sale. Customers cannot see or order it until it is published.",
  published: "On sale. Customers can see it in the shop.",
  archived: "Withdrawn. It is kept for old orders and can be published again.",
};

export default function ProductDetail() {
  const { id = "" } = useParams();
  const product = useApi<ApiProduct>(`/api/products/${encodeURIComponent(id)}`, 30000);
  const { push } = useToast();
  const [busy, setBusy] = useState(false);
  const mayManage = can("catalog.manage");

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
  const discounted = !item.priceOnRequest && !!item.originalPrice && item.originalPrice > item.price;
  const savings = discounted ? Math.round((1 - item.price / (item.originalPrice as number)) * 100) : 0;
  const notes = item.review ?? [];
  const options = item.variants ?? [];

  const setStatus = async (next: ProductStatus) => {
    setBusy(true);
    try {
      const updated = await mutate<ApiProduct>(`/api/products/${encodeURIComponent(item.id)}`, { status: next });
      product.setData(updated);
      push({ tone: "success", title: `${updated.name} is now ${PRODUCT_STATUS_LABEL[updated.status].toLowerCase()}`, desc: STATUS_NOTE[updated.status] });
    } catch (error) {
      push({ tone: "danger", title: "The state was not changed", desc: error instanceof Error ? error.message : String(error) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Products", to: "/catalog" }, { label: item.name }]}
        title={item.name}
        description={`${item.category}${item.subcategory ? ` · ${item.subcategory}` : ""} · ${item.sku ?? "no SKU"}`}
        actions={
          <>
            <Link to="/catalog" className={buttonCls("secondary", "md")}><ChevronLeft size={14} /> Products</Link>
            <Link to={`/catalog/${encodeURIComponent(item.id)}/edit`} className={buttonCls(item.status === "published" ? "primary" : "secondary", "md")}><Pencil size={14} /> Edit product</Link>
            {mayManage && item.status !== "published" && <Button loading={busy} onClick={() => void setStatus("published")}><Check size={14} /> Publish</Button>}
          </>
        }
      />

      <Card className="mb-4 flex flex-wrap items-center gap-3 p-4" padded={false}>
        <Badge tone={productStatusTone(item.status)}>{PRODUCT_STATUS_LABEL[item.status]}</Badge>
        <p className="min-w-0 flex-1 text-small text-muted">{STATUS_NOTE[item.status]}</p>
        {mayManage && item.status === "published" && <Button size="sm" variant="secondary" disabled={busy} onClick={() => void setStatus("draft")}>Back to draft</Button>}
        {mayManage && item.status !== "archived" && <Button size="sm" variant="secondary" disabled={busy} onClick={() => void setStatus("archived")}>Archive</Button>}
      </Card>

      {notes.length > 0 && (
        <Card className="mb-4 border-warning/40">
          <CardHeader
            title={<span className="inline-flex items-center gap-2"><AlertTriangle size={16} className="text-warning-strong" /> {notes.length} point{notes.length === 1 ? "" : "s"} to check before publishing</span>}
            description="Written by the catalogue import. Customers never see these. Clear each one in the editor once it is settled."
            action={<Link to={`/catalog/${encodeURIComponent(item.id)}/edit`} className={buttonCls("secondary", "sm")}>Open the editor</Link>}
          />
          <ul className="space-y-2">
            {notes.map((note, index) => (
              <li key={`${note.code}-${index}`} className="flex items-start gap-2 text-small text-slate-700">
                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-warning" />
                <span>{note.text}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}

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
            <CardHeader title="Product description" description="Shown on the product page of the shop." />
            <p className="whitespace-pre-line text-small leading-relaxed text-muted">{item.description || item.shortDescription || "No description has been written yet."}</p>
          </div>
        </Card>

        <div className="space-y-4">
          <Card>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-caption font-semibold tracking-wide text-subtle uppercase">Selling price</p>
                <p className={item.priceOnRequest ? "mt-1 text-h2 text-ink" : "mt-1 text-h1 text-ink"}>{priceLabel(item)}</p>
                {item.priceOnRequest && <p className="mt-1 text-small text-muted">Customers can ask for a quotation. It cannot be ordered until it has a price.</p>}
                {discounted && <p className="mt-1 text-small text-muted"><span className="line-through">{fmtPKR(item.originalPrice as number)}</span><span className="ml-2 text-danger-strong">Save {savings}%</span></p>}
              </div>
              <Badge tone={item.inStock ? "success" : "muted"}>{item.inStock ? (item.trackStock ? "In stock" : "Made to order") : "Not available"}</Badge>
            </div>
            <div className="mt-5 grid grid-cols-2 gap-3 border-t border-line pt-4">
              <div><p className="text-caption text-subtle">SKU</p><p className="mt-0.5 font-mono text-small font-semibold text-ink">{item.sku ?? (options.length ? "Sold by option" : "None yet")}</p></div>
              <div><p className="text-caption text-subtle">Stock</p><p className="mt-0.5 text-small font-semibold text-ink">{stockLabel(item)}</p></div>
              <div><p className="text-caption text-subtle">Series</p><p className="mt-0.5 text-small font-semibold text-ink">{item.series || "—"}</p></div>
              <div><p className="text-caption text-subtle">Storefront flags</p><div className="mt-1 flex flex-wrap gap-1.5">{item.isNew && <Badge tone="info">New</Badge>}{item.isBestSeller && <Badge tone="success">Bestseller</Badge>}{!item.isNew && !item.isBestSeller && <span className="text-caption text-muted">Standard listing</span>}</div></div>
            </div>
          </Card>

          <Card padded={false} className="overflow-hidden">
            <div className="p-5 pb-0"><CardHeader title="Options" description="Sizes, finishes or materials sold under their own SKU and price." /></div>
            {options.length ? (
              <table className="w-full text-small">
                <thead><tr className="border-y border-slate-100 text-left text-caption text-subtle"><th className="px-5 py-2 font-medium">Option</th><th className="px-2 py-2 font-medium">SKU</th><th className="px-5 py-2 text-right font-medium">Price</th></tr></thead>
                <tbody className="divide-y divide-slate-100">
                  {options.map((option) => (
                    <tr key={option.id} className={option.active ? "" : "text-subtle"}>
                      <td className="px-5 py-2.5">{option.label}{!option.active && <span className="ml-2 text-caption">(withdrawn)</span>}</td>
                      <td className="px-2 py-2.5 font-mono text-caption">{option.sku}</td>
                      <td className="px-5 py-2.5 text-right font-medium">{priceLabel(option)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : <p className="px-5 pb-5 text-small text-muted">Sold as one product, with no options.</p>}
          </Card>

          <Card>
            <CardHeader title="Colors & finishes" />
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

          {item.source?.site && (
            <Card>
              <CardHeader title="Where this product came from" description={`Imported from ${item.source.site}${item.source.capturedOn ? `, as it stood on ${item.source.capturedOn}` : ""}. Kept for the record; editing the product does not change it.`} />
              <dl className="grid grid-cols-[minmax(110px,0.7fr)_1fr] gap-x-3 gap-y-2 text-small">
                <dt className="text-muted">Address there</dt><dd className="text-right font-mono text-caption text-ink">/shop/{item.source.address}</dd>
                <dt className="text-muted">Name there</dt><dd className="text-right text-ink">{item.source.name ?? "—"}</dd>
                <dt className="text-muted">SKU as printed</dt><dd className="text-right font-mono text-caption text-ink">{item.source.sku || "none"}</dd>
                <dt className="text-muted">Price there</dt><dd className="text-right text-ink">{typeof item.source.price === "number" ? fmtPKR(item.source.price) : "—"}{item.source.compareAt ? ` (was ${fmtPKR(item.source.compareAt)})` : ""}</dd>
              </dl>
            </Card>
          )}
        </div>
      </div>

      <div className="mt-4 grid gap-3 sm:grid-cols-3">
        <Card className="flex items-center gap-3 p-4"><span className="grid h-9 w-9 place-items-center rounded-control bg-primary-50 text-primary-700"><ShoppingBag size={16} /></span><span><span className="block text-caption text-muted">Shop address</span><span className="block font-mono text-small font-semibold text-ink">/shop/{item.id}</span></span></Card>
        <Card className="flex items-center gap-3 p-4"><span className="grid h-9 w-9 place-items-center rounded-control bg-info-soft text-info-strong"><Eye size={16} /></span><span><span className="block text-caption text-muted">Catalog category</span><span className="block text-small font-semibold text-ink">{item.category}{item.subcategory ? ` / ${item.subcategory}` : ""}</span></span></Card>
        <Card className="flex items-center gap-3 p-4"><span className="grid h-9 w-9 place-items-center rounded-control bg-slate-100 text-slate-600"><Package size={16} /></span><span><span className="block text-caption text-muted">Last changed</span><span className="block text-small font-semibold text-ink">{item.updatedAt ? new Date(item.updatedAt).toLocaleString("en-PK") : "—"}</span></span></Card>
      </div>
    </div>
  );
}
