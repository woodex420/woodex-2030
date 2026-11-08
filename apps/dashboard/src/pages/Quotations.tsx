import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { SkeletonRows, EmptyState } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { QuoteTemplateDesigner, DEFAULT_QUOTE_TEMPLATE, PAYMENT_TERMS_NOTE } from "@/components/quotation/QuoteTemplateDesigner";
import { useApi, mutate, fmtPKR, timeAgo, type ApiLead, type ApiQuote, type ApiQuoteVersion, type ApiProduct, type ApiQuoteTemplate, type ApiQuoteTemplateBlock } from "@/lib/api";
import { ArrowRight, Check, FileText, Plus, Save, Search, Send, Sparkles } from "@/icons";

const IconTrash = ({ size = 14 }: { size?: number }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden>
    <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M6 6l1 14a1.5 1.5 0 0 0 1.5 1.4h7A1.5 1.5 0 0 0 17 20l1-14M10 11v6M14 11v6" />
  </svg>
);

/* ============================ LIST ============================ */

const quoteCols: Column<ApiQuote>[] = [
  {
    key: "ref",
    header: "Quote",
    cell: (r) => (
      <Link to={`/quotations/new?id=${r.id}`} className="flex items-center gap-2.5 rounded-control outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        <span className="grid h-8 w-8 place-items-center rounded-control bg-primary-50 text-primary-700">
          <FileText size={15} />
        </span>
        <span>
          <span className="block text-small font-semibold text-ink hover:text-primary-700">{r.ref}</span>
          <span className="block text-caption text-subtle capitalize">{r.source} · v{r.version} · {r.items.length} line {r.items.length === 1 ? "item" : "items"}</span>
        </span>
      </Link>
    ),
  },
  { key: "customer", header: "Customer", cell: (r) => <span className="text-small text-slate-700">{r.customer}</span> },
  { key: "created", header: "Created", cell: (r) => <span className="text-caption text-muted">{timeAgo(r.createdAt)}</span> },
  { key: "total", header: "Total", align: "right", cell: (r) => <span className="text-small font-semibold text-ink">{fmtPKR(r.total)}</span> },
  { key: "status", header: "Stage", cell: (r) => <StatusBadge status={r.status} /> },
];

export function QuotationsList() {
  const { data, loading, error, reload } = useApi<{ total: number; items: ApiQuote[] }>("/api/quotes", 15000);
  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/quotations" }, { label: "Quotations" }]}
        title="Sales & Quotations"
        description="Storefront quote requests land here instantly — create, approve and send, all persisted in the shared backend."
        actions={
          <>
            <Button variant="secondary" onClick={reload}>Refresh</Button>
            <Link to="/quotations/new" className="inline-flex">
              <Button>
                <Plus size={15} /> New E-Quotation
              </Button>
            </Link>
          </>
        }
      />
      {error ? (
        <Card>
          <EmptyState icon={<FileText size={20} />} title="Quotes API unreachable" description={"Start the backend with npm run dev:api — " + error} action={<Button onClick={reload}>Retry</Button>} />
        </Card>
      ) : (
        <Card padded={false}>
          {loading ? (
            <div className="p-5">
              <SkeletonRows rows={5} />
            </div>
          ) : (
            <DataTable rows={data?.items ?? []} columns={quoteCols} perPage={8} />
          )}
        </Card>
      )}
      <Card className="mt-4">
        <CardHeader title="Lifecycle" description="Every quote moves through visible, approval-gated stages." action={<Link to="/crm" className="text-caption font-semibold text-primary-600 hover:underline">Lead pipeline <ArrowRight size={12} className="inline" /></Link>} />
        <ol className="flex flex-wrap items-center gap-2">
          {["Draft", "Sent", "Viewed", "Negotiation", "Revised", "Approved"].map((s, i, a) => (
            <li key={s} className="flex items-center gap-2">
              <StatusBadge status={s} />
              {i < a.length - 1 && <ArrowRight size={13} className="text-slate-300" />}
            </li>
          ))}
          <li className="flex items-center gap-2">
            <span className="text-slate-300">/</span>
            <StatusBadge status="Rejected" />
            <StatusBadge status="Expired" />
          </li>
        </ol>
      </Card>
    </div>
  );
}

/* ============================ BUILDER ============================ */

type Line = {
  id: string;
  productId: string;
  category: string;
  series: string;
  name: string;
  material: string;
  finish: string;
  width: string;
  height: string;
  depth: string;
  dimensionUnit: string;
  customNotes: string;
  qty: number;
  price: number;
};
type Stage = "Draft" | "Review" | "Approval" | "Sent" | "Revised";

const lifecycle: Stage[] = ["Draft", "Review", "Approval", "Sent", "Revised"];
const todayInPakistan = () => {
  const parts = Object.fromEntries(new Intl.DateTimeFormat("en-US", {
    timeZone: "Asia/Karachi", year: "numeric", month: "2-digit", day: "2-digit",
  }).formatToParts(new Date()).map((part) => [part.type, part.value]));
  return `${parts.year}-${parts.month}-${parts.day}`;
};
const blankLine = (): Line => ({
  id: `${Date.now()}-${Math.random()}`, productId: "", category: "", series: "", name: "",
  material: "", finish: "", width: "", height: "", depth: "", dimensionUnit: "", customNotes: "", qty: 1, price: 0,
});
const quoteLine = (item: ApiQuote["items"][number], index: number): Line => ({
  id: String(item.id ?? `${Date.now()}-${index}`), productId: item.productId ?? "",
  category: item.category ?? "", series: item.series ?? "", name: item.name ?? "",
  material: item.material ?? "", finish: item.finish ?? "",
  width: item.width == null ? "" : String(item.width), height: item.height == null ? "" : String(item.height),
  depth: item.depth == null ? "" : String(item.depth), dimensionUnit: item.dimensionUnit ?? "",
  customNotes: item.customNotes ?? "", qty: Number(item.qty) || 1, price: Number(item.price) || 0,
});
const lineDimensions = (line: Line) => {
  const values = [["W", line.width], ["H", line.height], ["D", line.depth]]
    .filter(([, value]) => Boolean(value)).map(([label, value]) => `${label} ${value}`);
  return values.length ? `${values.join(" × ")}${line.dimensionUnit ? ` ${line.dimensionUnit}` : ""}` : "";
};

export function QuotationBuilder() {
  const { push } = useToast();
  const [searchParams] = useSearchParams();
  const quoteKey = searchParams.get("id");
  const quoteId = quoteKey && /^\d+$/.test(quoteKey) ? Number(quoteKey) : null;
  const catalog = useApi<{ items: ApiProduct[] }>("/api/products");
  const leads = useApi<{ items: ApiLead[] }>("/api/leads", 15000);
  const [status, setStatus] = useState<string>("Draft");
  const [savedId, setSavedId] = useState<number | null>(null);
  const [savedRef, setSavedRef] = useState("Q-new");
  const [version, setVersion] = useState(1);
  const [legacyPricing, setLegacyPricing] = useState(false);
  const [legacyTotal, setLegacyTotal] = useState<number | null>(null);
  const [confirmLegacyReprice, setConfirmLegacyReprice] = useState(false);
  const [clientId, setClientId] = useState<number | null>(null);
  const [relatedLeadId, setRelatedLeadId] = useState<number | null>(null);
  const [catalogOpen, setCatalogOpen] = useState(false);
  const [catalogQuery, setCatalogQuery] = useState("");
  const [customer, setCustomer] = useState({ name: "", company: "", email: "", phone: "", address: "" });
  const [issueDate, setIssueDate] = useState(todayInPakistan);
  const [validUntil, setValidUntil] = useState("");
  const [lines, setLines] = useState<Line[]>([]);
  const [discountPct, setDiscountPct] = useState(0);
  const [taxPct, setTaxPct] = useState(0);
  const [shipping, setShipping] = useState(0);
  const [terms, setTerms] = useState("");
  const [quoteTemplate, setQuoteTemplate] = useState<ApiQuoteTemplate>(() => DEFAULT_QUOTE_TEMPLATE);
  const [internalNote, setInternalNote] = useState("");
  const [audit, setAudit] = useState<ApiQuote["audit"]>([]);
  const [versions, setVersions] = useState<ApiQuoteVersion[]>([]);
  const [busy, setBusy] = useState(false);
  const [quoteLoading, setQuoteLoading] = useState(false);
  const [quoteLoadError, setQuoteLoadError] = useState("");
  const [previewOpen, setPreviewOpen] = useState(false);

  const fmt = fmtPKR;
  const issued = ["Sent", "Accepted", "Approved"].includes(status);
  const locked = busy || quoteLoading || issued;

  useEffect(() => {
    if (!quoteKey) {
      setSavedId(null);
      setSavedRef("Q-new");
      setVersion(1);
      setLegacyPricing(false);
      setLegacyTotal(null);
      setConfirmLegacyReprice(false);
      setClientId(null);
      setRelatedLeadId(null);
      setStatus("Draft");
      setCustomer({ name: "", company: "", email: "", phone: "", address: "" });
      setIssueDate(todayInPakistan());
      setValidUntil("");
      setLines([]);
      setDiscountPct(0);
      setTaxPct(0);
      setShipping(0);
      setTerms("");
      setQuoteTemplate(DEFAULT_QUOTE_TEMPLATE);
      setInternalNote("");
      setAudit([]);
      setVersions([]);
      setQuoteLoadError("");
      setQuoteLoading(false);
      return;
    }
    if (!quoteId) {
      setQuoteLoadError("Quote id must be a numeric record id.");
      return;
    }
    let alive = true;
    setQuoteLoading(true);
    setQuoteLoadError("");
    (async () => {
      try {
        const response = await fetch(`/api/quotes/${quoteId}`);
        const quote = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(quote.error || `HTTP ${response.status}`);
        if (!alive) return;
        setSavedId(quote.id);
        setSavedRef(quote.ref);
        setVersion(Number(quote.version) || 1);
        const hasPricingBreakdown = Boolean(quote.details?.calculation);
        setLegacyPricing(!hasPricingBreakdown);
        setLegacyTotal(hasPricingBreakdown ? null : Number(quote.total) || 0);
        setConfirmLegacyReprice(false);
        setClientId(quote.clientId ?? null);
        setRelatedLeadId(quote.leadId ?? null);
        setStatus(quote.status || "Draft");
        setCustomer({
          name: quote.customer ?? "", company: quote.details?.company ?? "", email: quote.details?.email ?? "",
          phone: quote.details?.phone ?? "", address: quote.details?.siteAddress ?? "",
        });
        setIssueDate(quote.details?.issueDate || (hasPricingBreakdown ? todayInPakistan() : ""));
        setValidUntil(quote.details?.validUntil || "");
        setLines((quote.items ?? []).map(quoteLine));
        setDiscountPct(Number(quote.details?.discountPercent) || 0);
        setTaxPct(Number(quote.details?.taxPercent) || 0);
        setShipping(Number(quote.details?.shipping) || 0);
        setTerms(quote.details?.terms ?? "");
        const savedTemplate = quote.details?.quoteTemplateSnapshot as ApiQuoteTemplate | undefined;
        setQuoteTemplate(savedTemplate ? {
          ...savedTemplate,
          header: savedTemplate.header.map((item) => ({ ...item })),
          footer: savedTemplate.footer.map((item) => ({ ...item })),
        } : DEFAULT_QUOTE_TEMPLATE);
        setInternalNote(quote.note ?? "");
        setAudit(quote.audit ?? []);
        try {
          const historyResponse = await fetch(`/api/quotes/${quoteId}/versions`);
          const history = await historyResponse.json().catch(() => ({}));
          if (alive && historyResponse.ok) setVersions(history.items ?? []);
        } catch { /* quote data remains usable if history is temporarily unavailable */ }
      } catch (error) {
        if (alive) setQuoteLoadError(error instanceof Error ? error.message : String(error));
      } finally {
        if (alive) setQuoteLoading(false);
      }
    })();
    return () => { alive = false; };
  }, [quoteKey, quoteId]);

  const totals = useMemo(() => {
    const round = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;
    const subtotal = round(lines.reduce((n, line) => n + line.qty * line.price, 0));
    const discount = round(subtotal * discountPct / 100);
    const tax = round((subtotal - discount) * taxPct / 100);
    return { subtotal, discount, tax, total: round(subtotal - discount + tax + shipping) };
  }, [lines, discountPct, taxPct, shipping]);
  const showingRecordedTotal = legacyPricing && !confirmLegacyReprice;
  const displayTotal = showingRecordedTotal && legacyTotal != null ? legacyTotal : totals.total;

  const loadVersions = async (id: number) => {
    try {
      const response = await fetch(`/api/quotes/${id}/versions`);
      const result = await response.json().catch(() => ({}));
      if (response.ok) setVersions(result.items ?? []);
    } catch { /* audit is still returned on the quote itself */ }
  };

  const updateLine = (id: string, patch: Partial<Line>) =>
    setLines((current) => current.map((line) => line.id === id ? { ...line, ...patch } : line));

  const addProduct = (product: ApiProduct) => {
    setLines((current) => {
      const existing = current.find((line) => line.productId === product.id);
      if (existing) return current.map((line) => line.id === existing.id ? { ...line, qty: line.qty + 1 } : line);
      return [...current, {
        ...blankLine(), productId: product.id, category: product.category ?? "", series: product.series ?? "",
        name: product.name, price: product.price,
      }];
    });
    push({ tone: "success", title: "Added to quotation", desc: `${product.name} · ${fmt(product.price)}` });
  };

  const saveQuote = async (nextStatus: string) => {
    if (legacyPricing && !confirmLegacyReprice) {
      push({ tone: "danger", title: "Confirm the new pricing breakdown before revising this legacy quote" });
      return;
    }
    if (!customer.name.trim()) {
      push({ tone: "danger", title: "Enter a client name before saving" });
      return;
    }
    if (!issueDate) {
      push({ tone: "danger", title: "Enter the quote issue date before saving" });
      return;
    }
    if (!lines.length || lines.some((line) => !line.name.trim())) {
      push({ tone: "danger", title: "Add at least one named line item" });
      return;
    }
    setBusy(true);
    try {
      const payload = {
        customer: customer.name.trim(),
        contact: [customer.phone.trim(), customer.email.trim()].filter(Boolean).join(" · "),
        leadId: relatedLeadId,
        items: lines.map((line) => ({
          id: line.id, ...(line.productId ? { productId: line.productId } : {}),
          name: line.name, category: line.category, series: line.series, material: line.material, finish: line.finish,
          ...(line.width !== "" ? { width: Number(line.width) } : {}),
          ...(line.height !== "" ? { height: Number(line.height) } : {}),
          ...(line.depth !== "" ? { depth: Number(line.depth) } : {}),
          ...(line.dimensionUnit ? { dimensionUnit: line.dimensionUnit } : {}),
          customNotes: line.customNotes, price: line.price, qty: line.qty,
        })),
        details: {
          company: customer.company, email: customer.email, phone: customer.phone, siteAddress: customer.address,
          issueDate, validUntil, currency: "PKR", discountPercent: discountPct, taxPercent: taxPct,
          shipping, terms, quoteTemplateId: quoteTemplate.id, quoteTemplateSnapshot: quoteTemplate,
        },
        note: internalNote,
        source: "dashboard",
        status: nextStatus,
      };
      const quote = savedId
        ? await mutate<ApiQuote>(`/api/quotes/${savedId}`, payload)
        : await mutate<ApiQuote>("/api/quotes", payload, "POST");
      setSavedId(quote.id);
      setSavedRef(quote.ref);
      setVersion(quote.version ?? 1);
      setLegacyPricing(false);
      setLegacyTotal(null);
      setConfirmLegacyReprice(false);
      setStatus(quote.status ?? nextStatus);
      if (quote.details?.quoteTemplateSnapshot) setQuoteTemplate(quote.details.quoteTemplateSnapshot);
      setAudit(quote.audit ?? []);
      setRelatedLeadId(quote.leadId ?? relatedLeadId);
      setClientId(quote.clientId ?? clientId);
      await loadVersions(quote.id);
      push({ tone: "success", title: savedId ? "Quotation revision saved" : "Quotation saved", desc: `${quote.ref} · ${fmt(quote.total)} PKR · v${quote.version ?? 1}` });
    } catch (error) {
      push({ tone: "danger", title: "Could not save quotation", desc: error instanceof Error ? error.message : String(error) });
    } finally {
      setBusy(false);
    }
  };

  const startRevision = async () => {
    if (!savedId) return;
    setBusy(true);
    try {
      const quote = await mutate<ApiQuote>(`/api/quotes/${savedId}`, { status: "Revised" });
      setStatus(quote.status);
      setAudit(quote.audit ?? []);
      push({ tone: "success", title: "Revision opened", desc: "The issued version remains in history; edits will be saved as a new version." });
    } catch (error) {
      push({ tone: "danger", title: "Could not start revision", desc: error instanceof Error ? error.message : String(error) });
    } finally {
      setBusy(false);
    }
  };

  const chooseLead = (value: string) => {
    const id = value ? Number(value) : null;
    setRelatedLeadId(id && Number.isSafeInteger(id) ? id : null);
    const lead = leads.data?.items.find((item) => item.id === id);
    if (lead) setCustomer((current) => ({
      ...current, name: lead.name ?? "", company: lead.company ?? "", email: lead.email ?? "", phone: lead.phone ?? "",
    }));
  };

  const nextStage = status === "Draft" || status === "Revised" ? "Review" : status === "Review" ? "Approval" : null;
  const headerTemplateBlocks = quoteTemplate.header.filter((item) => item.visible && item.type !== "payment-note");
  const footerTemplateBlocks = quoteTemplate.footer.filter((item) => item.visible);
  if (!footerTemplateBlocks.some((item) => item.type === "payment-note")) {
    footerTemplateBlocks.push({ id: "required-payment-note", type: "payment-note", label: "Formal payment note", content: PAYMENT_TERMS_NOTE, visible: true });
  }
  const printDate = (value: string) => {
    if (!value) return "—";
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString("en-PK", { day: "2-digit", month: "short", year: "numeric", timeZone: "Asia/Karachi" });
  };
  const renderTemplateBlock = (item: ApiQuoteTemplateBlock) => {
    switch (item.type) {
      case "brand":
        return (
          <div aria-label={item.label} className="flex items-center gap-3">
            <span className="grid h-12 w-12 shrink-0 place-items-center rounded-lg text-2xl font-bold text-white" style={{ backgroundColor: quoteTemplate.brandColor }}>
              {quoteTemplate.logoText || (quoteTemplate.companyName.trim().charAt(0) || "W").toUpperCase()}
            </span>
            <div>
              <p className="text-xl font-bold tracking-tight text-slate-900">{quoteTemplate.companyName}</p>
              {item.content?.trim() && <p className="mt-0.5 text-small text-slate-500">{item.content}</p>}
            </div>
          </div>
        );
      case "title":
        return <h1 className="text-3xl font-semibold tracking-tight text-slate-900">{item.label || "Quotation"}</h1>;
      case "meta":
        return (
          <div aria-label={item.label} className="grid gap-x-6 gap-y-2 rounded-lg border border-slate-200 bg-slate-50/70 p-3 text-small sm:grid-cols-2">
            <p><span className="text-slate-500">Reference</span><span className="ml-2 font-semibold">{savedId ? savedRef : "Draft · assigned on save"}</span></p>
            <p><span className="text-slate-500">Issue date</span><span className="ml-2">{printDate(issueDate)}</span></p>
            <p><span className="text-slate-500">Valid until</span><span className="ml-2">{validUntil ? printDate(validUntil) : "—"}</span></p>
            <p><span className="text-slate-500">Currency</span><span className="ml-2">PKR</span></p>
          </div>
        );
      case "customer":
        return (
          <section aria-label={item.label} className="border-l-2 border-slate-200 pl-4">
            <p className="text-caption font-semibold tracking-wide text-slate-500 uppercase">{item.label || "Prepared for"}</p>
            <p className="mt-1 font-semibold text-slate-900">{customer.name || "Client name not entered"}</p>
            {customer.company && <p>{customer.company}</p>}
            {customer.email && <p>{customer.email}</p>}
            {customer.phone && <p>{customer.phone}</p>}
            {customer.address && <p className="mt-1 whitespace-pre-wrap text-small">{customer.address}</p>}
          </section>
        );
      case "terms":
        return terms.trim() ? (
          <section aria-label={item.label} className="border-t border-slate-200 pt-4">
            <p className="text-caption font-semibold tracking-wide text-slate-500 uppercase">{item.label || "Terms"}</p>
            <p className="mt-2 whitespace-pre-wrap text-small leading-relaxed">{terms}</p>
          </section>
        ) : null;
      case "payment-note":
        return <p className="border-t border-slate-100 pt-3 text-small text-slate-600">{PAYMENT_TERMS_NOTE}</p>;
      case "contact": {
        const details = [quoteTemplate.companyAddress, quoteTemplate.companyPhone, quoteTemplate.companyEmail].filter(Boolean);
        return details.length ? (
          <section aria-label={item.label} className="border-t border-slate-200 pt-4 text-small text-slate-600">
            <p className="mb-1 text-caption font-semibold tracking-wide text-slate-500 uppercase">{item.label || "Contact"}</p>
            <p>{quoteTemplate.companyName}{details.length ? ` · ${details.join(" · ")}` : ""}</p>
          </section>
        ) : null;
      }
      case "custom":
        return item.content?.trim() ? (
          <section aria-label={item.label} className="whitespace-pre-wrap text-small leading-relaxed">
            {item.label && <p className="mb-1 font-semibold text-slate-800">{item.label}</p>}
            <p>{item.content}</p>
          </section>
        ) : null;
    }
  };

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/quotations" }, { label: savedId ? savedRef : "New Quotation" }]}
        title={savedId ? savedRef : "New E-Quotation"}
        description={savedId ? `Persistent quote · version ${version} · edits are audited` : "Build a customer-specific quote; unconfirmed commercial terms are left blank."}
        actions={<div className="flex items-center gap-2"><Button variant="secondary" onClick={() => setPreviewOpen(true)}><FileText size={14} /> Preview</Button><StatusBadge status={status} /></div>}
      />
      {quoteLoadError && <Card className="mb-4 border-danger/30"><p className="text-small text-danger-strong">{quoteLoading ? "Loading quotation… " : "Could not load quotation: "}{quoteLoadError}</p></Card>}

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
        <div className="min-w-0 space-y-4">
          <Card>
            <CardHeader title="Customer Information" description="Customer and site details stored with this quote" />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Client name" required>
                <Input value={customer.name} disabled={locked} onChange={(e) => setCustomer((c) => ({ ...c, name: e.target.value }))} />
              </Field>
              <Field label="Company">
                <Input value={customer.company} disabled={locked} onChange={(e) => setCustomer((c) => ({ ...c, company: e.target.value }))} />
              </Field>
              <Field label="Email">
                <Input type="email" value={customer.email} disabled={locked} onChange={(e) => setCustomer((c) => ({ ...c, email: e.target.value }))} />
              </Field>
              <Field label="Phone">
                <Input type="tel" value={customer.phone} disabled={locked} onChange={(e) => setCustomer((c) => ({ ...c, phone: e.target.value }))} />
              </Field>
              <Field label="Linked lead" hint={clientId ? `This quote is linked to client #${clientId}.` : "Optional. Linking requires matching client identity details."}>
                <Select value={relatedLeadId ?? ""} disabled={locked} onChange={(e) => chooseLead(e.target.value)}>
                  <option value="">No linked lead</option>
                  {(leads.data?.items ?? []).map((lead) => <option key={lead.id} value={lead.id}>{lead.ref} · {lead.name}</option>)}
                </Select>
              </Field>
              <Field label="Site address" className="sm:col-span-2">
                <Textarea value={customer.address} disabled={locked} rows={2} onChange={(e) => setCustomer((c) => ({ ...c, address: e.target.value }))} />
              </Field>
            </div>
          </Card>

          <QuoteTemplateDesigner value={quoteTemplate} onChange={setQuoteTemplate} disabled={locked} />

          <Card>
            <CardHeader
              title="Quotation Details"
              description={savedId ? `${savedRef} · version ${version}` : "A reference is assigned when first saved"}
              action={<div className="flex gap-2"><Button variant="secondary" size="sm" onClick={() => setPreviewOpen(true)}><FileText size={13} /> Preview</Button><Button variant="secondary" size="sm" onClick={() => catalog.reload()}>Refresh catalog</Button></div>}
            />
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              <Field label="Quote #"><Input value={savedRef === "Q-new" ? "Assigned on save" : savedRef} readOnly className="bg-slate-50 text-muted" /></Field>
              <Field label="Issue date" required><Input type="date" value={issueDate} disabled={locked} onChange={(e) => setIssueDate(e.target.value)} /></Field>
              <Field label="Valid until" hint="Optional"><Input type="date" value={validUntil} disabled={locked} onChange={(e) => setValidUntil(e.target.value)} /></Field>
              <Field label="Currency" hint="FX conversion is not configured"><Input value="PKR" readOnly className="bg-slate-50 text-muted" /></Field>
            </div>
          </Card>

          <Card padded={false}>
            <div className="flex flex-wrap items-center justify-between gap-2 px-5 pt-5 lg:px-6 lg:pt-6">
              <div>
                <h3 className="text-h3">Product / Service Lines</h3>
                <p className="mt-1 text-small text-muted">{lines.length} items · from the {catalog.data?.items.length ?? "live"}-SKU catalog or custom lines</p>
              </div>
              <div className="flex items-center gap-2">
                <Button variant="subtle" size="sm" disabled={locked} onClick={() => {
                  const product = (catalog.data?.items ?? []).find((item) => item.isBestSeller) ?? catalog.data?.items[0];
                  if (product) addProduct(product);
                }}><Sparkles size={14} /> Add a catalog suggestion</Button>
                <Button variant="secondary" size="sm" disabled={locked} onClick={() => setCatalogOpen(true)}>From Catalog</Button>
              </div>
            </div>
            {lines.length === 0 ? (
              <div className="px-5 pb-5 lg:px-6 lg:pb-6">
                <EmptyState icon={<FileText size={20} />} title="No line items yet" description="Add a catalog product or custom line, then enter the quote-specific options and pricing." action={<Button size="sm" disabled={locked} onClick={() => setCatalogOpen(true)}><Plus size={14} /> From Catalog</Button>} />
              </div>
            ) : (
              <>
                <div className="scrollbar-slim mt-3 overflow-x-auto">
                  <table className="w-full min-w-[980px] border-collapse text-left">
                    <thead>
                      <tr className="border-y border-line bg-slate-50 text-caption text-subtle">
                        <th className="px-5 py-2 font-medium uppercase tracking-wide lg:px-6">Item</th>
                        <th className="px-3 py-2 font-medium uppercase tracking-wide">Options / dimensions</th>
                        <th className="w-20 px-3 py-2 text-right font-medium uppercase tracking-wide">Qty</th>
                        <th className="w-32 px-3 py-2 text-right font-medium uppercase tracking-wide">Unit price (PKR)</th>
                        <th className="w-32 px-3 py-2 text-right font-medium uppercase tracking-wide">Amount</th>
                        <th className="w-10 px-4" />
                      </tr>
                    </thead>
                    <tbody>
                      {lines.map((line) => (
                        <tr key={line.id} className="border-b border-slate-100 align-top transition-colors hover:bg-primary-50/40 last:border-0">
                          <td className="min-w-48 px-5 py-3 lg:px-6">
                            <input aria-label="Item name" value={line.name} disabled={locked} onChange={(e) => updateLine(line.id, { name: e.target.value })} placeholder="Product or service" className="w-full rounded border border-transparent bg-transparent px-2 py-1.5 text-small font-medium text-ink hover:border-line-strong focus:border-primary-500 focus:bg-white focus:outline-none disabled:opacity-60" />
                            <input aria-label="Category" value={line.category} disabled={locked} onChange={(e) => updateLine(line.id, { category: e.target.value })} placeholder="Category" className="mt-1 w-full rounded border border-transparent bg-transparent px-2 py-1 text-caption text-muted hover:border-line-strong focus:border-primary-500 focus:bg-white focus:text-slate-700 focus:outline-none disabled:opacity-60" />
                            <input aria-label="Series" value={line.series} disabled={locked} onChange={(e) => updateLine(line.id, { series: e.target.value })} placeholder="Series" className="mt-1 w-full rounded border border-transparent bg-transparent px-2 py-1 text-caption text-muted hover:border-line-strong focus:border-primary-500 focus:bg-white focus:text-slate-700 focus:outline-none disabled:opacity-60" />
                          </td>
                          <td className="px-3 py-3">
                            <div className="grid min-w-64 grid-cols-2 gap-1.5">
                              <input aria-label="Material" value={line.material} disabled={locked} onChange={(e) => updateLine(line.id, { material: e.target.value })} placeholder="Material" className="h-8 min-w-0 rounded border border-line-strong bg-white px-2 text-caption focus:border-primary-500 focus:outline-none disabled:opacity-60" />
                              <input aria-label="Finish" value={line.finish} disabled={locked} onChange={(e) => updateLine(line.id, { finish: e.target.value })} placeholder="Finish / colour" className="h-8 min-w-0 rounded border border-line-strong bg-white px-2 text-caption focus:border-primary-500 focus:outline-none disabled:opacity-60" />
                              <div className="col-span-2 flex items-center gap-1.5">
                                <input aria-label="Width" type="number" min="0" value={line.width} disabled={locked} onChange={(e) => updateLine(line.id, { width: e.target.value })} placeholder="W" className="h-8 w-14 rounded border border-line-strong bg-white px-1.5 text-caption focus:border-primary-500 focus:outline-none disabled:opacity-60" />
                                <input aria-label="Height" type="number" min="0" value={line.height} disabled={locked} onChange={(e) => updateLine(line.id, { height: e.target.value })} placeholder="H" className="h-8 w-14 rounded border border-line-strong bg-white px-1.5 text-caption focus:border-primary-500 focus:outline-none disabled:opacity-60" />
                                <input aria-label="Depth" type="number" min="0" value={line.depth} disabled={locked} onChange={(e) => updateLine(line.id, { depth: e.target.value })} placeholder="D" className="h-8 w-14 rounded border border-line-strong bg-white px-1.5 text-caption focus:border-primary-500 focus:outline-none disabled:opacity-60" />
                                <select aria-label="Dimension unit" value={line.dimensionUnit} disabled={locked} onChange={(e) => updateLine(line.id, { dimensionUnit: e.target.value })} className="h-8 rounded border border-line-strong bg-white px-1.5 text-caption focus:border-primary-500 focus:outline-none disabled:opacity-60">
                                  <option value="">Unit</option><option value="mm">mm</option><option value="cm">cm</option><option value="in">in</option>
                                </select>
                              </div>
                              <input aria-label="Custom options or notes" value={line.customNotes} disabled={locked} onChange={(e) => updateLine(line.id, { customNotes: e.target.value })} placeholder="Other options / notes" className="col-span-2 h-8 min-w-0 rounded border border-line-strong bg-white px-2 text-caption focus:border-primary-500 focus:outline-none disabled:opacity-60" />
                            </div>
                          </td>
                          <td className="px-3 py-3 text-right">
                            <input type="number" min="0.01" step="0.01" aria-label="Quantity" value={line.qty} disabled={locked} onChange={(e) => updateLine(line.id, { qty: Math.max(0.01, Number(e.target.value) || 0.01) })} className="h-9 w-16 rounded border border-line-strong bg-white px-2 text-right text-small focus:border-primary-500 focus:outline-none disabled:opacity-60" />
                          </td>
                          <td className="px-3 py-3 text-right">
                            <input type="number" min="0" step="0.01" aria-label="Unit price" value={line.price} disabled={locked} onChange={(e) => updateLine(line.id, { price: Math.max(0, Number(e.target.value) || 0) })} className="h-9 w-28 rounded border border-line-strong bg-white px-2 text-right text-small focus:border-primary-500 focus:outline-none disabled:opacity-60" />
                          </td>
                          <td className="px-3 py-3 text-right text-small font-semibold text-ink">{fmt(line.qty * line.price)}</td>
                          <td className="px-4 py-3 text-right">
                            <button aria-label="Remove line" disabled={locked} onClick={() => setLines((current) => current.filter((item) => item.id !== line.id))} className="grid h-7 w-7 place-items-center rounded-control text-subtle transition-colors hover:bg-danger-soft hover:text-danger-strong disabled:opacity-40"><IconTrash size={14} /></button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2 px-5 py-3.5 lg:px-6">
                  <Button variant="secondary" size="sm" disabled={locked} onClick={() => setLines((current) => [...current, blankLine()])}><Plus size={14} /> Add custom line</Button>
                  <span className="text-caption text-subtle">Amounts are recalculated and validated by the API before saving.</span>
                </div>
              </>
            )}
          </Card>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader title="Discount / Tax / Delivery" description="No Woodex rates are prefilled. Server applies discount before tax." />
              {legacyPricing && <div className="mb-3 rounded-control border border-warning/40 bg-warning-soft p-3 text-caption text-slate-700">
                <p>The loaded record has a saved total of <strong>{fmt(legacyTotal ?? 0)} PKR</strong>, but no pricing breakdown. That total is preserved until you explicitly confirm new component values.</p>
                <label className="mt-2 flex items-start gap-2"><input type="checkbox" checked={confirmLegacyReprice} disabled={locked} onChange={(e) => setConfirmLegacyReprice(e.target.checked)} className="mt-0.5" /><span>I confirm the discount, tax and delivery/install values below for this new revision.</span></label>
              </div>}
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Field label="Discount %"><Input type="number" min="0" max="100" step="0.01" value={discountPct} disabled={locked} onChange={(e) => setDiscountPct(Number(e.target.value) || 0)} /></Field>
                <Field label="Tax %"><Input type="number" min="0" max="100" step="0.01" value={taxPct} disabled={locked} onChange={(e) => setTaxPct(Number(e.target.value) || 0)} /></Field>
                <Field label="Delivery / install (PKR)"><Input type="number" min="0" step="0.01" value={shipping} disabled={locked} onChange={(e) => setShipping(Number(e.target.value) || 0)} /></Field>
              </div>
            </Card>
            <Card>
              <CardHeader title="Terms" description="Optional text included in the quote preview; no sample policy is inserted." />
              <Textarea rows={4} value={terms} disabled={locked} onChange={(e) => setTerms(e.target.value)} placeholder="Enter approved payment, delivery, warranty or other terms — or leave blank." />
              <Field label="Internal note" className="mt-3">
                <Textarea rows={2} value={internalNote} disabled={locked} onChange={(e) => setInternalNote(e.target.value)} placeholder="Private note for the sales team (optional)" />
              </Field>
            </Card>
          </div>
        </div>

        <div className="space-y-4">
          <Card className="sticky top-20 z-20">
            <CardHeader title="Totals" />
            {showingRecordedTotal ? (
              <p className="rounded-control border border-warning/40 bg-warning-soft p-3 text-caption text-slate-700">No itemized pricing breakdown was stored for this legacy quote. Its recorded total is shown until you confirm a recalculation.</p>
            ) : (
              <dl className="space-y-2 text-small">
                <div className="flex justify-between"><dt className="text-muted">Subtotal</dt><dd className="font-medium text-ink">{fmt(totals.subtotal)}</dd></div>
                <div className="flex justify-between"><dt className="text-muted">Discount ({discountPct}%)</dt><dd className="font-medium text-danger-strong">− {fmt(totals.discount)}</dd></div>
                <div className="flex justify-between"><dt className="text-muted">Tax ({taxPct}%)</dt><dd className="font-medium text-ink">{fmt(totals.tax)}</dd></div>
                <div className="flex justify-between"><dt className="text-muted">Delivery / installation</dt><dd className="font-medium text-ink">{fmt(shipping)}</dd></div>
              </dl>
            )}
            <div className="mt-4 rounded-card bg-primary-500 p-4 text-white shadow-cta">
              <p className="text-caption font-medium tracking-wide uppercase opacity-90">{showingRecordedTotal ? "Recorded quote total · PKR" : "Quotation total · PKR"}</p>
              <p className="mt-1 text-display tracking-tight">{fmt(displayTotal)}</p>
              <p className="mt-0.5 text-caption opacity-90">{showingRecordedTotal ? "Stored total · breakdown unavailable" : "Server-validated when saved"}</p>
            </div>
            <div className="mt-4 space-y-2" role="group" aria-label="Quotation actions">
              <Button className="w-full" variant="secondary" disabled={busy || quoteLoading || issued || (legacyPricing && !confirmLegacyReprice)} onClick={() => saveQuote(status === "Revised" ? "Revised" : status)}><Save size={15} /> {status === "Revised" ? "Save Revision" : savedId ? "Save changes" : "Save Draft"}</Button>
              {issued ? (
                <Button className="w-full" disabled={busy || quoteLoading} onClick={startRevision}><FileText size={15} /> Start new revision</Button>
              ) : nextStage ? (
                <Button className="w-full" disabled={busy || quoteLoading || lines.length === 0 || (legacyPricing && !confirmLegacyReprice)} onClick={() => saveQuote(nextStage)}><Check size={15} /> {status === "Review" ? "Approve for issue" : "Submit for review"}</Button>
              ) : null}
              {status === "Approval" && <Button className="w-full" disabled={busy || quoteLoading || lines.length === 0 || (legacyPricing && !confirmLegacyReprice)} onClick={() => saveQuote("Sent")}><Send size={15} /> Issue / Send</Button>}
              {status === "Sent" && <p className="pt-1 text-center text-caption text-success-strong">Issued version v{version} is preserved in history.</p>}
              <Button className="w-full" variant="subtle" disabled={quoteLoading} onClick={() => setPreviewOpen(true)}><FileText size={15} /> Preview quote</Button>
            </div>
          </Card>

          <Card>
            <CardHeader title="Approval Workflow" description="Status changes and content revisions are audited" />
            <ol className="mb-4">
              {lifecycle.map((stage, index) => {
                const activeIdx = lifecycle.indexOf(status as Stage);
                const state = index < activeIdx ? "done" : index === activeIdx ? "current" : "todo";
                return (
                  <li key={stage} className="relative flex gap-3 pb-4 last:pb-0">
                    <span className={cn("z-10 grid h-6 w-6 shrink-0 place-items-center rounded-full border text-[10px] font-bold", state === "done" && "border-primary-500 bg-primary-500 text-white", state === "current" && "border-primary-500 bg-white text-primary-700", state === "todo" && "border-line-strong bg-white text-subtle")}>{state === "done" ? "✓" : index + 1}</span>
                    {index < lifecycle.length - 1 && <span className={cn("absolute top-6 bottom-0 left-3 w-px", index < activeIdx ? "bg-primary-500" : "bg-slate-200")} aria-hidden />}
                    <span className="pt-0.5"><span className={cn("block text-small font-semibold", state === "todo" ? "text-subtle" : "text-ink")}>{stage}</span></span>
                  </li>
                );
              })}
            </ol>
            <div className="rounded-card border border-line bg-slate-50/70 p-3">
              <p className="mb-1.5 text-caption font-semibold tracking-wide text-subtle uppercase">Audit history</p>
              {audit.length === 0 ? <p className="text-caption text-muted">Changes appear here after saving.</p> : <ul className="space-y-1.5">{audit.slice(0, 6).map((entry, index) => (
                <li key={`${entry.time}-${index}`} className="flex items-baseline justify-between gap-2 text-caption">
                  <span className="truncate text-slate-700"><strong className="font-semibold text-ink">{entry.who}</strong> {entry.action}</span>
                  <span className="shrink-0 text-subtle">{timeAgo(entry.time)}</span>
                </li>
              ))}</ul>}
            </div>
          </Card>

          <Card>
            <CardHeader title={`Version history · v${version}`} description={`${versions.length} saved ${versions.length === 1 ? "version" : "versions"}`} />
            {versions.length === 0 ? <p className="text-caption text-muted">Save this quotation to start its revision history.</p> : (
              <ol className="space-y-2">{[...versions].reverse().map((item) => (
                <li key={item.id} className="flex items-start justify-between gap-3 text-caption">
                  <span><strong className="font-semibold text-ink">v{item.version}</strong> · {item.action ?? "Saved"}<span className="block text-subtle">{item.actor ?? "System"}</span></span>
                  <span className="shrink-0 text-subtle">{timeAgo(item.createdAt)}</span>
                </li>
              ))}</ol>
            )}
          </Card>
        </div>
      </div>

      <Modal open={previewOpen} onClose={() => setPreviewOpen(false)} title="Quotation preview" description={savedId ? `${savedRef} · version ${version}` : "Unnumbered draft preview"} className="max-w-4xl max-h-[90vh] overflow-y-auto" footer={<><Button variant="secondary" onClick={() => setPreviewOpen(false)}>Close</Button><Button onClick={() => window.print()}>Print / Save PDF</Button></>}>
        <div id="quote-print" aria-label="Printable quotation" className="mx-auto max-w-3xl bg-white p-3 text-slate-800 sm:p-6">
          <div className="space-y-4 border-b border-slate-200 pb-5">
            {headerTemplateBlocks.map((item) => <div key={item.id}>{renderTemplateBlock(item)}</div>)}
          </div>
          <div className="overflow-x-auto py-5">
            <table className="w-full min-w-[520px] text-left text-small">
              <thead>
                <tr style={{ backgroundColor: quoteTemplate.brandColor, color: "#fff" }} className="text-caption uppercase">
                  <th className="py-2 pr-3 pl-2">Description / options</th><th className="px-3 py-2 text-right">Qty</th><th className="px-3 py-2 text-right">Unit price</th><th className="py-2 pr-2 pl-3 text-right">Amount</th>
                </tr>
              </thead>
              <tbody>{lines.map((line) => <tr key={line.id} className="border-b border-slate-100 align-top"><td className="py-3 pr-3"><strong>{line.name || "Unnamed line"}</strong><div className="mt-1 text-caption text-slate-500">{[line.category, line.series, line.material, line.finish, lineDimensions(line), line.customNotes].filter(Boolean).join(" · ")}</div></td><td className="px-3 py-3 text-right">{line.qty}</td><td className="px-3 py-3 text-right">{fmt(line.price)}</td><td className="py-3 pl-3 text-right font-medium">{fmt(line.qty * line.price)}</td></tr>)}</tbody>
            </table>
          </div>
          <div className="ml-auto max-w-sm space-y-2 border-t border-slate-200 pt-4 text-small">
            {showingRecordedTotal ? (
              <p className="text-caption text-slate-500">Legacy record · pricing breakdown unavailable</p>
            ) : <>
              <div className="flex justify-between"><span>Subtotal</span><span>{fmt(totals.subtotal)}</span></div>
              <div className="flex justify-between"><span>Discount ({discountPct}%)</span><span>{fmt(totals.discount)}</span></div>
              <div className="flex justify-between"><span>Tax ({taxPct}%)</span><span>{fmt(totals.tax)}</span></div>
              <div className="flex justify-between"><span>Delivery / installation</span><span>{fmt(shipping)}</span></div>
            </>}
            <div className="flex justify-between border-t border-slate-300 pt-2 text-base font-semibold"><span>{showingRecordedTotal ? "Recorded total · PKR" : "Estimated total · PKR"}</span><span>{fmt(displayTotal)}</span></div>
          </div>
          <div className="mt-6 space-y-4 border-t border-slate-200 pt-5">
            {footerTemplateBlocks.map((item) => <div key={item.id}>{renderTemplateBlock(item)}</div>)}
          </div>
        </div>
      </Modal>

      {/* Live catalog picker — searches the same 205-SKU dataset the storefront sells */}
      <Modal open={catalogOpen} onClose={() => setCatalogOpen(false)} title="Add from Catalog" description="Live from the shared backend" className="max-w-2xl" footer={null}>
        <div onClick={(e) => e.stopPropagation()}>
          <div className="relative">
            <Search size={15} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-subtle" />
            <input value={catalogQuery} onChange={(e) => setCatalogQuery(e.target.value)} placeholder="Search products…" className="h-10 w-full rounded-control border border-line-strong pl-9 text-small focus:border-primary-500 focus:outline-none" autoFocus />
          </div>
          <ul className="scrollbar-slim mt-3 max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-card border border-line">
            {(catalog.data?.items ?? [])
              .filter((p) => catalogQuery.trim() === "" || (p.name + " " + (p.subcategory ?? "")).toLowerCase().includes(catalogQuery.toLowerCase()))
              .slice(0, 40)
              .map((p) => (
                <li key={p.id} className="flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-primary-50/50">
                  <img src={"/img/" + p.images[0]} alt="" loading="lazy" className="h-11 w-14 shrink-0 rounded border border-line object-cover" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-small font-semibold text-ink">{p.name}</span>
                    <span className="block truncate text-caption text-muted capitalize">{p.subcategory?.replace(/-/g, " ")} · {p.inStock ? "in stock" : "made to order"}</span>
                  </span>
                  <span className="text-small font-semibold text-ink">{fmt(p.price)}</span>
                  <Button size="sm" onClick={() => addProduct(p)}><Plus size={13} /> Add</Button>
                </li>
              ))}
            {!catalog.data && (
              <li className="px-3 py-8"><SkeletonRows rows={3} /></li>
            )}
            {catalog.data && (catalog.data.items ?? []).filter((p) => catalogQuery.trim() === "" || p.name.toLowerCase().includes(catalogQuery.toLowerCase())).length === 0 && (
              <li className="px-3 py-8 text-center text-caption text-subtle">No products match “{catalogQuery}”.</li>
            )}
          </ul>
        </div>
      </Modal>
    </div>
  );
}
