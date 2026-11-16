# 05 — Admin Information Architecture and UX Specification

## 1. Experience goal

Replace the current long, noisy menu with a focused operating workspace. The new UI should feel premium, calm, data-dense where needed and consistent across every module.

## 2. Canonical navigation proposal

### WORKSPACE
- Dashboard
- Approvals
- Reports

### CUSTOMERS
- CRM
  - Leads
  - Contacts
  - Tasks & Visits
- Inbox
  - Conversations
  - Chat Bot (only when enabled)

### SALES
- Estimations
- Quotations
- Invoices
- Payments
- Documents

### COMMERCE
- Store Overview
- Products
- Categories
- Series
- Orders
- Delivery & Installation
- Support & Warranty

### OPERATIONS
- Operations Board
- Projects
- Vendors & Costs
- Client Portal
- Handovers

### PROCUREMENT & PRODUCTION
- Suppliers
- Purchase Orders
- Goods Receipts
- Supplier Invoices
- Production
- Stock Ledger
- Warehouses
- Stock Transfers
- Reservations

### ACCOUNTS
- Ledger & Reports
- Payables
- Income & Expenses

### GROWTH
- Automations
- Sequences
- WhatsApp Templates
- Marketing

### WEBSITE STUDIO
- Site Overview
- All Pages
- Visual Page Builder
- Navigation (header, footer, mega menu)
- Reusable Sections & Templates
- Storefront / Shop Design
- Blog
- Portfolio
- Forms
- Media Library
- Theme & Design System
- SEO
- SEO Agent
- Speed & Performance
- Site Health
- Website Settings
- Redirects

### SYSTEM
- Settings
- Access & Team
- Connections
- Platform
- Audit & Activity

### WORKSPACE
- Workspace Preferences
- Team Onboarding

## 3. Sidebar interaction rules

- One canonical navigation registry with stable route IDs.
- Filter entries by permissions and feature availability.
- Hide unavailable destinations from normal navigation; list roadmap items in a separate release/feature-status view.
- No repeated “Soon” badges and no “Legacy” labels in normal navigation.
- “Legacy” belongs in migration metadata and admin diagnostics.
- Collapsible groups; active route opens its parent group.
- Search across page title, keywords and route aliases.
- Command palette via `Ctrl/Cmd + K`.
- Pinned/favourite destinations.
- Recently visited screens.
- Desktop expanded and compact modes.
- Mobile drawer with clear close/focus behavior.
- Keyboard navigation and accessible active/focus states.
- Sidebar state should persist per user only if privacy and storage policy allow it.

## 4. Global application shell

### Sidebar
- WOODEX OS wordmark and workspace identity.
- Search / command trigger.
- Grouped navigation.
- Optional pinned/favourites.
- User/workspace footer with settings access.

### Topbar
- Breadcrumbs and page title context.
- Global search / command palette.
- Quick-create menu.
- Notifications with unread count.
- Help/support entry.
- User menu and workspace preferences.

### Main content
- Page heading and concise description.
- Primary action aligned consistently.
- Contextual filters/date range.
- Main content grid.
- Secondary detail panel only when it improves workflow.
- Responsive collapse behavior.

## 5. Global visual design tokens

### Brand direction
- Premium neutral workspace.
- Charcoal navigation.
- White surfaces.
- Restrained WOODEX green for primary actions and selected states.
- Semantic status colors only for meaningful states.

### Initial tokens (subject to contrast validation)

| Token | Value |
|---|---|
| `color.primary` | `#4F9D21` |
| `color.primaryHover` | `#43851C` |
| `color.sidebar` | `#171B1E` |
| `color.text` | `#1D2226` |
| `color.textSecondary` | `#66717A` |
| `color.muted` | `#7B858D` |
| `color.canvas` | `#F6F7F8` |
| `color.surface` | `#FFFFFF` |
| `color.border` | `#E5E8EA` |
| `color.success` | `#2E8B57` |
| `color.warning` | `#D99A24` |
| `color.danger` | `#D9534F` |
| `color.info` | `#3578B8` |

### Typography
Use Inter as the initial shared font direction.

| Role | Size |
|---|---:|
| Display | 32–40px |
| Page title | 26–30px |
| Section title | 18–22px |
| Card title | 14–16px |
| Body | 14–16px |
| Dense table | 13–14px |
| Caption | 12px minimum |

### Geometry
- Controls: 8px radius.
- Cards: 12px radius.
- Panels: 14–16px radius.
- 1px neutral borders.
- Subtle shadows; avoid stacked heavy shadows.
- Spacing scale: 4, 8, 12, 16, 20, 24, 32, 40, 48px.

## 6. Component library requirements

Create shared standards for:
- Buttons, icon buttons, links, split buttons.
- Inputs, selects, comboboxes, date controls and search.
- Cards, KPI cards, tabs, accordions and drawers.
- Tables with sort, filters, pagination, selection and bulk actions.
- Status badges, progress indicators, timelines and activity feeds.
- Dialogs, confirmations, toasts and inline validation.
- Page headers, breadcrumbs, action bars and filter bars.
- Empty, loading, error, no-permission and offline states.
- File upload, media picker and document preview.

Do not build a separate style for every module unless there is a clear domain need.

## 7. Dashboard layout

### Business snapshot
- Sales value / orders (metric definition to be confirmed).
- Open quotations.
- Collections and outstanding invoices.
- Active projects/orders.
- Pending approvals.
- Operational exceptions.

### Sales funnel
- New leads.
- Qualified leads.
- Estimates.
- Quotations sent.
- Approved/accepted quotations.
- Conversion rate and time period.

### Finance
- Invoiced.
- Collected.
- Outstanding.
- Overdue aging.
- Payables due.

### Commerce and operations
- Orders by status.
- Production queue.
- Delivery/installation schedule.
- Stock exceptions.
- Pending purchase receipts.

### Projects
- Project health.
- Upcoming milestones.
- Budget variance.
- Handover status.

### Activity and actions
- Recent business events.
- Pending approvals.
- Quick-create actions.
- Integration/automation failures requiring attention.

Every KPI must link to filtered records. Every chart must disclose date range, currency and metric definition. All values must come from APIs, not placeholders.

## 8. Standard page patterns

### List page
- Page title, description and primary action.
- Search and filters.
- Saved views where useful.
- Sortable, paginated table.
- Bulk actions gated by permission.
- Empty state with useful next step.

### Detail page
- Record identity and status.
- Primary action.
- Summary fields.
- Related records.
- Activity timeline.
- Documents and communication.
- Permission-aware tabs.

### Create/edit flow
- Group related fields.
- Clear required markers.
- Inline validation.
- Save draft versus submit/issue/publish actions separated.
- Unsaved-change warning.
- Server errors shown near the relevant field.

### Approval flow
- Show requester, amount/impact, evidence and history.
- Approve/reject with optional/required reason according to policy.
- Do not let an approver approve their own action where separation of duties is required.

## 9. Responsive and accessibility

- Desktop: full navigation and data tables.
- Tablet: compact navigation and fewer grid columns.
- Mobile: drawer navigation, stacked metrics, table-to-card treatment for key workflows.
- Visible focus ring.
- Keyboard-operable menus/dialogs.
- Labelled controls and meaningful error announcements.
- Sufficient contrast.
- Do not use color alone to communicate status.

## 10. UX acceptance

- A new staff member can find a common workflow using search or the sidebar.
- The sidebar shows only permitted and available screens.
- No duplicate destinations for the same action.
- Dashboard metrics are traceable to records.
- Core create/edit/approve flows have loading, success and error feedback.
- Mobile layouts do not hide primary actions or critical record data.
- Shared components look and behave consistently.
