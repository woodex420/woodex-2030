import { BrowserRouter, Link, Navigate, Route, Routes } from "react-router";
import { ToastProvider } from "@/components/ui/Toast";
import { AppShell } from "@/components/layout/AppShell";
import { ErrorState } from "@/components/ui/States";
import { buttonCls } from "@/components/ui/Button";
import Dashboard from "@/pages/Dashboard";
import Crm from "@/pages/Crm";
import { QuotationsList, QuotationBuilder } from "@/pages/Quotations";
import Catalog from "@/pages/Catalog";
import ProductEditor from "@/pages/ProductEditor";
import ProductDetail from "@/pages/ProductDetail";
import Ecommerce from "@/pages/Ecommerce";
import Orders from "@/pages/Orders";
import OrderDetail from "@/pages/OrderDetail";
import Clients from "@/pages/Clients";
import Companies from "@/pages/Companies";
import Website, { PageEditor } from "@/pages/Website";
import ThemeStudio from "@/pages/ThemeStudio";
import Marketing from "@/pages/Marketing";
import Meetings from "@/pages/Meetings";
import Inbox from "@/pages/Inbox";
import Invoices from "@/pages/Invoices";
import Projects from "@/pages/Projects";
import Operations from "@/pages/Operations";
import Suppliers from "@/pages/Suppliers";
import PurchaseOrders from "@/pages/PurchaseOrders";
import GoodsReceipts from "@/pages/GoodsReceipts";
import SupplierInvoices from "@/pages/SupplierInvoices";
import Production from "@/pages/Production";
import StockLedger from "@/pages/StockLedger";
import Warehouses from "@/pages/Warehouses";
import StockTransfers from "@/pages/StockTransfers";
import StockReservations from "@/pages/StockReservations";
import Ledger from "@/pages/Ledger";
import Expenses from "@/pages/Expenses";
import CreditNotes from "@/pages/CreditNotes";
import PaymentSchedules from "@/pages/PaymentSchedules";
import Analytics from "@/pages/Analytics";
import Tasks from "@/pages/Tasks";
import UIComponents from "@/pages/UIComponents";
import Settings from "@/pages/Settings";
import { Placeholder } from "@/pages/Placeholder";
import { Package } from "@/icons";

function NotFound() {
  return (
    <div className="mx-auto max-w-xl py-16">
      <ErrorState
        title="404 — Screen not found"
        description="That route doesn't exist in this workspace. Use the navigation to open a module, or return to Overview."
        debug="Check the URL or choose a screen from the workspace navigation."
      />
      <div className="mt-4 text-center">
        <Link to="/" className={buttonCls("primary", "md")}>
          Back to Overview
        </Link>
      </div>
    </div>
  );
}

import { useEffect, useState } from "react";
import Login from "@/pages/Login";
import { restoreSession, useAuth } from "@/lib/auth";
import { canonicalDashboardPath, isOutsideDashboard } from "@/lib/router-paths";
import { ErrorBoundary } from "@/components/ui/ErrorBoundary";

const dashboardBasename = import.meta.env.BASE_URL.replace(/\/+$/, "") || "/";

/* Owns the basename problem so no page can trip over it.
   <BrowserRouter> matches nothing when the path sits outside its basename, and
   "nothing" means zero DOM — a white screen with no error to explain it. Rather
   than trusting every caller to land on a canonical path, correct the path here
   and then re-render, so the router is only ever mounted on a URL it can match.
   If the path is unrelated to the dashboard entirely, fall back to its home.
   See lib/router-paths.ts for the matching rules. */
function DashboardRouter({ children }: { children: React.ReactNode }) {
  const [ready, setReady] = useState(false);
  useEffect(() => {
    const target = canonicalDashboardPath(dashboardBasename, location.pathname);
    if (target) window.history.replaceState(null, "", target + location.search + location.hash);
    else if (isOutsideDashboard(dashboardBasename, location.pathname)) {
      /* Some other off-basename path (stale bookmark, or a host that did not
         rewrite). Land on the dashboard home rather than rendering nothing. */
      window.history.replaceState(null, "", dashboardBasename + "/" + location.search);
    }
    setReady(true);
  }, []);
  if (!ready)
    return (
      <div className="grid min-h-screen place-items-center bg-surface-secondary">
        <p className="text-caption font-semibold text-muted">Opening the workspace…</p>
      </div>
    );
  return <BrowserRouter basename={dashboardBasename}>{children}</BrowserRouter>;
}

export default function App() {
  const { status } = useAuth();
  if (status === "loading")
    return (
      <div className="grid min-h-screen place-items-center bg-surface-secondary">
        <button onClick={() => void restoreSession()} className="rounded-card border border-hairline bg-white px-5 py-3 text-caption font-bold text-muted shadow-card hover:text-ink">Connecting to workspace… (tap to retry)</button>
      </div>);
  if (status === "out") return <Login />;
  return (
    <ToastProvider>
      <DashboardRouter>
        <ErrorBoundary label="the workspace">
          <Routes>
          <Route element={<AppShell />}>
            <Route index element={<Dashboard />} />
            <Route path="/crm" element={<Crm />} />
            <Route path="/clients" element={<Clients />} />
            <Route path="/companies" element={<Companies />} />
            <Route path="/meetings" element={<Meetings />} />
            <Route path="/quotations" element={<QuotationsList />} />
            <Route path="/quotations/new" element={<QuotationBuilder />} />
            <Route path="/catalog" element={<Catalog />} />
            <Route path="/catalog/new" element={<ProductEditor />} />
            <Route path="/catalog/:id/edit" element={<ProductEditor />} />
            <Route path="/catalog/:id" element={<ProductDetail />} />
            <Route path="/orders" element={<Orders />} />
            <Route path="/orders/:id" element={<OrderDetail />} />
            <Route path="/invoices" element={<Invoices />} />
            <Route path="/projects" element={<Projects />} />
            <Route path="/tasks" element={<Tasks />} />
            <Route path="/ui-components" element={<UIComponents />} />
            <Route path="/operations" element={<Operations />} />
            <Route path="/operations/:stage" element={<Operations />} />
            {/* Operations modules. Each has had a working API since 009-011 and
                no screen until now — the gap that made the whole backend look
                "not implemented" from the outside. */}
            <Route path="/suppliers" element={<Suppliers />} />
            <Route path="/purchase-orders" element={<PurchaseOrders />} />
            <Route path="/goods-receipts" element={<GoodsReceipts />} />
            <Route path="/supplier-invoices" element={<SupplierInvoices />} />
            <Route path="/production" element={<Production />} />
            <Route path="/stock-ledger" element={<StockLedger />} />
            <Route path="/warehouses" element={<Warehouses />} />
            <Route path="/stock-transfers" element={<StockTransfers />} />
            <Route path="/stock-reservations" element={<StockReservations />} />
            <Route path="/ledger" element={<Ledger />} />
            <Route path="/expenses" element={<Expenses />} />
            <Route path="/credit-notes" element={<CreditNotes />} />
            <Route path="/payment-schedules" element={<PaymentSchedules />} />
            <Route path="/analytics" element={<Analytics />} />
            <Route path="/settings" element={<Settings />} />
            <Route path="/ecommerce" element={<Ecommerce />} />
            <Route path="/website" element={<Website />} />
            <Route path="/website/theme" element={<ThemeStudio />} />
            <Route path="/website/edit/:id" element={<PageEditor />} />
            <Route path="/marketing" element={<Marketing />} />
            <Route path="/omnichannel" element={<Inbox />} />
            <Route
              path="/support"
              element={
                <Placeholder
                  crumbs={["Service", "Support"]}
                  title="Support & After-Sales"
                  description="Warranty claims, complaints and service tickets for delivered projects."
                  icon={<Package size={20} />}
                  planned="Phase 4 (Business Pack)"
                />
              }
            />
            {/* legacy/stray entry paths (old bookmarks, wx_return leftovers) → Overview */}
            {["/login", "/signin", "/dashboard", "/home", "/overview"].map((p) => (
              <Route key={p} path={p} element={<Navigate to="/" replace />} />
            ))}
            <Route path="*" element={<NotFound />} />
          </Route>
          </Routes>
        </ErrorBoundary>
      </DashboardRouter>
    </ToastProvider>
  );
}
