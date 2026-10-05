import { BrowserRouter, Link, Navigate, Route, Routes } from "react-router";
import { ToastProvider } from "@/components/ui/Toast";
import { AppShell } from "@/components/layout/AppShell";
import { ErrorState } from "@/components/ui/States";
import { buttonCls } from "@/components/ui/Button";
import Dashboard from "@/pages/Dashboard";
import Crm from "@/pages/Crm";
import { QuotationsList, QuotationBuilder } from "@/pages/Quotations";
import Catalog from "@/pages/Catalog";
import Clients from "@/pages/Clients";
import Website, { PageEditor } from "@/pages/Website";
import ThemeStudio from "@/pages/ThemeStudio";
import Marketing from "@/pages/Marketing";
import Inbox from "@/pages/Inbox";
import Invoices from "@/pages/Invoices";
import Projects from "@/pages/Projects";
import Operations from "@/pages/Operations";
import Analytics from "@/pages/Analytics";
import Settings from "@/pages/Settings";
import { Placeholder } from "@/pages/Placeholder";
import { Package, Store } from "@/icons";

function NotFound() {
  return (
    <div className="mx-auto max-w-xl py-16">
      <ErrorState
        title="404 — Screen not found"
        description="That route doesn't exist in this workspace. The navigation covers the seven Phase-3 priority screens."
        debug="design.md §34 — initial screen priority"
      />
      <div className="mt-4 text-center">
        <Link to="/" className={buttonCls("primary", "md")}>
          Back to Overview
        </Link>
      </div>
    </div>
  );
}

import Login from "@/pages/Login";
import { restoreSession, useAuth } from "@/lib/auth";

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
      <BrowserRouter>
        <Routes>
          <Route element={<AppShell />}>
            <Route index element={<Dashboard />} />
            <Route path="/crm" element={<Crm />} />
            <Route path="/clients" element={<Clients />} />
            <Route path="/quotations" element={<QuotationsList />} />
            <Route path="/quotations/new" element={<QuotationBuilder />} />
            <Route path="/catalog" element={<Catalog />} />
            <Route path="/invoices" element={<Invoices />} />
            <Route path="/projects" element={<Projects />} />
            <Route path="/operations" element={<Operations />} />
            <Route path="/operations/:stage" element={<Operations />} />
            <Route path="/analytics" element={<Analytics />} />
            <Route path="/settings" element={<Settings />} />
            <Route
              path="/ecommerce"
              element={
                <Placeholder
                  crumbs={["Sales", "Ecommerce"]}
                  title="Ecommerce"
                  description="Storefront orders, marketplace sync and commerce operations."
                  icon={<Store size={20} />}
                  planned="Phase 4"
                  to="/catalog"
                  targetLabel="Open Catalog (built)"
                />
              }
            />
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
      </BrowserRouter>
    </ToastProvider>
  );
}
