# Dashboard Overview `[planned]`

Admin dashboard for WOODEX operations. Not yet built — this is the target spec.

## Access Model
Roles table (`app_role` enum): `admin`, `sales`, `editor`.

| Capability | admin | sales | editor |
|---|---|---|---|
| Manage users / roles | ✅ | ❌ | ❌ |
| CRM (leads, quotes) | ✅ | ✅ | ❌ |
| Orders | ✅ | ✅ | ❌ |
| Products / Series | ✅ | ❌ | ✅ |
| Blog / Projects | ✅ | ❌ | ✅ |
| Analytics | ✅ | ✅ (own pipeline) | ❌ |

## Layout
- Left sidebar: Dashboard, CRM, Quotes, Orders, Products, Projects, Blog, Analytics, Settings.
- Top bar: search, notifications, user menu.
- Content: table + drawer/modal pattern for edits.

## Tech
- Same React/Tailwind stack.
- Route prefix `/admin/*`, gated by `has_role`.
- Data via Supabase JS client + React Query.

## Sub-docs
- [CRM](./crm.md)
- [Quotes](./quotes.md)
- [Orders](./orders.md)
- [Projects](./projects.md)
- [Products](./products.md)
- [Blog](./blog.md)
- [Analytics](./analytics.md)
