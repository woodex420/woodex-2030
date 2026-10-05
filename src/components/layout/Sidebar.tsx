import { useState } from "react";
import type { ComponentType } from "react";
import { Link, useLocation } from "react-router";
import { cn } from "@/lib/cn";
import { Logo } from "@/components/layout/Logo";
import {
  BarChart,
  Building,
  ChevronDown,
  ClipboardList,
  FolderKanban,
  Globe,
  LayoutDashboard,
  Megaphone,
  MessagesSquare,
  Settings,
  Store,
  Truck,
  Users,
  Wrench,
  X,
} from "@/icons";

type IconType = ComponentType<{ size?: number; className?: string }>;

type NavItem = { label: string; to: string; icon: IconType };
type NavGroup = { label: string; items: NavItem[] };

/* Primary navigation — design.md §4.2 (12 modules, exact order). */
const groups: NavGroup[] = [
  {
    label: "Workspace",
    items: [{ label: "Overview", to: "/", icon: LayoutDashboard }],
  },
  {
    label: "Sales",
    items: [
      { label: "CRM", to: "/crm", icon: Users },
      { label: "Sales & Quotations", to: "/quotations", icon: ClipboardList },
      { label: "Ecommerce", to: "/ecommerce", icon: Store },
      { label: "Website / CMS", to: "/website", icon: Globe },
      { label: "Marketing", to: "/marketing", icon: Megaphone },
      { label: "Omnichannel", to: "/omnichannel", icon: MessagesSquare },
    ],
  },
  {
    label: "Delivery",
    items: [
      { label: "Projects", to: "/projects", icon: FolderKanban },
      { label: "Production", to: "/operations/production", icon: Wrench },
      { label: "Delivery", to: "/operations/delivery", icon: Truck },
    ],
  },
  {
    label: "Insights",
    items: [
      { label: "Analytics", to: "/analytics", icon: BarChart },
      { label: "Settings", to: "/settings", icon: Settings },
    ],
  },
];

export function isActive(to: string, pathname: string) {
  if (to === "/") return pathname === "/";
  return pathname === to || pathname.startsWith(to + "/");
}

export function Sidebar({
  collapsed = false,
  mobileOpen = false,
  onCloseMobile,
  onToggleCollapsed,
}: {
  collapsed?: boolean;
  mobileOpen?: boolean;
  onCloseMobile?: () => void;
  onToggleCollapsed?: () => void;
}) {
  const { pathname } = useLocation();
  const [closedGroups, setClosedGroups] = useState<Record<string, boolean>>({});
  const toggleGroup = (g: string) => setClosedGroups((c) => ({ ...c, [g]: !c[g] }));

  const inner = (
    <div className="flex h-full min-h-0 flex-col bg-charcoal-950">
      {/* Brand header */}
      <div className={cn("flex h-16 shrink-0 items-center border-b border-white/6 px-4", collapsed && "px-0")}>
        <Logo collapsed={collapsed} />
        {!collapsed && (
          <div className="ml-auto flex items-center gap-1">
            <button
              onClick={onToggleCollapsed}
              aria-label="Collapse sidebar"
              className="hidden h-8 w-8 place-items-center rounded-control text-slate-400 transition-colors hover:bg-white/8 hover:text-white lg:grid"
            >
              «
            </button>
            <button
              onClick={onCloseMobile}
              aria-label="Close menu"
              className="grid h-8 w-8 place-items-center rounded-control text-slate-400 hover:bg-white/8 lg:hidden"
            >
              <X size={16} />
            </button>
          </div>
        )}
        {collapsed && (
          <button
            onClick={onToggleCollapsed}
            aria-label="Expand sidebar"
            className="absolute top-4 left-[60px] z-10 hidden h-7 w-7 place-items-center rounded-full border border-white/10 bg-charcoal-800 text-slate-300 hover:text-white lg:grid"
          >
            »
          </button>
        )}
      </div>

      {/* Workspace context — §4.2 */}
      <div className={cn("shrink-0 px-3 pt-3.5", collapsed && "px-2")}>
        <div
          className={cn(
            "flex items-center gap-2.5 rounded-card border border-white/8 bg-white/4 px-3 py-2.5",
            collapsed && "justify-center px-0 py-2"
          )}
          title="Workspace: Woodex Interiors"
        >
          <span className="grid h-6 w-6 shrink-0 place-items-center rounded-control bg-primary-500/20 text-caption font-bold text-primary-100">
            WI
          </span>
          {!collapsed && (
            <span className="min-w-0 flex-1">
              <span className="block truncate text-caption font-semibold text-white">Woodex Interiors</span>
              <span className="block text-caption text-slate-500">1 workspace · Live</span>
            </span>
          )}
          {!collapsed && <ChevronDown size={14} className="shrink-0 text-slate-500" />}
        </div>
      </div>

      {/* Navigation */}
      <nav aria-label="Primary" className="scrollbar-slim min-h-0 flex-1 overflow-y-auto px-3 py-3">
        {groups.map((g) => {
          const open = !closedGroups[g.label];
          return (
            <div key={g.label} className="mb-1.5">
              {!collapsed ? (
                <button
                  onClick={() => toggleGroup(g.label)}
                  aria-expanded={open}
                  className="group flex w-full items-center justify-between rounded-control px-2.5 py-1.5 text-caption font-semibold tracking-[0.08em] text-slate-500 uppercase transition-colors hover:text-slate-300"
                >
                  {g.label}
                  <ChevronDown
                    size={13}
                    className={cn("text-slate-600 transition-transform duration-150", !open && "-rotate-90")}
                  />
                </button>
              ) : (
                <div className="mx-auto my-2 h-px w-6 bg-white/10" />
              )}
              {open && (
                <ul className="mt-0.5 space-y-0.5">
                  {g.items.map((item) => {
                    const active = isActive(item.to, pathname);
                    const Icon = item.icon;
                    return (
                      <li key={item.label}>
                        <Link
                          to={item.to}
                          title={collapsed ? item.label : undefined}
                          onClick={onCloseMobile}
                          className={cn(
                            "group relative flex h-9 items-center gap-2.5 rounded-control px-2.5 text-small font-medium transition-all duration-150",
                            collapsed && "justify-center px-0",
                            active
                              ? "bg-primary-500 text-white shadow-cta hover:bg-primary-500"
                              : "text-slate-400 hover:bg-white/6 hover:text-white"
                          )}
                        >
                          <Icon size={18} className="shrink-0" />
                          {!collapsed && <span className="truncate">{item.label}</span>}
                        </Link>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
          );
        })}
      </nav>

      {/* Brand footer panel — §4.2 */}
      <div className={cn("shrink-0 border-t border-white/6 p-3", collapsed && "px-2")}>
        <div
          className={cn(
            "flex items-center gap-2.5 rounded-card bg-gradient-to-br from-primary-500/25 to-primary-700/10 px-3 py-2.5 ring-1 ring-white/8",
            collapsed && "justify-center px-1.5 py-2"
          )}
          title="Build beautiful spaces with Woodex"
        >
          <span className="grid h-8 w-8 shrink-0 place-items-center rounded-control bg-primary-500 text-white">
            <Building size={16} />
          </span>
          {!collapsed && (
            <span className="min-w-0">
              <span className="block text-caption font-semibold text-white">Build beautiful spaces</span>
              <span className="block text-caption text-slate-400">with Woodex</span>
            </span>
          )}
        </div>
      </div>
    </div>
  );

  return (
    <>
      {/* Desktop rail */}
      <aside
        className={cn(
          "sticky top-0 z-40 hidden h-screen shrink-0 transition-[width] duration-200 lg:block",
          collapsed ? "w-[76px]" : "w-[264px]"
        )}
        aria-label="Sidebar"
      >
        {inner}
      </aside>

      {/* Mobile drawer */}
      <div
        className={cn(
          "fixed inset-0 z-50 lg:hidden",
          mobileOpen ? "pointer-events-auto" : "pointer-events-none"
        )}
        aria-hidden={!mobileOpen}
      >
        <div
          onClick={onCloseMobile}
          className={cn(
            "absolute inset-0 bg-charcoal-950/50 transition-opacity duration-200",
            mobileOpen ? "opacity-100" : "opacity-0"
          )}
        />
        <div
          className={cn(
            "absolute inset-y-0 left-0 w-[280px] shadow-pop transition-transform duration-200 ease-out",
            mobileOpen ? "translate-x-0" : "-translate-x-full"
          )}
        >
          {inner}
        </div>
      </div>
    </>
  );
}
