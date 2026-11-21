# 36 — The old system's tables, and who owns each in the admin

**Master list T-175, T-176 and T-177.** One row for every table of the old system (the React shop and dashboard
with their Node API, on PostgreSQL): what it holds, whether its rows are brought over, into which tables of the
Laravel admin, which screen owns it there, and in which wave (§1 to §7). Then how a value is brought over (§8,
T-176) and how a record keeps its old number (§9, T-177).

The tables below are written from [`acceptance/tables.json`](acceptance/tables.json) by
`node scripts/verify-table-map.mjs --write`. CI runs the same script as a check: it reads the old schema files,
the admin's migrations, the admin's menu and the master list, and fails when one of them no longer agrees with
the map (§6). To change a row, change the file and run the script.

**Every row is a proposal.** No record has moved, and none moves on the strength of this document: the mapping
of a module is approved by you before its first real record is brought over (T-181).

---

## 1. The short version

1. **The old system has 73 tables. Every one has a row here.** 72 come from its schema files; the 73rd is the
   list of schema changes that its migration tool keeps.
2. **The live database is the 73 tables of the files, column for column** (read on 20 November, reading only).
   No table is missing there, and none is there that the files do not know.
3. **It is almost empty.** 19 of the 73 tables hold a row; 54 hold none. Of its 381 rows, 148 are the sample
   products, 148 their opening stock, 16 the chart of accounts, 16 materials and 7 service pages from the seed,
   21 the list of schema changes. What could be a record of the business is a handful: one enquiry, one client,
   one conversation with one message, one task, one page, two entries of the books (§3). These rows were
   counted, not read: whether they are trials is yours to say.
4. **So what has to move is work, not rows.** The 35 tables that wait are 35 jobs the old dashboard does and
   the admin does not do yet: orders, delivery, support, stock, purchasing, production, the shop's pages. The
   map says which line builds each (§2, §5).
5. **A name that stands on both sides is not the same table.** 24 names do: `leads`, `quotes`, `invoices`,
   `payments` and twenty more. The shapes differ in every case (§4).
6. **What a move does with a value, and with a record's number, is decided and tested** (§8, §9): a time, an
   amount of money, a list inside a cell, yes and no, a status, a text that is too long, two values that differ
   only in capital letters, and the old number of a record. The tests run on the three database servers the
   admin is promised for. Nothing uses the code yet, and no record has moved.

<!-- generated:numbers — written by scripts/verify-table-map.mjs --write; do not edit by hand -->
|  |  |
|---|---|
| Tables of the old system | **73**: 72 that its schema files create, and the list of schema changes that the migration tool keeps |
| Move into tables the admin has today | **29** |
| Wait for a table the admin does not have yet | **35** |
| Stay: their rows are not brought over | **9** |
| Tables the admin's migrations create | 125. 28 of them receive rows from the old system by this map |
| Names that stand on both sides | 24. The same name is not the same table: see §4 |
| In the live database on 20 November 2026 | 73 tables, 765 columns, 381 rows. 19 tables hold rows, 54 are empty |
<!-- /generated:numbers -->

## 2. Every table, by the area of the admin that owns it

**How to read a row.** *From* is where the old system creates the table: `base` is `scripts/schema.postgres.sql`,
a number is the migration of that number in `scripts/migrations/`, `tool` is the migration tool itself. *Rows
today* is the count in the live database when it was last read (§3). *What happens* is one of three:

- **Moves**: its rows are brought into tables the admin has today.
- **Waits**: the admin has no table for it yet. The line named builds it, and the rows are brought over then.
- **Stays**: its rows are not brought over. The row says why, and which tables of the admin do the job instead.

*Destination* is the screen of the admin where a person will find these records. *Line · wave* is the line of
the master list that brings the table over, and the wave that line belongs to.

<!-- generated:areas — written by scripts/verify-table-map.mjs --write; do not edit by hand -->
### Customers (12 tables)

| Old table | From | What it holds | Rows today | What happens | Destination | Line · wave | To know |
|---|---|---|---|---|---|---|---|
| `leads` | base | An enquiry: who asked, for what, through which channel, and how far it has come. | 1 | **Moves** into `leads`, `contacts` | Leads | T-101 · 3 | One row here becomes two there: the person (name, phone, e-mail) is a contact, the enquiry is a lead of that contact. The stage is a word here and a row of the pipeline there. Where the enquiry came from (`utm_…`, `referrer`) goes into the lead's `attribution`. |
| `clients` | base | A customer as the old dashboard knows one: a person with a phone number and an e-mail address in a comparable form, and the mark that two were merged. | 1 | **Moves** into `contacts` | Contacts | T-101 · 3 | Two clients merged into one here (`merged_into`) arrive as one contact. |
| `client_links` | base | Which enquiry, quotation, order or task belongs to which client: one row a link. | 1 | **Moves** into `leads`, `quotes`, `invoices`, `tasks` | Contacts | T-101 · 3 | No table of its own there. Each link becomes the contact named on the record it points at (`contact_id`). A link to an order waits for orders (wave 6). |
| `companies` | 014 | A firm that buys: name, tax number, city, who looks after it. | 0 | **Moves** into `companies` | Contacts | T-101 · 3 |  |
| `contacts` | 014 | A person at a company, with their role there and whether they are the main one. | 0 | **Moves** into `contacts` | Contacts | T-101 · 3 | The same name on both sides, not the same thing: here a contact always belongs to a company; there a contact is any person, and a client is a contact too. |
| `tasks` | base | Something to do for a client, an enquiry, a quotation or an order, with a day and an owner. | 1 | **Moves** into `tasks` | Tasks & Visits | T-101 · 3 | The owner is a name written out here and a person of the team there. |
| `site_visits` | base | A visit to a customer's place: when, where, who goes, what came of it. | 0 | **Moves** into `site_visits` | Tasks & Visits | T-101 · 3 |  |
| `meetings` | 014 | A meeting with a company, a client or about an enquiry: time, place, who attends, the agenda and the outcome. | 0 | **Moves** into `tasks`, `activities` | Tasks & Visits | T-101 · 3 | A meeting still to come becomes a task of the kind Meeting; one that was held becomes an entry of the history, with its outcome. |
| `activities` | 014 | The history of a record: calls, notes, changes of status, in the order they happened. Rows are only added. | 0 | **Moves** into `activities` | Leads | T-101 · 3 |  |
| `attachments` | 014 | A file attached to a company, a contact or an enquiry: its name, size and where it is kept. | 0 | **Moves** into `media` | Contacts | T-101 · 3 | The row moves and so does the file: it is fetched and stored with its record. |
| `conversations` | base | A thread of messages with one client: who answers it, how many are unread. | 1 | **Moves** into `conversations` | Conversations | T-118 · 10 | The inbox is built in the admin and switched off. It opens with its own line, and the old threads move then. |
| `messages` | base | One message of a thread: the channel, the direction, the words. | 1 | **Moves** into `messages` | Conversations | T-118 · 10 |  |

### Sales (6 tables)

| Old table | From | What it holds | Rows today | What happens | Destination | Line · wave | To know |
|---|---|---|---|---|---|---|---|
| `quotes` | base | A quotation: the customer, the lines as one block of JSON, the total, the state. | 0 | **Moves** into `quotes`, `quote_items` | Quotations | T-103 · 4 | The lines are one block of JSON here and rows there. Money is rupees with decimals here and whole paisa there. The customer is a name written out here and a contact there. |
| `quote_versions` | base | Each saved state of a quotation, as a snapshot, with who saved it. | 0 | **Moves** into `quote_versions` | Quotations | T-103 · 4 |  |
| `quote_templates` | base | A layout for quotations, as a block of JSON. | 0 | **Moves** into `quote_templates` | Documents | T-103 · 4 | The shapes differ: a template there is built of named parts. With few rows it is quicker to make them again in the admin than to convert them. |
| `invoices` | base | An invoice: the customer, the lines as JSON, discount, tax, shipping, what is paid. | 0 | **Moves** into `invoices`, `invoice_items` | Invoices | T-103 · 4 | As for quotations: lines become rows, rupees become whole paisa, the customer becomes a contact. The number of an issued invoice is kept as it is. |
| `payments` | base | Money received against an invoice: amount, method, reference. | 0 | **Moves** into `payments`, `payment_allocations` | Payments | T-103 · 4 | A payment here belongs to one invoice. There it is a receipt of its own, and a second row says how much of it goes to which invoice. |
| `payment_schedules` | 013 | The instalments of one invoice: each with its day, its amount and what is paid of it. | 0 | **Waits**: the admin has no table for it yet | Payments | T-113 · 9 | The admin splits a quotation into payment steps and bills each step (`quote_milestones`). Instalments of one invoice are not there yet. |

### Commerce (12 tables)

| Old table | From | What it holds | Rows today | What happens | Destination | Line · wave | To know |
|---|---|---|---|---|---|---|---|
| `products` | base | A product of the shop: its content as one block of JSON, with the price, the stock count, the code and the state beside it. | 148 | **Stays.** Its rows are the sample catalogue the shop was first filled with: none is a product of Woodex (defect D-007). The real products were loaded into the admin from the capture of woodexfurniture.pk, as drafts, and are published there. In the admin: `commerce_products`, `commerce_product_revisions`, `commerce_product_media` | Products | T-105 · 5 | If the real catalogue is loaded into the old shop as well (line T-091), the two meet by the product's code. The stock count beside each product goes with the stock ledger (wave 7). |
| `product_variants` | 019 | The options of a product, each with its own code and price. | 0 | **Waits**: the admin has no table for it yet | Products | T-011 · 5 | The capture of the real catalogue holds no options, and the admin's products have none yet. Whether and how they are sold is your answer (line T-011). |
| `orders` | base | An order: the customer, the lines as JSON, the total, the stage, the token of its tracking page. | 0 | **Waits**: the admin has no table for it yet | Orders | T-106 · 6 | The admin keeps a basket sent from the shop as a request for a quotation (`storefront_quote_requests`). It has no orders yet. |
| `returns` | base | Goods sent back: which order, why, what was refunded. | 0 | **Waits**: the admin has no table for it yet | Orders | T-106 · 6 |  |
| `dispatch_runs` | 015 | One trip of a vehicle: driver, crew, when it was due and when it left. | 0 | **Waits**: the admin has no table for it yet | Delivery & Installation | T-107 · 6 |  |
| `deliveries` | 015 | A delivery to a customer: the address, who received and signed, the condition, the photograph. | 0 | **Waits**: the admin has no table for it yet | Delivery & Installation | T-107 · 6 |  |
| `delivery_lines` | 015 | What was on a delivery: each item, how many, its serial number. | 0 | **Waits**: the admin has no table for it yet | Delivery & Installation | T-107 · 6 |  |
| `installed_assets` | 015 | A piece of furniture standing at a customer's: where, since when, its warranty. | 0 | **Waits**: the admin has no table for it yet | Support & Warranty | T-108 · 6 |  |
| `tickets` | 016 | A complaint or a request for service: what is wrong, how urgent, by when it must be answered and solved. | 0 | **Waits**: the admin has no table for it yet | Support & Warranty | T-108 · 6 |  |
| `ticket_messages` | 016 | The messages of a ticket, those to the customer and those among staff. | 0 | **Waits**: the admin has no table for it yet | Support & Warranty | T-108 · 6 |  |
| `service_visits` | 016 | A technician's visit for a ticket: when, what was done, what it cost. | 0 | **Waits**: the admin has no table for it yet | Support & Warranty | T-108 · 6 |  |
| `warranty_claims` | 016 | A claim under warranty and the answer given: covered or not, why, at what cost. | 0 | **Waits**: the admin has no table for it yet | Support & Warranty | T-108 · 6 |  |

### Operations (4 tables)

| Old table | From | What it holds | Rows today | What happens | Destination | Line · wave | To know |
|---|---|---|---|---|---|---|---|
| `projects` | base | A project for a client: stage, dates, planned and actual cost, progress. | 0 | **Moves** into `projects` | Projects | T-112 · 9 | A project there starts from an accepted quotation and belongs to a contact; here it stands alone and names a client. |
| `project_milestones` | base | The steps of a project, each with a day and whether it is done. | 0 | **Moves** into `project_milestones` | Projects | T-112 · 9 | A step there is also a share of the price that falls due; here it is a date and a tick. |
| `boq_lines` | base | The bill of quantities of a project: what is supplied, how many, at what cost each. | 0 | **Waits**: the admin has no table for it yet | Projects | T-112 · 9 | The admin has no bill of quantities of its own: a project carries the lines of its quotation, and its costs are entered as they arise. |
| `project_files` | base | A file of a project: its name, kind, size and address. | 0 | **Moves** into `media` | Projects | T-112 · 9 | The row holds an address. The file is fetched and stored with the project. |

### Procurement & Production (20 tables)

| Old table | From | What it holds | Rows today | What happens | Destination | Line · wave | To know |
|---|---|---|---|---|---|---|---|
| `suppliers` | 009 | A firm Woodex buys from: contact, tax number, payment terms. | 0 | **Moves** into `vendors` | Suppliers | T-110 · 7 | The admin calls them vendors and has them already, for the costs of projects. The two become one list. |
| `purchase_orders` | 009 | An order to a supplier: its state, who approved it and when. | 0 | **Waits**: the admin has no table for it yet | Purchase Orders | T-110 · 7 |  |
| `purchase_order_lines` | 009 | The lines of a purchase order: the product or material, how many, at what cost. | 0 | **Waits**: the admin has no table for it yet | Purchase Orders | T-110 · 7 |  |
| `materials` | base | The materials and finishes the shop and the workshop name, each a block of JSON: natural oak, a laminate, a fabric. | 16 | **Waits**: the admin has no table for it yet | Purchase Orders | T-110 · 7 | A purchase order and a bill of material can name a material as the thing bought or used. Its rows come from the seed. |
| `goods_receipts` | 009 | Goods that arrived against a purchase order: when, received by whom. | 0 | **Waits**: the admin has no table for it yet | Goods Receipts | T-110 · 7 |  |
| `goods_receipt_lines` | 009 | How many of each ordered line arrived. | 0 | **Waits**: the admin has no table for it yet | Goods Receipts | T-110 · 7 |  |
| `supplier_invoices` | 010 | A supplier's invoice, and how it compares with the order and with what arrived. | 0 | **Moves** into `bills` | Supplier Invoices | T-110 · 7 | The admin calls them bills and shows them under Payables. A bill there is not compared with an order and a receipt yet: that comparison is built by the same line. |
| `supplier_invoice_lines` | 010 | The lines of a supplier's invoice. | 0 | **Moves** into `bill_items` | Supplier Invoices | T-110 · 7 |  |
| `supplier_payments` | 010 | Money paid to a supplier against an invoice. | 0 | **Moves** into `bill_payments` | Supplier Invoices | T-110 · 7 |  |
| `stock_movements` | 003 | The stock ledger: every change of a product's stock, with its reason. Rows are only added. | 148 | **Waits**: the admin has no table for it yet | Stock Ledger | T-109 · 7 | The rows it holds today are the opening stock of the sample products. |
| `stock_reservations` | 003 | Stock held for an order or a quotation until a day. | 0 | **Waits**: the admin has no table for it yet | Reservations | T-109 · 7 |  |
| `warehouses` | 012 | A place where stock is kept. | 0 | **Waits**: the admin has no table for it yet | Warehouses | T-109 · 7 |  |
| `storage_locations` | 012 | A shelf or zone inside a warehouse. | 0 | **Waits**: the admin has no table for it yet | Warehouses | T-109 · 7 |  |
| `stock_levels` | 012 | How many of each product stand at each location: a count kept beside the ledger. | 0 | **Waits**: the admin has no table for it yet | Stock Ledger | T-109 · 7 | Not copied: counted again from the ledger after the move, and compared with these rows. |
| `stock_transfers` | 012 | Stock sent from one location to another: sent when, received when. | 0 | **Waits**: the admin has no table for it yet | Stock Transfers | T-109 · 7 |  |
| `stock_transfer_lines` | 012 | What a transfer carried: each product and how many. | 0 | **Waits**: the admin has no table for it yet | Stock Transfers | T-109 · 7 |  |
| `boms` | 011 | A bill of material: what one product is made of. | 0 | **Waits**: the admin has no table for it yet | Production | T-111 · 8 |  |
| `bom_lines` | 011 | One part or material of a bill of material, and how much of it one piece takes. | 0 | **Waits**: the admin has no table for it yet | Production | T-111 · 8 |  |
| `job_cards` | 011 | A job of the workshop: what is built, how many, its stage, the quality check. | 0 | **Waits**: the admin has no table for it yet | Production | T-111 · 8 |  |
| `job_card_materials` | 011 | What a job needs and what was handed out for it. | 0 | **Waits**: the admin has no table for it yet | Production | T-111 · 8 |  |

### Accounts (6 tables)

| Old table | From | What it holds | Rows today | What happens | Destination | Line · wave | To know |
|---|---|---|---|---|---|---|---|
| `accounts` | 013 | The chart of accounts: code, name, kind. | 16 | **Moves** into `chart_of_accounts` | Ledger & reports | T-113 · 9 | The admin has a chart of its own. An account here is matched to one there by its code; only an account without a match is added. |
| `journal_entries` | 013 | An entry of the books: its day, what caused it, and the entry it reverses if it is a reversal. | 2 | **Moves** into `journal_entries` | Ledger & reports | T-113 · 9 | Only if the double-entry ledger is part of the launch: your decision (line T-013). An entry is never changed on the way: it arrives with its lines, and what was reversed stays reversed. |
| `journal_lines` | 013 | The lines of an entry: an account, a debit or a credit. | 4 | **Moves** into `journal_lines` | Ledger & reports | T-113 · 9 | Rupees with two decimals here, whole paisa there. |
| `expenses` | 013 | Money spent: on what, to whom, how it was paid. | 0 | **Moves** into `expenses` | Income & expenses | T-113 · 9 |  |
| `credit_notes` | 013 | A reduction of what a customer owes, with its reason, and the entry it posted. | 0 | **Waits**: the admin has no table for it yet | Income & expenses | T-113 · 9 | The admin can void an invoice. It has no credit note yet. |
| `tax_records` | 013 | A register of tax by period, worked out from invoices, credit notes and expenses. | 0 | **Stays.** It holds nothing that is not on the documents it was worked out from. The admin reads tax from the documents and from the tax accounts of the books; a register by period is worked out again from them, not copied. In the admin: `invoices`, `journal_lines` | Ledger & reports | T-113 · 9 |  |

### Growth (1 table)

| Old table | From | What it holds | Rows today | What happens | Destination | Line · wave | To know |
|---|---|---|---|---|---|---|---|
| `track_events` | base | A log of visits to the shop's pages: views, clicks, how far a page was read, the campaign in the address. | 0 | **Stays.** Nothing else refers to it, and it is of use for a few weeks. What the admin keeps is where each enquiry came from, on the lead itself. In the admin: `leads` | Marketing | T-120 · 10 |  |

### Website Studio (5 tables)

| Old table | From | What it holds | Rows today | What happens | Destination | Line · wave | To know |
|---|---|---|---|---|---|---|---|
| `pages` | base | A page of the shop: its address, title, state, and what was last published. | 1 | **Waits**: the admin has no table for it yet | All pages | T-117 · 10 | The admin has tables called `pages`, `page_blocks`, `page_versions` and `saved_sections`. They belong to the page builder that came with the admin, and that is not where the shop's pages are going: they go to the builder on Puck, once its prototype is accepted (lines T-115 and T-116). |
| `page_blocks` | base | The blocks a page is built of, in their order, each with its settings as JSON. | 0 | **Waits**: the admin has no table for it yet | Page builder | T-117 · 10 |  |
| `page_versions` | base | Earlier states of a page, as snapshots. | 0 | **Waits**: the admin has no table for it yet | All pages | T-117 · 10 |  |
| `saved_sections` | base | A block kept for use on other pages. | 0 | **Waits**: the admin has no table for it yet | Sections & templates | T-117 · 10 |  |
| `services` | base | The words and pictures of the shop's service pages, each a block of JSON: what the service is, its steps, its questions and answers. | 7 | **Waits**: the admin has no table for it yet | All pages | T-117 · 10 | Its rows come from the seed. They are pages in all but name, and move with the pages. |

### System (7 tables)

| Old table | From | What it holds | Rows today | What happens | Destination | Line · wave | To know |
|---|---|---|---|---|---|---|---|
| `users` | base | The people who sign in to the old dashboard: name, role, password. | 1 | **Stays.** A password here is kept in a form the admin cannot read, so no account can arrive ready to use. Each person is invited in the admin and sets a password of their own. What is carried over is the meaning of the five roles (line T-060). In the admin: `users`, `roles`, `model_has_roles` | Access & team | T-060 · 2 |  |
| `sessions` | base | The open sign-ins of the old dashboard. | 3 | **Stays.** On the day of the switch everybody signs in to the admin anew. In the admin: `sessions` | Access & team | T-065 · 2 |  |
| `password_resets` | 017 | Requests for a new password that are not used up yet. | 0 | **Stays.** Each ends within hours. In the admin a person asks again. In the admin: `password_reset_tokens` | Access & team | T-065 · 2 |  |
| `throttle_buckets` | 018 | Counts of wrong passwords, for an e-mail address or a network address, kept for minutes. | 6 | **Stays.** They are worth nothing an hour later. The admin counts for itself. | Access & team | T-065 · 2 |  |
| `audit_logs` | 008 | Who did what in the old dashboard, with the record before and after. | 0 | **Moves** into `activity_log` | Audit & activity | T-178 · 2 | It arrives as history and changes nothing in the admin. The person is a name written out here; there it is a person of the team where one matches. |
| `meta` | base | Settings kept by name: today the mark that the sample catalogue was loaded, and how far a supplier's invoice may differ from its order. The shop's theme is kept here too once somebody changes it. | 2 | **Stays.** These are settings, not records. Each is set again in the admin where it belongs: the tolerance with purchasing (line T-110), the theme with the Theme screen (line T-116). The mark of the sample catalogue is not needed. In the admin: `settings`, `theme_tokens` | Settings | — |  |
| `schema_migrations` | tool | The list of schema changes this database has received, kept by the migration tool (`scripts/migrate.mjs`). | 21 | **Stays.** It describes this database and no other. The admin keeps a list of its own. | Platform | — |  |
<!-- /generated:areas -->

## 3. What the live database holds

<!-- generated:live — written by scripts/verify-table-map.mjs --write; do not edit by hand -->
Read on 20 November 2026, 06:58 UTC, from woodex-2030 on Supabase, PostgreSQL 17.11. Each of the 73 tables has the columns of the schema built from the files in this repository, name for name, with the same type and the same rule for empty values (read at 06:18:07Z and compared).

| Table | Rows | What happens to it |
|---|---|---|
| `products` | 148 | Stays |
| `stock_movements` | 148 | Waits (wave 7) |
| `schema_migrations` | 21 | Stays |
| `accounts` | 16 | Moves (wave 9) |
| `materials` | 16 | Waits (wave 7) |
| `services` | 7 | Waits (wave 10) |
| `throttle_buckets` | 6 | Stays |
| `journal_lines` | 4 | Moves (wave 9) |
| `sessions` | 3 | Stays |
| `journal_entries` | 2 | Moves (wave 9) |
| `meta` | 2 | Stays |
| `client_links` | 1 | Moves (wave 3) |
| `clients` | 1 | Moves (wave 3) |
| `conversations` | 1 | Moves (wave 10) |
| `leads` | 1 | Moves (wave 3) |
| `messages` | 1 | Moves (wave 10) |
| `pages` | 1 | Waits (wave 10) |
| `tasks` | 1 | Moves (wave 3) |
| `users` | 1 | Stays |

**Empty (54):** `activities`, `attachments`, `audit_logs`, `bom_lines`, `boms`, `boq_lines`, `companies`, `contacts`, `credit_notes`, `deliveries`, `delivery_lines`, `dispatch_runs`, `expenses`, `goods_receipt_lines`, `goods_receipts`, `installed_assets`, `invoices`, `job_card_materials`, `job_cards`, `meetings`, `orders`, `page_blocks`, `page_versions`, `password_resets`, `payment_schedules`, `payments`, `product_variants`, `project_files`, `project_milestones`, `projects`, `purchase_order_lines`, `purchase_orders`, `quote_templates`, `quote_versions`, `quotes`, `returns`, `saved_sections`, `service_visits`, `site_visits`, `stock_levels`, `stock_reservations`, `stock_transfer_lines`, `stock_transfers`, `storage_locations`, `supplier_invoice_lines`, `supplier_invoices`, `supplier_payments`, `suppliers`, `tax_records`, `ticket_messages`, `tickets`, `track_events`, `warehouses`, `warranty_claims`.
<!-- /generated:live -->

**How it was read.** Through the Supabase connection you switched on, as the database's owner, with statements
that only read: the names of the tables and their columns from the database's own catalogue, and the number of
rows of each table. No record's content was read, and the reading wrote nothing. Two things that are no
records were read as they stand: the list of schema changes (the name and the checksum of each migration file)
and the names, not the values, of the two settings in `meta`. The statement that counts the rows is printed by
`node scripts/verify-table-map.mjs --query`; its answer, saved to a file, is compared with the map by
`node scripts/verify-table-map.mjs --live <file>`, which names a table the map does not know.

The rows were counted twice that morning: at 06:18 UTC, and again at 06:58 UTC, after migration 021 had been
applied (T-172; it changes no table and no column). The second count is the one above. One figure differs: the
list of schema changes holds 21 rows where it held 20.

**What "column for column" means.** The schema was built on a test database from the files of this repository
(the base file and migrations 001 to 020, at 06:18 UTC; migration 021 adds no column). For each of its 73 tables
a fingerprint was made of every column's name, type and whether it may be empty; the same was asked of the live
database. All 73 fingerprints agree, and both sides count 765 columns. Constraints, indexes and triggers were
not compared this way; the triggers were read by name (six, each attached and switched on).

## 4. The same name on both sides

<!-- generated:names — written by scripts/verify-table-map.mjs --write; do not edit by hand -->
`activities`, `companies`, `contacts`, `conversations`, `expenses`, `invoices`, `journal_entries`, `journal_lines`, `leads`, `messages`, `page_blocks`, `page_versions`, `pages`, `payments`, `project_milestones`, `projects`, `quote_templates`, `quote_versions`, `quotes`, `saved_sections`, `sessions`, `site_visits`, `tasks`, `users`.
<!-- /generated:names -->

These are tables of the old system whose name the admin uses too. None can be copied as it is. The old
system's 765 columns are, by type: 466 text, 208 whole numbers, 45 times with a zone, 24 floating numbers,
14 numbers with decimals, 5 days and 3 yes-or-no columns. What that means for a move:

- **Money.** The old system keeps rupees, with decimals: as a floating number in 23 columns (`quotes.total`,
  `invoices.total`, `payments.amount`, a product's price, the costs of purchasing) and as a number with two
  decimals in 13 (the books, expenses, credit notes, payment schedules, tax records, a service visit's charge,
  a warranty claim's cost). The admin keeps whole paisa (`total_minor`). Every amount is multiplied by 100 and
  checked to be whole.
- **Times.** By their names, 105 text columns in 46 tables hold a time or a day: 98 end in `_at`
  (`created_at TEXT`), four in `_date`, three are called `due`. The old API writes them as text, in the form
  `2026-11-08T20:54:28.341Z`. Only the newer tables (migrations 014 to 019) keep a time as a time. The admin
  keeps a time as a time, in UTC. A text that is not a time has to be found before the move, not during it.
- **Lists inside a cell.** 24 text columns are read as JSON by the old API. A quotation's lines, an invoice's
  lines and an order's lines are one block of it in the old system and rows of their own in the admin.
- **Yes and no.** The old system writes 0 and 1 into a whole-number column (13 are held to that by a
  constraint), and a true yes-or-no in three columns.
- **Upper and lower case.** PostgreSQL holds the product codes `WX-Desk-01` and `wx-desk-01` to be two
  values. MySQL, as the admin sets it up, holds them to be one, and the same goes for two spellings of one
  e-mail address. Two old records that differ only so can meet on a column that must be unique.
- **People.** The old system writes a person out (`owner TEXT`, `customer TEXT`). The admin points at a member
  of the team or at a contact. A name that matches nobody has to be decided, record by record or by a rule.
- **Numbers of records.** The same record gets a new number in the admin. The table `legacy_refs` keeps the old
  number beside the new one, so that a second run finds what the first brought over.

Two lines of the master list have tested and settled exactly this: T-176 (the differences between PostgreSQL
and MySQL, on real shapes) is §8, and T-177 (one rule for the numbers of records) is §9. Who a written-out
person becomes is not settled there: that is each module's importer (T-178).

## 5. Wave by wave

What each wave of the master list brings over, by this map. A table that stays is not in this table.

<!-- generated:waves — written by scripts/verify-table-map.mjs --write; do not edit by hand -->
| Wave | Tables | Move into tables that exist | Wait for a table | Rows in the live database today |
|---|---|---|---|---|
| 2 | 1 | `audit_logs` | — | 0 |
| 3 | 10 | `leads`, `clients`, `client_links`, `companies`, `contacts`, `tasks`, `site_visits`, `meetings`, `activities`, `attachments` | — | 4 |
| 4 | 5 | `quotes`, `quote_versions`, `quote_templates`, `invoices`, `payments` | — | 0 |
| 5 | 1 | — | `product_variants` | 0 |
| 6 | 10 | — | `orders`, `returns`, `dispatch_runs`, `deliveries`, `delivery_lines`, `installed_assets`, `tickets`, `ticket_messages`, `service_visits`, `warranty_claims` | 0 |
| 7 | 16 | `suppliers`, `supplier_invoices`, `supplier_invoice_lines`, `supplier_payments` | `purchase_orders`, `purchase_order_lines`, `materials`, `goods_receipts`, `goods_receipt_lines`, `stock_movements`, `stock_reservations`, `warehouses`, `storage_locations`, `stock_levels`, `stock_transfers`, `stock_transfer_lines` | 164 |
| 8 | 4 | — | `boms`, `bom_lines`, `job_cards`, `job_card_materials` | 0 |
| 9 | 10 | `projects`, `project_milestones`, `project_files`, `accounts`, `journal_entries`, `journal_lines`, `expenses` | `payment_schedules`, `boq_lines`, `credit_notes` | 22 |
| 10 | 7 | `conversations`, `messages` | `pages`, `page_blocks`, `page_versions`, `saved_sections`, `services` | 10 |
<!-- /generated:waves -->

## 6. How the map is kept true

| When this changes | The check says |
|---|---|
| A migration of the old system creates a table | The table has no row in the map |
| A row is taken out of the map, or written twice | The table's name, and that it has no row, or two |
| A row sends rows into a table the admin does not create | The table's name and the target |
| A migration of the admin creates a table in a way the check cannot read | The file, so that the check is taught and not fooled |
| A screen of the admin is renamed or removed | The row whose destination is gone |
| A line of the master list is done while a row still waits for it | The row: say where the rows went |
| A row's wave is not the wave the list gives its line | The row, with both waves |
| The live database had a table the map does not know | The table's name |
| This document no longer shows what the file says | The block to write again |

`node scripts/test-verify-table-map.mjs` tries the check: it changes one thing at a time in a copy and requires
the check to fail and to name the reason. Both run in CI (`ci.yml`).

The check cannot read the live database: CI has no address for it. What the database held is written into the
file by hand, with its time, each time it is read.

§8 and §9 have checks of their own; the end of §9 lists them.

## 7. What this map does not decide

1. **Whether a record moves at all.** Which customers and which documents may be brought over is yours
   (T-017, T-181). The map says where a table goes if it does.
2. **The books.** `accounts`, `journal_entries` and `journal_lines` move only if the double-entry ledger is part
   of the launch (T-013).
3. **Options of a product.** `product_variants` waits for your answer on how options are sold (T-011).
4. **The people.** By this map nobody's account is carried over: each person is invited and sets a password.
   If you want the accounts moved instead, say so; the passwords cannot be.
5. **Which column goes into which, record by record.** That is the importer of each module (T-178), with its
   dry run and its reconciliation (T-179). How a value is converted on the way is §8, and how the record keeps
   its old number is §9. Both are proposals in the same sense as the rows of the map: the 14 words that have no
   place yet (§8), and every rule of §8 and §9, are yours to change when you approve a module (T-181).

## 8. How a value is brought over (T-176)

The old system keeps its records in PostgreSQL and was written for JavaScript. The admin keeps them in MySQL or
MariaDB and is written in PHP. The two disagree about a value in more ways than §4 names, and the three servers
the admin is promised for (MySQL 8.4, MariaDB 10.11, MariaDB 11.4) disagree with each other as well. A test on
the sandbox's own database (SQLite) shows almost none of it.

So every importer goes through one class, `App\Domain\System\Import\OldSystem\Convert` (in `platform/`), and
nothing else converts a value of the old system. The class rounds nothing, cuts nothing and guesses nothing.
A value that does not fit is **refused by name**: what it was meant to be, the value, and the reason. An
importer's dry run lists the refusals (T-179), so you see them before a record moves.

Nothing uses the class yet. It runs against no data.

<!-- generated:shape-numbers — written by scripts/verify-old-shape.mjs --write; do not edit by hand -->
|  |  |
|---|---|
| Tables of the old system | **73**. The key is one number in 65, one text in 6, two columns in 2 |
| Columns whose words are listed | **42**, with 185 words: 39 by a CHECK constraint of the schema (163 words), 3 only by the old API's code (22 words) |
| Of them in tables that move today | **13** columns, 64 words: **50** have a place in the admin, **14** have none, each with its reason |
| In tables that wait or stay | 29 columns, 121 words. They get a place when their table gets one |
| Columns held to 0 or 1 · true yes-or-no columns | 13 · 3 |
| Unique indexes an importer can meet | **32**: 15 the admin fills itself, 5 hold only numbers of other records, 1 is the table of the old numbers, **11** take a value of the old system (10 stop the import when two meet, 1 keeps the first) |
| Names in the table of the old numbers | **35**, for the 29 tables that move. All begin with `node.` |
<!-- /generated:shape-numbers -->

### The rules, kind by kind

| A value | As the old system holds it | In the admin | Refused |
|---|---|---|---|
| **A time** | A text, in 105 columns: `2026-11-08T20:54:28.341Z`. The `Z` says UTC | The same moment. A screen shows it in Pakistan time: 9 November, 01:54. The part of a second is dropped | A text that is no time (`Invalid Date`, `08/11/2026`, `next Tuesday`); a day that does not exist (30 February); a time of day with no time zone, unless the importer says where that clock stood; a moment before 1970 or after 19 January 2038, which a time column of the admin cannot hold |
| **A day** | A text: `2026-11-08` (`tasks.due`, `invoices.due`) | The same day. No time zone moves it: in a day column it stays 8 November; in a time column it is the start of 8 November in Pakistan | A day that does not exist; a year before 1000 |
| **Money, exact** | Rupees with two decimals, in 13 columns: `1234.50` | Whole paisa: 123,450 | `1234.565`: it is not whole paisa. It is not rounded |
| **Money, floating** | Rupees as a floating number, in 23 columns. 0.1 + 0.2 is 0.30000000000000004 there | Whole paisa. That number is 30 paisa: the tail is the noise of floating numbers, far smaller than a millionth of a paisa. Rs 99,999,999.99 arrives as 9,999,999,999 paisa | `1234.565`: half a paisa is not noise. Rs 100 billion or more: a floating number that large no longer holds its paisa for certain |
| **A list inside a cell** | A text of JSON, in 24 columns | The value, read back the same on every server: Urdu, an emoji, a number above 9,007,199,254,740,992 to the last digit | A text that is not JSON, with the reason |
| **Yes and no** | 0 and 1 in a whole-number column; `t` and `f` in three true yes-or-no columns | No and yes | 2, `yes`, an empty text. An empty cell stays empty: whether that means no is the importer's to say |
| **A word** (a status, a stage, a priority) | One of the words the old system allows, compared exactly: `Paid`, not `paid` | Its place in the admin's list of the same thing (the table below) | A word the old system does not allow there; a word that has no place in the admin; a word of a table that does not move yet |
| **A text** | Of any length | The same text, if it fits the column. Letters are counted, not bytes: an Urdu letter and an emoji count one each | A text longer than the column, with both lengths |
| **A value that must be unique** | Two spellings can stand side by side: `WX-Desk-01`, `wx-desk-01` | The rule written for that column decides before anything is written (the last table of this section) | Two values that meet, where the rule says the import stops |

Two things follow for whoever writes an importer.

**Add the paisa, never the rupees.** A thousand floating amounts added up have drifted: in the test the sum of
a thousand lines differs from the true amount. Each line converted to paisa and then added is exact, and the
database adds the thousand lines to the same paisa on every server.

**Hand a time over as the conversion returns it.** The admin writes a time as the clock of the object it is
given, and the database takes that clock for Pakistan time. A time handed over in UTC is kept five hours early,
on every server and without an error. The conversion therefore returns the moment on the workspace's clock,
and a test pins the mistake so that nobody tidies it away.

### Where the servers disagree

Each line is pinned by a test that says which server is answering.

| What is done | MySQL 8.4 | MariaDB 10.11 and 11.4 | SQLite (the sandbox) | So the conversion |
|---|---|---|---|---|
| A time with a part of a second is handed to a time column | Rounds it: 20:54:28.641 becomes 20:54:29 | Cuts it: 20:54:28 | Keeps the text as it came | Drops the part of a second before the database sees it |
| A moment after 19 January 2038 | Refuses it | Refuses it | Keeps it, and says nothing | Refuses it itself, by name |
| A text longer than its column | Refuses it | Refuses it | Keeps all of it | Refuses it itself, by name |
| A list with named parts is written to a JSON column | Hands the names back in an order of its own | Keeps the text as it was written | Keeps the text | Relies on no such order: what must stay in order goes in a list, and a list keeps its order on all of them |
| Two values that differ only in capital letters, in a unique column | Holds them to be one, and refuses the second | The same | Holds them to be two | Applies the written rule of that column first |
| `Strasse` and `Straße` in a unique column | Holds them to be one | The same | Holds them to be two | Does not see this one. The database refuses the second value, and the import stops there |

The last line is the limit of the rule, said plainly. What a person can type differently is foreseen: capital
and small letters, a letter with and without its accent, spaces at the end. What only the database's own table
of letters knows is not; there the unique column itself is the protection, and it stops the import instead of
losing a record.

### Where each word goes

A status, a stage or a priority is a word in the old system, and the old system lists the words a column may
hold. The admin keeps its own list for the same thing. This table says where each word of a table that moves
goes. It is written from `platform/resources/data/old-system-rules.json`, a file kept by hand.

<!-- generated:shape-words — written by scripts/verify-old-shape.mjs --write; do not edit by hand -->
| Column of the old system | Its word | In the admin |
|---|---|---|
| `accounts.kind` | `asset` | `asset` (AccountType) |
| `accounts.kind` | `liability` | `liability` (AccountType) |
| `accounts.kind` | `equity` | `equity` (AccountType) |
| `accounts.kind` | `revenue` | `income` (AccountType) |
| `accounts.kind` | `expense` | `expense` (AccountType) |
| `activities.kind` | `call` | `call` (ActivityType) |
| `activities.kind` | `meeting` | `meeting` (ActivityType) |
| `activities.kind` | `note` | `note` (ActivityType) |
| `activities.kind` | `email` | `email` (ActivityType) |
| `activities.kind` | `status_change` | **No place.** The admin keeps three kinds for this: a change of stage, an event of a quotation, an event of an invoice. Which one depends on what the entry is about, not on the word. |
| `activities.kind` | `document` | **No place.** The admin's timeline has no kind for "a document was attached". |
| `activities.kind` | `payment` | `payment` (ActivityType) |
| `activities.kind` | `system` | `system` (ActivityType) |
| `conversations.status` | `open` | `open` (ConversationStatus) |
| `conversations.status` | `pending` | `pending` (ConversationStatus) |
| `conversations.status` | `resolved` | `closed` (ConversationStatus) |
| `conversations.status` | `snoozed` | `snoozed` (ConversationStatus) |
| `invoices.status` | `Issued` | `issued` (InvoiceStatus) |
| `invoices.status` | `Partially Paid` | `partially_paid` (InvoiceStatus) |
| `invoices.status` | `Paid` | `paid` (InvoiceStatus) |
| `invoices.status` | `Cancelled` | `void` (InvoiceStatus) |
| `meetings.channel` | `in-person` | `meeting` (TaskType) |
| `meetings.channel` | `phone` | `call` (TaskType) |
| `meetings.channel` | `video` | `meeting` (TaskType) |
| `meetings.status` | `scheduled` | `open` (TaskStatus) |
| `meetings.status` | `held` | `done` (TaskStatus) |
| `meetings.status` | `cancelled` | `cancelled` (TaskStatus) |
| `meetings.status` | `no_show` | **No place.** A task of the admin is open, done or cancelled. A meeting nobody came to is none of the three. |
| `messages.direction` | `inbound` | `in` (MessageDirection) |
| `messages.direction` | `outbound` | `out` (MessageDirection) |
| `messages.direction` | `system` | **No place.** A message of the admin comes in or goes out. A line the system wrote itself is neither: in the admin it is a note inside the conversation. |
| `projects.stage` | `Planning` | `planning` (ProjectStage) |
| `projects.stage` | `Procurement` | `procurement` (ProjectStage) |
| `projects.stage` | `Production` | **No place.** The admin's stages are those of a fit-out: design, execution, finishing. The making of furniture has no stage of its own there. |
| `projects.stage` | `Delivery` | **No place.** The admin has no stage for the delivery. |
| `projects.stage` | `Installation` | **No place.** The admin has no stage for the installation. |
| `projects.stage` | `Handover` | `handover` (ProjectStage) |
| `quotes.status` | `Draft` | `draft` (QuoteStatus) |
| `quotes.status` | `Review` | **No place.** The admin has one step before a quotation is sent (waiting for approval); the old system has two, and this is the first of them. |
| `quotes.status` | `Approval` | `pending_approval` (QuoteStatus) |
| `quotes.status` | `Sent` | `sent` (QuoteStatus) |
| `quotes.status` | `Viewed` | `viewed` (QuoteStatus) |
| `quotes.status` | `Negotiation` | `changes_requested` (QuoteStatus) |
| `quotes.status` | `Revised` | **No place.** In the old system a quotation that is changed after it was sent stays one record with this status. In the admin the change is a new revision, and the earlier one is marked as replaced. |
| `quotes.status` | `Accepted` | `accepted` (QuoteStatus) |
| `quotes.status` | `Approved` | **No place.** The same word, another meaning. In the old system it is the last step: the customer agreed. In the admin "approved" is a step inside the company, before the quotation is sent. It must not be matched by its name. |
| `quotes.status` | `Rejected` | `declined` (QuoteStatus) |
| `quotes.status` | `Expired` | `expired` (QuoteStatus) |
| `quotes.status` | `Cancelled` | `void` (QuoteStatus) |
| `site_visits.status` | `Scheduled` | `scheduled` (VisitStatus) |
| `site_visits.status` | `Confirmed` | `confirmed` (VisitStatus) |
| `site_visits.status` | `Completed` | `completed` (VisitStatus) |
| `site_visits.status` | `Cancelled` | `cancelled` (VisitStatus) |
| `supplier_invoices.match_state` | `matched` | **No place.** The admin does not compare a bill with its purchase order and its goods receipt, so it keeps no result of that comparison. |
| `supplier_invoices.match_state` | `tolerance` | **No place.** The admin does not compare a bill with its purchase order and its goods receipt, so it keeps no result of that comparison. |
| `supplier_invoices.match_state` | `variance` | **No place.** The admin does not compare a bill with its purchase order and its goods receipt, so it keeps no result of that comparison. |
| `supplier_invoices.match_state` | `unlinked` | **No place.** The admin does not compare a bill with its purchase order and its goods receipt, so it keeps no result of that comparison. |
| `supplier_invoices.status` | `draft` | `draft` (BillStatus) |
| `supplier_invoices.status` | `posted` | `received` (BillStatus) |
| `supplier_invoices.status` | `paid` | `paid` (BillStatus) |
| `supplier_invoices.status` | `cancelled` | `void` (BillStatus) |
| `tasks.priority` | `low` | `low` (LeadPriority) |
| `tasks.priority` | `normal` | `normal` (LeadPriority) |
| `tasks.priority` | `high` | `high` (LeadPriority) |

- `invoices.status`: The old system works "Overdue" out when it shows an invoice and never stores it; so does the admin.
- `meetings.channel`: A meeting becomes a task and an entry on the timeline. That it was held by video is kept in the note, not in the kind.
- `supplier_invoices.status`: The admin works "part paid" out from what was paid; the old system has no such word.
<!-- /generated:shape-words -->

**A word with no place is not lost and not guessed.** An importer that meets one stops at that record and the
dry run names it. Before the module is approved you say where the word goes, or that the admin's list gets a
new entry for it. The one to look at first is `Approved` on a quotation: the same word means "the customer
agreed" in the old system and "passed inside the company, not sent yet" in the admin. Matched by its name it
would turn every won quotation into one that was never sent.

Two columns hold no closed list, so nothing is placed here. `leads.status` takes any text; the old dashboard
offers nine stages, and in the admin a stage is a row of the pipeline, not a word. `orders.due` takes any text.
Two more lists stand only in the old API's code and are neither a status, a stage nor a priority: the channel of
a message and the kind of a project's file. All four are for the importer of their module (T-178).

### The words of tables that do not move yet

These get a place when their table gets one. Until then the conversion refuses them and names the line of the
master list the table waits for.

<!-- generated:shape-waiting-words — written by scripts/verify-old-shape.mjs --write; do not edit by hand -->
| Column of the old system | Its words | Its table |
|---|---|---|
| `bom_lines.item_kind` | `product`, `material` | Waits for T-111 (wave 8) |
| `credit_notes.status` | `draft`, `issued`, `applied`, `cancelled` | Waits for T-113 (wave 9) |
| `deliveries.condition` | `good`, `damaged`, `partial`, `rejected` | Waits for T-107 (wave 6) |
| `deliveries.status` | `pending`, `in_transit`, `delivered`, `failed`, `returned` | Waits for T-107 (wave 6) |
| `dispatch_runs.status` | `planned`, `dispatched`, `completed`, `cancelled` | Waits for T-107 (wave 6) |
| `installed_assets.status` | `installed`, `removed`, `replaced`, `faulty` | Waits for T-108 (wave 6) |
| `job_card_materials.item_kind` | `product`, `material` | Waits for T-111 (wave 8) |
| `job_cards.stage` | `queued`, `in_progress`, `qc`, `done`, `cancelled` | Waits for T-111 (wave 8) |
| `orders.stage` | `production`, `qc`, `dispatch`, `delivery`, `installation` | Waits for T-106 (wave 6) |
| `orders.status` | `Pending`, `On Track`, `In Progress`, `On Hold`, `Delayed`, `Completed` | Waits for T-106 (wave 6) |
| `password_resets.reason` | `forgotten`, `compromised`, `offboarding`, `initial` | Stays in the old system |
| `payment_schedules.status` | `pending`, `paid`, `waived` | Waits for T-113 (wave 9) |
| `products.status` | `draft`, `published`, `archived` | Stays in the old system |
| `purchase_order_lines.item_kind` | `product`, `material` | Waits for T-110 (wave 7) |
| `purchase_orders.status` | `draft`, `approved`, `sent`, `partially_received`, `received`, `cancelled` | Waits for T-110 (wave 7) |
| `returns.state` | `Requested`, `Inspecting`, `Refunding`, `Closed` | Waits for T-106 (wave 6) |
| `service_visits.status` | `scheduled`, `attended`, `completed`, `missed`, `cancelled` | Waits for T-108 (wave 6) |
| `stock_movements.reason` | `receipt`, `sale`, `return`, `adjustment`, `transfer`, `wastage`, `production` | Waits for T-109 (wave 7) |
| `stock_reservations.status` | `held`, `released`, `consumed` | Waits for T-109 (wave 7) |
| `stock_transfers.status` | `draft`, `dispatched`, `received`, `cancelled` | Waits for T-109 (wave 7) |
| `tax_records.kind` | `sales`, `purchase`, `withholding` | Stays in the old system |
| `throttle_buckets.kind` | `login`, `ip` | Stays in the old system |
| `tickets.category` | `damage`, `defect`, `missing_part`, `fitting`, `finish`, `delivery`, `other` | Waits for T-108 (wave 6) |
| `tickets.channel` | `phone`, `email`, `whatsapp`, `visit`, `web` | Waits for T-108 (wave 6) |
| `tickets.priority` | `low`, `normal`, `high`, `urgent` | Waits for T-108 (wave 6) |
| `tickets.status` | `open`, `in_progress`, `waiting`, `resolved`, `closed` | Waits for T-108 (wave 6) |
| `tickets.waiting_on` | `us`, `them` | Waits for T-108 (wave 6) |
| `users.role` | `owner`, `editor`, `sales`, `finance`, `purchasing`, `production`, `viewer` | Stays in the old system |
| `warranty_claims.verdict` | `pending`, `covered`, `not_covered`, `goodwill` | Waits for T-108 (wave 6) |
<!-- /generated:shape-waiting-words -->

### Every unique column, and its rule

PostgreSQL holds `WX-Desk-01` and `wx-desk-01` to be two values. MySQL and MariaDB, as the admin sets them up,
hold them to be one; the same goes for `Café` and `cafe`, and for a value with a space after it. Two old records
that differ only so meet on a column that must be unique.

The table lists every unique column of the tables an importer can write to: the 28 that receive rows by the
map, the table of the old numbers, and `users` and `commerce_products`, which the map leaves alone today. For
each it says who gives the value. Where the old system's value is written, it says what happens when two meet:
the import stops and names both records, or the first is kept and the other is listed for you. **Stopping is
the rule almost everywhere**, because two invoices under one number is a question for a person, not for a
program. A test compares this table with the unique columns the database itself reports; a unique column that
is added later fails it until its rule is written.

<!-- generated:shape-unique — written by scripts/verify-old-shape.mjs --write; do not edit by hand -->
| Unique in the admin | What it is | The value comes from | When two old values meet |
|---|---|---|---|
| `bill_payments.idempotency_key` | The admin makes this key itself, once for each payment it records. | The admin makes it | — |
| `bill_payments.public_id` | The admin makes this identifier itself for every new record. | The admin makes it | — |
| `bills.public_id` | The admin makes this identifier itself for every new record. | The admin makes it | — |
| `bills.vendor_id + number` | The number the supplier wrote on the invoice. Two invoices of one supplier under one number cannot both be entered. | The old system: `supplier_invoices.supplier_ref` | **The import stops** and names both records |
| `chart_of_accounts.code` | The code of an account. Two accounts cannot share one. | The old system: `accounts.code` | **The import stops** and names both records |
| `commerce_products.slug` | The address of a product. By the map the old products stay where they are; if that changes, two products cannot share an address. | The old system: `products.id` | **The import stops** and names both records |
| `commerce_products.source_key` | The code under which a product came in. By the map the old products stay where they are; if that changes, two products cannot share a code. | The old system: `products.id` | **The import stops** and names both records |
| `conversations.public_id` | The admin makes this identifier itself for every new record. | The admin makes it | — |
| `conversations.widget_token` | The admin makes this key itself for a chat on the website. | The admin makes it | — |
| `expenses.public_id` | The admin makes this identifier itself for every new record. | The admin makes it | — |
| `invoices.number` | The number printed on an invoice. The old system does not hold it unique, the admin does: two invoices under one number stop the import. | The old system: `invoices.ref` | **The import stops** and names both records |
| `invoices.project_milestone_id` | One invoice for one milestone of a project. | Numbers of other records only | — |
| `invoices.public_id` | The admin makes this identifier itself for every new record. | The admin makes it | — |
| `invoices.source_quote_id` | One invoice from one quotation. | Numbers of other records only | — |
| `journal_entries.entry_no` | The number of an entry of the books. | The old system: `journal_entries.ref` | **The import stops** and names both records |
| `journal_entries.source_type + source_id` | Which record an entry of the books was made for: a type name of the admin's own, and that record's number there. No text of the old system is written here, so capital letters cannot matter. Two old entries made for one record would meet here; the importer of the books settles that (T-178). | The admin makes it | — |
| `leads.ref` | The number of an enquiry. The old system does not hold it unique, the admin does. | The old system: `leads.ref` | **The import stops** and names both records |
| `legacy_refs.legacy_table + legacy_id` | The old name and the old number of a record. Only the one class writes them, in a form in which upper and lower case cannot meet (T-177). | The one class of §9 | — |
| `media.uuid` | The admin makes this identifier itself for every new record. | The admin makes it | — |
| `messages.external_id` | The number a provider gives a message. The old system kept none, so an importer leaves it empty; the admin fills it for the messages it receives itself. | The admin makes it | — |
| `payment_allocations.payment_id + invoice_id` | One share of a payment for one invoice. | Numbers of other records only | — |
| `payments.public_id` | The admin makes this identifier itself for every new record. | The admin makes it | — |
| `payments.receipt_number` | The admin numbers its receipts itself. | The admin makes it | — |
| `projects.code` | The number of a project. | The old system: `projects.ref` | **The import stops** and names both records |
| `projects.public_id` | The admin makes this identifier itself for every new record. | The admin makes it | — |
| `projects.quote_id` | One project from one quotation. | Numbers of other records only | — |
| `quote_templates.slug` | The address of a template, made from its name. Two templates whose names differ only in case would get one address. | The old system: `quote_templates.name` | **The import stops** and names both records |
| `quote_versions.quote_id + revision` | One record for each revision of a quotation. | Numbers of other records only | — |
| `quotes.number + option_label` | The number printed on a quotation. The old system does not hold it unique, the admin does. | The old system: `quotes.ref` | **The import stops** and names both records |
| `quotes.public_id` | The admin makes this identifier itself for every new record. | The admin makes it | — |
| `users.email` | An address for signing in. By the map nobody's account is carried over: people are invited. If that changes, one mailbox is one person: the account with the lower old number is kept and the other is listed for you. | The old system: `users.email` | **The first is kept** (the lower old number); the other is listed |
| `vendors.public_id` | The admin makes this identifier itself for every new record. | The admin makes it | — |
<!-- /generated:shape-unique -->

## 9. The number of a record (T-177)

A record gets a new number in the admin. The table `legacy_refs` keeps the old number beside the new one, so
that a second run of an importer finds what the first brought over, and so that a reference can be followed:
which enquiry of the admin is the old enquiry 17?

For the old system one class writes that table: `App\Domain\System\Import\OldSystem\Refs`. An importer asks it
"was this row brought over, and has it changed since?", and the class writes the answer. An importer never
names the table itself; a test reads every file of the admin and fails on one that does.

### The rule

1. **The name.** A row is entered under `node.`, the old table, `>` and the admin's table:
   `node.leads>contacts`. `node.` stands for this old system, and no other importer's names begin with it, so
   the old enquiry 17 and the enquiry 17 of the studio's older CRM never meet. The name is derived from the
   table's name and from the map; the map gains nothing.
2. **The key.** The row's own key, as one text.
   - A number is its digits: `17`. Never `017` or `17.0`, which would be a second text for the same record.
   - A text is kept letter for letter where it is made of small letters, digits, `-`, `_` and `.`. Every other
     sign is written as `%` and two digits for each of its bytes: a capital letter, a space, an accent, an Urdu
     letter. `Desk` is written `%44esk`. This is why: the admin's database holds `Desk` and `desk` to be the
     same text, and the old one does not. Written so, two different keys are two different texts on every
     server.
   - A key of two columns is joined with `:`, as `lead:17`. A `:` inside a value is written `%3a`, so `1` with
     `27` and `12` with `7` cannot come out alike, and neither can a value that holds the joining sign.
   - The column holds 64 characters. A key that is longer, as it is written, is refused by name. It is not cut.
3. **One row, two records.** An old enquiry becomes a contact and a lead. It is entered once for each, under
   the same key: `node.leads>contacts` and `node.leads>leads`. Two old rows may point at one record (two
   enquiries of one person are one contact). Where one row makes several records in one table (the lines of a
   quotation), those get no entry of their own: they are written and replaced with their quotation, and its
   entry answers for them.
4. **The checksum.** SHA-256 of the row as the importer read it: the columns in the order of their names, each
   value as a text, an empty cell as nothing. The same row gives the same checksum in whatever order it was
   read. A row whose content changed gives another, and is seen as changed.

An entry holds the name, the key, the admin's table, the number of the record there, the checksum and the time.

### What a run does

| The row | What happens |
|---|---|
| Was never brought over | The importer makes the record, and the entry is written with it, both or neither. A record is never left without its entry, which would make a second record on the next run |
| Was brought over, and is as it was | Nothing is made and nothing is written: not a record, not an entry, not a time. A test counts the statements a second run sends, and none of them writes |
| Was brought over, and has changed | The importer is told so, with the number of the record. If it says what to do, that is done and the entry takes the new checksum. If it says nothing, nothing is written, and the row is reported as changed on every run until somebody has dealt with it. A change is never passed over quietly |
| Was brought over by another run at the same moment | The database refuses the second entry, what this run made is taken back, and the refusal says so |

### The names

<!-- generated:shape-entries — written by scripts/verify-old-shape.mjs --write; do not edit by hand -->
| Table of the old system | Its key | Entered under |
|---|---|---|
| `accounts` | `id` (a number) | `node.accounts>chart_of_accounts` |
| `activities` | `id` (a number) | `node.activities>activities` |
| `attachments` | `id` (a number) | `node.attachments>media` |
| `audit_logs` | `id` (a number) | `node.audit_logs>activity_log` |
| `client_links` | `entity` (a text) and `entity_id` (a number) | `node.client_links>leads`, `node.client_links>quotes`, `node.client_links>invoices`, `node.client_links>tasks` |
| `clients` | `id` (a number) | `node.clients>contacts` |
| `companies` | `id` (a number) | `node.companies>companies` |
| `contacts` | `id` (a number) | `node.contacts>contacts` |
| `conversations` | `id` (a number) | `node.conversations>conversations` |
| `expenses` | `id` (a number) | `node.expenses>expenses` |
| `invoices` | `id` (a number) | `node.invoices>invoices` |
| `journal_entries` | `id` (a number) | `node.journal_entries>journal_entries` |
| `journal_lines` | `id` (a number) | `node.journal_lines>journal_lines` |
| `leads` | `id` (a number) | `node.leads>leads`, `node.leads>contacts` |
| `meetings` | `id` (a number) | `node.meetings>tasks`, `node.meetings>activities` |
| `messages` | `id` (a number) | `node.messages>messages` |
| `payments` | `id` (a number) | `node.payments>payments`, `node.payments>payment_allocations` |
| `project_files` | `id` (a number) | `node.project_files>media` |
| `project_milestones` | `id` (a number) | `node.project_milestones>project_milestones` |
| `projects` | `id` (a number) | `node.projects>projects` |
| `quote_templates` | `id` (a text) | `node.quote_templates>quote_templates` |
| `quote_versions` | `id` (a number) | `node.quote_versions>quote_versions` |
| `quotes` | `id` (a number) | `node.quotes>quotes` |
| `site_visits` | `id` (a number) | `node.site_visits>site_visits` |
| `supplier_invoice_lines` | `id` (a number) | `node.supplier_invoice_lines>bill_items` |
| `supplier_invoices` | `id` (a number) | `node.supplier_invoices>bills` |
| `supplier_payments` | `id` (a number) | `node.supplier_payments>bill_payments` |
| `suppliers` | `id` (a number) | `node.suppliers>vendors` |
| `tasks` | `id` (a number) | `node.tasks>tasks` |

No entry of their own:

- `invoices` into `invoice_items`: The lines of an invoice are one block inside the old row and rows of their own in the admin. They are written with their invoice and replaced with it; the entry of the invoice answers for them.
- `quotes` into `quote_items`: The lines of a quotation are one block inside the old row and rows of their own in the admin. They are written with their quotation and replaced with it; the entry of the quotation answers for them.

Not entered today, because their rows are not brought over, and with a key that is not one number: `materials` (`id` (a text); waits for T-110, wave 7), `meta` (`key` (a text); stays in the old system), `products` (`id` (a text); stays in the old system), `services` (`id` (a text); waits for T-117, wave 10), `sessions` (`token` (a text); stays in the old system), `stock_levels` (`product_id` (a text) and `location_id` (a number); waits for T-109, wave 7). The other 38 tables that wait or stay have one number for their key.
<!-- /generated:shape-entries -->

### The longest key

The column holds 64 characters, and this round widens nothing. A number has at most 19 digits. The key of
`quote_templates` is made by the old API as `qt-` and 36 characters, 39 in all. The key of `client_links` is a
short word and a number. So no key of a table that moves today can be too long.

Two tables that do not move today can have one. The old API allows a product's code of up to 80 characters
(small letters, digits and dashes), which is more than 64, and `stock_levels` has that code and a number for its
key. In the sample data the longest code has 31 characters, and in the admin's own catalogue file 37.

**In the live database the longest code has 31 characters too.** When this section was written the connection to
it was switched off, and the section said that the live database had not been read. It was read on 21 November
2026 at 04:53 UTC, reading only: one question, which returns the number of rows and the length of the longest
key of each table whose key is a text, and no value.

| Table and key | Rows | The longest key, in characters |
|---|---|---|
| `products.id` | 148 | 31 |
| `stock_levels.product_id` | 0 | no row |
| `quote_templates.id` | 0 | no row |
| `materials.id` | 16 | 14 |
| `meta.key` | 2 | 27 |
| `services.id` | 7 | 1 |
| `sessions.token` | 3 | 64 |

So no key that is in the live database today is longer than the column; the one that fills it, a session's, stays
in the old system. That says nothing of a code that somebody types tomorrow: the old API still allows 80
characters. `products` stays and `stock_levels` waits for T-109, so nothing is refused today. The line that moves
either of them reads the longest key again first; if it is longer than 64, that line adds one migration that only
widens the column. Until then the class refuses such a key by name.

### Who else writes the table

Six classes that came with the admin write or delete entries, for other old systems and under names of their
own. None is changed by this round.

| File (under `platform/`) | What it brings over | Its names |
|---|---|---|
| `app/Domain/System/Import/V21Importer.php` | The studio's older CRM | `wx_users`, `wx_clients`, `wx_leads`, `wx_lead_notes`, `wx_bookings` |
| `app/Domain/System/Import/LeadCsvImporter.php` | A list of leads from a file | `lead_csv_` and the year |
| `app/Console/Commands/ImportWebsite.php` | A website's pages and pictures, as one bundle | `website-bundle`, `website-image` |
| `app/Domain/Website/Import/ImportLegacyPage.php` | One page of the old website | `website-page` |
| `app/Domain/Website/Import/ImportLegacyMedia.php` | The pictures of the old website | `website-image` |
| `app/Console/Commands/ResetWebsite.php` | Nothing: it deletes the website's entries | `website-bundle`, `website-page`, `website-image`, `legacy-website` |

The plan counted these six. Reading every file found two more that name the table, and the test lists them
too: `app/Console/Commands/ImportLegacy.php` only asks whether a page was brought over, and the test checks
that it does not write; `database/quarantine/interior-content/2026_10_17_000200_reload_the_complete_website.php`
is a migration that is kept out of use and wrote a marker under `website-reload`.

### How §8 and §9 are kept true

| When this changes | Which check says so |
|---|---|
| A migration of the old system adds a table, a key, or a word to a list | `scripts/verify-old-shape.mjs` (in `build-and-test`): the file that tells the admin the old system's shape is no longer what the schema gives |
| A word of a table that moves has neither a place nor a reason | The same script names the word; so does the admin's test, which sends every word through the conversion |
| A word is sent to a value the admin's list does not have | Both, with the word and the list |
| A unique column is added to a table an importer writes to | The admin's test: the database reports a unique column that has no written rule |
| A new file of the admin names `legacy_refs` | The admin's test names the file |
| An importer that came with the admin takes a new name, or one that begins with `node.` | The admin's test: the list of names in use differs |
| The map sends a table somewhere else | The script: the names of §9 are written again, and the test's count follows the map |
| This document no longer shows what the two files say | The script names the block to write again (`node scripts/verify-old-shape.mjs --write`) |

`platform/tests/Feature/OldSystem/` holds the admin's tests: 66 of them, run on MySQL 8.4, MariaDB 10.11 and
MariaDB 11.4 in CI and on SQLite in a sandbox. They were tried against 38 mistakes, one at a time, on a copy:
money rounded instead of refused, a word taken out, a time handed over in UTC, the check for a changed row
removed, a key written as it comes, a seventh class that writes the table, and 32 more. Each was noticed and
named ([report 38](38-RELEASE-2026-11-20-VALUES-AND-RECORD-NUMBERS.md)). `scripts/test-verify-old-shape.mjs` does the
same for the script.
