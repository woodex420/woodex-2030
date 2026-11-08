import { Link } from "react-router";
import { PageHeader } from "@/components/layout/PageHeader";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { buttonCls } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { EmptyState, ErrorState, SkeletonRows } from "@/components/ui/States";
import { useApi, useRealtime, fmtPKR, type ApiOrder, type ApiProduct } from "@/lib/api";
import { ArrowRight, Building, Package, Receipt, ShoppingBag, Store, Truck } from "@/icons";

const columns: Column<ApiOrder>[] = [
  { key: "ref", header: "Order", cell: (order) => <Link to={`/orders/${order.id}`} className="font-mono text-small font-semibold text-primary-700 hover:text-primary-800">{order.ref}</Link> },
  { key: "customer", header: "Customer", cell: (order) => <span className="text-small text-slate-700">{order.customer}</span> },
  { key: "total", header: "Value", align: "right", cell: (order) => <span className="text-small font-semibold text-ink">{fmtPKR(order.total)}</span> },
  { key: "status", header: "Status", cell: (order) => <StatusBadge status={order.status} /> },
];

export default function Ecommerce() {
  const products = useApi<{ total: number; items: ApiProduct[] }>("/api/products", 30000);
  const orders = useApi<{ total: number; items: ApiOrder[] }>("/api/orders", 15000);
  const invoices = useApi<{ total: number; collected: number; outstanding: number; items: unknown[] }>("/api/invoices", 30000);
  useRealtime(/products|orders|invoices|payments/, () => { products.reload(); orders.reload(); invoices.reload(); });

  const orderItems = orders.data?.items ?? [];
  const activeOrders = orderItems.filter((order) => !["Completed", "Cancelled"].includes(order.status));
  const attention = orderItems.filter((order) => ["Delayed", "On Hold"].includes(order.status));
  const readError = orders.error || invoices.error;

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales" }, { label: "Ecommerce" }]}
        title="Ecommerce"
        description="A live snapshot of the shared product catalog, orders and invoices."
        actions={<Link to="/catalog/new" className={buttonCls("primary", "md")}><Package size={15} /> New product</Link>}
      />

      {readError && <div className="mb-4"><ErrorState title="Some commerce metrics are unavailable" description="Catalog data can still be used, but protected order or invoice totals could not be refreshed." debug={readError} onRetry={() => { orders.reload(); invoices.reload(); }} className="py-5" /></div>}

      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Card className="flex items-center gap-3 p-4"><span className="grid h-10 w-10 place-items-center rounded-control bg-primary-50 text-primary-700"><Package size={18} /></span><span><span className="block text-caption text-muted">Products</span><span className="block text-h2 text-ink">{products.data?.total ?? "—"}</span></span></Card>
        <Card className="flex items-center gap-3 p-4"><span className="grid h-10 w-10 place-items-center rounded-control bg-info-soft text-info-strong"><ShoppingBag size={18} /></span><span><span className="block text-caption text-muted">Active orders</span><span className="block text-h2 text-ink">{orders.data ? activeOrders.length : "—"}</span></span></Card>
        <Card className="flex items-center gap-3 p-4"><span className="grid h-10 w-10 place-items-center rounded-control bg-warning-soft text-warning-strong"><Truck size={18} /></span><span><span className="block text-caption text-muted">Needs attention</span><span className="block text-h2 text-ink">{orders.data ? attention.length : "—"}</span></span></Card>
        <Card className="flex items-center gap-3 p-4"><span className="grid h-10 w-10 place-items-center rounded-control bg-success-soft text-success-strong"><Receipt size={18} /></span><span><span className="block text-caption text-muted">Outstanding invoices</span><span className="block text-bodylg font-bold text-ink">{invoices.data ? fmtPKR(invoices.data.outstanding) : "—"}</span></span></Card>
      </div>

      <div className="mb-4 grid gap-4 xl:grid-cols-2">
        <Card>
          <CardHeader title="Product catalog" description={products.error ? "Catalog sync is unavailable." : `${products.data?.total ?? "—"} products in the live shared catalog.`} action={<Link to="/catalog" className={buttonCls("secondary", "sm")}>Manage catalog <ArrowRight size={13} /></Link>} />
          {products.loading && !products.data ? <SkeletonRows rows={3} cols={2} /> : products.error ? <p className="text-small text-muted">The product list is available from the Catalog module when the API is back online.</p> : (
            <div className="grid gap-2 sm:grid-cols-2">{(products.data?.items ?? []).slice(0, 4).map((product) => <Link key={product.id} to={`/catalog/${encodeURIComponent(product.id)}`} className="flex items-center gap-3 rounded-control border border-line p-3 transition-colors hover:border-primary-300 hover:bg-primary-50/40"><span className="grid h-9 w-9 place-items-center rounded-control bg-slate-100 text-slate-600"><Package size={16} /></span><span className="min-w-0 flex-1"><span className="block truncate text-small font-semibold text-ink">{product.name}</span><span className="block truncate text-caption text-muted">{product.category} · {fmtPKR(product.price)}</span></span><Badge tone={product.inStock ? "success" : "muted"} dot={false}>{product.inStock ? "In stock" : "Made to order"}</Badge></Link>)}</div>
          )}
        </Card>

        <Card>
          <CardHeader title="Recent orders" description="Checkout orders with current fulfillment and value." action={<Link to="/orders" className={buttonCls("secondary", "sm")}>All orders <ArrowRight size={13} /></Link>} />
          {orders.loading && !orders.data ? <SkeletonRows rows={4} /> : orders.error ? <p className="text-small text-muted">Order data could not be loaded. Retry from the Orders module.</p> : orderItems.length ? <DataTable rows={orderItems.slice(0, 5)} columns={columns} /> : <EmptyState icon={<ShoppingBag size={18} />} title="No orders yet" description="Completed storefront checkouts appear here." className="py-6" />}
        </Card>
      </div>

      <Card>
        <CardHeader title="Commerce modules" description="Available workflows and current data-model coverage." />
        <div className="grid gap-3 md:grid-cols-3">
          <Link to="/orders" className="rounded-card border border-line p-4 transition-colors hover:border-primary-300 hover:bg-primary-50/30"><span className="grid h-9 w-9 place-items-center rounded-control bg-primary-50 text-primary-700"><ShoppingBag size={16} /></span><h3 className="mt-3 text-small font-semibold text-ink">Orders & fulfillment</h3><p className="mt-1 text-caption text-muted">Live order records, details, status and production stages.</p><span className="mt-3 inline-flex text-caption font-semibold text-primary-700">Open orders <ArrowRight size={12} className="ml-1" /></span></Link>
          <Link to="/clients" className="rounded-card border border-line p-4 transition-colors hover:border-primary-300 hover:bg-primary-50/30"><span className="grid h-9 w-9 place-items-center rounded-control bg-info-soft text-info-strong"><Building size={16} /></span><h3 className="mt-3 text-small font-semibold text-ink">Customers</h3><p className="mt-1 text-caption text-muted">Shared client records and customer history are managed in CRM.</p><span className="mt-3 inline-flex text-caption font-semibold text-primary-700">Open clients <ArrowRight size={12} className="ml-1" /></span></Link>
          <Link to="/invoices" className="rounded-card border border-line p-4 transition-colors hover:border-primary-300 hover:bg-primary-50/30"><span className="grid h-9 w-9 place-items-center rounded-control bg-success-soft text-success-strong"><Receipt size={16} /></span><h3 className="mt-3 text-small font-semibold text-ink">Invoices & payments</h3><p className="mt-1 text-caption text-muted">Issue invoices, track balances and record payments.</p><span className="mt-3 inline-flex text-caption font-semibold text-primary-700">Open invoices <ArrowRight size={12} className="ml-1" /></span></Link>
        </div>
        <div className="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-card border border-dashed border-line-strong bg-slate-50/70 p-4">
          <div className="flex items-start gap-3"><span className="grid h-9 w-9 shrink-0 place-items-center rounded-control bg-slate-200 text-slate-600"><Store size={16} /></span><div><h3 className="text-small font-semibold text-ink">Vendors & purchasing</h3><p className="mt-1 max-w-2xl text-caption leading-relaxed text-muted">Supplier profiles, purchase orders and receiving are not in the current data model. Keep this as a clearly marked prototype until the sourcing schema is defined.</p></div></div>
          <Badge tone="muted" dot={false}>Model not configured</Badge>
        </div>
      </Card>
    </div>
  );
}
