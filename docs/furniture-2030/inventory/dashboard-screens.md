# Dashboard screens

Generated from the code at commit `9b8cdca` by `docs/furniture-2030/evidence/generate-inventory.mjs`. Do not edit by hand; re-run the script.

**44 routes** declared in `apps/dashboard/src/App.tsx` (redirects and the not-found route are not listed). "In menu" is the sidebar label. "Browser check" is the result of loading the route in a headless Chromium after signing in as the demo owner: it shows whether the screen renders, not whether every action on it works.

| Route | Page component | In menu | Browser check |
|---|---|---|---|
| `/` | Dashboard | Overview | renders |
| `/crm` | Crm | Leads & Pipeline | renders |
| `/clients` | Clients | Clients | renders |
| `/companies` | Companies | Companies | renders |
| `/meetings` | Meetings | Meetings | renders |
| `/deliveries` | Deliveries | Delivery & Installation | renders |
| `/support` | Support | Support & Warranty | renders |
| `/login-locks` | Throttle | Login locks | renders |
| `/quotations` | QuotationsList | Quotations | renders |
| `/quotations/new` | QuotationBuilder |  | renders |
| `/catalog` | Catalog | Product Catalog | renders |
| `/catalog/new` | ProductEditor |  | not checked |
| `/catalog/:id/edit` | ProductEditor |  | not checked (needs a record id) |
| `/catalog/:id` | ProductDetail |  | not checked (needs a record id) |
| `/orders` | Orders | Orders | renders |
| `/orders/:id` | OrderDetail |  | not checked (needs a record id) |
| `/invoices` | Invoices | Invoices & Payments | renders |
| `/projects` | Projects | Projects | renders |
| `/tasks` | Tasks | Tasks & Follow-ups | renders |
| `/ui-components` | UIComponents | UI Components (Dev) | renders |
| `/operations` | Operations | Operations | renders |
| `/operations/:stage` | Operations |  | not checked (needs a record id) |
| `/suppliers` | Suppliers | Suppliers | renders |
| `/purchase-orders` | PurchaseOrders | Purchase Orders | **ERROR-SCREEN** (products.data ?? []).map is not a function |
| `/goods-receipts` | GoodsReceipts | Goods Receipts | renders |
| `/supplier-invoices` | SupplierInvoices | Supplier Invoices | renders |
| `/production` | Production | Production | **ERROR-SCREEN** (products.data ?? []).map is not a function |
| `/stock-ledger` | StockLedger | Stock Ledger | renders |
| `/warehouses` | Warehouses | Warehouses | renders |
| `/stock-transfers` | StockTransfers | Stock Transfers | renders |
| `/stock-reservations` | StockReservations | Reservations | renders; 400 GET /api/stock-reservations/availability?product_id= |
| `/ledger` | Ledger | Ledger | renders |
| `/expenses` | Expenses | Expenses | renders |
| `/credit-notes` | CreditNotes | Credit Notes | renders |
| `/payment-schedules` | PaymentSchedules | Payment Schedules | renders |
| `/analytics` | Analytics | Business Analytics | renders |
| `/settings` | Settings | Workspace Settings | renders |
| `/ecommerce` | Ecommerce | Commerce Overview | renders |
| `/website` | Website | Website / CMS | renders |
| `/website/theme` | ThemeStudio | Theme Studio | renders |
| `/website/edit/:id` | PageEditor |  | not checked (needs a record id) |
| `/marketing` | Marketing | Landing Analytics | renders |
| `/omnichannel` | Inbox | Shared Inbox | renders |
