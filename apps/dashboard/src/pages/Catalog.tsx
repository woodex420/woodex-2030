import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button, buttonCls } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, Skeleton } from "@/components/ui/States";
import { ErrorState } from "@/components/ui/States";
import { Select, SearchInput, Field, Input } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import {
  useApi, useProducts, useRealtime, mutate, img, fmtPKR, priceLabel, stockLabel,
  PRODUCT_STATUS_LABEL, productStatusTone,
  type ApiProduct, type ProductStatus,
} from "@/lib/api";
import { can } from "@/lib/auth";
import { AlertTriangle, Check, Download, Package, Plus, Settings2 } from "@/icons";

type SortKey = "featured" | "name" | "price-asc" | "price-desc";
type StatusTab = ProductStatus | "all";
const TABS: StatusTab[] = ["all", "published", "draft", "archived"];
const PAGE = 48;

/* What GET /api/catalog/status answers. Only the fields this screen reads. */
type CatalogStatus = {
  schemaReady: boolean;
  schemaNote?: string;
  demo: { total: number; published: number };
  imported: { total: number; published: number };
  publishedWithoutSku: number;
  dataset: { name: string; products: number } | null;
};

type BulkReply = {
  status: ProductStatus;
  changed: number;
  unchanged: number;
  failed: number;
  results: { id: string; ok: boolean; error?: string; code?: string }[];
};

const isTab = (value: string | null): value is StatusTab => !!value && (TABS as string[]).includes(value);

export default function Catalog() {
  const [params, setParams] = useSearchParams();
  const tab: StatusTab = isTab(params.get("status")) ? (params.get("status") as StatusTab) : "all";
  const toCheckOnly = params.get("check") === "1";

  const [query, setQuery] = useState("");
  const [sub, setSub] = useState("all");
  const [sort, setSort] = useState<SortKey>("featured");
  const [shown, setShown] = useState(PAGE);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [bulkBusy, setBulkBusy] = useState(false);
  const [manage, setManage] = useState<ApiProduct | null>(null);
  const [priceDraft, setPriceDraft] = useState("");
  const [onRequestDraft, setOnRequestDraft] = useState(false);
  const [availableDraft, setAvailableDraft] = useState(true);
  const [saving, setSaving] = useState(false);
  const { push } = useToast();
  const mayManage = can("catalog.manage");

  /* Every state, not only what is on sale: this is where drafts are checked. */
  const products = useProducts(30000);
  /* The state of the catalogue (migration pending, demo still on sale) is for
     the roles that may read it. Every signed-in role can open this list; asking
     for the summary as a viewer, sales or finance was answered 403 on every
     visit, for two notices those roles could not act on anyway. */
  const mayReadStatus = mayManage || can("catalog.view");
  const status = useApi<CatalogStatus>(mayReadStatus ? "/api/catalog/status" : null, 60000);
  useRealtime(/products/, () => { void products.reload(); void status.reload(); });

  const list = useMemo(() => products.data?.items ?? [], [products.data]);
  const counts = useMemo(() => {
    const fromApi = products.data?.counts;
    const count = (s: ProductStatus) => fromApi?.[s] ?? list.filter((p) => p.status === s).length;
    return { all: list.length, published: count("published"), draft: count("draft"), archived: count("archived") };
  }, [products.data, list]);
  const toCheck = useMemo(() => list.filter((p) => p.status !== "archived" && (p.review?.length ?? 0) > 0).length, [list]);

  const setTab = (next: StatusTab) => {
    const copy = new URLSearchParams(params);
    if (next === "all") copy.delete("status"); else copy.set("status", next);
    setParams(copy, { replace: true });
  };
  const setToCheckOnly = (on: boolean) => {
    const copy = new URLSearchParams(params);
    if (on) copy.set("check", "1"); else copy.delete("check");
    setParams(copy, { replace: true });
  };

  const inTab = useMemo(() => (tab === "all" ? list : list.filter((p) => p.status === tab)), [list, tab]);

  const subcategories = useMemo(() => {
    const set = new Map<string, string>();
    inTab.forEach((p) => p.subcategory && set.set(p.subcategory, p.subcategory.replace(/-/g, " ")));
    return Array.from(set.entries()).sort((a, b) => a[1].localeCompare(b[1]));
  }, [inTab]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    let l = inTab.filter(
      (p) =>
        (sub === "all" || p.subcategory === sub) &&
        (!toCheckOnly || (p.review?.length ?? 0) > 0) &&
        (q === "" ||
          [p.name, p.shortDescription, p.series ?? "", p.sku ?? "", p.id, ...p.variants.map((v) => v.sku)]
            .join(" ").toLowerCase().includes(q)),
    );
    /* A product on request has no price to sort by; it goes last either way. */
    const byPrice = (direction: 1 | -1) => (a: ApiProduct, b: ApiProduct) =>
      a.priceOnRequest === b.priceOnRequest ? direction * (a.price - b.price) : a.priceOnRequest ? 1 : -1;
    if (sort === "price-asc") l = [...l].sort(byPrice(1));
    if (sort === "price-desc") l = [...l].sort(byPrice(-1));
    if (sort === "name") l = [...l].sort((a, b) => a.name.localeCompare(b.name));
    return l;
  }, [inTab, sub, query, sort, toCheckOnly]);

  /* A new filter starts from the top, and a selection never outlives the list
     it was made in: "publish 12 selected" must mean the 12 on screen. */
  useEffect(() => { setShown(PAGE); setSelected(new Set()); }, [tab, sub, query, sort, toCheckOnly]);

  const visible = filtered.slice(0, shown);
  const toggle = (id: string) => setSelected((current) => {
    const next = new Set(current);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });
  const allMatchingSelected = filtered.length > 0 && filtered.every((p) => selected.has(p.id));

  const bulk = async (next: ProductStatus) => {
    const ids = filtered.filter((p) => selected.has(p.id)).map((p) => p.id);
    if (!ids.length) return;
    setBulkBusy(true);
    try {
      let changed = 0, unchanged = 0;
      const failures: BulkReply["results"] = [];
      /* The API takes 500 ids at a time; the catalogue may hold more. */
      for (let at = 0; at < ids.length; at += 500) {
        const reply = await mutate<BulkReply>("/api/products/bulk-status", { status: next, ids: ids.slice(at, at + 500) }, "POST");
        changed += reply.changed;
        unchanged += reply.unchanged;
        failures.push(...reply.results.filter((r) => !r.ok));
      }
      const verb = next === "published" ? "published" : next === "archived" ? "archived" : "moved back to draft";
      const parts = [`${changed} ${verb}`];
      if (unchanged) parts.push(`${unchanged} already ${PRODUCT_STATUS_LABEL[next].toLowerCase()}`);
      if (failures.length) parts.push(`${failures.length} not changed: ${failures[0].error ?? "refused"}`);
      push({ tone: failures.length ? "warning" : "success", title: "Catalogue updated", desc: parts.join(" · ") });
      /* Keep the refused ones selected so they can be opened and fixed. */
      setSelected(new Set(failures.map((r) => r.id)));
      await products.reload();
      void status.reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not change the products", desc: e instanceof Error ? e.message : String(e) });
    } finally {
      setBulkBusy(false);
    }
  };

  const openManage = (p: ApiProduct) => {
    setManage(p);
    setPriceDraft(p.priceOnRequest ? "" : String(p.price));
    setOnRequestDraft(p.priceOnRequest);
    setAvailableDraft(p.inStock);
  };

  const saveManage = async () => {
    if (!manage) return;
    const price = Number(priceDraft);
    if (!onRequestDraft && (!Number.isInteger(price) || price <= 0)) {
      push({ tone: "danger", title: "Enter a price", desc: "A price is whole rupees above zero. If there is no price yet, mark the product as price on request." });
      return;
    }
    setSaving(true);
    try {
      /* No quantity is sent: stock moves through the ledger, never from here. */
      const updated = await mutate<ApiProduct>("/api/products/" + encodeURIComponent(manage.id),
        onRequestDraft ? { priceOnRequest: true, inStock: availableDraft } : { price, priceOnRequest: false, inStock: availableDraft });
      products.setData((d) => (d ? { ...d, items: d.items.map((p) => (p.id === updated.id ? updated : p)) } : d));
      push({ tone: "success", title: "Product updated", desc: updated.status === "published" ? `${updated.name} — the shop shows the change within a minute.` : `${updated.name} — not on sale yet (${PRODUCT_STATUS_LABEL[updated.status].toLowerCase()}).` });
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
          title="The catalogue could not be loaded"
          description="The product list did not arrive from the server. Check the connection and try again."
          debug={products.error}
          onRetry={products.reload}
        />
      </div>
    );

  const demoStillOnSale = (status.data?.demo.published ?? 0) > 0 && (status.data?.imported.total ?? 0) > 0;

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Ecommerce", to: "/ecommerce" }, { label: "Catalog" }]}
        title="Furniture Catalog"
        description={
          products.loading
            ? "Loading the catalogue…"
            : `${counts.published} on sale · ${counts.draft} draft${counts.draft === 1 ? "" : "s"} · ${counts.archived} archived`
        }
        actions={
          <>
            <Link to="/catalog/new" className={buttonCls("primary", "md")}><Plus size={15} /> New product</Link>
            <Link to="/catalog/import" className={buttonCls("secondary", "md")}><Download size={15} /> Catalogue import</Link>
          </>
        }
      />

      {status.data && !status.data.schemaReady && (
        <Card className="mb-4 flex items-start gap-3 border-warning/40 bg-warning-soft/40 p-4" padded={false}>
          <AlertTriangle size={18} className="mt-0.5 shrink-0 text-warning-strong" />
          <p className="text-small text-slate-700">
            <strong className="text-ink">This database is waiting for catalogue migration 019.</strong>{" "}
            The catalogue works as before. SKUs, drafts, price on request, made-to-order stock, options and the import switch on when the migration is applied.
          </p>
        </Card>
      )}

      {demoStillOnSale && (
        <Card className="mb-4 flex flex-wrap items-center gap-3 border-warning/40 bg-warning-soft/40 p-4" padded={false}>
          <AlertTriangle size={18} className="shrink-0 text-warning-strong" />
          <p className="min-w-0 flex-1 text-small text-slate-700">
            <strong className="text-ink">{status.data?.demo.published} demo products are still on sale</strong> next to the imported catalogue. Customers can see and order them.
          </p>
          <Link to="/catalog/import" className={buttonCls("secondary", "sm")}>Withdraw the demo catalogue</Link>
        </Card>
      )}

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 lg:gap-4">
        {TABS.map((t) => (
          <button
            key={t}
            type="button"
            onClick={() => setTab(t)}
            aria-pressed={tab === t}
            className={cn(
              "rounded-card border bg-surface px-5 py-3.5 text-left shadow-card transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500",
              tab === t ? "border-primary-500 ring-1 ring-primary-500" : "border-line hover:border-primary-300",
            )}
          >
            <span className="block text-caption font-medium text-subtle uppercase">{t === "all" ? "All products" : t === "published" ? "On sale" : PRODUCT_STATUS_LABEL[t] + (t === "draft" ? "s" : "")}</span>
            <span className="mt-0.5 block text-h3 text-ink">{products.loading ? "—" : counts[t]}</span>
          </button>
        ))}
      </div>

      <Card className="mb-4 flex flex-wrap items-center gap-3 p-3.5" padded={false}>
        <SearchInput className="w-full sm:max-w-64" value={query} onChange={setQuery} placeholder={products.loading ? "Loading…" : "Search by name, SKU or address…"} />
        <Select className="h-9 w-52 text-caption" value={sub} onChange={(e) => setSub(e.target.value)} aria-label="Filter by subcategory">
          <option value="all">All subcategories</option>
          {subcategories.map(([id, label]) => (
            <option key={id} value={id} className="capitalize">
              {label}
            </option>
          ))}
        </Select>
        <Select className="h-9 w-36 text-caption" value={sort} onChange={(e) => setSort(e.target.value as SortKey)} aria-label="Sort products">
          <option value="featured">Catalogue order</option>
          <option value="name">Name A–Z</option>
          <option value="price-asc">Price ↑</option>
          <option value="price-desc">Price ↓</option>
        </Select>
        <label className="flex cursor-pointer items-center gap-2 text-caption font-medium text-slate-700">
          <input type="checkbox" checked={toCheckOnly} onChange={(e) => setToCheckOnly(e.target.checked)} className="h-4 w-4 accent-[#4F9D21]" />
          To check only{toCheck ? ` (${toCheck})` : ""}
        </label>
        <span className="ml-auto hidden items-center gap-1.5 text-caption text-subtle md:flex">
          <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-success" /> live · 30s sync
        </span>
      </Card>

      {mayManage && !products.loading && filtered.length > 0 && (
        <Card className="mb-4 flex flex-wrap items-center gap-3 p-3.5" padded={false}>
          <label className="flex cursor-pointer items-center gap-2 text-caption font-medium text-slate-700">
            <input
              type="checkbox"
              checked={allMatchingSelected}
              onChange={(e) => setSelected(e.target.checked ? new Set(filtered.map((p) => p.id)) : new Set())}
              className="h-4 w-4 accent-[#4F9D21]"
            />
            Select all {filtered.length} shown by these filters
          </label>
          <span className="text-caption text-muted" aria-live="polite">{selected.size ? `${selected.size} selected` : "Nothing selected"}</span>
          <span className="ml-auto flex flex-wrap gap-2">
            <Button size="sm" disabled={!selected.size || bulkBusy} onClick={() => void bulk("published")}><Check size={13} /> Publish</Button>
            <Button size="sm" variant="secondary" disabled={!selected.size || bulkBusy} onClick={() => void bulk("draft")}>Back to draft</Button>
            <Button size="sm" variant="secondary" disabled={!selected.size || bulkBusy} onClick={() => void bulk("archived")}>Archive</Button>
          </span>
        </Card>
      )}

      {products.loading && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-80 rounded-card" />
          ))}
        </div>
      )}

      {!products.loading && list.length === 0 && (
        <Card>
          <EmptyState
            icon={<Package size={22} />}
            title="The catalogue is empty"
            description="Add the first product by hand, or load the prepared catalogue as drafts and check it before anything goes on sale."
            action={<Link to="/catalog/new" className={buttonCls("primary", "md")}><Plus size={15} /> New product</Link>}
            secondary={<Link to="/catalog/import" className={buttonCls("secondary", "md")}>Catalogue import</Link>}
          />
        </Card>
      )}

      {!products.loading && list.length > 0 && filtered.length === 0 && (
        <Card>
          <EmptyState
            icon={<Package size={22} />}
            title="No products match these filters"
            description="Try another state, clear the search, or switch off “To check only”."
            action={
              <Button variant="secondary" onClick={() => { setSub("all"); setQuery(""); setParams(new URLSearchParams(), { replace: true }); }}>
                Clear filters
              </Button>
            }
          />
        </Card>
      )}

      {!products.loading && filtered.length > 0 && (
        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {visible.map((p: ApiProduct) => {
            const notes = p.review?.length ?? 0;
            const discounted = !p.priceOnRequest && !!p.originalPrice && p.originalPrice > p.price;
            return (
              <li key={p.id} className={cn(
                "group flex flex-col overflow-hidden rounded-card border bg-surface shadow-card transition-all duration-150 hover:-translate-y-0.5 hover:shadow-pop",
                selected.has(p.id) ? "border-primary-500 ring-1 ring-primary-500" : "border-line",
              )}>
                <div className="relative aspect-[4/3] overflow-hidden bg-slate-100">
                  {p.images[0]
                    ? <img src={img(p.images[0])} alt={p.name} loading="lazy" className={cn("h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.03]", p.status === "archived" && "opacity-50 grayscale")} />
                    : <div className="grid h-full place-items-center text-subtle"><Package size={24} /></div>}
                  {mayManage && (
                    <label className="absolute top-2 left-2 grid h-7 w-7 cursor-pointer place-items-center rounded-control bg-white/90 shadow-card backdrop-blur-sm">
                      <input type="checkbox" checked={selected.has(p.id)} onChange={() => toggle(p.id)} aria-label={`Select ${p.name}`} className="h-4 w-4 accent-[#4F9D21]" />
                    </label>
                  )}
                  <div className="absolute top-2.5 right-2.5 flex flex-col items-end gap-1">
                    {p.status !== "published" && <Badge tone={productStatusTone(p.status)} className="bg-white/90 backdrop-blur-sm">{PRODUCT_STATUS_LABEL[p.status]}</Badge>}
                    {p.isNew && <Badge tone="info" className="bg-white/90 backdrop-blur-sm">New</Badge>}
                    {p.isBestSeller && <Badge tone="success" className="bg-white/90 backdrop-blur-sm">Bestseller</Badge>}
                    {discounted && (
                      <Badge tone="danger" dot={false} className="bg-white/90 backdrop-blur-sm">-{Math.round((1 - p.price / (p.originalPrice as number)) * 100)}%</Badge>
                    )}
                  </div>
                  <span className="absolute bottom-2.5 left-2.5 rounded bg-charcoal-950/70 px-1.5 py-0.5 font-mono text-[10px] capitalize text-white/90 backdrop-blur-sm">
                    {p.subcategory?.replace(/-/g, " ") ?? p.category}
                  </span>
                  {mayManage && (
                    <button
                      type="button"
                      onClick={() => openManage(p)}
                      title="Price and availability"
                      aria-label={`Price and availability of ${p.name}`}
                      className="absolute right-2 bottom-2 grid h-7 w-7 place-items-center rounded-control bg-white/85 text-slate-600 shadow-card backdrop-blur-sm transition-opacity duration-150 hover:text-ink focus-visible:opacity-100 md:opacity-0 md:group-hover:opacity-100"
                    >
                      <Settings2 size={14} />
                    </button>
                  )}
                </div>
                <div className="flex flex-1 flex-col p-4">
                  <h3 className="line-clamp-1 text-bodylg font-semibold text-ink" title={p.name}><Link to={`/catalog/${encodeURIComponent(p.id)}`} className="transition-colors hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">{p.name}</Link></h3>
                  <p className="mt-1 flex-1 text-caption leading-relaxed text-muted">
                    <span className="font-mono text-slate-700">{p.sku ?? (p.variants.length ? `${p.variants.length} option${p.variants.length === 1 ? "" : "s"}` : "No SKU")}</span>
                    {p.shortDescription ? <span className="line-clamp-2 block">{p.shortDescription}</span> : null}
                  </p>
                  {notes > 0 && (
                    <p className="mt-2 inline-flex items-center gap-1.5 text-caption font-medium text-warning-strong">
                      <AlertTriangle size={12} /> {notes} point{notes === 1 ? "" : "s"} to check
                    </p>
                  )}
                  <div className="mt-3 flex items-end justify-between gap-2 border-t border-slate-100 pt-3">
                    <div>
                      <p className={cn("leading-none tracking-tight text-ink", p.priceOnRequest ? "text-small font-semibold" : "text-h3")}>{priceLabel(p)}</p>
                      {discounted && (
                        <p className="mt-0.5 text-caption text-subtle line-through">{fmtPKR(p.originalPrice as number)}</p>
                      )}
                    </div>
                    <span className={cn("text-right text-caption font-medium", p.inStock ? "text-success-strong" : "text-danger-strong")}>
                      {stockLabel(p)}
                    </span>
                  </div>
                  <div className="mt-3 grid grid-cols-2 gap-2">
                    <Link to={`/catalog/${encodeURIComponent(p.id)}`} className={buttonCls("secondary", "sm")}>Details</Link>
                    <Link to={`/catalog/${encodeURIComponent(p.id)}/edit`} className={buttonCls("ghost", "sm")}>Edit</Link>
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {!products.loading && filtered.length > shown && (
        <div className="mt-5 flex flex-col items-center gap-2">
          <p className="text-caption text-muted">Showing {visible.length} of {filtered.length}</p>
          <Button variant="secondary" onClick={() => setShown((n) => n + PAGE)}>Show {Math.min(PAGE, filtered.length - shown)} more</Button>
        </div>
      )}

      {/* Price and availability — the two things changed most often. */}
      <Modal
        open={!!manage}
        onClose={() => setManage(null)}
        title={"Price and availability — " + (manage?.name ?? "")}
        description="Stock quantities are not changed here: they move through the stock ledger."
        footer={
          <>
            <Button variant="secondary" onClick={() => setManage(null)}>Cancel</Button>
            <Button onClick={() => void saveManage()} disabled={saving}>{saving ? "Saving…" : "Save changes"}</Button>
          </>
        }
      >
        {manage && (
          <div className="space-y-4">
            <Field label="Price (PKR)" hint={onRequestDraft ? "No price is shown. Customers can ask for a quotation and cannot order." : "Whole rupees."}>
              <Input type="number" min="1" step="1" disabled={onRequestDraft} value={onRequestDraft ? "" : priceDraft} onChange={(e) => setPriceDraft(e.target.value)} />
            </Field>
            <label className="flex items-center justify-between gap-3 rounded-card border border-line p-3.5">
              <span>
                <span className="block text-small font-semibold text-ink">Price on request</span>
                <span className="block text-caption text-muted">Use this until a price is agreed. It is never shown as PKR 0.</span>
              </span>
              <input type="checkbox" checked={onRequestDraft} onChange={(e) => setOnRequestDraft(e.target.checked)} className="h-5 w-5 accent-[#4F9D21]" />
            </label>
            <label className="flex items-center justify-between gap-3 rounded-card border border-line p-3.5">
              <span>
                <span className="block text-small font-semibold text-ink">Available to order</span>
                <span className="block text-caption text-muted">Switch off to stop orders without withdrawing the product from the shop.</span>
              </span>
              <input type="checkbox" checked={availableDraft} onChange={(e) => setAvailableDraft(e.target.checked)} className="h-5 w-5 accent-[#4F9D21]" />
            </label>
          </div>
        )}
      </Modal>
    </div>
  );
}
