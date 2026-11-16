# Roles and capabilities

Generated from the code at commit `9b8cdca` by `docs/furniture-2030/evidence/generate-inventory.mjs`. Do not edit by hand; re-run the script.

Roles are fixed in code (`ROLE_CAPS` in `apps/api/server.mjs`); they are not yet configurable from the dashboard. A capability guards a group of API paths. "Routes reachable" counts API routes the role passes at the gate, including public and sign-in routes.

| Role | Capabilities | Routes reachable (of 214) |
|---|---|---|
| owner | `*` | 214 |
| editor | `page.manage`, `theme.manage`, `catalog.manage` | 47 |
| sales | `crm.manage`, `quote.manage`, `order.manage`, `project.manage` | 70 |
| finance | `finance.manage`, `quote.view-move`, `order.view-move` | 39 |
| purchasing | `purchasing.manage`, `catalog.view` | 34 |
| production | `production.manage`, `catalog.view` | 33 |
| viewer | none | 25 |

## Capability to routes

| Capability | Routes | Held by | Example paths |
|---|---|---|---|
| `catalog.manage` | 3 | owner, editor | `/api/products` |
| `crm.manage` | 20 | owner, sales | `/api/leads`, `/api/clients`, `/api/tasks`, `/api/site-visits`, `/api/inbox` |
| `finance.manage` | 14 | owner, finance | `/api/supplier-invoices`, `/api/invoices`, `/api/returns` |
| `order.manage` | 3 | owner, sales | `/api/orders` |
| `page.manage` | 17 | owner, editor | `/api/pages`, `/api/saved-sections`, `/api/blocks`, `/api/media` |
| `production.manage` | 8 | owner, production | `/api/boms`, `/api/job-cards` |
| `project.manage` | 13 | owner, sales | `/api/projects` |
| `purchasing.manage` | 9 | owner, purchasing | `/api/suppliers`, `/api/purchase-orders`, `/api/goods-receipts` |
| `purge` | 1 | owner | `/api/track` |
| `quote.manage` | 9 | owner, sales | `/api/quote-templates`, `/api/quotes` |
| `theme.manage` | 2 | owner, editor | `/api/theme`, `/api/target` |
| `users.manage` | 6 | owner | `/api/users` |
| `owner-only` | 84 | owner | `/api/diagnostics`, `/api/stock-movements`, `/api/warehouses`, `/api/storage-locations`, `/api/stock-levels`, `/api/stock-transfers` |

Capabilities granted to a role but not returned by `capsFor()` for any route: `quote.view-move`, `order.view-move`, `catalog.view` (checked inside handlers, if at all).
