import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useNavigate } from "react-router";
import { cn } from "@/lib/cn";
import { fmtPKR, timeAgo, type ApiInvoice, type ApiLead, type ApiOrder, type ApiProduct, type ApiQuote } from "@/lib/api";
import { Banknote, ClipboardList, FileText, Receipt, Search, Users } from "@/icons";

type Item = { id: string; group: string; label: string; hint?: string; icon: ReactNode; to: string; kw?: string };

const NAV: Item[] = [
  { id: "n-ov", group: "Go to", label: "Overview", icon: <ClipboardList size={14} />, to: "/", kw: "home dashboard" },
  { id: "n-crm", group: "Go to", label: "CRM pipeline", icon: <Users size={14} />, to: "/crm", kw: "leads kanban" },
  { id: "n-cl", group: "Go to", label: "Clients & 360", icon: <Users size={14} />, to: "/clients", kw: "customer lifetime value merge review dedupe" },
  { id: "n-q", group: "Go to", label: "Sales & Quotations", icon: <FileText size={14} />, to: "/quotations", kw: "quotes e-quotation" },
  { id: "n-nq", group: "Actions", label: "New E-Quotation", hint: "opens the builder", icon: <FileText size={14} />, to: "/quotations/new", kw: "create quote draft" },
  { id: "n-inv", group: "Go to", label: "Invoices & Payments", icon: <Receipt size={14} />, to: "/invoices", kw: "finance receivables" },
  { id: "n-nc", group: "Actions", label: "New client", hint: "opens form", icon: <Users size={14} />, to: "/clients?new=1", kw: "add customer create" },
  { id: "n-ni", group: "Actions", label: "Invoice from quote", hint: "opens issuer", icon: <Banknote size={14} />, to: "/invoices?issue=1", kw: "create invoice bill" },
  { id: "n-cat", group: "Go to", label: "Catalog", icon: <Search size={14} />, to: "/catalog", kw: "products skus price stock" },
  { id: "n-ops", group: "Go to", label: "Operations board", icon: <ClipboardList size={14} />, to: "/operations", kw: "orders production delivery returns rma" },
  { id: "n-an", group: "Go to", label: "Analytics", icon: <Receipt size={14} />, to: "/analytics", kw: "reports charts" },
  { id: "n-set", group: "Go to", label: "Settings", icon: <Users size={14} />, to: "/settings", kw: "team integrations" },
];

async function j<T>(url: string): Promise<T[]> {
  try {
    const r = await fetch(url, { headers: { accept: "application/json" } });
    return r.ok ? ((await r.json()) as { items: T[] }).items ?? [] : [];
  } catch { return []; }
}

/** ⌘K palette — navigation, quick actions and live record search over /api. */
export function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const navigate = useNavigate();
  const [q, setQ] = useState("");
  const [sel, setSel] = useState(0);
  const [records, setRecords] = useState<Item[]>([]);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!open) return;
    setQ(""); setSel(0);
    inputRef.current?.focus();
    void (async () => {
      const [leads, quotes, orders, invoices, products] = await Promise.all([
        j<ApiLead>("/api/leads"), j<ApiQuote>("/api/quotes"), j<ApiOrder>("/api/orders"),
        j<ApiInvoice>("/api/invoices"), j<ApiProduct>("/api/products"),
      ]);
      setRecords([
        ...leads.slice(0, 12).map((l) => ({ id: "l" + l.id, group: "Leads", label: `${l.name} · ${l.ref}`, hint: l.status + " · " + timeAgo(l.createdAt), icon: <Users size={14} />, to: "/crm", kw: (l.name + l.ref + (l.interest ?? "")).toLowerCase() })),
        ...quotes.slice(0, 12).map((x) => ({ id: "q" + x.id, group: "Quotations", label: `${x.ref} · ${x.customer}`, hint: x.status + " · " + fmtPKR(x.total), icon: <FileText size={14} />, to: "/quotations", kw: (x.ref + x.customer + x.status).toLowerCase() })),
        ...orders.slice(0, 12).map((o) => ({ id: "o" + o.id, group: "Orders", label: `${o.ref} · ${o.customer}`, hint: o.stage + " · " + fmtPKR(o.total), icon: <ClipboardList size={14} />, to: "/operations", kw: (o.ref + o.customer + o.stage).toLowerCase() })),
        ...invoices.slice(0, 12).map((i) => ({ id: "i" + i.id, group: "Invoices", label: `${i.ref} · ${i.customer}`, hint: i.status + " · balance " + fmtPKR(i.balance), icon: <Receipt size={14} />, to: "/invoices", kw: (i.ref + i.customer + i.status).toLowerCase() })),
        ...products.slice(0, 40).map((p) => ({ id: "p" + p.id, group: "Products", label: p.name, hint: fmtPKR(p.price) + (p.inStock ? "" : " · out of stock"), icon: <Search size={14} />, to: "/catalog?q=" + encodeURIComponent(p.name), kw: (p.name + p.category + (p.series ?? "")).toLowerCase() })),
      ]);
    })();
  }, [open]);

  const results = useMemo(() => {
    const all = [...NAV, ...records];
    const needle = q.trim().toLowerCase();
    const pool = needle ? all.filter((it) => (it.label + " " + (it.kw ?? "") + " " + it.group).toLowerCase().includes(needle)) : all.filter((it) => it.group === "Go to" || it.group === "Actions");
    return pool.slice(0, 12);
  }, [q, records]);

  useEffect(() => setSel(0), [q]);
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
      if (e.key === "ArrowDown") { e.preventDefault(); setSel((v) => Math.min(v + 1, results.length - 1)); }
      if (e.key === "ArrowUp") { e.preventDefault(); setSel((v) => Math.max(v - 1, 0)); }
      if (e.key === "Enter" && results[sel]) { onClose(); navigate(results[sel].to); }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, results, sel, navigate, onClose]);

  if (!open) return null;
  let lastGroup = "";
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/40 p-4 pt-[12vh] backdrop-blur-[2px]" onMouseDown={onClose}>
      <div role="dialog" aria-modal="true" aria-label="Command palette" className="w-full max-w-xl overflow-hidden rounded-card border border-line bg-white shadow-xl" onMouseDown={(e) => e.stopPropagation()}>
        <div className="flex items-center gap-2.5 border-b border-line px-4">
          <Search size={16} className="text-subtle" />
          <input ref={inputRef} value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search pages, actions, leads, quotes, orders, invoices, products…"
            className="h-12 flex-1 bg-transparent text-small text-ink outline-none placeholder:text-subtle" />
          <kbd className="rounded border border-line bg-slate-50 px-1.5 py-0.5 text-[10px] font-medium text-subtle">esc</kbd>
        </div>
        <ul className="max-h-[46vh] overflow-y-auto p-1.5 scrollbar-slim">
          {results.length === 0 && <li className="px-3 py-6 text-center text-small text-subtle">No matches for “{q}”.</li>}
          {results.map((it, i) => {
            const head = it.group !== lastGroup ? (lastGroup = it.group) : null;
            return (
              <li key={it.id}>
                {head && <p className="px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wider text-subtle">{head}</p>}
                <button onMouseEnter={() => setSel(i)} onClick={() => { onClose(); navigate(it.to); }}
                  className={cn("flex w-full items-center gap-2.5 rounded-control px-3 py-2 text-left transition-colors", i === sel ? "bg-primary-50 text-primary-900" : "hover:bg-slate-50")}>
                  <span className={cn("grid h-7 w-7 shrink-0 place-items-center rounded-control", i === sel ? "bg-primary-600 text-white" : "bg-slate-100 text-slate-600")}>{it.icon}</span>
                  <span className="min-w-0 flex-1 truncate text-small font-semibold text-ink">{it.label}</span>
                  {it.hint && <span className="shrink-0 text-caption text-subtle">{it.hint}</span>}
                </button>
              </li>
            );
          })}
        </ul>
        <div className="flex items-center justify-between border-t border-line bg-slate-50/60 px-4 py-2 text-[11px] text-subtle">
          <span>↑↓ navigate · ↵ open · ⎋ close</span>
          <span className="font-semibold text-success-strong">live · shared backend</span>
        </div>
      </div>
    </div>
  );
}
