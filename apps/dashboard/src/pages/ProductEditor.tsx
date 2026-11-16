import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import { useNavigate, useParams } from "react-router";
import { PageHeader } from "@/components/layout/PageHeader";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { ErrorState, Skeleton } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import {
  img, mutate, useApi, useProducts, priceLabel, PRODUCT_STATUS_LABEL, productStatusTone,
  type ApiProduct, type ApiReviewNote, type ApiVariant, type ProductStatus,
} from "@/lib/api";
import { AlertTriangle, Check, ChevronLeft, Eye, Plus, Save, Trash2 } from "@/icons";

type ProductDraft = {
  id: string;
  name: string;
  sku: string;
  status: ProductStatus;
  category: string;
  subcategory: string;
  series: string;
  price: string;
  priceOnRequest: boolean;
  originalPrice: string;
  description: string;
  shortDescription: string;
  images: string;
  colors: string;
  specifications: string;
  features: string;
  inStock: boolean;
  trackStock: boolean;
  stockQty: string;
  isNew: boolean;
  isBestSeller: boolean;
  review: ApiReviewNote[];
};

/* A new product starts empty. The form used to open with a colour, a material
   and a feature already typed in — "Natural Oak", "Wood veneer",
   "Hand-finished" — and a stock of 10. Each was one click from being published
   as a fact about a product nobody had described yet. Examples belong in the
   placeholders, where they cannot be saved. */
const blankDraft = (): ProductDraft => ({
  id: "", name: "", sku: "", status: "draft", category: "", subcategory: "", series: "",
  price: "", priceOnRequest: false, originalPrice: "",
  description: "", shortDescription: "", images: "", colors: "", specifications: "", features: "",
  inStock: true, trackStock: false, stockQty: "0",
  isNew: false, isBestSeller: false, review: [],
});

const slugify = (value: string) => value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 80);
const splitValues = (value: string) => value.split(/[\n,]/).map((part) => part.trim()).filter(Boolean);
const parsePairs = (value: string) => value.split("\n").map((line) => {
  const [left, ...right] = line.split("|");
  return { left: left?.trim() ?? "", right: right.join("|").trim() };
}).filter((pair) => pair.left && pair.right);

function fromProduct(product: ApiProduct): ProductDraft {
  return {
    id: product.id,
    name: product.name,
    sku: product.sku ?? "",
    status: product.status ?? "published",
    category: product.category,
    subcategory: product.subcategory ?? "",
    series: product.series ?? "",
    price: product.priceOnRequest ? "" : String(product.price),
    priceOnRequest: !!product.priceOnRequest,
    originalPrice: product.originalPrice ? String(product.originalPrice) : "",
    description: product.description ?? "",
    shortDescription: product.shortDescription ?? "",
    images: (product.images ?? []).join("\n"),
    colors: (product.colors ?? []).map((color) => `${color.name} | ${color.hex}`).join("\n"),
    specifications: (product.specifications ?? []).map((spec) => `${spec.label} | ${spec.value}`).join("\n"),
    features: (product.features ?? []).join("\n"),
    inStock: product.inStock,
    trackStock: product.trackStock !== false,
    stockQty: String(product.stockQty ?? 0),
    isNew: !!product.isNew,
    isBestSeller: !!product.isBestSeller,
    review: product.review ?? [],
  };
}

const checkboxCls = "h-4 w-4 accent-[#4F9D21]";
const errorText = (error: unknown) => (error instanceof Error ? error.message : String(error));

export default function ProductEditor() {
  const { id } = useParams();
  const isNew = !id;
  const navigate = useNavigate();
  const { push } = useToast();
  const product = useApi<ApiProduct>(id ? `/api/products/${encodeURIComponent(id)}` : null);
  const catalog = useProducts();
  const media = useApi<{ items: string[] }>("/api/media");
  /* Whether this database has catalogue migration 019. Without it a product
     can only be what it was before: published, priced, stock counted. */
  const schema = useApi<{ schemaReady: boolean }>("/api/catalog/status");
  const modern = schema.data?.schemaReady !== false;
  const [draft, setDraft] = useState<ProductDraft>(blankDraft);
  const [idTouched, setIdTouched] = useState(false);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  useEffect(() => {
    if (isNew) {
      setDraft(blankDraft());
      setIdTouched(false);
    } else if (product.data) {
      setDraft(fromProduct(product.data));
      setIdTouched(true);
    }
  }, [isNew, product.data]);

  useEffect(() => {
    if (isNew && !idTouched) setDraft((current) => ({ ...current, id: slugify(current.name) }));
  }, [isNew, idTouched, draft.name]);

  /* A database without 019 cannot store a draft or a made-to-order product. */
  useEffect(() => {
    if (isNew && schema.data && !schema.data.schemaReady)
      setDraft((current) => ({ ...current, status: "published", trackStock: true, priceOnRequest: false, sku: "" }));
  }, [isNew, schema.data]);

  const categories = useMemo(() => [...new Set((catalog.data?.items ?? []).map((item) => item.category).filter(Boolean))].sort(), [catalog.data]);
  const series = useMemo(() => [...new Set((catalog.data?.items ?? []).map((item) => item.series ?? "").filter(Boolean))].sort(), [catalog.data]);
  const imageList = useMemo(() => splitValues(draft.images), [draft.images]);

  const update = <K extends keyof ProductDraft>(key: K, value: ProductDraft[K]) => setDraft((current) => ({ ...current, [key]: value }));

  const appendImage = (value: string) => {
    if (!value || imageList.includes(value)) return;
    update("images", [...imageList, value].join("\n"));
  };

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSaving(true);
    const body = {
      id: draft.id.trim().toLowerCase(),
      name: draft.name.trim(),
      category: draft.category.trim(),
      subcategory: draft.subcategory.trim() || undefined,
      series: draft.series.trim() || undefined,
      ...(draft.priceOnRequest ? { priceOnRequest: true } : { price: Number(draft.price), priceOnRequest: false }),
      originalPrice: !draft.priceOnRequest && draft.originalPrice ? Number(draft.originalPrice) : null,
      description: draft.description.trim(),
      shortDescription: draft.shortDescription.trim() || draft.description.trim().slice(0, 160),
      images: splitValues(draft.images),
      colors: parsePairs(draft.colors).map(({ left, right }) => ({ name: left, hex: right })),
      specifications: parsePairs(draft.specifications).map(({ left, right }) => ({ label: left, value: right })),
      features: splitValues(draft.features),
      inStock: draft.inStock,
      trackStock: draft.trackStock,
      /* A quantity is sent only where one is counted. Leaving it out is what
         keeps a made-to-order product from acquiring a number. */
      ...(draft.trackStock ? { stockQty: Number(draft.stockQty) } : {}),
      isNew: draft.isNew,
      isBestSeller: draft.isBestSeller,
      sku: draft.sku.trim(),
      status: draft.status,
      review: draft.review,
    };
    try {
      const saved = await mutate<ApiProduct>(isNew ? "/api/products" : `/api/products/${encodeURIComponent(id!)}`, body, isNew ? "POST" : "PATCH");
      push({
        tone: "success",
        title: isNew ? "Product created" : "Product saved",
        desc: saved.status === "published" ? `${saved.name} is on sale.` : `${saved.name} is saved as ${PRODUCT_STATUS_LABEL[saved.status].toLowerCase()}. Customers do not see it.`,
      });
      navigate(`/catalog/${encodeURIComponent(saved.id)}`);
    } catch (error) {
      push({ tone: "danger", title: "Could not save product", desc: errorText(error) });
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    if (!id) return;
    setDeleting(true);
    try {
      await mutate(`/api/products/${encodeURIComponent(id)}`, undefined, "DELETE");
      push({ tone: "success", title: "Product deleted", desc: "It is gone from the catalogue and the shop." });
      navigate("/catalog");
    } catch (error) {
      push({ tone: "danger", title: "Could not delete product", desc: errorText(error) });
    } finally {
      setDeleting(false);
      setDeleteOpen(false);
    }
  };

  if (!isNew && product.loading && !product.data) {
    return <div><PageHeader title="Loading product…" /><Skeleton className="h-[520px]" /></div>;
  }
  if (!isNew && product.error) {
    return <div><PageHeader crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Products", to: "/catalog" }, { label: "Edit" }]} title="Product unavailable" /><ErrorState title="Could not load this product" description="The product may have been removed or the catalog API is unavailable." debug={product.error} onRetry={() => void product.reload()} /></div>;
  }

  const previewImage = imageList[0] ? img(imageList[0]) : "";
  const source = product.data?.source;

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Products", to: "/catalog" }, { label: isNew ? "New product" : "Edit product" }]}
        title={isNew ? "Create product" : `Edit ${product.data?.name ?? "product"}`}
        description="One record for the dashboard and the shop. A draft is not shown to customers."
        actions={<Button type="button" variant="secondary" onClick={() => navigate(isNew ? "/catalog" : `/catalog/${encodeURIComponent(id!)}`)}><ChevronLeft size={14} /> Cancel</Button>}
      />

      {!modern && (
        <Card className="mb-4 flex items-start gap-3 border-warning/40 bg-warning-soft/40 p-4" padded={false}>
          <AlertTriangle size={18} className="mt-0.5 shrink-0 text-warning-strong" />
          <p className="text-small text-slate-700"><strong className="text-ink">This database is waiting for catalogue migration 019.</strong> Until it is applied a product is always published, priced and stock-counted; SKU, draft, price on request, made-to-order and options are switched off here.</p>
        </Card>
      )}

      <form onSubmit={(event) => void save(event)} className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div className="space-y-4">
          {draft.review.length > 0 && (
            <Card className="border-warning/40">
              <CardHeader
                title={<span className="inline-flex items-center gap-2"><AlertTriangle size={16} className="text-warning-strong" /> {draft.review.length} point{draft.review.length === 1 ? "" : "s"} to check</span>}
                description="Written by the catalogue import. Customers never see them. Mark a point as settled once it is; it is removed when you save."
              />
              <ul className="space-y-2">
                {draft.review.map((note, index) => (
                  <li key={`${note.code}-${index}`} className="flex items-start justify-between gap-3 rounded-control border border-line p-3">
                    <span className="text-small text-slate-700">{note.text}</span>
                    <Button type="button" size="sm" variant="secondary" className="shrink-0" onClick={() => update("review", draft.review.filter((_, i) => i !== index))}><Check size={13} /> Settled</Button>
                  </li>
                ))}
              </ul>
              {source?.site && (
                <p className="mt-3 text-caption text-subtle">
                  On {source.site}{source.capturedOn ? ` (${source.capturedOn})` : ""}: “{source.name}”, SKU printed as “{source.sku || "none"}”, price {typeof source.price === "number" ? `PKR ${source.price.toLocaleString("en-PK")}` : "—"}{source.compareAt ? `, was PKR ${source.compareAt.toLocaleString("en-PK")}` : ""}.
                </p>
              )}
            </Card>
          )}

          <Card>
            <CardHeader title="Product identity" description="The address is used in the shop's URL and on order lines. The SKU is the code on quotations, orders and delivery notes." />
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Product name" required className="sm:col-span-2"><Input autoFocus required maxLength={140} value={draft.name} onChange={(event) => update("name", event.target.value)} placeholder="e.g. Oakline executive desk" /></Field>
              <Field label="Address (product ID)" required hint={isNew ? "Lowercase letters, numbers and dashes; 2–80 characters." : "Fixed after creation, so existing links keep working."}><Input required readOnly={!isNew} pattern="[a-z0-9][a-z0-9-]{0,78}[a-z0-9]" maxLength={80} value={draft.id} onChange={(event) => { setIdTouched(true); update("id", event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, "")); }} placeholder="oakline-executive-desk" /></Field>
              <Field label="SKU" hint="Unique across products and options. Needed before publishing, unless the product is sold by options."><Input disabled={!modern} maxLength={40} className="font-mono" value={draft.sku} onChange={(event) => update("sku", event.target.value.toUpperCase())} placeholder="ET-10001" /></Field>
              <Field label="Category" required hint="Use a category that already exists where one fits."><Input required list="product-categories" value={draft.category} onChange={(event) => update("category", event.target.value)} placeholder="office-tables" /><datalist id="product-categories">{categories.map((value) => <option key={value} value={value} />)}</datalist></Field>
              <Field label="Subcategory" hint="Optional; a stable slug such as executive-tables."><Input list="product-subcategories" value={draft.subcategory} onChange={(event) => update("subcategory", event.target.value)} placeholder="executive-tables" /><datalist id="product-subcategories">{[...new Set((catalog.data?.items ?? []).map((item) => item.subcategory ?? "").filter(Boolean))].map((value) => <option key={value} value={value} />)}</datalist></Field>
              <Field label="Series" hint="Optional. Existing series are suggested."><Input list="product-series" value={draft.series} onChange={(event) => update("series", event.target.value)} placeholder="woodex-series" /><datalist id="product-series">{series.map((value) => <option key={value} value={value} />)}</datalist></Field>
              <Field label="Short description" hint="Shown on product cards; max 240 characters."><Input maxLength={240} value={draft.shortDescription} onChange={(event) => update("shortDescription", event.target.value)} placeholder="A concise product summary" /></Field>
              <Field label="Full description" className="sm:col-span-2"><Textarea maxLength={5000} className="min-h-28" value={draft.description} onChange={(event) => update("description", event.target.value)} placeholder="Materials, dimensions, construction and use…" /></Field>
            </div>
          </Card>

          <Card>
            <CardHeader title="Price" description="Whole rupees. A product with no agreed price is marked “price on request”: it can be quoted and cannot be ordered." />
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Price (PKR)" required={!draft.priceOnRequest}><Input required={!draft.priceOnRequest} disabled={draft.priceOnRequest} type="number" min="1" step="1" value={draft.priceOnRequest ? "" : draft.price} onChange={(event) => update("price", event.target.value)} placeholder={draft.priceOnRequest ? "On request" : "85000"} /></Field>
              <Field label="Previous price (PKR)" hint="Optional; shown struck through when it is higher than the price."><Input disabled={draft.priceOnRequest} type="number" min="1" step="1" value={draft.priceOnRequest ? "" : draft.originalPrice} onChange={(event) => update("originalPrice", event.target.value)} placeholder="Leave blank if not reduced" /></Field>
              <label className="flex items-center gap-2 text-small font-medium text-slate-700 sm:col-span-2"><input type="checkbox" disabled={!modern} checked={draft.priceOnRequest} onChange={(event) => update("priceOnRequest", event.target.checked)} className={checkboxCls} /> Price on request</label>
            </div>
          </Card>

          <Card>
            <CardHeader title="Availability & stock" description="Furniture built to order has no stock count. Count stock only for products kept on a shelf." />
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-3">
                <label className="flex items-center gap-2 text-small font-medium text-slate-700"><input type="checkbox" checked={draft.inStock} onChange={(event) => update("inStock", event.target.checked)} className={checkboxCls} /> Available to order</label>
                <label className="flex items-center gap-2 text-small font-medium text-slate-700"><input type="checkbox" disabled={!modern} checked={draft.trackStock} onChange={(event) => update("trackStock", event.target.checked)} className={checkboxCls} /> Count stock for this product</label>
              </div>
              {draft.trackStock
                ? <Field label="Quantity in stock" required hint={isNew ? "Recorded as the opening balance in the stock ledger." : "Changing this records a recount in the stock ledger."}><Input required type="number" min="0" step="1" value={draft.stockQty} onChange={(event) => update("stockQty", event.target.value)} /></Field>
                : <p className="text-small text-muted">Made to order: no quantity is stored or shown, and an order does not reduce any stock.</p>}
              <div className="flex flex-wrap items-end gap-4 pb-1 sm:col-span-2">
                <label className="flex items-center gap-2 text-small font-medium text-slate-700"><input type="checkbox" checked={draft.isNew} onChange={(event) => update("isNew", event.target.checked)} className={checkboxCls} /> New arrival</label>
                <label className="flex items-center gap-2 text-small font-medium text-slate-700"><input type="checkbox" checked={draft.isBestSeller} onChange={(event) => update("isBestSeller", event.target.checked)} className={checkboxCls} /> Bestseller</label>
              </div>
            </div>
          </Card>

          {modern && (isNew
            ? <Card><CardHeader title="Options" description="Sizes, finishes or materials with their own SKU and price can be added after the product is created." /></Card>
            : <VariantsCard productId={id!} />)}

          <Card>
            <CardHeader title="Images, colors & specifications" description="One image, color, feature or specification per line." />
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Product images" required hint="At least one: a file name from the media library, or an https image address."><Textarea required className="min-h-24 font-mono text-caption" value={draft.images} onChange={(event) => update("images", event.target.value)} placeholder="product-storage.jpg" /></Field>
              <Field label="Add a library image" hint="Select a file to append it to the image list."><Select value="" onChange={(event) => appendImage(event.target.value)}><option value="">Choose media…</option>{(media.data?.items ?? []).map((file) => <option key={file} value={file}>{file}</option>)}</Select><p className="mt-1 text-caption text-subtle">{media.error ? "Media library could not be loaded." : `${media.data?.items?.length ?? 0} library images available`}</p></Field>
              <Field label="Colors" hint="Format: color name | #hex"><Textarea className="min-h-24 font-mono text-caption" value={draft.colors} onChange={(event) => update("colors", event.target.value)} placeholder="Natural Oak | #c4a35a" /></Field>
              <Field label="Specifications" hint="Format: label | value"><Textarea className="min-h-24 font-mono text-caption" value={draft.specifications} onChange={(event) => update("specifications", event.target.value)} placeholder={'Dimensions | 72×36×30\nMaterial | Oak veneer'} /></Field>
              <Field label="Features" hint="One feature per line." className="sm:col-span-2"><Textarea className="min-h-24" value={draft.features} onChange={(event) => update("features", event.target.value)} placeholder={'Soft-close drawers\nCable management'} /></Field>
            </div>
          </Card>
        </div>

        <aside className="space-y-4 xl:sticky xl:top-20">
          <Card padded={false} className="overflow-hidden">
            <div className="aspect-[4/3] bg-slate-100">
              {previewImage ? <img src={previewImage} alt="Product preview" className="h-full w-full object-cover" /> : <div className="grid h-full place-items-center text-small text-subtle">Add a product image</div>}
            </div>
            <div className="space-y-3 p-4">
              <div className="flex flex-wrap gap-2"><Badge tone={productStatusTone(draft.status)}>{PRODUCT_STATUS_LABEL[draft.status]}</Badge><Badge tone={draft.inStock ? "success" : "muted"}>{draft.inStock ? (draft.trackStock ? "In stock" : "Made to order") : "Not available"}</Badge>{draft.isNew && <Badge tone="info">New</Badge>}{draft.isBestSeller && <Badge tone="warning">Bestseller</Badge>}</div>
              <div><p className="text-caption text-subtle">{draft.category || "Category"}{draft.subcategory ? ` · ${draft.subcategory}` : ""}</p><h2 className="mt-1 text-h3">{draft.name || "Product name"}</h2><p className="mt-1 line-clamp-3 text-small text-muted">{draft.shortDescription || "The short description appears here."}</p></div>
              <p className="text-h2 text-ink">{draft.priceOnRequest ? "Price on request" : draft.price ? `PKR ${Number(draft.price).toLocaleString("en-PK")}` : "Set a price"}</p>
              {draft.images && <p className="flex items-center gap-1.5 truncate text-caption text-subtle"><Eye size={13} /> {imageList.length} image{imageList.length === 1 ? "" : "s"}</p>}
              <Field label="State" hint={draft.status === "published" ? "On sale: customers see it in the shop." : draft.status === "draft" ? "Not on sale. Publish it when it has been checked." : "Withdrawn from the shop; kept for old orders."}>
                <Select disabled={!modern} value={draft.status} onChange={(event) => update("status", event.target.value as ProductStatus)}>
                  <option value="draft">Draft</option>
                  <option value="published">Published</option>
                  <option value="archived">Archived</option>
                </Select>
              </Field>
              <Button type="submit" loading={saving} className="w-full"><Save size={14} /> {isNew ? "Create product" : "Save product"}</Button>
              {!isNew && <Button type="button" variant="danger" className="w-full" onClick={() => setDeleteOpen(true)}><Trash2 size={14} /> Delete product</Button>}
              <p className="text-caption leading-relaxed text-subtle">The shop reads the catalogue from the same database. A change to a published product shows there within a minute.</p>
            </div>
          </Card>
          {!isNew && product.data?.updatedAt && <p className="px-1 text-caption text-subtle">Last updated {new Date(product.data.updatedAt).toLocaleString("en-PK")}</p>}
        </aside>
      </form>

      <Modal
        open={deleteOpen}
        onClose={() => { if (!deleting) setDeleteOpen(false); }}
        title="Delete this product?"
        description="Deleting is for a product created by mistake. One that has stock history, a bill of materials, job cards or transfers cannot be deleted: archive it instead, and it leaves the shop while old orders keep their lines."
        footer={<><Button type="button" variant="secondary" disabled={deleting} onClick={() => setDeleteOpen(false)}>Keep product</Button><Button type="button" variant="danger" loading={deleting} onClick={() => void remove()}>Delete product</Button></>}
      >
        <p className="text-small text-muted"><strong className="text-ink">{product.data?.name}</strong> ({product.data?.id}) and its options will be removed. This cannot be undone.</p>
      </Modal>
    </div>
  );
}

/* ---- options: a size, a finish, a material, each with its own SKU and price ----
   Saved one by one, straight to the API, and kept apart from the form above:
   adding an option must not throw away what is typed but not yet saved there. */
type VariantDraft = { label: string; sku: string; price: string; priceOnRequest: boolean; active: boolean };
const blankVariant = (): VariantDraft => ({ label: "", sku: "", price: "", priceOnRequest: false, active: true });
const variantBody = (v: VariantDraft) => ({
  label: v.label.trim(),
  sku: v.sku.trim(),
  active: v.active,
  ...(v.priceOnRequest ? { priceOnRequest: true } : { price: Number(v.price), priceOnRequest: false }),
});

function VariantsCard({ productId }: { productId: string }) {
  const { push } = useToast();
  const base = `/api/products/${encodeURIComponent(productId)}/variants`;
  const list = useApi<{ total: number; items: ApiVariant[] }>(base);
  const [adding, setAdding] = useState<VariantDraft>(blankVariant);
  const [edits, setEdits] = useState<Record<number, VariantDraft>>({});
  const [busy, setBusy] = useState<number | "new" | null>(null);

  const editOf = (v: ApiVariant): VariantDraft => edits[v.id] ?? { label: v.label, sku: v.sku, price: v.priceOnRequest ? "" : String(v.price), priceOnRequest: v.priceOnRequest, active: v.active };
  const setEdit = (v: ApiVariant, patch: Partial<VariantDraft>) => setEdits((current) => ({ ...current, [v.id]: { ...editOf(v), ...patch } }));

  const run = async (key: number | "new", action: () => Promise<unknown>, done: string) => {
    setBusy(key);
    try {
      await action();
      push({ tone: "success", title: done });
      await list.reload();
      return true;
    } catch (error) {
      push({ tone: "danger", title: "The option was not saved", desc: errorText(error) });
      return false;
    } finally {
      setBusy(null);
    }
  };

  const add = async () => {
    if (await run("new", () => mutate(base, variantBody(adding), "POST"), "Option added")) setAdding(blankVariant());
  };
  const saveRow = async (v: ApiVariant) => {
    if (await run(v.id, () => mutate(`${base}/${v.id}`, variantBody(editOf(v))), "Option saved"))
      setEdits((current) => { const next = { ...current }; delete next[v.id]; return next; });
  };
  const removeRow = (v: ApiVariant) => void run(v.id, () => mutate(`${base}/${v.id}`, undefined, "DELETE"), "Option removed");

  const items = list.data?.items ?? [];
  const row = (v: VariantDraft, change: (patch: Partial<VariantDraft>) => void, idPrefix: string) => (
    <>
      <Input aria-label="Option label" id={`${idPrefix}-label`} maxLength={120} value={v.label} onChange={(event) => change({ label: event.target.value })} placeholder="1800 mm · Walnut" />
      <Input aria-label="Option SKU" maxLength={40} className="font-mono" value={v.sku} onChange={(event) => change({ sku: event.target.value.toUpperCase() })} placeholder="ET-10001-18" />
      <Input aria-label="Option price in rupees" type="number" min="1" step="1" disabled={v.priceOnRequest} value={v.priceOnRequest ? "" : v.price} onChange={(event) => change({ price: event.target.value })} placeholder={v.priceOnRequest ? "On request" : "Price"} />
      <label className="flex items-center gap-1.5 text-caption text-slate-700"><input type="checkbox" checked={v.priceOnRequest} onChange={(event) => change({ priceOnRequest: event.target.checked })} className={checkboxCls} /> On request</label>
    </>
  );

  return (
    /* This card sits inside the product form. Enter in one of its fields must
       not submit that form and navigate away with the option half typed. */
    <Card>
      <div onKeyDown={(event) => { if (event.key === "Enter" && (event.target as HTMLElement).tagName === "INPUT") event.preventDefault(); }}>
      <CardHeader title="Options" description="Sizes, finishes or materials sold under their own SKU and price. A product that has options is ordered by option." />
      {list.error && <p className="mb-3 text-small text-danger-strong">The options could not be loaded: {list.error}</p>}
      {items.length === 0 && !list.loading && !list.error && <p className="mb-3 text-small text-muted">No options: this product is sold as one item at the price above.</p>}
      <div className="space-y-2">
        {items.map((v) => (
          <div key={v.id} className="grid items-center gap-2 rounded-control border border-line p-2.5 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_110px_auto_auto]">
            {row(editOf(v), (patch) => setEdit(v, patch), `variant-${v.id}`)}
            <span className="flex items-center gap-2">
              <label className="flex items-center gap-1.5 text-caption text-slate-700"><input type="checkbox" checked={editOf(v).active} onChange={(event) => setEdit(v, { active: event.target.checked })} className={checkboxCls} /> On sale</label>
              <Button type="button" size="sm" variant="secondary" loading={busy === v.id} disabled={!edits[v.id]} onClick={() => void saveRow(v)}>Save</Button>
              <Button type="button" size="sm" variant="ghost" disabled={busy === v.id} onClick={() => removeRow(v)} aria-label={`Remove option ${v.label}`}><Trash2 size={13} /></Button>
            </span>
            <span className="text-caption text-subtle sm:col-span-5">Now: {v.label} · {v.sku} · {priceLabel(v)}{v.active ? "" : " · withdrawn"}</span>
          </div>
        ))}
      </div>
      <div className="mt-3 grid items-center gap-2 rounded-control border border-dashed border-line-strong p-2.5 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_110px_auto_auto]">
        {row(adding, (patch) => setAdding((current) => ({ ...current, ...patch })), "variant-new")}
        <Button type="button" size="sm" loading={busy === "new"} disabled={!adding.label.trim() || !adding.sku.trim() || (!adding.priceOnRequest && !adding.price)} onClick={() => void add()}><Plus size={13} /> Add option</Button>
      </div>
      </div>
    </Card>
  );
}
