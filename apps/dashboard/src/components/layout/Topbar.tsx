import { useEffect, useState } from "react";
import { Link } from "react-router";
import { cn } from "@/lib/cn";
import { logout, useAuth } from "@/lib/auth";
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
import { useApi, timeAgo, useRealtime, useRealtimeStatus, type ApiStats, type RtEvent } from "@/lib/api";
import { CommandPalette } from "@/components/CommandPalette";
import {
  Bell,
  Check,
  ChevronsLeft,
  FileText,
  LogOut,
  Menu,
  Search,
  Settings,
  ShoppingBag,
  User,
} from "@/icons";

type Note = { id: string; title: string; desc: string; time: string; tone: keyof typeof chipTone };

const TONE_BY_TYPE: Record<string, Note["tone"]> = {
  leads: "info", quotes: "primary", orders: "warning", invoices: "success", payments: "success", returns: "danger", products: "muted", clients: "primary", tasks: "neutral",
};

function UserChip() {
  const { user } = useAuth();
  if (!user) return null;
  return (
    <span className="flex items-center gap-1.5 rounded-full border border-line bg-white py-0.5 pr-1 pl-0.5" title={user.email + " · role " + user.role}>
      <span className="grid h-7 w-7 place-items-center rounded-full bg-primary-600 text-[10px] font-black text-white">{user.name.split(" ").map((w) => w[0]).slice(0, 2).join("")}</span>
      <span className="hidden text-left md:block">
        <span className="block max-w-28 truncate text-[11px] font-bold leading-tight text-ink">{user.name}</span>
        <span className="block text-[9px] font-semibold uppercase tracking-wide text-subtle">{user.role}</span>
      </span>
      <button onClick={() => void logout()} aria-label="Sign out" className="grid h-7 w-7 place-items-center rounded-full text-slate-500 transition-colors hover:bg-danger-soft hover:text-danger-strong">
        <LogOut size={13} />
      </button>
    </span>
  );
}

export function Topbar({
  onOpenMobile,
  collapsed,
  onExpand,
}: {
  onOpenMobile: () => void;
  collapsed: boolean;
  onExpand: () => void;
}) {
  const [palette, setPalette] = useState(false);
  const [liveNotes, setLiveNotes] = useState<Note[]>([]);
  const [unread, setUnread] = useState(0);
  const rt = useRealtimeStatus();
  const { data: stats } = useApi<ApiStats>("/api/stats", 60000);
  const { push } = useToast();

  /* ⌘K / Ctrl+K opens the command palette — §05 "command/search shortcut" */
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        setPalette((v) => !v);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  /* SSE events land at the top of the notification stack */
  useRealtime(/leads|quotes|orders|invoices|payments|returns|products|clients|tasks/, (ev: RtEvent) => {
    setLiveNotes((prev) => [{ id: ev.type + Date.now(), title: ev.title ?? "Update", desc: [ev.who, ev.context].filter(Boolean).join(" · ") || "changed in the shared backend", time: "just now", tone: TONE_BY_TYPE[ev.type] ?? "neutral" }, ...prev].slice(0, 14));
    setUnread((u) => u + 1);
  });

  const recentNotes: Note[] = (stats?.recent ?? []).slice(0, 8).map((r, i) => ({
    id: "r" + i, title: r.title, desc: [r.who, r.context].filter(Boolean).join(" · "),
    time: r.time ? timeAgo(r.time) : "just now", tone: TONE_BY_TYPE[r.type] ?? "neutral",
  }));
  const notes = [...liveNotes, ...recentNotes.filter((n) => !liveNotes.some((l) => l.title === n.title))];

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

      {/* Command palette trigger (styled like the global search) */}
      <button
        onClick={() => setPalette(true)}
        aria-label="Open command palette"
        className="group relative hidden max-w-md flex-1 items-center md:flex"
      >
        <span className="flex h-10 w-full items-center gap-2 rounded-control border border-line-strong bg-slate-50 pr-14 pl-9 text-left text-small text-subtle transition-colors group-hover:border-slate-400 group-hover:bg-white">
          <Search size={14} className="-ml-5 text-subtle" />
          Search leads, quotes, orders, invoices, products…
        </span>
        <kbd className="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 rounded border border-line bg-white px-1.5 py-0.5 font-sans text-[10px] font-medium text-subtle">
          ⌘K
        </kbd>
      </button>

      <span className="flex-1 md:hidden" />

      {/* Right cluster */}
      <div className="flex items-center gap-1.5 lg:gap-2">
        <UserChip />

        {/* Realtime status */}
        <span
          title={rt === "live" ? "Live sync connected (SSE)" : rt === "down" ? "Live sync down — 60s polling keeps data fresh" : "Connecting…"}
          className={cn(
            "hidden items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide sm:inline-flex",
            rt === "live" ? "bg-success-soft text-success-strong" : rt === "down" ? "bg-danger-soft text-danger-strong" : "bg-slate-100 text-slate-500"
          )}
        >
          <span className={cn("h-1.5 w-1.5 rounded-full", rt === "live" ? "animate-pulse bg-success" : rt === "down" ? "bg-danger" : "bg-slate-400")} />
          {rt === "live" ? "live" : rt === "down" ? "reconnecting" : "connect…"}
        </span>

        {/* Notifications — live events on top */}
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
                onClick={() => { setUnread(0); push({ tone: "primary", title: "All notifications marked as read" }); }}
                className="inline-flex items-center gap-1 text-caption font-medium text-primary-600 hover:text-primary-700"
              >
                <Check size={13} /> Mark all read
              </button>
            </div>
            <ul className="max-h-[320px] divide-y divide-slate-100 overflow-y-auto scrollbar-slim">
              {notes.length === 0 && <li className="px-4 py-6 text-center text-caption text-subtle">Quiet right now — events from the storefront and the board land here.</li>}
              {notes.map((n) => (
                <li key={n.id}>
                  <span className="flex gap-3 px-4 py-3 transition-colors">
                    <span className={cn("mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full", chipTone[n.tone])}>
                      <span className="h-1.5 w-1.5 rounded-full bg-current opacity-70" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-small font-semibold text-ink">{n.title}</span>
                      <span className="block truncate text-caption text-muted">{n.desc}</span>
                    </span>
                    <span className="shrink-0 text-caption whitespace-nowrap text-subtle">{n.time}</span>
                  </span>
                </li>
              ))}
            </ul>
            <div className="border-t border-line px-4 py-2.5 text-center">
              <Link to="/analytics" className="text-caption font-medium text-primary-600 hover:underline">View all activity</Link>
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
              {stats?.finance.deals.open ?? 0}
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
          <DropdownItem onClick={() => push({ tone: "info", title: "Profile editor arrives with RBAC (P2 auth)" })}>
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

      <CommandPalette open={palette} onClose={() => setPalette(false)} />
    </header>
  );
}
