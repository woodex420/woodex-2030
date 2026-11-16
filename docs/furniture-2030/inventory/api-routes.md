# API route inventory

Generated from the code at commit `9b8cdca` by `docs/furniture-2030/evidence/generate-inventory.mjs`. Do not edit by hand; re-run the script.

**214 routes** in `apps/api/server.mjs`. "Access" is what the server's own `isPublic()` and `capsFor()` return for the route; `owner-only` means no capability is mapped, so only the owner role reaches it. Handlers may apply further checks.

## Summary by module

| Module | Routes | Public | Owner-only |
|---|---|---|---|
| CRM | 30 | 2 | 14 |
| Catalogue | 9 | 6 | 0 |
| Delivery and installation | 13 | 0 | 13 |
| Finance | 20 | 0 | 15 |
| Inbox and messaging | 6 | 0 | 0 |
| Inventory | 18 | 0 | 18 |
| Marketing and analytics | 9 | 1 | 6 |
| Orders and returns | 8 | 2 | 0 |
| Other | 1 | 0 | 1 |
| Payables | 6 | 0 | 0 |
| Platform (auth, users, audit) | 18 | 4 | 5 |
| Procurement | 9 | 0 | 0 |
| Production and quality | 8 | 0 | 0 |
| Projects and BOQ | 13 | 0 | 0 |
| Quotations | 10 | 1 | 0 |
| Support and warranty | 12 | 0 | 12 |
| Website and CMS | 24 | 6 | 0 |

## Summary by access rule

| Access | Routes |
|---|---|
| `owner-only` | 84 |
| `public` | 22 |
| `crm.manage` | 20 |
| `page.manage` | 17 |
| `finance.manage` | 14 |
| `project.manage` | 13 |
| `quote.manage` | 9 |
| `purchasing.manage` | 9 |
| `production.manage` | 8 |
| `users.manage` | 6 |
| `catalog.manage` | 3 |
| `order.manage` | 3 |
| `auth` | 3 |
| `theme.manage` | 2 |
| `purge` | 1 |

## All routes

| Module | Method | Path | Access |
|---|---|---|---|
| Catalogue | GET | `/api/materials` | `public` |
| Catalogue | GET | `/api/products` | `public` |
| Catalogue | POST | `/api/products` | `catalog.manage` |
| Catalogue | DELETE | `/api/products/:id` | `catalog.manage` |
| Catalogue | GET | `/api/products/:id` | `public` |
| Catalogue | PATCH | `/api/products/:id` | `catalog.manage` |
| Catalogue | GET | `/api/products/:id/stock` | `public` |
| Catalogue | GET | `/api/products/overrides` | `public` |
| Catalogue | GET | `/api/services` | `public` |
| CRM | GET | `/api/activities` | `owner-only` |
| CRM | POST | `/api/activities` | `owner-only` |
| CRM | GET | `/api/attachments` | `owner-only` |
| CRM | POST | `/api/attachments` | `owner-only` |
| CRM | GET | `/api/clients` | `crm.manage` |
| CRM | POST | `/api/clients` | `crm.manage` |
| CRM | GET | `/api/clients/:id` | `crm.manage` |
| CRM | PATCH | `/api/clients/:id` | `crm.manage` |
| CRM | POST | `/api/clients/:id/merge` | `crm.manage` |
| CRM | GET | `/api/clients/review` | `crm.manage` |
| CRM | GET | `/api/companies` | `owner-only` |
| CRM | POST | `/api/companies` | `owner-only` |
| CRM | GET | `/api/companies/:id` | `owner-only` |
| CRM | PATCH | `/api/companies/:id` | `owner-only` |
| CRM | GET | `/api/contacts` | `owner-only` |
| CRM | POST | `/api/contacts` | `owner-only` |
| CRM | PATCH | `/api/contacts/:id` | `owner-only` |
| CRM | GET | `/api/leads` | `crm.manage` |
| CRM | POST | `/api/leads` | `public` |
| CRM | PATCH | `/api/leads/:id` | `crm.manage` |
| CRM | POST | `/api/leads/:id/whatsapp-handoff` | `public` |
| CRM | GET | `/api/meetings` | `owner-only` |
| CRM | POST | `/api/meetings` | `owner-only` |
| CRM | PATCH | `/api/meetings/:id` | `owner-only` |
| CRM | GET | `/api/site-visits` | `crm.manage` |
| CRM | POST | `/api/site-visits` | `crm.manage` |
| CRM | PATCH | `/api/site-visits/:id` | `crm.manage` |
| CRM | GET | `/api/tasks` | `crm.manage` |
| CRM | POST | `/api/tasks` | `crm.manage` |
| CRM | PATCH | `/api/tasks/:id` | `crm.manage` |
| Delivery and installation | GET | `/api/deliveries` | `owner-only` |
| Delivery and installation | POST | `/api/deliveries` | `owner-only` |
| Delivery and installation | GET | `/api/deliveries/:id` | `owner-only` |
| Delivery and installation | PATCH | `/api/deliveries/:id` | `owner-only` |
| Delivery and installation | POST | `/api/deliveries/:id/lines` | `owner-only` |
| Delivery and installation | GET | `/api/deliveries/shortfall/:orderId` | `owner-only` |
| Delivery and installation | GET | `/api/dispatch-runs` | `owner-only` |
| Delivery and installation | POST | `/api/dispatch-runs` | `owner-only` |
| Delivery and installation | PATCH | `/api/dispatch-runs/:id` | `owner-only` |
| Delivery and installation | GET | `/api/installed-assets` | `owner-only` |
| Delivery and installation | POST | `/api/installed-assets` | `owner-only` |
| Delivery and installation | GET | `/api/installed-assets/:id` | `owner-only` |
| Delivery and installation | PATCH | `/api/installed-assets/:id` | `owner-only` |
| Finance | GET | `/api/accounts` | `owner-only` |
| Finance | POST | `/api/accounts` | `owner-only` |
| Finance | PATCH | `/api/accounts/:id` | `owner-only` |
| Finance | GET | `/api/credit-notes` | `owner-only` |
| Finance | POST | `/api/credit-notes` | `owner-only` |
| Finance | PATCH | `/api/credit-notes/:id` | `owner-only` |
| Finance | GET | `/api/expenses` | `owner-only` |
| Finance | POST | `/api/expenses` | `owner-only` |
| Finance | GET | `/api/invoices` | `finance.manage` |
| Finance | POST | `/api/invoices` | `finance.manage` |
| Finance | GET | `/api/invoices/:id` | `finance.manage` |
| Finance | PATCH | `/api/invoices/:id` | `finance.manage` |
| Finance | POST | `/api/invoices/:id/payments` | `finance.manage` |
| Finance | GET | `/api/journal` | `owner-only` |
| Finance | POST | `/api/journal` | `owner-only` |
| Finance | GET | `/api/journal/:id` | `owner-only` |
| Finance | POST | `/api/journal/:id/reverse` | `owner-only` |
| Finance | GET | `/api/payment-schedules` | `owner-only` |
| Finance | POST | `/api/payment-schedules` | `owner-only` |
| Finance | GET | `/api/tax-records` | `owner-only` |
| Inbox and messaging | GET | `/api/inbox` | `crm.manage` |
| Inbox and messaging | POST | `/api/inbox/:clientId/handoff` | `crm.manage` |
| Inbox and messaging | POST | `/api/inbox/:clientId/messages` | `crm.manage` |
| Inbox and messaging | GET | `/api/inbox/:clientId/thread` | `crm.manage` |
| Inbox and messaging | PATCH | `/api/inbox/conversations/:id` | `crm.manage` |
| Inbox and messaging | GET | `/api/inbox/templates` | `crm.manage` |
| Inventory | GET | `/api/stock-levels` | `owner-only` |
| Inventory | GET | `/api/stock-levels/reconcile` | `owner-only` |
| Inventory | GET | `/api/stock-movements` | `owner-only` |
| Inventory | GET | `/api/stock-movements/reconcile` | `owner-only` |
| Inventory | GET | `/api/stock-reservations` | `owner-only` |
| Inventory | POST | `/api/stock-reservations` | `owner-only` |
| Inventory | PATCH | `/api/stock-reservations/:id` | `owner-only` |
| Inventory | GET | `/api/stock-reservations/availability` | `owner-only` |
| Inventory | GET | `/api/stock-transfers` | `owner-only` |
| Inventory | POST | `/api/stock-transfers` | `owner-only` |
| Inventory | GET | `/api/stock-transfers/:id` | `owner-only` |
| Inventory | PATCH | `/api/stock-transfers/:id` | `owner-only` |
| Inventory | GET | `/api/storage-locations` | `owner-only` |
| Inventory | POST | `/api/storage-locations` | `owner-only` |
| Inventory | PATCH | `/api/storage-locations/:id` | `owner-only` |
| Inventory | GET | `/api/warehouses` | `owner-only` |
| Inventory | POST | `/api/warehouses` | `owner-only` |
| Inventory | PATCH | `/api/warehouses/:id` | `owner-only` |
| Marketing and analytics | GET | `/api/marketing/stats` | `owner-only` |
| Marketing and analytics | GET | `/api/ops/throttle` | `owner-only` |
| Marketing and analytics | DELETE | `/api/ops/throttle/:key` | `owner-only` |
| Marketing and analytics | POST | `/api/ops/throttle/sweep` | `owner-only` |
| Marketing and analytics | GET | `/api/stats` | `owner-only` |
| Marketing and analytics | GET | `/api/stats/revenue` | `owner-only` |
| Marketing and analytics | POST | `/api/target` | `theme.manage` |
| Marketing and analytics | DELETE | `/api/track` | `purge` |
| Marketing and analytics | POST | `/api/track` | `public` |
| Orders and returns | GET | `/api/orders` | `order.manage` |
| Orders and returns | POST | `/api/orders` | `public` |
| Orders and returns | GET | `/api/orders/:id` | `order.manage` |
| Orders and returns | PATCH | `/api/orders/:id` | `order.manage` |
| Orders and returns | POST | `/api/orders/lookup` | `public` |
| Orders and returns | GET | `/api/returns` | `finance.manage` |
| Orders and returns | POST | `/api/returns` | `finance.manage` |
| Orders and returns | PATCH | `/api/returns/:id` | `finance.manage` |
| Other | GET | `/api/finance/trial-balance` | `owner-only` |
| Payables | GET | `/api/supplier-invoices` | `finance.manage` |
| Payables | POST | `/api/supplier-invoices` | `finance.manage` |
| Payables | GET | `/api/supplier-invoices/:id` | `finance.manage` |
| Payables | PATCH | `/api/supplier-invoices/:id` | `finance.manage` |
| Payables | POST | `/api/supplier-invoices/:id/payments` | `finance.manage` |
| Payables | GET | `/api/supplier-invoices/summary` | `finance.manage` |
| Platform (auth, users, audit) | GET | `/api/audit` | `owner-only` |
| Platform (auth, users, audit) | GET | `/api/audit/health` | `owner-only` |
| Platform (auth, users, audit) | GET | `/api/audit/migrations` | `owner-only` |
| Platform (auth, users, audit) | POST | `/api/auth/change-password` | `auth` |
| Platform (auth, users, audit) | GET | `/api/auth/demo-available` | `public` |
| Platform (auth, users, audit) | POST | `/api/auth/login` | `public` |
| Platform (auth, users, audit) | POST | `/api/auth/logout` | `auth` |
| Platform (auth, users, audit) | GET | `/api/auth/me` | `auth` |
| Platform (auth, users, audit) | POST | `/api/auth/reset-password` | `public` |
| Platform (auth, users, audit) | GET | `/api/diagnostics/queries` | `owner-only` |
| Platform (auth, users, audit) | GET | `/api/events` | `owner-only` |
| Platform (auth, users, audit) | GET | `/api/health` | `public` |
| Platform (auth, users, audit) | GET | `/api/users` | `users.manage` |
| Platform (auth, users, audit) | POST | `/api/users` | `users.manage` |
| Platform (auth, users, audit) | PATCH | `/api/users/:id` | `users.manage` |
| Platform (auth, users, audit) | POST | `/api/users/:id/password-reset` | `users.manage` |
| Platform (auth, users, audit) | POST | `/api/users/:id/password-reset/revoke` | `users.manage` |
| Platform (auth, users, audit) | GET | `/api/users/:id/password-resets` | `users.manage` |
| Procurement | GET | `/api/goods-receipts` | `purchasing.manage` |
| Procurement | POST | `/api/goods-receipts` | `purchasing.manage` |
| Procurement | GET | `/api/purchase-orders` | `purchasing.manage` |
| Procurement | POST | `/api/purchase-orders` | `purchasing.manage` |
| Procurement | GET | `/api/purchase-orders/:id` | `purchasing.manage` |
| Procurement | PATCH | `/api/purchase-orders/:id` | `purchasing.manage` |
| Procurement | GET | `/api/suppliers` | `purchasing.manage` |
| Procurement | POST | `/api/suppliers` | `purchasing.manage` |
| Procurement | PATCH | `/api/suppliers/:id` | `purchasing.manage` |
| Production and quality | GET | `/api/boms` | `production.manage` |
| Production and quality | POST | `/api/boms` | `production.manage` |
| Production and quality | GET | `/api/boms/:id` | `production.manage` |
| Production and quality | GET | `/api/job-cards` | `production.manage` |
| Production and quality | POST | `/api/job-cards` | `production.manage` |
| Production and quality | GET | `/api/job-cards/:id` | `production.manage` |
| Production and quality | PATCH | `/api/job-cards/:id` | `production.manage` |
| Production and quality | POST | `/api/job-cards/:id/materials/issue` | `production.manage` |
| Projects and BOQ | GET | `/api/projects` | `project.manage` |
| Projects and BOQ | POST | `/api/projects` | `project.manage` |
| Projects and BOQ | DELETE | `/api/projects/:id` | `project.manage` |
| Projects and BOQ | GET | `/api/projects/:id` | `project.manage` |
| Projects and BOQ | PATCH | `/api/projects/:id` | `project.manage` |
| Projects and BOQ | POST | `/api/projects/:id/boq` | `project.manage` |
| Projects and BOQ | DELETE | `/api/projects/:id/boq/:lineId` | `project.manage` |
| Projects and BOQ | PATCH | `/api/projects/:id/boq/:lineId` | `project.manage` |
| Projects and BOQ | POST | `/api/projects/:id/files` | `project.manage` |
| Projects and BOQ | DELETE | `/api/projects/:id/files/:fid` | `project.manage` |
| Projects and BOQ | POST | `/api/projects/:id/milestones` | `project.manage` |
| Projects and BOQ | DELETE | `/api/projects/:id/milestones/:mid` | `project.manage` |
| Projects and BOQ | PATCH | `/api/projects/:id/milestones/:mid` | `project.manage` |
| Quotations | GET | `/api/quote-templates` | `quote.manage` |
| Quotations | POST | `/api/quote-templates` | `quote.manage` |
| Quotations | DELETE | `/api/quote-templates/:id` | `quote.manage` |
| Quotations | PATCH | `/api/quote-templates/:id` | `quote.manage` |
| Quotations | GET | `/api/quotes` | `quote.manage` |
| Quotations | POST | `/api/quotes` | `public` |
| Quotations | GET | `/api/quotes/:id` | `quote.manage` |
| Quotations | PATCH | `/api/quotes/:id` | `quote.manage` |
| Quotations | POST | `/api/quotes/:id/invoice` | `quote.manage` |
| Quotations | GET | `/api/quotes/:id/versions` | `quote.manage` |
| Support and warranty | GET | `/api/service-visits` | `owner-only` |
| Support and warranty | POST | `/api/service-visits` | `owner-only` |
| Support and warranty | PATCH | `/api/service-visits/:id` | `owner-only` |
| Support and warranty | GET | `/api/support/summary` | `owner-only` |
| Support and warranty | GET | `/api/tickets` | `owner-only` |
| Support and warranty | POST | `/api/tickets` | `owner-only` |
| Support and warranty | GET | `/api/tickets/:id` | `owner-only` |
| Support and warranty | PATCH | `/api/tickets/:id` | `owner-only` |
| Support and warranty | POST | `/api/tickets/:id/messages` | `owner-only` |
| Support and warranty | GET | `/api/warranty-claims` | `owner-only` |
| Support and warranty | POST | `/api/warranty-claims` | `owner-only` |
| Support and warranty | PATCH | `/api/warranty-claims/:id` | `owner-only` |
| Website and CMS | GET | `/api/blocks` | `page.manage` |
| Website and CMS | GET | `/api/media` | `page.manage` |
| Website and CMS | GET | `/api/pages` | `page.manage` |
| Website and CMS | POST | `/api/pages` | `page.manage` |
| Website and CMS | DELETE | `/api/pages/:id` | `page.manage` |
| Website and CMS | PUT | `/api/pages/:id/blocks` | `page.manage` |
| Website and CMS | POST | `/api/pages/:id/preview-link` | `page.manage` |
| Website and CMS | POST | `/api/pages/:id/publish` | `page.manage` |
| Website and CMS | POST | `/api/pages/:id/rollback` | `page.manage` |
| Website and CMS | POST | `/api/pages/:id/unpublish` | `page.manage` |
| Website and CMS | GET | `/api/pages/:id/versions` | `page.manage` |
| Website and CMS | GET | `/api/pages/:key` | `page.manage` |
| Website and CMS | GET | `/api/pages/:key/analytics` | `page.manage` |
| Website and CMS | GET | `/api/pages/:key/public` | `public` |
| Website and CMS | GET | `/api/saved-sections` | `page.manage` |
| Website and CMS | POST | `/api/saved-sections` | `page.manage` |
| Website and CMS | DELETE | `/api/saved-sections/:id` | `page.manage` |
| Website and CMS | PATCH | `/api/saved-sections/:id` | `page.manage` |
| Website and CMS | GET | `/api/sitemap` | `public` |
| Website and CMS | GET | `/api/sitemap.xml` | `public` |
| Website and CMS | GET | `/api/theme` | `public` |
| Website and CMS | PUT | `/api/theme` | `theme.manage` |
| Website and CMS | GET | `/robots.txt` | `public` |
| Website and CMS | GET | `/sitemap.xml` | `public` |
