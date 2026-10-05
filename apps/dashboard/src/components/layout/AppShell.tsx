import { useEffect, useState } from "react";
import { Outlet, useLocation } from "react-router";
import { Sidebar } from "@/components/layout/Sidebar";
import { Topbar } from "@/components/layout/Topbar";

/** Application shell — §4.1: sidebar + topbar + workspace content. */
export function AppShell() {
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const { pathname } = useLocation();

  useEffect(() => {
    setMobileOpen(false);
    window.scrollTo({ top: 0 });
  }, [pathname]);

  return (
    <div className="flex min-h-screen items-start bg-page">
      <Sidebar
        collapsed={collapsed}
        mobileOpen={mobileOpen}
        onCloseMobile={() => setMobileOpen(false)}
        onToggleCollapsed={() => setCollapsed((c) => !c)}
      />
      <div className="flex min-h-screen w-full min-w-0 flex-1 flex-col">
        <Topbar
          onOpenMobile={() => setMobileOpen(true)}
          collapsed={collapsed}
          onExpand={() => setCollapsed(false)}
        />
        <main className="w-full flex-1 px-4 py-5 lg:px-6 lg:py-6">
          <Outlet />
        </main>
        <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-line px-4 py-3 text-caption text-subtle lg:px-6">
          <span>© {new Date().getFullYear()} Woodex — Agency OS Core + Business Pack</span>
          <span>Design system v1.0 · White · Woodex Green · Charcoal</span>
        </footer>
      </div>
    </div>
  );
}
