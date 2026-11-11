# Migration register — WOODEX OS platform for Woodex Furniture

**Status: review record.** Every migration below was read in full before it was run. Nothing here is a schema design decision for Furniture's own modules; those come with each later phase.

**Reviewed:** 2026-11-11 · **Source:** `woodex420/woodexinterior` @ `603428c` · **Applies to:** `platform/database/`

---

## 1. Summary

| Set | Count | Treatment |
|---|---|---|
| Schema and reference migrations | 72 | In `platform/database/migrations/`. Run on SQLite locally and on MySQL 8.4 in CI |
| Interior website content migrations | 11 | Quarantined in `platform/database/quarantine/interior-content/`. Outside the migration path; never run |
| Settings migrations | 21 | In `platform/database/settings/`. Run. Several write Interior values as defaults — section 5 |

The 72 create 105 tables (106 with the migration log), 152 foreign keys and 188 indexes, counted on SQLite.

How each was reviewed: the executable statements of every file — `up()` and `down()` — were read with comments removed.
The earlier proposal had classified 64 of them by pattern only; that gap is closed.

## 2. Findings

| # | Finding | Consequence |
|---|---|---|
| M1 | `create_telegram_pending_messages_table`: `down()` dropped a table name that `up()` never created | **Fixed** in this copy, with a test. The defect also exists in the Interior source |
| M2 | Four migrations have no `down()`: the activity log, media and settings package stubs, and `create_document_libraries`. One more (`add_input_tax_account`) has an empty one | A full step-by-step rollback is not possible. `migrate:fresh` works and is exercised in CI |
| M3 | No raw SQL and no driver-specific branches anywhere | Portable between SQLite and MySQL; confirmed by the CI run on MySQL 8.4 |
| M4 | All money is `bigInteger` minor units; rates are basis points; quantities are `decimal(14,3)` | Meets the integer-money rule |
| M5 | Every moment is a `timestamp` column; every business date is a `date` column | The split the UTC rule needs is already in the schema. MySQL `TIMESTAMP` ends on 2038-01-19 — a long-term item, not a blocker |
| M6 | `quote_templates` defaults are Interior-shaped (`interior`, `execution`, `execution_50_40_10`) | Change with a new migration when business lines are re-scoped for Furniture |
| M7 | Tables of switched-off modules are referenced by core tables: quotes → conversation messages, projects → website pages, team members and pages → website media | Their schema must stay even while the modules are off. The tables stay empty |
| M8 | `create_document_libraries` inserts Interior and Furniture rows together | Kept unchanged for now — section 3 |
| M9 | Two migrations take column defaults from application enum classes | They cannot run without the application code; acceptable inside this application |

## 3. Difference from the proposal

The proposal said `create_document_libraries` would be adapted so that only Furniture rows are written. It is unchanged.
Reading the code showed why: every quotation and invoice on a non-Furniture business line loads the `interior` document design and fails without it, and the inherited tests use that line throughout.
Removing the Interior rows belongs with the re-scoping of business lines in Phase 2. Until then a Furniture database holds one unused Interior design row and seven Interior description starters — reference wording, no prices, no customer data.

## 4. The 72 migrations

`down()`: **yes** = reverses the change; **no** = absent; **empty** = present but does nothing.

| Id | Migration | What `up()` does | `down()` | Notes |
|---|---|---|---|---|
| `0001_01_01_000000` | `create_users_table` | creates `users`, `password_reset_tokens`, `sessions` | yes | Standard Laravel users, password resets, sessions; adds phone, title, active flag, last sign-in |
| `0001_01_01_000001` | `create_cache_table` | creates `cache`, `cache_locks` | yes |  |
| `0001_01_01_000002` | `create_jobs_table` | creates `jobs`, `job_batches`, `failed_jobs` | yes |  |
| `2026_10_15_000001` | `add_two_factor_columns_to_users_table` | alters `users` | yes |  |
| `2026_10_15_000002` | `create_permission_tables` | creates `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` | yes | Spatie permission package schema |
| `2026_10_15_000003` | `create_activity_log_table` | creates `activity_log` | no | Spatie activity log; package stub ships without `down()` |
| `2026_10_15_000004` | `create_media_table` | creates `media` | no | Spatie media library; package stub ships without `down()` |
| `2026_10_15_000005` | `create_settings_table` | creates `settings` | no | Spatie settings; package stub ships without `down()` |
| `2026_10_15_000006` | `create_notifications_table` | creates `notifications` | yes |  |
| `2026_10_15_000100` | `create_system_tables` | creates `number_sequences`, `webhook_events`, `integration_logs`, `telegram_links`, `alert_rules`, `approvals`, `change_requests`, `legacy_refs` | yes | Number series, inbound webhook events, integration log, Telegram links, alert rules, approvals, change requests, legacy import references |
| `2026_10_15_000200` | `create_crm_tables` | creates `companies`, `contacts`, `tags`, `taggables`, `pipeline_stages`, `leads`, `activities`, `tasks`, `site_visits` | yes | Lead keeps its contact and stage (`restrictOnDelete`); activities and tasks follow their lead |
| `2026_10_15_000300` | `create_sales_tables` | creates `catalog_items`, `estimator_rates`, `quote_templates`, `quotes`, `quote_sections`, `quote_items`, `quote_milestones`, `quote_versions`, `quote_events` | yes | Money as integer minor units, rates in basis points, quantity `decimal(14,3)`. `quote_templates` defaults are Interior-shaped (`interior`, `execution`, `execution_50_40_10`) |
| `2026_10_15_000400` | `create_projects_and_billing_tables` | creates `bank_accounts`, `projects`, `project_milestones`, `invoices`, `invoice_items`, `payments`, `payment_allocations` | yes | Invoices and payments keep their contact; an allocation keeps its invoice (`restrictOnDelete`) |
| `2026_10_16_000100` | `create_crm_automation_tables` | creates `assignment_rules`, `saved_views`; alters `tasks`, `leads` | yes |  |
| `2026_10_16_000200` | `create_website_tables` | creates `website_media`, `pages`, `page_blocks`, `redirects`, `navigation_items`, `page_versions` | yes | Schema of the switched-off website module; kept because other tables reference it |
| `2026_10_16_000300` | `add_settings_to_page_blocks` | alters `page_blocks` | yes |  |
| `2026_10_16_000350` | `backdate_import_path_columns` | alters `pages`, `page_blocks` | yes | Guarded: adds two columns only if missing |
| `2026_10_16_000700` | `add_invoice_to_payments` | alters `payments` | yes |  |
| `2026_10_16_000800` | `create_project_updates_table` | creates `project_updates` | yes |  |
| `2026_10_16_000900` | `create_project_handover_items_table` | creates `project_handover_items`; alters `projects` | yes | Foreign key from projects to website pages (`case_study_page_id`) |
| `2026_10_16_000901` | `create_portal_access_codes_table` | creates `portal_access_codes` | yes |  |
| `2026_10_16_120000` | `create_conversations_tables` | creates `conversations`, `messages` | yes | Column defaults come from application enum classes |
| `2026_10_17_000300` | `add_whatsapp_webhook_recovery` | alters `webhook_events` | yes |  |
| `2026_10_17_010000` | `add_acceptance_hint_to_quotes` | alters `quotes` | yes | Foreign key from quotes to conversation messages |
| `2026_10_17_010000` | `create_mcp_access_tokens_table` | creates `mcp_access_tokens` | yes |  |
| `2026_10_17_020000` | `create_google_accounts_tables` | creates `google_identities`, `google_connections` | yes | Token columns are plain `text`; encryption is the model’s job — verify before the Google module is ever switched on |
| `2026_10_17_030000` | `unique_invoice_project_milestone` | alters `invoices` | yes | Reads invoices first and stops with an explanation if duplicates exist; changes no row |
| `2026_10_17_040000` | `create_whatsapp_templates_table` | creates `whatsapp_templates` | yes | Column default comes from an application enum class |
| `2026_10_17_050000` | `create_scheduled_task_runs_table` | creates `scheduled_task_runs` | yes |  |
| `2026_10_17_060000` | `create_canned_replies_table` | creates `canned_replies` | yes |  |
| `2026_10_17_070000` | `create_theme_tokens_table` | creates `theme_tokens` | yes |  |
| `2026_10_17_080000` | `create_saved_sections_table` | creates `saved_sections` | yes |  |
| `2026_10_17_120000` | `create_document_libraries` | creates `description_templates`, `document_designs`; writes reference rows | no | **Mixed.** Creates two tables, then inserts an Interior and a Furniture document design and 12 description starters (7 Interior, 5 Furniture). No `down()`. Kept unchanged — see section 3 |
| `2026_10_17_130000` | `item_images` | alters `catalog_items`, `quote_items` | yes |  |
| `2026_10_17_140000` | `create_accounts_tables` | creates `chart_of_accounts`, `accounting_periods`, `journal_entries`, `journal_lines` | yes |  |
| `2026_10_17_160000` | `create_payment_method_accounts` | creates `payment_method_accounts` | yes | Guarded: skips if the table exists |
| `2026_10_17_170000` | `create_estimates` | creates `estimates`; alters `quotes` | yes | Guarded on table and column |
| `2026_10_17_180000` | `create_automations` | creates `automations` | yes | Guarded: skips if the table exists |
| `2026_10_17_190000` | `add_widget_to_conversations` | alters `conversations` | yes | Guarded on table and columns |
| `2026_10_17_210000` | `create_monthly_reports` | creates `monthly_reports` | yes | Guarded: skips if the table exists |
| `2026_10_18_100000` | `create_telegram_pending_messages_table` | creates `pending_telegram_messages` | yes | **Defect M1, fixed:** `down()` dropped `telegram_pending_messages`; `up()` creates `pending_telegram_messages` |
| `2026_10_18_120000` | `add_conversation_reply_sla` | alters `conversations` | yes |  |
| `2026_10_18_130000` | `create_automation_sequences` | creates `automation_sequences`, `automation_steps`, `automation_enrolments`, `automation_step_runs` | yes |  |
| `2026_10_18_140000` | `create_bot_tables` | creates `bot_knowledge`, `bot_usages` | yes |  |
| `2026_10_18_151000` | `add_block_key_to_page_blocks` | alters `page_blocks`; writes reference rows | yes | Guarded. On an empty database the column already exists and nothing runs |
| `2026_10_18_152000` | `add_mega_to_navigation_items` | alters `navigation_items` | yes |  |
| `2026_10_18_154000` | `create_page_templates_table` | creates `page_templates` | yes |  |
| `2026_10_18_155000` | `create_content_entries_table` | creates `content_entries` | yes |  |
| `2026_10_18_160000` | `create_team_members_table` | creates `team_members` | yes |  |
| `2026_10_18_162000` | `create_pagespeed_runs_table` | creates `pagespeed_runs` | yes |  |
| `2026_10_18_163000` | `add_canvas_to_pages` | alters `pages` | yes | Guarded: skips if the column exists |
| `2026_10_18_170000` | `create_social_posts_table` | creates `social_posts` | yes |  |
| `2026_10_18_180000` | `add_anonymised_at_to_contacts` | alters `contacts` | yes |  |
| `2026_10_18_190000` | `create_project_expenses_table` | creates `project_expenses` | yes |  |
| `2026_10_20_120000` | `widen_canvas_html_on_pages` | alters `pages` | yes | Column type change (`text` to `longText`) |
| `2026_10_21_140000` | `create_integration_connections_table` | creates `integration_connections` | yes |  |
| `2026_10_26_120000` | `create_recurring_invoices_table` | creates `recurring_invoices` | yes |  |
| `2026_10_26_120100` | `add_recurring_invoice_sequence` | reference rows only | yes | Inserts number series `RI` if missing |
| `2026_10_26_150000` | `create_vendors_table` | creates `vendors` | yes |  |
| `2026_10_26_150100` | `create_bills_tables` | creates `bills`, `bill_items` | yes | Bill keeps its vendor; a bill line keeps its account (`restrictOnDelete`) |
| `2026_10_26_150200` | `create_bill_payments_table` | creates `bill_payments` | yes |  |
| `2026_10_26_150300` | `create_recurring_expenses_table` | creates `recurring_expenses` | yes |  |
| `2026_10_26_150400` | `create_expenses_table` | creates `expenses` | yes |  |
| `2026_10_26_150500` | `add_posting_to_project_expenses` | alters `project_expenses` | yes |  |
| `2026_10_26_150600` | `add_input_tax_account` | reference rows only | empty | Inserts account 1210 only if the chart exists and the code is free; `down()` is empty |
| `2026_10_26_160000` | `add_pdf_design_to_document_designs` | alters `document_designs` | yes |  |
| `2026_10_26_170000` | `add_manual_invoice_sequence` | reference rows only | yes | Inserts number series `MI` if missing |
| `2026_10_26_180000` | `add_type_site_intro_to_quotes` | alters `quotes` | yes |  |
| `2026_10_26_190000` | `align_default_document_accent` | reference rows only | yes | Changes one colour value on rows that still carry the old default |
| `2026_10_26_200000` | `create_outbound_webhooks_tables` | creates `outbound_webhooks`, `webhook_deliveries` | yes |  |
| `2026_10_27_200000` | `add_furniture_documents` | alters `quote_items`, `invoice_items`, `quotes`, `invoices`, `document_designs`; writes reference rows | yes | Furniture document columns; inserts number series `WFI` if missing |
| `2026_11_02_180000` | `link_project_cost_sources` | creates `project_cost_requests`; alters `bills`, `expenses`, `bill_payments` | yes | Idempotency key on bill payments; project cost requests keyed by a request id |

## 5. The 21 settings migrations

Each adds default values to the `settings` table. None creates a table. Most have no `down()`.

| Migration | What it writes |
|---|---|
| `create_business_settings` | Business identity. **Interior values:** legal and brand name, address, phone, WhatsApp, e-mail, website, hours |
| `create_sales_settings` | Quotation and sales rules. **Interior values:** payment terms, signatories, discount limits, budget bands, lost reasons |
| `create_notification_settings` | Quiet hours, digest time, roles for unassigned leads |
| `add_crm_automation_settings` | Pakistan public holidays 2026–27; response targets by source; lead scoring. **Scoring is Interior-shaped** (square feet, fit-out, renovation) |
| `create_site_settings` | Website identity defaults for the switched-off website module |
| `add_invoice_settings` | Advance invoice mode and invoice terms. **Interior wording** |
| `add_project_settings` | One flag: tell the client when a project stage changes |
| `add_handover_settings` | Handover checklist. **Interior fit-out items** (paint, plumbing, keys); review request delay |
| `add_review_url` | Empty Google review address |
| `add_quote_follow_up_settings` | Follow-up and expiry warning days |
| `create_integration_settings` | WhatsApp connection fields, all empty |
| `add_chat_widget_setting` | Chat widget off |
| `add_chat_alert_sound_setting` | Chat alert sound off |
| `add_conversation_sla_settings` | Reply targets for conversations |
| `add_whatsapp_cost_settings` | WhatsApp cost tracking, all zero |
| `add_automation_safe_limits` | Daily contact limit and quiet hours for automation |
| `create_bot_settings` | Chat bot off, no provider, no key |
| `add_header_footer_settings` | Website header and footer fields (removed again below) |
| `add_speed_settings` | Empty page-speed key |
| `remove_the_header_footer_settings` | Removes the eight header and footer fields |
| `add_invoice_automation_settings` | Invoice reminder days |

**Not adapted yet.** The rows marked Interior are the studio's real name, address, phone, e-mail, payment terms and signatories.
They are written into any database these migrations run on. Furniture values have to come from the owner (decision D-8); nothing was invented.
The synthetic preview overwrites them with labelled placeholders after migrating.

## 6. The 11 quarantined migrations

| Id | Migration | Why it must not run for Furniture |
|---|---|---|
| `2026_10_16_000300` | `seed_legacy_redirects` | Inserts the 54 redirects of the old Interior website |
| `2026_10_16_000400` | `import_website_content` | Imports Interior pages and media from the bundled website |
| `2026_10_16_000500` | `ensure_website_content` | Re-runs the Interior import if pages are missing |
| `2026_10_16_000600` | `repair_imported_image_alt` | Rewrites alt text on imported Interior images |
| `2026_10_16_110000` | `publish_imported_website_pages` | Publishes the imported Interior pages |
| `2026_10_16_110010` | `add_enquiry_form_to_home` | Adds a block to the Interior home page |
| `2026_10_16_130000` | `add_call_to_action_to_pages` | Adds blocks to Interior pages |
| `2026_10_16_130100` | `link_city_pages_to_their_areas` | Edits Interior city pages |
| `2026_10_16_130200` | `correct_the_privacy_page_hosting_claims` | Edits the Interior privacy page |
| `2026_10_17_000200` | `reload_the_complete_website` | Empties the website tables and re-imports 143 Interior pages and 105 photographs |
| `2026_10_21_090000` | `remove_the_furniture_page` | Deletes one page of the Interior site |

They also depend on `database/data/`, which was not copied, so they could not run even by mistake.
The CI job checks on every run that none of them is recorded as run and that all eleven are still in the quarantine folder.
