import { useMemo, useState } from "react";
import { Link } from "react-router";
import { PageHeader } from "@/components/layout/PageHeader";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Button, buttonCls } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { SearchInput } from "@/components/ui/Field";
import { EmptyState, ErrorState, SkeletonRows } from "@/components/ui/States";
import { Tabs } from "@/components/ui/Tabs";
import { useApi, useRealtime, fmtPKR, timeAgo, type ApiOrder } from "@/lib/api";
import { ClipboardList, Factory, RefreshCw, ShoppingBag, Truck } from "@/icons";

type Filter = "all" | "open" | "attention" | "completed";
const stageLabels: Record<string, string> = { production: "Production", qc: "Quality check", dispatch: "Dispatch", delivery: "Delivery", installation: "Installation" };
const isOpen = (order: ApiOrder) => !["Completed", "Cancelled"].includes(order.status);
const needsAttention = (order: ApiOrder) => ["Delayed", "On Hold"].includes(order.status);

export default function Orders() {
  const { data, error, loading, reload } = useApi<{ total: number; items: ApiOrder[] }>("/api/orders", 15000);
  const [filter, setFilter] = useState<Filter>("all");
  const [query, setQuery] = useState("");
  useRealtime(/orders/, () => reload());

  const items = data?.items ?? [];
  const totalValue = items.reduce((sum, order) => sum + (order.total || 0), 0);
  const active = items.filter(isOpen).length;
  const attention = items.filter(needsAttention).length;
  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return items.filter((order) => {
      const matchesFilter = filter === "all" || (filter === "open" && isOpen(order)) || (filter === "attention" && needsAttention(order)) || (filter === "completed" && order.status === "Completed");
      const matchesQuery = !needle || [order.ref, order.customer, order.status, order.stage, order.owner, ...order.items.map((line) => line.name ?? "")].join(" ").toLowerCase().includes(needle);
      return matchesFilter && matchesQuery;
    });
  }, [items, filter, query]);

  const columns: Column<ApiOrder>[] = [
    { key: "order", header: "Order", className: "min-w-[210px]", cell: (order) => (
      <Link to={`/orders/${order.id}`} className="group flex items-center gap-2.5 rounded-control focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-control bg-primary-50 text-primary-700"><ShoppingBag size={16} /></span>
        <span className="min-w-0"><span className="block font-mono text-small font-semibold text-ink group-hover:text-primary-700">{order.ref}</span><span className="block max-w-[220px] truncate text-caption text-subtle">{order.items?.[0]?.name ?? `${order.items?.length ?? 0} line items`}{(order.items?.length ?? 0) > 1 ? ` +${order.items.length - 1} more` : ""}</span></span>
      </Link>
    ) },
    { key: "customer", header: "Customer", cell: (order) => <span className="text-small font-medium text-slate-700">{order.customer}</span> },
    { key: "stage", header: "Fulfillment", cell: (order) => <span className="inline-flex items-center gap-1.5 text-caption text-muted"><Factory size={13} />{stageLabels[order.stage] ?? order.stage}</span> },
    { key: "total", header: "Order value", align: "right", cell: (order) => <span className="text-small font-semibold text-ink">{fmtPKR(order.total)}</span> },
    { key: "due", header: "Due", cell: (order) => <span className="text-caption text-muted">{order.due || "Not scheduled"}</span> },
    { key: "status", header: "Status", cell: (order) => <StatusBadge status={order.status} /> },
    { key: "view", header: "", align: "right", cell: (order) => <Link to={`/orders/${order.id}`} className={buttonCls("secondary", "sm")}>Details</Link> },
  ];

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/ecommerce" }, { label: "Orders" }]}
        title="Orders"
        description="Live checkout records with fulfillment, value and payment context. Open a record for its full order details."
        actions={<><Button type="button" variant="secondary" onClick={() => void reload()}><RefreshCw size={14} /> Refresh</Button><Link to="/operations" className={buttonCls("secondary", "md")}><Truck size={14} /> Operations board</Link></>}
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Card className="flex items-center gap-3 p-4"><span className="grid h-10 w-10 place-items-center rounded-control bg-primary-50 text-primary-700"><ShoppingBag size={17} /></span><span><span className="block text-caption text-muted">All orders</span><span className="block text-h2 text-ink">{data?.total ?? items.length}</span></span></Card>
        <Card className="flex items-center gap-3 p-4"><span className="grid h-10 w-10 place-items-center rounded-control bg-info-soft text-info-strong"><ClipboardList size={17} /></span><span><span className="block text-caption text-muted">Active</span><span className="block text-h2 text-ink">{active}</span></span></Card>
        <Card className="flex items-center gap-3 p-4"><span className="grid h-10 w-10 place-items-center rounded-control bg-warning-soft text-warning-strong"><Truck size={17} /></span><span><span className="block text-caption text-muted">Needs attention</span><span className="block text-h2 text-ink">{attention}</span></span></Card>
        <Card className="flex items-center gap-3 p-4"><span className="grid h-10 w-10 place-items-center rounded-control bg-success-soft text-success-strong"><Factory size={17} /></span><span><span className="block text-caption text-muted">Total order value</span><span className="block text-bodylg font-bold text-ink">{fmtPKR(totalValue)}</span></span></Card>
      </div>

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3.5 lg:px-5">
          <Tabs value={filter} onChange={(value) => setFilter(value as Filter)} variant="pill" items={[
            { value: "all", label: "All", count: items.length },
            { value: "open", label: "Active", count: active },
            { value: "attention", label: "Attention", count: attention },
            { value: "completed", label: "Completed", count: items.filter((order) => order.status === "Completed").length },
          ]} />
          <SearchInput className="w-full sm:w-64" value={query} onChange={setQuery} placeholder="Search order, client, product…" />
        </div>
        {error && data && <div role="status" className="border-b border-warning/30 bg-warning-soft/40 px-4 py-2 text-caption text-warning-strong">Showing the last loaded order list. Refresh failed: {error}</div>}
        {loading && !data ? <div className="p-4"><SkeletonRows rows={6} /></div>
          : error && !data ? <div className="p-4"><ErrorState title="Orders unavailable" description="The shared orders API could not be reached." debug="GET /api/orders" onRetry={() => void reload()} /></div>
          : filtered.length ? <DataTable key={`${filter}-${query}`} rows={filtered} columns={columns} perPage={12} />
          : <EmptyState icon={<ShoppingBag size={20} />} title={query ? "No matching orders" : filter === "completed" ? "No completed orders" : "No orders in this view"} description={query ? "Try another order reference, customer or product name." : "Orders created by storefront checkout appear here."} />}
      </Card>
      <p className="mt-3 flex items-center gap-1.5 text-caption text-subtle"><Badge tone="success" dot={false}>Live API</Badge>{items.length ? `Last order update ${items[0]?.updatedAt ? timeAgo(items[0].updatedAt) : "from shared backend"}` : "Waiting for the first checkout"}</p>
    </div>
  );
}
