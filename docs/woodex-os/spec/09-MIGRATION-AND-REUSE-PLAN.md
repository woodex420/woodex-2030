# 09 — Migration and Reuse Plan

## 1. Objective

Move from the existing React + Express/Node + PostgreSQL/Vercel operating system and imported Laravel foundation toward a React + Laravel + MySQL system deployed for Hostinger Shared Hosting, without losing business records or interrupting critical workflows.

## 2. Current-state evidence

Repository discovery found:
- Existing React storefront and dashboard.
- Existing Express/Node API and current PostgreSQL/Supabase production database documented in the current-state report.
- Existing Laravel platform foundation under `platform/`.
- Laravel optional modules, several disabled or incomplete.
- Existing typed-block React CMS is the current page model authority.
- Imported HTML-file builder is not a compatible replacement.
- Puck is evaluated but not yet integrated/accepted.
- Existing React apps were left operational while Laravel foundation was imported.
- Repository docs describe a module-by-module replacement strategy and a single-writer rule.

Revalidate all counts, active deployments, database schemas and route behavior at the start of execution.

## 3. Reuse policy

### Reuse
- Existing React storefront and dashboard application shells.
- Working React UI components after design-system normalization.
- Working routes and workflows where behavior is proven.
- Generic Laravel authentication, CRM, sales, finance, project and system foundations after code review.
- Existing domain rules, validation and tests when portable.
- Existing media/catalogue/business records only after confirming they belong to the WOODEX furniture business and are authorized for migration.

### Refactor
- Dashboard sidebar and shell.
- Duplicate or conflicting navigation.
- Tailwind/design-token mismatch between storefront and dashboard.
- Existing CMS/page models to a versioned Puck-compatible model.
- Laravel modules and permissions to match the canonical product IA.
- API contracts and error handling.

### Rebuild where necessary
- Missing commerce and order workflows.
- Missing procurement, stock and production functionality.
- Missing delivery/installation and warranty workflows.
- Unified Puck Website Studio.
- Integration/automation workflows that are only placeholders.
- MySQL deployment and migration adapters.

### Exclude
- Woodex Interior brand assets and names.
- Interior-specific page content, photos, price lists and project history.
- Interior-specific workflows and seeded data.
- Unapproved historical records or unrelated customer data.

## 4. Migration rules

1. Never run Express and Laravel as simultaneous writers to the same business tables.
2. Identify canonical table owner before implementing API write paths.
3. Keep stable business identifiers where practical; otherwise maintain mapping tables.
4. Use repeatable, idempotent import scripts.
5. Preserve source snapshots for reconciliation.
6. Migrate one bounded module at a time.
7. Run read-only comparison before cutover.
8. Freeze or delta-sync source writes during final migration window.
9. Switch write authority once.
10. Keep rollback route and database snapshot until acceptance.

## 5. Recommended migration sequence

1. Identity, users, roles, permissions and audit.
2. CRM contacts and leads.
3. Sales estimates and quotations.
4. Invoices and payment records after accounting validation.
5. Product catalogue, categories, series and media.
6. Orders and customer links.
7. Suppliers and procurement.
8. Inventory ledger and warehouse balances.
9. Production jobs and stages.
10. Projects, vendors, costs and handovers.
11. Inbox, automation and integrations.
12. CMS and Puck content.
13. Reports and remaining legacy functions.

This sequence is a proposal. Dependencies and current data quality may change the order.

## 6. Per-module cutover checklist

- Source routes and tables inventoried.
- Target Laravel domain owner identified.
- Data mapping approved.
- Schema and constraints ready.
- Import script tested on a copy.
- Counts and critical totals reconciled.
- Duplicate and orphan report reviewed.
- Permissions and API tests pass.
- React workflow passes against Laravel staging.
- Background jobs and integrations tested.
- Source writes disabled or redirected.
- Target Laravel write authority enabled.
- Monitoring and rollback available.
- Owner accepts the module.

## 7. Data reconciliation

For each migrated domain compare:
- Total records.
- Records by status.
- Foreign-key relationships.
- Duplicate normalized contacts.
- Document numbers and totals.
- Invoice balances and payment allocations.
- Product/variant counts.
- Stock ledger totals by product and warehouse.
- Project costs and outstanding commitments.
- Media references and missing assets.

A count match alone is not enough; reconcile business invariants.

## 8. CMS migration

- Inventory all current page types and block types.
- Identify canonical current editor and renderer.
- Map each supported block to a Puck component.
- Preserve URLs, SEO and media references.
- Convert representative pages first.
- Compare rendered screenshots and interactions.
- Keep original page payload backup.
- Migrate page groups only after acceptance.
- Retain rollback to prior renderer/content version.

## 9. Host migration

Current documented deployment: Vercel with Node API and PostgreSQL/Supabase. Target: Hostinger Shared Hosting with Laravel and MySQL.

Migration steps:
1. Verify Hostinger plan and runtime capabilities.
2. Provision staging domain/subdomain.
3. Create MySQL database and least-privilege user.
4. Configure PHP, extensions, Composer and Laravel storage.
5. Deploy Laravel with secrets outside public web root.
6. Deploy React static builds.
7. Configure HTTPS, domains, CORS/session and mail.
8. Configure cron and queue strategy.
9. Import staging data and run acceptance.
10. Test backup and restore.
11. Obtain explicit cutover approval.
12. Switch traffic and monitor.
13. Retain rollback environment until sign-off.

## 10. Rollback strategy

- Application rollback: redeploy previous known-good build.
- Data rollback: restore pre-cutover snapshot only when no accepted post-cutover writes would be lost; otherwise perform controlled forward repair.
- Module rollback: switch read/write routing back only if source remains consistent and has not diverged.
- CMS rollback: repoint published revision to previous valid revision.
- Never assume a database restore is safe after new transactions have been accepted. Define the recovery point and recovery time before cutover.
