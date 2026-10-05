import { BrowserRouter, Link, Route, Routes } from "react-router";
import { ToastProvider } from "@/components/ui/Toast";
import { AppShell } from "@/components/layout/AppShell";
import { ErrorState } from "@/components/ui/States";
import { buttonCls } from "@/components/ui/Button";
import Dashboard from "@/pages/Dashboard";
import Crm from "@/pages/Crm";
import { QuotationsList, QuotationBuilder } from "@/pages/Quotations";
import Catalog from "@/pages/Catalog";
import Projects from "@/pages/Projects";
import Operations from "@/pages/Operations";
import Analytics from "@/pages/Analytics";
import Settings from "@/pages/Settings";
import { Placeholder } from "@/pages/Placeholder";
import { Globe, Megaphone, MessagesSquare, Package, Store } from "@/icons";

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

export default function App() {
  return (
    <ToastProvider>
      <BrowserRouter>
        <Routes>
          <Route element={<AppShell />}>
            <Route index element={<Dashboard />} />
            <Route path="/crm" element={<Crm />} />
            <Route path="/quotations" element={<QuotationsList />} />
            <Route path="/quotations/new" element={<QuotationBuilder />} />
            <Route path="/catalog" element={<Catalog />} />
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
            <Route
              path="/website"
              element={
                <Placeholder
                  crumbs={["Digital", "Website / CMS"]}
                  title="Website / CMS"
                  description="Page builder, templates and multi-site publishing."
                  icon={<Globe size={20} />}
                  planned="Phase 4 (visual builder §30)"
                />
              }
            />
            <Route
              path="/marketing"
              element={
                <Placeholder
                  crumbs={["Digital", "Marketing"]}
                  title="Marketing"
                  description="Campaigns, automations and AI-assisted drafts behind review gates."
                  icon={<Megaphone size={20} />}
                  planned="Phase 4"
                  to="/analytics"
                  targetLabel="Campaign analytics (live)"
                />
              }
            />
            <Route
              path="/omnichannel"
              element={
                <Placeholder
                  crumbs={["Digital", "Omnichannel"]}
                  title="Omnichannel"
                  description="WhatsApp, calls, email and inbox unified conversations."
                  icon={<MessagesSquare size={20} />}
                  planned="Phase 4"
                />
              }
            />
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
            <Route path="*" element={<NotFound />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </ToastProvider>
  );
}
