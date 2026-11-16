import { useMemo, useState } from "react";
import { Link } from "react-router";
import { PageHeader } from "@/components/layout/PageHeader";
import { Badge } from "@/components/ui/Badge";
import { Button, buttonCls } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { Select } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { mutate, useApi, fmtPKR } from "@/lib/api";
import { can } from "@/lib/auth";
import { AlertTriangle, Check, ChevronLeft, Download, Package } from "@/icons";

/* The catalogue import, as a screen.

   The prepared catalogue ships with the build (apps/api/catalog-data.json,
   written by scripts/catalog-build.mjs from the capture of the old shop). This
   screen shows what loading it would do to THIS database before anything is
   written, loads it as drafts, and withdraws the demo catalogue. It publishes
   nothing: publishing happens on the catalogue screen, after a person has
   looked at the drafts. */

type Action = "create" | "replace" | "present" | "conflict";
type PlanItem = {
  id: string; name: string; sku: string | null; price: number; priceOnRequest: boolean;
  category: string; subcategory: string | null; review: number;
  action: Action; reason?: string; heldByDemo?: boolean; replaces?: string;
};
type Plan = {
  schemaReady: boolean;
  dataset: { name: string; site: string | null; capturedOn: string | null; products: number; priceOnRequest: number; needingReview: number };
  summary: { create: number; replace: number; present: number; conflict: number; heldByDemo: number };
  items: PlanItem[];
  skipped: { address?: string; name?: string; reason: string }[];
};
type Status = {
  schemaReady: boolean;
  counts: { draft: number; published: number; archived: number };
  total: number;
  demo: { total: number; published: number };
  imported: { total: number; published: number };
  publishedWithoutSku: number;
  withReviewNotes: number;
};
type ImportReply = {
  created: number;
  replaced: { id: string; name: string; replaced: string }[];
  present: number;
  conflicts: { id: string; name: string; reason: string }[];
  refused: { id: string; reason: string }[];
  note: string;
};
type ArchiveReply = { archived: number; publishedLeft: number; warning?: string };

const ACTION_LABEL: Record<Action, string> = {
  create: "Will be loaded",
  replace: "Takes a demo product's place",
  present: "Already loaded",
  conflict: "Left out",
};
const actionTone = (action: Action) =>
  action === "create" || action === "replace" ? "success" as const : action === "present" ? "muted" as const : "danger" as const;
const errorText = (error: unknown) => (error instanceof Error ? error.message : String(error));

export default function CatalogImport() {
  const plan = useApi<Plan>("/api/catalog/import");
  const status = useApi<Status>("/api/catalog/status");
  const { push } = useToast();
  const mayManage = can("catalog.manage");
  const [replaceDemo, setReplaceDemo] = useState(false);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<ImportReply | null>(null);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [archiving, setArchiving] = useState(false);
  const [filter, setFilter] = useState<Action | "all" | "review">("all");

  const crumbs = [{ label: "Ecommerce", to: "/ecommerce" }, { label: "Products", to: "/catalog" }, { label: "Catalogue import" }];
  const header = (
    <PageHeader
      crumbs={crumbs}
      title="Catalogue import"
      description="Load the prepared catalogue as drafts, check it, then publish. Nothing on this screen puts a product on sale."
      actions={<Link to="/catalog" className={buttonCls("secondary", "md")}><ChevronLeft size={14} /> Products</Link>}
    />
  );

  const items = useMemo(() => plan.data?.items ?? [], [plan.data]);
  const shownItems = useMemo(() => items.filter((item) =>
    filter === "all" ? true : filter === "review" ? item.review > 0 : item.action === filter), [items, filter]);

  if ((plan.loading && !plan.data) || (status.loading && !status.data)) return <div>{header}<Skeleton className="h-[420px]" /></div>;

  /* 404 with code no_dataset: this build ships no prepared catalogue. */
  if (plan.error === "HTTP 404") {
    return (
      <div>
        {header}
        <Card><EmptyState icon={<Package size={22} />} title="No prepared catalogue in this build" description="A catalogue is prepared from a capture of the old shop with npm run catalog:build and ships with the next deployment." /></Card>
      </div>
    );
  }
  if (plan.error || status.error || !plan.data || !status.data) {
    return <div>{header}<ErrorState title="The import could not be read" description="The server did not answer with the prepared catalogue." debug={plan.error ?? status.error ?? ""} onRetry={() => { void plan.reload(); void status.reload(); }} /></div>;
  }

  const { dataset, summary, skipped } = plan.data;
  const ready = plan.data.schemaReady;
  const willLoad = summary.create + (replaceDemo ? summary.heldByDemo : 0);
  const otherConflicts = summary.conflict - summary.heldByDemo;
  const demo = status.data.demo;
  const publishedAfterArchive = status.data.counts.published - demo.published;

  const load = async () => {
    setLoading(true);
    try {
      const reply = await mutate<ImportReply>("/api/catalog/import", { dataset: dataset.name, replaceDemo }, "POST");
      setResult(reply);
      const loaded = reply.created + reply.replaced.length;
      push({
        tone: loaded ? "success" : "info",
        title: loaded ? `${loaded} product${loaded === 1 ? "" : "s"} loaded as drafts` : "Nothing new to load",
        desc: reply.note,
      });
      await Promise.all([plan.reload(), status.reload()]);
    } catch (error) {
      push({ tone: "danger", title: "The catalogue was not loaded", desc: errorText(error) });
      void plan.reload();
    } finally {
      setLoading(false);
    }
  };

  const archiveDemo = async () => {
    setArchiving(true);
    try {
      const reply = await mutate<ArchiveReply>("/api/catalog/archive-demo", { confirm: "archive-demo" }, "POST");
      push({
        tone: reply.warning ? "warning" : "success",
        title: `${reply.archived} demo product${reply.archived === 1 ? "" : "s"} archived`,
        desc: reply.warning ?? `${reply.publishedLeft} product${reply.publishedLeft === 1 ? " is" : "s are"} on sale.`,
      });
      await Promise.all([plan.reload(), status.reload()]);
    } catch (error) {
      push({ tone: "danger", title: "The demo catalogue was not archived", desc: errorText(error) });
    } finally {
      setArchiving(false);
      setArchiveOpen(false);
    }
  };

  return (
    <div>
      {header}

      {!ready && (
        <Card className="mb-4 flex items-start gap-3 border-warning/40 bg-warning-soft/40 p-4" padded={false}>
          <AlertTriangle size={18} className="mt-0.5 shrink-0 text-warning-strong" />
          <p className="text-small text-slate-700"><strong className="text-ink">This database is waiting for catalogue migration 019.</strong> The list below can be read, and it can be loaded once the migration is applied (scripts/migrations/019_catalogue.sql, with scripts/migrate.mjs). Until then the catalogue works as it did before.</p>
        </Card>
      )}

      <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div className="space-y-4">
          <Card>
            <CardHeader
              title="The prepared catalogue"
              description={`From ${dataset.site ?? "the capture"}${dataset.capturedOn ? `, as it stood on ${dataset.capturedOn}` : ""}. Every change the rules made is listed in the import report (docs/furniture-2030/catalogue/IMPORT-REPORT.md).`}
            />
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {[
                { l: "Products", v: dataset.products },
                { l: "Price on request", v: dataset.priceOnRequest },
                { l: "With points to check", v: dataset.needingReview },
                { l: "Rows left out", v: skipped.length },
              ].map((s) => (
                <div key={s.l} className="rounded-control border border-line p-3">
                  <dt className="text-caption text-subtle">{s.l}</dt>
                  <dd className="mt-0.5 text-h3 text-ink">{s.v}</dd>
                </div>
              ))}
            </dl>
            {skipped.length > 0 && (
              <ul className="mt-3 space-y-1 text-caption text-muted">
                {skipped.map((row, index) => <li key={index}>Left out: “{row.name ?? row.address}”. {row.reason}</li>)}
              </ul>
            )}
          </Card>

          <Card>
            <CardHeader title="What loading it does to this database" description="Worked out against the products that are here now. Running it again changes nothing: a product already loaded is left exactly as staff last saved it." />
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {[
                { l: "Will be loaded", v: willLoad, tone: "text-success-strong" },
                { l: "Already loaded", v: summary.present, tone: "text-ink" },
                { l: "Address held by a demo product", v: replaceDemo ? 0 : summary.heldByDemo, tone: summary.heldByDemo && !replaceDemo ? "text-warning-strong" : "text-ink" },
                { l: "Left out for another reason", v: otherConflicts, tone: otherConflicts ? "text-danger-strong" : "text-ink" },
              ].map((s) => (
                <div key={s.l} className="rounded-control border border-line p-3">
                  <dt className="text-caption text-subtle">{s.l}</dt>
                  <dd className={`mt-0.5 text-h3 ${s.tone}`}>{s.v}</dd>
                </div>
              ))}
            </dl>

            {summary.heldByDemo > 0 && (
              <label className="mt-4 flex items-start gap-3 rounded-card border border-line p-3.5">
                <input type="checkbox" checked={replaceDemo} onChange={(event) => setReplaceDemo(event.target.checked)} className="mt-0.5 h-4 w-4 accent-[#4F9D21]" />
                <span>
                  <span className="block text-small font-semibold text-ink">Let the real product take the place of the demo product on the same address ({summary.heldByDemo})</span>
                  <span className="block text-caption text-muted">The demo product's content is replaced and it becomes a draft. Its stock history and old demo orders stay; the stock the demo invented is written off in the ledger.</span>
                </span>
              </label>
            )}

            <div className="mt-4 flex flex-wrap items-center gap-3">
              <Button onClick={() => void load()} loading={loading} disabled={!mayManage || !ready || willLoad === 0}>
                <Download size={15} /> {willLoad ? `Load ${willLoad} product${willLoad === 1 ? "" : "s"} as drafts` : "Nothing to load"}
              </Button>
              {!mayManage && <span className="text-caption text-muted">Your role cannot change the catalogue.</span>}
              {status.data.counts.draft > 0 && <Link to="/catalog?status=draft" className={buttonCls("secondary", "md")}>Review {status.data.counts.draft} draft{status.data.counts.draft === 1 ? "" : "s"}</Link>}
            </div>

            {result && (
              <div className="mt-4 rounded-card border border-line bg-slate-50 p-3.5 text-small text-slate-700">
                <p className="flex items-center gap-2 font-semibold text-ink"><Check size={14} className="text-success-strong" /> Last run: {result.created} loaded, {result.replaced.length} took a demo product's place, {result.present} already there, {result.conflicts.length + result.refused.length} left out. 0 published.</p>
                {result.refused.map((row) => <p key={row.id} className="mt-1 text-danger-strong">{row.id}: {row.reason}</p>)}
              </div>
            )}
          </Card>

          <Card padded={false} className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
              <div><h3 className="text-h3">Product by product</h3><p className="mt-0.5 text-small text-muted">{shownItems.length} of {items.length}</p></div>
              <Select className="h-9 w-56 text-caption" value={filter} onChange={(event) => setFilter(event.target.value as typeof filter)} aria-label="Filter the list">
                <option value="all">All products</option>
                <option value="create">Will be loaded</option>
                <option value="present">Already loaded</option>
                <option value="conflict">Left out</option>
                <option value="review">With points to check</option>
              </Select>
            </div>
            <div className="max-h-[520px] overflow-auto">
              <table className="w-full text-small">
                <thead className="sticky top-0 bg-surface">
                  <tr className="border-y border-slate-100 text-left text-caption text-subtle">
                    <th className="px-5 py-2 font-medium">Product</th>
                    <th className="px-2 py-2 font-medium">SKU</th>
                    <th className="px-2 py-2 text-right font-medium">Price</th>
                    <th className="px-5 py-2 font-medium">Outcome</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {shownItems.map((item) => (
                    <tr key={item.id}>
                      <td className="px-5 py-2.5">
                        {item.action === "present"
                          ? <Link to={`/catalog/${encodeURIComponent(item.id)}`} className="font-medium text-ink hover:text-primary-700">{item.name}</Link>
                          : <span className="font-medium text-ink">{item.name}</span>}
                        <span className="block font-mono text-caption text-subtle">/shop/{item.id}</span>
                        {item.review > 0 && <span className="mt-0.5 inline-flex items-center gap-1 text-caption text-warning-strong"><AlertTriangle size={11} /> {item.review} to check</span>}
                      </td>
                      <td className="px-2 py-2.5 font-mono text-caption">{item.sku ?? "—"}</td>
                      <td className="px-2 py-2.5 text-right whitespace-nowrap">{item.priceOnRequest ? "On request" : fmtPKR(item.price)}</td>
                      <td className="px-5 py-2.5">
                        <Badge tone={actionTone(item.action)}>{ACTION_LABEL[item.action]}</Badge>
                        {item.reason && <span className="mt-1 block text-caption text-muted">{item.reason}</span>}
                        {item.replaces && <span className="mt-1 block text-caption text-muted">Replaces the demo product {item.replaces}.</span>}
                      </td>
                    </tr>
                  ))}
                  {shownItems.length === 0 && <tr><td colSpan={4} className="px-5 py-8 text-center text-muted">Nothing in this group.</td></tr>}
                </tbody>
              </table>
            </div>
          </Card>
        </div>

        <aside className="space-y-4">
          <Card>
            <CardHeader title="This database now" />
            <dl className="space-y-2 text-small">
              <div className="flex justify-between gap-3"><dt className="text-muted">On sale</dt><dd className="font-semibold text-ink">{status.data.counts.published}</dd></div>
              <div className="flex justify-between gap-3"><dt className="text-muted">Drafts</dt><dd className="font-semibold text-ink">{status.data.counts.draft}</dd></div>
              <div className="flex justify-between gap-3"><dt className="text-muted">Archived</dt><dd className="font-semibold text-ink">{status.data.counts.archived}</dd></div>
              <div className="flex justify-between gap-3 border-t border-line pt-2"><dt className="text-muted">From the import</dt><dd className="font-semibold text-ink">{status.data.imported.total} ({status.data.imported.published} on sale)</dd></div>
              <div className="flex justify-between gap-3"><dt className="text-muted">Demo products</dt><dd className="font-semibold text-ink">{demo.total} ({demo.published} on sale)</dd></div>
              <div className="flex justify-between gap-3"><dt className="text-muted">On sale without a SKU</dt><dd className="font-semibold text-ink">{status.data.publishedWithoutSku}</dd></div>
              <div className="flex justify-between gap-3"><dt className="text-muted">With points to check</dt><dd className="font-semibold text-ink">{status.data.withReviewNotes}</dd></div>
            </dl>
          </Card>

          <Card>
            <CardHeader title="The demo catalogue" description="The sample products this system was first filled with. They are not WOODEX products." />
            {demo.total === 0
              ? <p className="text-small text-muted">This database holds no demo products.</p>
              : demo.published === 0
                ? <p className="text-small text-muted">All {demo.total} demo products are withdrawn. They are archived, not deleted, because demo orders still name them.</p>
                : (
                  <>
                    <p className="text-small text-slate-700"><strong className="text-ink">{demo.published} demo product{demo.published === 1 ? " is" : "s are"} on sale.</strong> Customers can see and order them.</p>
                    <Button className="mt-3" variant="secondary" disabled={!mayManage || !ready} onClick={() => setArchiveOpen(true)}>Withdraw the demo catalogue</Button>
                  </>
                )}
          </Card>

          <Card>
            <CardHeader title="The order of work" />
            <ol className="list-decimal space-y-2 pl-5 text-small text-slate-700">
              <li>Load the catalogue. Every product arrives as a draft.</li>
              <li>Open the drafts. Settle the points marked “to check”: prices on request, generated SKUs, names.</li>
              <li>Publish the products that are right, one by one or many together.</li>
              <li>Withdraw the demo catalogue, so only real products are on sale.</li>
            </ol>
          </Card>
        </aside>
      </div>

      <Modal
        open={archiveOpen}
        onClose={() => { if (!archiving) setArchiveOpen(false); }}
        title="Withdraw the demo catalogue?"
        description={`${demo.published} demo product${demo.published === 1 ? "" : "s"} will be archived. They leave the shop at once and can be published again from the catalogue.`}
        footer={<><Button type="button" variant="secondary" disabled={archiving} onClick={() => setArchiveOpen(false)}>Keep them on sale</Button><Button type="button" variant="danger" loading={archiving} onClick={() => void archiveDemo()}>Withdraw {demo.published} demo product{demo.published === 1 ? "" : "s"}</Button></>}
      >
        {publishedAfterArchive <= 0
          ? <p className="flex items-start gap-2 text-small text-slate-700"><AlertTriangle size={16} className="mt-0.5 shrink-0 text-warning-strong" /><span><strong className="text-ink">No other product is on sale.</strong> After this the shop shows an empty catalogue until real products are published.</span></p>
          : <p className="text-small text-muted">{publishedAfterArchive} other product{publishedAfterArchive === 1 ? " stays" : "s stay"} on sale.</p>}
      </Modal>
    </div>
  );
}
