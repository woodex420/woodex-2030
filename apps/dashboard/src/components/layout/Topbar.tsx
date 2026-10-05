import { useEffect, useRef, useState } from "react";
import { Link } from "react-router";
import { cn } from "@/lib/cn";
import { buttonCls } from "@/components/ui/Button";
import { Avatar } from "@/components/ui/Avatar";
import { Badge, chipTone } from "@/components/ui/Badge";
import {
  Dropdown,
  DropdownDivider,
  DropdownItem,
  DropdownLabel,
} from "@/components/ui/Dropdown";
import { useToast } from "@/components/ui/Toast";
import { notifications } from "@/data/mock";
import {
  Bell,
  Check,
  ChevronsLeft,
  FileText,
  LogOut,
  Menu,
  Settings,
  ShoppingBag,
  User,
} from "@/icons";

export function Topbar({
  onOpenMobile,
  collapsed,
  onExpand,
}: {
  onOpenMobile: () => void;
  collapsed: boolean;
  onExpand: () => void;
}) {
  const searchRef = useRef<HTMLInputElement>(null);
  const [unread, setUnread] = useState(notifications.length);
  const { push } = useToast();

  /* ⌘K / Ctrl+K focuses global search — §05 "command/search shortcut" */
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        searchRef.current?.focus();
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  return (
    <header className="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-2 border-b border-line bg-white px-4 lg:gap-3 lg:px-6">
      <button
        onClick={onOpenMobile}
        aria-label="Open menu"
        className="grid h-9 w-9 place-items-center rounded-control text-slate-600 hover:bg-slate-100 lg:hidden"
      >
        <Menu size={20} />
      </button>
      {collapsed && (
        <button
          onClick={onExpand}
          aria-label="Expand sidebar"
          className="hidden h-9 w-9 place-items-center rounded-control text-slate-600 hover:bg-slate-100 lg:grid"
        >
          <ChevronsLeft size={18} />
        </button>
      )}

      {/* Global search */}
      <div className="relative hidden max-w-md flex-1 md:block">
        <label className="sr-only" htmlFor="global-search">
          Global search
        </label>
        <input
          id="global-search"
          ref={searchRef}
          type="search"
          placeholder="Search leads, quotations, projects…"
          className="h-10 w-full rounded-control border border-line-strong bg-slate-50 pr-14 pl-9 text-small text-ink transition-colors placeholder:text-subtle focus:border-primary-500 focus:bg-white focus:ring-2 focus:ring-primary-500/20 focus:outline-none"
        />
        <svg
          className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-subtle"
          width="15"
          height="15"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          aria-hidden
        >
          <circle cx="11" cy="11" r="7" />
          <path d="m20 20-3.8-3.8" />
        </svg>
        <kbd className="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 rounded border border-line bg-white px-1.5 py-0.5 font-sans text-[10px] font-medium text-subtle">
          ⌘K
        </kbd>
      </div>

      <div className="flex-1 md:hidden" />

      {/* Right cluster */}
      <div className="flex items-center gap-1.5 lg:gap-2">
        {/* Notifications */}
        <Dropdown
          align="right"
          panelClassName="w-[min(92vw,360px)]"
          trigger={(open) => (
            <button
              aria-label={`Notifications${unread ? `, ${unread} unread` : ""}`}
              className={cn(
                "relative grid h-9 w-9 place-items-center rounded-control text-slate-600 transition-colors hover:bg-slate-100",
                open && "bg-slate-100 text-ink"
              )}
            >
              <Bell size={19} />
              {unread > 0 && (
                <span className="absolute top-1.5 right-1.5 grid h-4 min-w-4 place-items-center rounded-full bg-danger px-0.5 text-[9px] font-bold text-white ring-2 ring-white">
                  {unread}
                </span>
              )}
            </button>
          )}
        >
          <div onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between border-b border-line px-4 py-3">
              <p className="text-bodylg font-semibold text-ink">Notifications</p>
              <button
                onClick={() => {
                  setUnread(0);
                  push({ tone: "primary", title: "All notifications marked as read" });
                }}
                className="inline-flex items-center gap-1 text-caption font-medium text-primary-600 hover:text-primary-700"
              >
                <Check size={13} /> Mark all read
              </button>
            </div>
            <ul className="max-h-[320px] divide-y divide-slate-100 overflow-y-auto scrollbar-slim">
              {notifications.map((n) => (
                <li key={n.id}>
                  <Link
                    to="/analytics"
                    className="flex gap-3 px-4 py-3 transition-colors hover:bg-slate-50"
                  >
                    <span className={cn("mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full", chipTone[n.tone])}>
                      <span className={cn("h-1.5 w-1.5 rounded-full", `bg-current`, "opacity-70")} />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-small font-semibold text-ink">{n.title}</span>
                      <span className="block truncate text-caption text-muted">{n.desc}</span>
                    </span>
                    <span className="shrink-0 text-caption whitespace-nowrap text-subtle">{n.time}</span>
                  </Link>
                </li>
              ))}
            </ul>
            <div className="border-t border-line px-4 py-2.5 text-center">
              <span className="text-caption font-medium text-primary-600">View all activity</span>
            </div>
          </div>
        </Dropdown>

        {/* Commerce shortcut */}
        <Link
          to="/ecommerce"
          aria-label="Commerce"
          className="relative hidden h-9 w-9 place-items-center rounded-control text-slate-600 transition-colors hover:bg-slate-100 sm:grid"
        >
          <ShoppingBag size={19} />
          <span className="absolute -top-0.5 -right-0.5">
            <Badge tone="primary" dot={false} className="px-1.5 py-0 text-[9px]">
              3
            </Badge>
          </span>
        </Link>

        <span aria-hidden className="mx-1 hidden h-6 w-px bg-line sm:block" />

        {/* E-Quotation primary CTA — §05 */}
        <Link to="/quotations/new" className={buttonCls("primary", "md", "hidden sm:inline-flex")}>
          <FileText size={16} />
          E-Quotation
        </Link>
        <Link
          to="/quotations/new"
          aria-label="New E-Quotation"
          className={buttonCls("primary", "md", "px-2.5 sm:hidden")}
        >
          <FileText size={17} />
        </Link>

        <span aria-hidden className="mx-0.5 hidden h-6 w-px bg-line lg:block" />

        {/* User menu */}
        <Dropdown
          align="right"
          panelClassName="w-64"
          trigger={(open) => (
            <button
              aria-label="Account menu"
              className={cn(
                "flex items-center gap-2.5 rounded-control py-1 pr-2 pl-1 transition-colors duration-150 hover:bg-slate-100",
                open && "bg-slate-100"
              )}
            >
              <Avatar initials="AR" size="md" tone="primary" />
              <span className="hidden text-left lg:block">
                <span className="block max-w-32 truncate text-caption font-semibold text-ink">Ayesha Rehman</span>
                <span className="block text-caption text-subtle">Admin · Woodex</span>
              </span>
            </button>
          )}
        >
          <div className="flex items-center gap-3 px-4 py-3.5">
            <Avatar initials="AR" size="lg" tone="primary" />
            <div className="min-w-0">
              <p className="truncate text-small font-semibold text-ink">Ayesha Rehman</p>
              <p className="truncate text-caption text-muted">ayesha@woodexagency.com</p>
            </div>
          </div>
          <DropdownDivider />
          <DropdownLabel>Account</DropdownLabel>
          <DropdownItem onClick={() => push({ tone: "info", title: "Profile editor (Phase 3)" })}>
            <User size={15} className="text-slate-500" /> My profile
          </DropdownItem>
          <DropdownItem>
            <Link to="/settings" className="flex w-full items-center gap-2.5">
              <Settings size={15} className="text-slate-500" /> Workspace settings
            </Link>
          </DropdownItem>
          <DropdownDivider />
          <DropdownItem tone="danger" onClick={() => push({ tone: "warning", title: "Signed out (demo action)" })}>
            <LogOut size={15} /> Sign out
          </DropdownItem>
        </Dropdown>
      </div>
    </header>
  );
}
