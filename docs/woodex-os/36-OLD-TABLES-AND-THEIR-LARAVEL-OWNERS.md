# 36 — The old system's tables, and who owns each in the admin

**Master list T-175.** One row for every table of the old system (the React shop and dashboard with their Node
API, on PostgreSQL): what it holds, whether its rows are brought over, into which tables of the Laravel admin,
which screen owns it there, and in which wave.

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

The next two lines of the master list test and settle exactly this: T-176 (the differences between PostgreSQL
and MySQL, on real shapes) and T-177 (one rule for the numbers of records).

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

## 7. What this map does not decide

1. **Whether a record moves at all.** Which customers and which documents may be brought over is yours
   (T-017, T-181). The map says where a table goes if it does.
2. **The books.** `accounts`, `journal_entries` and `journal_lines` move only if the double-entry ledger is part
   of the launch (T-013).
3. **Options of a product.** `product_variants` waits for your answer on how options are sold (T-011).
4. **The people.** By this map nobody's account is carried over: each person is invited and sets a password.
   If you want the accounts moved instead, say so; the passwords cannot be.
5. **The order of the columns, and every value's conversion.** That is the importer of each module (T-178),
   with its dry run and its reconciliation (T-179).
