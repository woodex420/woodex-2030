# Woodex Agency OS — Frontend

Dashboard application UI implementing **design.md v1.0** (Woodex Agency OS + Woodex Business Pack).
TailAdmin is a UX reference only (§32) — this codebase contains **no cloned TailAdmin source, assets, or branding**.

## Stack

- React 19 + TypeScript (strict) + Vite 8
- Tailwind CSS v4 via `@tailwindcss/vite` — design tokens live in `src/index.css` under `@theme`
- `react-router` (client routing) — no UI kit, no chart library (dependency-free SVG charts)

## Design system mapping

| Spec | Implementation |
|---|---|
| §02 palette | `@theme` tokens: `primary-500 #4F9D21`, charcoal, slate, semantic colors (+`-strong`/`-soft` variants for accessible text) |
| §03 typography | Inter + token scale `text-display / h1 / h2 / h3 / bodylg / small / caption` |
| §04/§05 shell | `AppShell` → dark `Sidebar` (12 nav modules, collapsible, groups, workspace chip, brand footer) + white `Topbar` (⌘K search, notifications, cart, **E-Quotation CTA**, user menu) |
| §06 workspace context | `WorkspaceContextBar`: greeting, workspace switcher, website link, date range |
| §07/§08 dashboard | KPI row (6), revenue/orders chart with hover tooltip, quotation pipeline, recent leads table, production & delivery cards, activity feed |
| §09 screens | `/crm` kanban (drag & drop), `/quotations/new` E-Quotation builder (lines, totals, approval bar, audit trail), `/catalog`, `/projects` (8-tab workspace incl. BOQ, milestones, sign-off), `/operations/:stage`, `/analytics` |
| §13 tables | `DataTable` — sticky header, hover, 52px rows, pagination |
| §14 status system | `StatusBadge` — single mapping for Lead / Quotation / Invoice / Operations states (dot + label, never color alone) |
| §15/§16 | 8px grid utilities, `rounded-card` 10px / `rounded-panel` 12px |
| §19 a11y | focus-visible rings, roles (`tablist`, `progressbar`, `switch`, `dialog`, `aria-live`), semantic landmarks, labeled controls |
| §26 AI UX | AI actions are surfaced as *review-gated* suggestions (toast + audit note), never autonomous |
| §27 states | `EmptyState`, `Skeleton/SkeletonRows`, `ErrorState` components + 404 + placeholder screens |

## Run

```bash
npm install
npm run dev      # Vite dev server (binds 0.0.0.0:5173, allowed hosts incl. .e2b.app preview)
npm run build    # tsc -b && vite build
```

## Layout

```
src/
  index.css              # design tokens (§21) — single source of truth
  lib/cn.ts              # class merging + formatters
  icons/                 # original Lucide-style outline icon set (§17)
  data/mock.ts           # operational mock data + status maps
  components/
    ui/                  # Button, Card, Badge/StatusBadge, Field, Tabs, Dropdown,
                         # Modal, Toast, DataTable, Progress, Avatar, Switch, States
    charts/              # LineAreaChart, BarList, Donut (dependency-free SVG)
    layout/              # Logo, Sidebar, Topbar, PageHeader, AppShell
    dashboard.tsx        # KPI row, pipeline, ops cards, activity feed, context bar
  pages/                 # Dashboard, Crm, Quotations, Catalog, Projects, Operations,
                         # Analytics, Settings, Placeholder
```

Next phases per design.md §34: validate these 7 screens, then extend to Ecommerce, CMS, Marketing, Omnichannel, Automation, Support, Settings and the Visual Builder (§30).
