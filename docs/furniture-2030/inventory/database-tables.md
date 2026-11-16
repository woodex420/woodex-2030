# Database tables

Generated from the code at commit `9b8cdca` by `docs/furniture-2030/evidence/generate-inventory.mjs`. Do not edit by hand; re-run the script.

Read from a seeded local SQLite database. **71 tables.** "FKs (SQLite)" is the number of foreign keys SQLite itself declares; PostgreSQL adds its own through `scripts/migrations`. "ID gap" marks tables whose generated id the API reads back but which are missing from `RETURN_ID_TABLES`, so PostgreSQL returns 0 (see `../02-AUDIT-FINDINGS.md`). "Demo rows" is the row count after the local demo seed.

## Summary by module

| Module | Tables | With ID gap |
|---|---|---|
| CRM | 10 | 5 |
| Catalogue | 3 | 0 |
| Delivery and installation | 4 | 4 |
| Finance | 9 | 5 |
| Inbox and messaging | 2 | 0 |
| Inventory | 7 | 5 |
| Marketing and analytics | 1 | 0 |
| Orders and returns | 2 | 0 |
| Other | 2 | 0 |
| Payables | 2 | 1 |
| Platform (auth, users, audit) | 5 | 1 |
| Procurement | 5 | 3 |
| Production and quality | 4 | 2 |
| Projects and BOQ | 4 | 0 |
| Quotations | 3 | 0 |
| Support and warranty | 4 | 4 |
| Website and CMS | 4 | 0 |

SQLite declares 0 foreign keys across all tables. PostgreSQL schema and migrations contain 64 `REFERENCES` clauses and 124 `CHECK` clauses.

## All tables

| Module | Table | Columns | FKs (SQLite) | ID gap | Demo rows | Money-like columns and their SQLite types |
|---|---|---|---|---|---|---|
| Catalogue | `materials` | 2 | 0 |  | 16 |  |
| Catalogue | `products` | 6 | 0 |  | 148 | price REAL |
| Catalogue | `services` | 2 | 0 |  | 7 |  |
| CRM | `activities` | 9 | 0 | **yes** | 0 |  |
| CRM | `attachments` | 11 | 0 | **yes** | 0 |  |
| CRM | `client_links` | 3 | 0 |  | 21 |  |
| CRM | `clients` | 15 | 0 |  | 13 |  |
| CRM | `companies` | 14 | 0 | **yes** | 0 |  |
| CRM | `contacts` | 13 | 0 | **yes** | 0 |  |
| CRM | `leads` | 26 | 0 |  | 6 |  |
| CRM | `meetings` | 17 | 0 | **yes** | 0 |  |
| CRM | `site_visits` | 16 | 0 |  | 0 |  |
| CRM | `tasks` | 13 | 0 |  | 3 |  |
| Delivery and installation | `deliveries` | 17 | 0 | **yes** | 0 |  |
| Delivery and installation | `delivery_lines` | 7 | 0 | **yes** | 0 |  |
| Delivery and installation | `dispatch_runs` | 14 | 0 | **yes** | 0 |  |
| Delivery and installation | `installed_assets` | 18 | 0 | **yes** | 0 |  |
| Finance | `accounts` | 8 | 0 | **yes** | 16 |  |
| Finance | `credit_notes` | 15 | 0 | **yes** | 0 | subtotal REAL, tax REAL, total REAL |
| Finance | `expenses` | 15 | 0 | **yes** | 0 | amount REAL, tax_amount REAL |
| Finance | `invoices` | 17 | 0 |  | 2 | subtotal REAL, discount REAL, tax REAL, shipping REAL, total REAL, paid REAL |
| Finance | `journal_entries` | 9 | 0 | **yes** | 0 |  |
| Finance | `journal_lines` | 6 | 0 |  | 0 | debit REAL, credit REAL |
| Finance | `payment_schedules` | 10 | 0 | **yes** | 0 | amount REAL, paid REAL |
| Finance | `payments` | 7 | 0 |  | 1 | amount REAL |
| Finance | `tax_records` | 9 | 0 |  | 0 | rate REAL, taxable_base REAL, tax_amount REAL |
| Inbox and messaging | `conversations` | 9 | 0 |  | 0 |  |
| Inbox and messaging | `messages` | 8 | 0 |  | 0 |  |
| Inventory | `stock_levels` | 4 | 0 | **yes** | 0 |  |
| Inventory | `stock_movements` | 11 | 0 |  | 149 |  |
| Inventory | `stock_reservations` | 9 | 0 | **yes** | 0 |  |
| Inventory | `stock_transfer_lines` | 4 | 0 |  | 0 |  |
| Inventory | `stock_transfers` | 11 | 0 | **yes** | 0 |  |
| Inventory | `storage_locations` | 9 | 0 | **yes** | 0 |  |
| Inventory | `warehouses` | 12 | 0 | **yes** | 0 |  |
| Marketing and analytics | `track_events` | 13 | 0 |  | 0 |  |
| Orders and returns | `orders` | 12 | 0 |  | 8 | total REAL |
| Orders and returns | `returns` | 11 | 0 |  | 1 | refund_amount REAL |
| Other | `meta` | 2 | 0 |  | 1 |  |
| Other | `supplier_payments` | 8 | 0 |  | 0 | amount REAL |
| Payables | `supplier_invoice_lines` | 8 | 0 |  | 0 | unit_cost REAL, line_total REAL |
| Payables | `supplier_invoices` | 21 | 0 | **yes** | 0 | subtotal REAL, tax REAL, total REAL, paid REAL |
| Platform (auth, users, audit) | `audit_logs` | 12 | 0 |  | 2 |  |
| Platform (auth, users, audit) | `password_resets` | 9 | 0 | **yes** | 0 |  |
| Platform (auth, users, audit) | `sessions` | 4 | 0 |  | 7 |  |
| Platform (auth, users, audit) | `throttle_buckets` | 8 | 0 |  | 36 |  |
| Platform (auth, users, audit) | `users` | 9 | 0 |  | 5 |  |
| Procurement | `goods_receipt_lines` | 4 | 0 |  | 0 |  |
| Procurement | `goods_receipts` | 7 | 0 | **yes** | 0 |  |
| Procurement | `purchase_order_lines` | 9 | 0 |  | 0 | unit_cost REAL |
| Procurement | `purchase_orders` | 14 | 0 | **yes** | 0 |  |
| Procurement | `suppliers` | 14 | 0 | **yes** | 0 |  |
| Production and quality | `bom_lines` | 8 | 0 |  | 0 |  |
| Production and quality | `boms` | 10 | 0 | **yes** | 0 |  |
| Production and quality | `job_card_materials` | 8 | 0 |  | 0 |  |
| Production and quality | `job_cards` | 19 | 0 | **yes** | 0 |  |
| Projects and BOQ | `boq_lines` | 9 | 0 |  | 0 | unit_cost REAL |
| Projects and BOQ | `project_files` | 8 | 0 |  | 0 |  |
| Projects and BOQ | `project_milestones` | 8 | 0 |  | 0 |  |
| Projects and BOQ | `projects` | 16 | 0 |  | 0 | planned_cost REAL, actual_cost REAL |
| Quotations | `quote_templates` | 5 | 0 |  | 0 |  |
| Quotations | `quote_versions` | 7 | 0 |  | 5 |  |
| Quotations | `quotes` | 15 | 0 |  | 5 | total REAL |
| Support and warranty | `service_visits` | 16 | 0 | **yes** | 0 | charge_amount REAL |
| Support and warranty | `ticket_messages` | 7 | 0 | **yes** | 0 |  |
| Support and warranty | `tickets` | 24 | 0 | **yes** | 0 |  |
| Support and warranty | `warranty_claims` | 16 | 0 | **yes** | 0 | cost_to_woodex REAL |
| Website and CMS | `page_blocks` | 5 | 0 |  | 9 |  |
| Website and CMS | `page_versions` | 6 | 0 |  | 1 |  |
| Website and CMS | `pages` | 10 | 0 |  | 2 |  |
| Website and CMS | `saved_sections` | 7 | 0 |  | 1 |  |

## Columns

Primary-key columns are marked `PK`; `!` marks NOT NULL.

- **`accounts`** — id INTEGER PK; code TEXT !; name TEXT !; kind TEXT !; active INTEGER !; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`activities`** — id INTEGER PK; entity_type TEXT !; entity_id INTEGER !; kind TEXT !; summary TEXT !; detail TEXT; actor TEXT; occurred_at TEXT !; created_at TEXT !
- **`attachments`** — id INTEGER PK; entity_type TEXT !; entity_id INTEGER !; filename TEXT !; mime_type TEXT; size_bytes INTEGER; path TEXT; checksum TEXT; uploaded_by TEXT; notes TEXT; created_at TEXT !
- **`audit_logs`** — id INTEGER PK; actor_id INTEGER; actor_email TEXT; actor_name TEXT; actor_role TEXT; action TEXT !; entity_type TEXT !; entity_id TEXT; before_json TEXT; after_json TEXT; ip TEXT; created_at TEXT !
- **`bom_lines`** — id INTEGER PK; bom_id INTEGER !; line_no INTEGER !; item_kind TEXT !; item_id TEXT !; qty_per_output INTEGER !; unit TEXT; note TEXT
- **`boms`** — id INTEGER PK; ref TEXT !; product_id TEXT !; name TEXT !; version INTEGER !; output_qty INTEGER !; active INTEGER !; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`boq_lines`** — id INTEGER PK; project_id INTEGER !; sku TEXT; description TEXT !; qty REAL !; unit TEXT; unit_cost REAL !; sort INTEGER; created_at TEXT
- **`client_links`** — client_id INTEGER !; entity TEXT !; entity_id INTEGER !
- **`clients`** — id INTEGER PK; name TEXT !; company TEXT; email TEXT; email_norm TEXT; phone TEXT; phone_norm TEXT; city TEXT; tags TEXT; notes TEXT; source TEXT; merged_into INTEGER; company_id INTEGER; created_at TEXT; updated_at TEXT
- **`companies`** — id INTEGER PK; name TEXT !; name_norm TEXT !; industry TEXT; website TEXT; phone TEXT; email TEXT; tax_ref TEXT; city TEXT; address TEXT; account_owner TEXT; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`contacts`** — id INTEGER PK; company_id INTEGER; client_id INTEGER; name TEXT !; email TEXT; email_norm TEXT; phone TEXT; phone_norm TEXT; role TEXT; is_primary INTEGER !; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`conversations`** — id INTEGER PK; client_id INTEGER !; status TEXT; assignee TEXT; priority INTEGER; unread INTEGER; last_at TEXT; created_at TEXT; updated_at TEXT
- **`credit_notes`** — id INTEGER PK; ref TEXT !; invoice_id INTEGER; customer TEXT !; reason TEXT; subtotal REAL !; tax REAL !; total REAL !; status TEXT !; issued_at TEXT; applied_at TEXT; journal_entry_id INTEGER; note TEXT; created_at TEXT !; updated_at TEXT !
- **`deliveries`** — id INTEGER PK; ref TEXT !; order_id INTEGER; project_id INTEGER; dispatch_run_id INTEGER; customer TEXT !; address TEXT; delivered_at TEXT; received_by TEXT; signed_by TEXT; photo TEXT; condition TEXT !; damage_notes TEXT; status TEXT !; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`delivery_lines`** — id INTEGER PK; delivery_id INTEGER !; product_id TEXT; description TEXT !; qty INTEGER !; unit TEXT; serial TEXT
- **`dispatch_runs`** — id INTEGER PK; ref TEXT !; vehicle TEXT; driver TEXT; crew TEXT; address TEXT; city TEXT; scheduled_for TEXT; departed_at TEXT; returned_at TEXT; status TEXT !; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`expenses`** — id INTEGER PK; ref TEXT !; category TEXT !; payee TEXT; amount REAL !; tax_amount REAL !; spent_at TEXT !; method TEXT; reference TEXT; account_id INTEGER; journal_entry_id INTEGER; note TEXT; created_by TEXT; created_at TEXT !; updated_at TEXT !
- **`goods_receipt_lines`** — id INTEGER PK; goods_receipt_id INTEGER !; purchase_order_line_id INTEGER !; qty_received INTEGER !
- **`goods_receipts`** — id INTEGER PK; ref TEXT !; purchase_order_id INTEGER !; received_at TEXT !; received_by TEXT; note TEXT; created_at TEXT !
- **`installed_assets`** — id INTEGER PK; tag TEXT !; order_id INTEGER; project_id INTEGER; delivery_id INTEGER; product_id TEXT; name TEXT !; location TEXT; room TEXT; serial TEXT; installed_on TEXT; installed_by TEXT; warranty_months INTEGER; warranty_until TEXT; status TEXT !; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`invoices`** — id INTEGER PK; ref TEXT; quote_id INTEGER; order_id INTEGER; customer TEXT !; items TEXT !; subtotal REAL !; discount REAL !; tax REAL !; shipping REAL !; total REAL !; paid REAL !; status TEXT; due TEXT; notes TEXT; created_at TEXT; updated_at TEXT
- **`job_card_materials`** — id INTEGER PK; job_card_id INTEGER !; line_no INTEGER !; item_kind TEXT !; item_id TEXT !; qty_required INTEGER !; qty_issued INTEGER !; created_at TEXT !
- **`job_cards`** — id INTEGER PK; ref TEXT !; bom_id INTEGER; product_id TEXT !; order_id INTEGER; qty_to_build INTEGER !; qty_built INTEGER !; stage TEXT !; assigned_to TEXT; started_at TEXT; completed_at TEXT; qc_passed_at TEXT; qc_by TEXT; qc_note TEXT; due_at TEXT; notes TEXT; created_by TEXT; created_at TEXT !; updated_at TEXT !
- **`journal_entries`** — id INTEGER PK; ref TEXT !; entry_date TEXT !; memo TEXT; source_type TEXT; source_id INTEGER; reverses_id INTEGER; posted_by TEXT; created_at TEXT !
- **`journal_lines`** — id INTEGER PK; entry_id INTEGER !; account_id INTEGER !; debit REAL !; credit REAL !; description TEXT
- **`leads`** — id INTEGER PK; ref TEXT; name TEXT !; company TEXT; interest TEXT; source TEXT; contact TEXT; status TEXT; owner TEXT; note TEXT; email TEXT; phone TEXT; preferred_channel TEXT; contact_consent INTEGER; is_returning INTEGER; identity_review INTEGER; consent_at TEXT; consent_version TEXT; landing_path TEXT; referrer TEXT; utm_source TEXT; utm_medium TEXT; utm_campaign TEXT; company_id INTEGER; created_at TEXT; updated_at TEXT
- **`materials`** — id TEXT PK; json TEXT !
- **`meetings`** — id INTEGER PK; ref TEXT !; title TEXT !; company_id INTEGER; client_id INTEGER; lead_id INTEGER; starts_at TEXT !; minutes INTEGER !; location TEXT; channel TEXT !; owner TEXT; status TEXT !; attendees TEXT; agenda TEXT; outcome TEXT; created_at TEXT !; updated_at TEXT !
- **`messages`** — id INTEGER PK; conversation_id INTEGER !; channel TEXT !; direction TEXT; author TEXT; body TEXT !; meta TEXT; created_at TEXT
- **`meta`** — key TEXT PK; value TEXT
- **`orders`** — id INTEGER PK; ref TEXT; customer TEXT !; items TEXT !; total REAL !; status TEXT; stage TEXT; owner TEXT; due TEXT; tracking_token_hash TEXT; created_at TEXT; updated_at TEXT
- **`page_blocks`** — id INTEGER PK; page_id INTEGER !; type TEXT !; props TEXT !; sort INTEGER !
- **`page_versions`** — id INTEGER PK; page_id INTEGER !; snapshot TEXT !; note TEXT; who TEXT; created_at TEXT
- **`pages`** — id INTEGER PK; slug TEXT !; title TEXT !; status TEXT; seo_title TEXT; seo_desc TEXT; created_at TEXT; updated_at TEXT; published_snapshot TEXT; theme TEXT
- **`password_resets`** — id INTEGER PK; user_id INTEGER !; token_hash TEXT !; reason TEXT !; requested_by INTEGER; created_at TEXT !; expires_at TEXT !; used_at TEXT; revoked_at TEXT
- **`payment_schedules`** — id INTEGER PK; invoice_id INTEGER !; seq INTEGER !; due_date TEXT !; amount REAL !; paid REAL !; status TEXT !; note TEXT; created_at TEXT !; updated_at TEXT !
- **`payments`** — id INTEGER PK; invoice_id INTEGER !; amount REAL !; method TEXT; reference TEXT; note TEXT; created_at TEXT
- **`products`** — id TEXT PK; json TEXT !; price REAL !; in_stock INTEGER !; stock_qty INTEGER !; updated_at TEXT !
- **`project_files`** — id INTEGER PK; project_id INTEGER !; name TEXT !; kind TEXT; size INTEGER; url TEXT; uploaded_by TEXT; created_at TEXT
- **`project_milestones`** — id INTEGER PK; project_id INTEGER !; title TEXT !; due_at TEXT; done INTEGER; sort INTEGER; note TEXT; created_at TEXT
- **`projects`** — id INTEGER PK; ref TEXT !; name TEXT !; client_id INTEGER !; order_id INTEGER; owner TEXT; stage TEXT !; address TEXT; start_at TEXT; due_at TEXT; planned_cost REAL; actual_cost REAL; progress INTEGER; notes TEXT; created_at TEXT; updated_at TEXT
- **`purchase_order_lines`** — id INTEGER PK; purchase_order_id INTEGER !; line_no INTEGER !; item_kind TEXT !; item_id TEXT !; description TEXT; qty_ordered INTEGER !; unit TEXT; unit_cost REAL !
- **`purchase_orders`** — id INTEGER PK; ref TEXT !; supplier_id INTEGER !; status TEXT !; currency TEXT !; expected_at TEXT; approved_by TEXT; approved_at TEXT; sent_at TEXT; closed_at TEXT; note TEXT; created_by TEXT; created_at TEXT !; updated_at TEXT !
- **`quote_templates`** — id TEXT PK; name TEXT !; template TEXT !; created_at TEXT !; updated_at TEXT !
- **`quote_versions`** — id INTEGER PK; quote_id INTEGER !; version INTEGER !; snapshot TEXT !; actor TEXT; action TEXT; created_at TEXT
- **`quotes`** — id INTEGER PK; ref TEXT; customer TEXT !; contact TEXT; lead_id INTEGER; items TEXT !; total REAL !; status TEXT; source TEXT; note TEXT; audit TEXT; details TEXT !; version INTEGER !; created_at TEXT; updated_at TEXT
- **`returns`** — id INTEGER PK; ref TEXT; order_id INTEGER; customer TEXT; item TEXT; reason TEXT; state TEXT; refund_amount REAL; resolution TEXT; created_at TEXT; updated_at TEXT
- **`saved_sections`** — id INTEGER PK; name TEXT !; category TEXT; tags TEXT; block TEXT !; created_at TEXT; updated_at TEXT
- **`service_visits`** — id INTEGER PK; ref TEXT !; ticket_id INTEGER !; scheduled_for TEXT; technician TEXT; address TEXT; status TEXT !; arrived_at TEXT; completed_at TEXT; work_done TEXT; parts_used TEXT; chargeable INTEGER !; charge_amount REAL; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`services`** — id TEXT PK; json TEXT !
- **`sessions`** — token TEXT PK; user_id INTEGER !; created_at TEXT; expires_at TEXT
- **`site_visits`** — id INTEGER PK; ref TEXT !; lead_id INTEGER !; client_id INTEGER !; scheduled_at TEXT !; timezone TEXT !; address TEXT !; owner TEXT; status TEXT !; notes TEXT; outcome TEXT; reminder_at TEXT; reminder_task_id INTEGER; audit TEXT !; created_at TEXT; updated_at TEXT
- **`stock_levels`** — product_id TEXT PK !; location_id INTEGER PK !; qty INTEGER !; updated_at TEXT !
- **`stock_movements`** — id INTEGER PK; product_id TEXT !; delta INTEGER !; reason TEXT !; ref_type TEXT; ref_id INTEGER; note TEXT; created_by TEXT; created_at TEXT !; idem_key TEXT; location_id INTEGER
- **`stock_reservations`** — id INTEGER PK; product_id TEXT !; qty INTEGER !; ref_type TEXT !; ref_id INTEGER; status TEXT !; expires_at TEXT; created_at TEXT !; released_at TEXT
- **`stock_transfer_lines`** — id INTEGER PK; transfer_id INTEGER !; product_id TEXT !; qty INTEGER !
- **`stock_transfers`** — id INTEGER PK; ref TEXT !; from_location_id INTEGER !; to_location_id INTEGER !; status TEXT !; note TEXT; created_by TEXT; dispatched_at TEXT; received_at TEXT; created_at TEXT !; updated_at TEXT !
- **`storage_locations`** — id INTEGER PK; warehouse_id INTEGER !; code TEXT !; name TEXT !; zone TEXT; active INTEGER !; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`supplier_invoice_lines`** — id INTEGER PK; supplier_invoice_id INTEGER !; purchase_order_line_id INTEGER; line_no INTEGER !; description TEXT; qty_billed INTEGER !; unit_cost REAL !; line_total REAL !
- **`supplier_invoices`** — id INTEGER PK; ref TEXT !; supplier_id INTEGER !; purchase_order_id INTEGER; goods_receipt_id INTEGER; supplier_ref TEXT !; invoice_date TEXT !; due_date TEXT; currency TEXT !; subtotal REAL !; tax REAL !; total REAL !; paid REAL !; status TEXT !; match_state TEXT; match_json TEXT; matched_at TEXT; note TEXT; created_by TEXT; created_at TEXT !; updated_at TEXT !
- **`supplier_payments`** — id INTEGER PK; supplier_invoice_id INTEGER !; amount REAL !; method TEXT; reference TEXT; note TEXT; paid_at TEXT; created_at TEXT !
- **`suppliers`** — id INTEGER PK; code TEXT !; name TEXT !; contact_name TEXT; phone TEXT; email TEXT; address TEXT; tax_ref TEXT; payment_terms TEXT; currency TEXT !; active INTEGER !; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`tasks`** — id INTEGER PK; client_id INTEGER; lead_id INTEGER; quote_id INTEGER; order_id INTEGER; site_visit_id INTEGER; title TEXT !; due TEXT; owner TEXT; priority TEXT; done INTEGER; created_at TEXT; updated_at TEXT
- **`tax_records`** — id INTEGER PK; kind TEXT !; source_type TEXT !; source_id INTEGER; tax_period TEXT !; rate REAL !; taxable_base REAL !; tax_amount REAL !; created_at TEXT !
- **`throttle_buckets`** — id INTEGER PK; bucket_key TEXT !; kind TEXT !; failures INTEGER !; window_started_at TEXT !; locked_until TEXT; last_seen_at TEXT !; created_at TEXT !
- **`ticket_messages`** — id INTEGER PK; ticket_id INTEGER !; author TEXT; body TEXT !; internal INTEGER !; channel TEXT; created_at TEXT !
- **`tickets`** — id INTEGER PK; ref TEXT !; customer TEXT !; client_id INTEGER; company_id INTEGER; order_id INTEGER; project_id INTEGER; asset_id INTEGER; subject TEXT !; description TEXT; category TEXT !; channel TEXT !; priority TEXT !; status TEXT !; waiting_on TEXT !; owner TEXT; first_response_at TEXT; respond_by TEXT; resolve_by TEXT; resolved_at TEXT; resolution TEXT; closed_at TEXT; created_at TEXT !; updated_at TEXT !
- **`track_events`** — id INTEGER PK; page_id INTEGER; slug TEXT !; kind TEXT !; label TEXT; href TEXT; cid TEXT; ref TEXT; utm_source TEXT; utm_medium TEXT; utm_campaign TEXT; pct INTEGER; created_at TEXT
- **`users`** — id INTEGER PK; email TEXT !; name TEXT !; role TEXT !; pass_salt TEXT; pass_hash TEXT; active INTEGER; created_at TEXT; updated_at TEXT
- **`warehouses`** — id INTEGER PK; code TEXT !; name TEXT !; city TEXT; address TEXT; manager TEXT; phone TEXT; is_default INTEGER !; active INTEGER !; notes TEXT; created_at TEXT !; updated_at TEXT !
- **`warranty_claims`** — id INTEGER PK; ref TEXT !; ticket_id INTEGER; asset_id INTEGER; customer TEXT !; part TEXT; fault TEXT !; claimed_on TEXT !; warranty_until_on_claim TEXT; verdict TEXT !; decided_on TEXT; decided_by TEXT; reason TEXT; cost_to_woodex REAL; created_at TEXT !; updated_at TEXT !
