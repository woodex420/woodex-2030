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
import { img, mutate, useApi, type ApiProduct } from "@/lib/api";
import { ChevronLeft, Eye, Save, Trash2 } from "@/icons";

type ProductDraft = {
  id: string;
  name: string;
  category: string;
  subcategory: string;
  series: string;
  price: string;
  originalPrice: string;
  description: string;
  shortDescription: string;
  images: string;
  colors: string;
  specifications: string;
  features: string;
  inStock: boolean;
  stockQty: string;
  isNew: boolean;
  isBestSeller: boolean;
  rating: string;
  reviews: string;
};

const blankDraft = (): ProductDraft => ({
  id: "", name: "", category: "", subcategory: "", series: "", price: "", originalPrice: "",
  description: "", shortDescription: "", images: "product-storage.jpg", colors: "Natural Oak | #c4a35a",
  specifications: "Material | Wood veneer", features: "Hand-finished", inStock: true, stockQty: "10",
  isNew: false, isBestSeller: false, rating: "0", reviews: "0",
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
    category: product.category,
    subcategory: product.subcategory ?? "",
    series: product.series ?? "",
    price: String(product.price),
    originalPrice: product.originalPrice ? String(product.originalPrice) : "",
    description: product.description ?? "",
    shortDescription: product.shortDescription ?? "",
    images: (product.images ?? []).join("\n"),
    colors: (product.colors ?? []).map((color) => `${color.name} | ${color.hex}`).join("\n"),
    specifications: (product.specifications ?? []).map((spec) => `${spec.label} | ${spec.value}`).join("\n"),
    features: (product.features ?? []).join("\n"),
    inStock: product.inStock,
    stockQty: String(product.stockQty ?? (product.inStock ? 99 : 0)),
    isNew: !!product.isNew,
    isBestSeller: !!product.isBestSeller,
    rating: String(product.rating ?? 0),
    reviews: String(product.reviews ?? 0),
  };
}

export default function ProductEditor() {
  const { id } = useParams();
  const isNew = !id;
  const navigate = useNavigate();
  const { push } = useToast();
  const product = useApi<ApiProduct>(id ? `/api/products/${encodeURIComponent(id)}` : null);
  const catalog = useApi<{ items: ApiProduct[] }>("/api/products");
  const media = useApi<{ items: string[] }>("/api/media");
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
      price: Number(draft.price),
      originalPrice: draft.originalPrice ? Number(draft.originalPrice) : null,
      description: draft.description.trim(),
      shortDescription: draft.shortDescription.trim() || draft.description.trim().slice(0, 160),
      images: splitValues(draft.images),
      colors: parsePairs(draft.colors).map(({ left, right }) => ({ name: left, hex: right })),
      specifications: parsePairs(draft.specifications).map(({ left, right }) => ({ label: left, value: right })),
      features: splitValues(draft.features),
      inStock: draft.inStock,
      stockQty: Number(draft.stockQty),
      isNew: draft.isNew,
      isBestSeller: draft.isBestSeller,
      rating: Number(draft.rating),
      reviews: Number(draft.reviews),
    };
    try {
      const saved = await mutate<ApiProduct>(isNew ? "/api/products" : `/api/products/${encodeURIComponent(id!)}`, body, isNew ? "POST" : "PATCH");
      push({ tone: "success", title: isNew ? "Product created" : "Product updated", desc: `${saved.name} is now in the shared catalog.` });
      navigate(`/catalog/${encodeURIComponent(saved.id)}`);
    } catch (error) {
      push({ tone: "danger", title: "Could not save product", desc: error instanceof Error ? error.message : String(error) });
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    if (!id) return;
    setDeleting(true);
    try {
      await mutate(`/api/products/${encodeURIComponent(id)}`, undefined, "DELETE");
      push({ tone: "success", title: "Product removed", desc: "It will disappear from the live storefront catalog." });
      navigate("/catalog");
    } catch (error) {
      push({ tone: "danger", title: "Could not remove product", desc: error instanceof Error ? error.message : String(error) });
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

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Products", to: "/catalog" }, { label: isNew ? "New product" : "Edit product" }]}
        title={isNew ? "Create product" : `Edit ${product.data?.name ?? "product"}`}
        description="Maintain one shared catalog record for the dashboard and storefront."
        actions={<Button type="button" variant="secondary" onClick={() => navigate(isNew ? "/catalog" : `/catalog/${encodeURIComponent(id!)}`)}><ChevronLeft size={14} /> Cancel</Button>}
      />

      <form onSubmit={(event) => void save(event)} className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div className="space-y-4">
          <Card>
            <CardHeader title="Product identity" description="A stable product slug is used by the storefront URL and order snapshots." />
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Product name" required className="sm:col-span-2"><Input autoFocus required maxLength={140} value={draft.name} onChange={(event) => update("name", event.target.value)} placeholder="e.g. Oakline executive desk" /></Field>
              <Field label="Product ID / slug" required hint={isNew ? "Lowercase letters, numbers and dashes; 2–80 characters." : "Product IDs stay fixed after creation so existing links remain stable."}><Input required readOnly={!isNew} pattern="[a-z0-9][a-z0-9-]{0,78}[a-z0-9]" maxLength={80} value={draft.id} onChange={(event) => { setIdTouched(true); update("id", event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, "")); }} placeholder="oakline-executive-desk" /></Field>
              <Field label="Category" required hint="Use a current category ID or add one; category administration is not modeled yet."><Input required list="product-categories" value={draft.category} onChange={(event) => update("category", event.target.value)} placeholder="office-tables" /><datalist id="product-categories">{categories.map((value) => <option key={value} value={value} />)}</datalist></Field>
              <Field label="Subcategory" hint="Optional; use a stable slug such as executive-tables."><Input list="product-subcategories" value={draft.subcategory} onChange={(event) => update("subcategory", event.target.value)} placeholder="executive-tables" /><datalist id="product-subcategories">{[...new Set((catalog.data?.items ?? []).map((item) => item.subcategory ?? "").filter(Boolean))].map((value) => <option key={value} value={value} />)}</datalist></Field>
              <Field label="Series" hint="Optional. Existing series IDs are suggested."><Input list="product-series" value={draft.series} onChange={(event) => update("series", event.target.value)} placeholder="woodex-series" /><datalist id="product-series">{series.map((value) => <option key={value} value={value} />)}</datalist></Field>
              <Field label="Short description" hint="Shown on product cards; max 240 characters."><Input maxLength={240} value={draft.shortDescription} onChange={(event) => update("shortDescription", event.target.value)} placeholder="A concise product summary" /></Field>
              <Field label="Full description" className="sm:col-span-2"><Textarea maxLength={5000} className="min-h-28" value={draft.description} onChange={(event) => update("description", event.target.value)} placeholder="Materials, dimensions, construction and use…" /></Field>
            </div>
          </Card>

          <Card>
            <CardHeader title="Pricing & stock" description="These values are read by the catalog and used for live storefront stock indicators." />
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Current price (PKR)" required><Input required type="number" min="1" step="1" value={draft.price} onChange={(event) => update("price", event.target.value)} placeholder="85000" /></Field>
              <Field label="Original / list price (PKR)" hint="Optional; shown as a sale price when greater than current price."><Input type="number" min="1" step="1" value={draft.originalPrice} onChange={(event) => update("originalPrice", event.target.value)} placeholder="Leave blank if not discounted" /></Field>
              <Field label="Stock quantity" required><Input required type="number" min="0" step="1" value={draft.stockQty} onChange={(event) => update("stockQty", event.target.value)} /></Field>
              <Field label="Storefront rating" hint="Use 0 until review data is available."><Input type="number" min="0" max="5" step="0.1" value={draft.rating} onChange={(event) => update("rating", event.target.value)} /></Field>
              <Field label="Review count"><Input type="number" min="0" step="1" value={draft.reviews} onChange={(event) => update("reviews", event.target.value)} /></Field>
              <div className="flex flex-wrap items-end gap-4 pb-1">
                <label className="flex items-center gap-2 text-small font-medium text-slate-700"><input type="checkbox" checked={draft.inStock} onChange={(event) => { const checked = event.target.checked; setDraft((current) => ({ ...current, inStock: checked, stockQty: checked && current.stockQty === "0" ? "10" : !checked ? "0" : current.stockQty })); }} className="h-4 w-4 accent-[#4F9D21]" /> In stock</label>
                <label className="flex items-center gap-2 text-small font-medium text-slate-700"><input type="checkbox" checked={draft.isNew} onChange={(event) => update("isNew", event.target.checked)} className="h-4 w-4 accent-[#4F9D21]" /> New arrival</label>
                <label className="flex items-center gap-2 text-small font-medium text-slate-700"><input type="checkbox" checked={draft.isBestSeller} onChange={(event) => update("isBestSeller", event.target.checked)} className="h-4 w-4 accent-[#4F9D21]" /> Bestseller</label>
              </div>
            </div>
          </Card>

          <Card>
            <CardHeader title="Images, colors & specifications" description="Use image filenames from the media library. One image, color, feature or specification per line." />
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Product images" required hint="At least one file name from the library or secure https image URL, one per line."><Textarea required className="min-h-24 font-mono text-caption" value={draft.images} onChange={(event) => update("images", event.target.value)} placeholder="product-storage.jpg" /></Field>
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
              <div className="flex flex-wrap gap-2"><Badge tone={draft.inStock ? "success" : "muted"}>{draft.inStock ? "In stock" : "Made to order"}</Badge>{draft.isNew && <Badge tone="info">New</Badge>}{draft.isBestSeller && <Badge tone="warning">Bestseller</Badge>}</div>
              <div><p className="text-caption text-subtle">{draft.category || "Category"}{draft.subcategory ? ` · ${draft.subcategory}` : ""}</p><h2 className="mt-1 text-h3">{draft.name || "Product name"}</h2><p className="mt-1 line-clamp-3 text-small text-muted">{draft.shortDescription || "Short product description will appear here."}</p></div>
              <p className="text-h2 text-ink">{draft.price ? `PKR ${Number(draft.price).toLocaleString("en-PK")}` : "Set a price"}</p>
              {draft.images && <p className="flex items-center gap-1.5 truncate text-caption text-subtle"><Eye size={13} /> {imageList.length} image{imageList.length === 1 ? "" : "s"}</p>}
              <Button type="submit" loading={saving} className="w-full"><Save size={14} /> {isNew ? "Create product" : "Save product"}</Button>
              {!isNew && <Button type="button" variant="danger" className="w-full" onClick={() => setDeleteOpen(true)}><Trash2 size={14} /> Delete product</Button>}
              <p className="text-caption leading-relaxed text-subtle">Saving updates the shared API. The storefront refreshes its catalog through the products event and periodic sync.</p>
            </div>
          </Card>
          {!isNew && product.data?.updatedAt && <p className="px-1 text-caption text-subtle">Last updated {new Date(product.data.updatedAt).toLocaleString("en-PK")}</p>}
        </aside>
      </form>

      <Modal
        open={deleteOpen}
        onClose={() => { if (!deleting) setDeleteOpen(false); }}
        title="Delete this product?"
        description="This removes it from the shared catalog and storefront. Historical order line items remain as snapshots."
        footer={<><Button type="button" variant="secondary" disabled={deleting} onClick={() => setDeleteOpen(false)}>Keep product</Button><Button type="button" variant="danger" loading={deleting} onClick={() => void remove()}>Delete product</Button></>}
      >
        <p className="text-small text-muted"><strong className="text-ink">{product.data?.name}</strong> ({product.data?.id}) will no longer appear in product search or the storefront. This cannot be undone.</p>
      </Modal>
    </div>
  );
}
