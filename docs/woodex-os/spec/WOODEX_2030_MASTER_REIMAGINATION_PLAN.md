# WOODEX 2030 — Master Reimagination Plan

**Project:** WOODEX Furniture 2030  
**Status:** Planning baseline; owner approval required before implementation  
**Proposed new repository:** `woodex-furniture-2030`  
**Target stack:** Laravel, Blade, Livewire, MySQL  
**Target hosting:** Hostinger Shared Web Hosting (exact plan to be verified)

## 1. Executive decision

Build a premium, SEO-led WOODEX Furniture storefront and a unified administration platform for CRM, sales, quotations, catalogue, configurator, orders, inventory, procurement, production, delivery, installation, support, CMS and reporting.

Use a Laravel modular monolith. Admin uses Laravel Blade + Livewire. Migrate the storefront gradually to server-rendered Laravel Blade, with Livewire/Alpine for progressive interaction. Compile Vite/Tailwind assets before deployment. Use a dedicated Furniture MySQL database and a versioned REST API at `/api/v1`.

Build in a **new repository**. Existing repositories are read-only source assets for audit and selective reuse—not targets to overwrite or reorganize.

## 2. Non-negotiable business boundaries

- `woodexfurniture.pk` is WOODEX Furniture.
- `woodex.com.pk` is WOODEX Interior.
- Furniture and Interior must retain separate domains, hosting, production databases, credentials, storage, backups and deployment controls.
- The Furniture app must never connect to or write to the Interior production database.
- Interior customers, leads, quotations, invoices, financial records, projects and private media are excluded from Furniture migration unless specifically authorized in writing.
- Preserve original repositories and histories.
- No production deployment, DNS change, destructive migration, bulk import or irreversible action without explicit owner approval.

## 3. Source systems

| Source | Known purpose | Required treatment |
|---|---|---|
| https://github.com/woodex420/woodexinterior | Existing Woodex OS / Laravel platform; historical inspected commit `603428cca1e45f9da2ffd9c4a0d88eb741285339` | Read-only audit; reuse only generic approved capabilities; exclude Interior data |
| https://github.com/woodex420/woodex-2030 | Existing WOODEX 2030 React/Vite/TypeScript + Express project; historical short commit `399c33c` | Read-only audit; selectively migrate approved Furniture commerce features |
| https://woodexfurniture.pk/ | Furniture storefront | Audit URLs, content, catalogue, SEO and redirects |
| https://woodex.com.pk/ | Interior site | Boundary reference only |

Historical commits may be stale. Record current branch, full commit SHA, timestamp and working-tree state before relying on either source.

### Source protection rules

- Inspect source code, schemas, migrations, routes, dependencies, tests and deployment assumptions without modifying source repositories.
- Never run source migrations or seeders against production databases.
- Never copy `.env`, credentials, private keys, tokens or private customer exports.
- Use synthetic/anonymized fixtures.
- Preserve applicable licenses and attribution.
- Do not infer completeness from a README, screenshot or menu item.

### Feature disposition register

Assign every source feature one status: **KEEP**, **ADAPT**, **REBUILD**, **MERGE**, **EXTEND**, **DEFER**, **EXCLUDE** or **BLOCKED**. Record evidence, dependencies, risks, migration approach, tests and required approval.

## 4. Selective reuse strategy

Potential generic capabilities from the Laravel OS:
- Authentication, users, roles and permissions
- CRM contacts, companies, leads, activities and follow-ups
- Sales pipeline
- Quotations, revisions, approvals and PDF documents
- Invoices and receipts
- Projects, BOQ and document management
- CMS, SEO, media, notifications, audit logs and reporting
- Integration and automation foundations

Potential WOODEX 2030 commerce capabilities:
- Catalogue, categories, products, variants and materials
- Configurator and pricing
- Cart, checkout, orders and returns
- Stock ledger, suppliers, purchase orders and goods receipts
- Projects and CMS

All reuse decisions require code-level review. Previously reported WOODEX 2030 counts (118 API handlers, 37 tables, 148 products, 16 materials and 7 services) are provisional until revalidated at a pinned commit. Reproduce and test the suspected PostgreSQL generated-ID/procurement issue; SQLite success alone is not sufficient evidence.

Do not retain two independent backends writing the same live business objects. After cutover, each object must have one canonical owner.

## 5. Product and storefront scope

Storefront page families:
1. Home
2. Categories and subcategories
3. Product listings, filters and sorting
4. Product detail, gallery and specifications
5. Variants, finishes, materials and options
6. Configurator/customization
7. Cart and checkout
8. Request-a-quote
9. Customer account and order tracking
10. Returns, warranty and support
11. About, factory/process, quality and projects
12. Case studies, blog, guides and FAQs
13. Contact and policies

Catalogue must support categories, products, SKUs, variants, option sets, materials, finishes, dimensions, specifications, images, documents, price modes, publication state and SEO metadata.

Configurator options must be data-driven with compatibility rules. Save a stable configuration snapshot with the enquiry, quote or order. Validate options and calculate prices server-side. Never trust browser-supplied prices or stock.

Do not fabricate stock, reviews, ratings, warranty terms, delivery dates, certifications or other commercial promises.

## 6. Admin and operations modules

| Module | Scope |
|---|---|
| Identity & access | Users, roles, permissions, sessions, audit |
| CRM & sales | Leads, customers, companies, activities, pipeline, follow-ups |
| Quotations | Revisions, line items, approvals, expiry, PDF |
| Catalogue | Products, categories, variants, materials, media, SEO |
| Configurator | Option sets, compatibility and pricing rules |
| Commerce | Cart, checkout, orders, status history |
| Payments | Manual/gateway payments, webhooks, reconciliation, refunds |
| Inventory | Warehouses, locations, stock ledger, reservations, adjustments |
| Procurement | Suppliers, purchase orders, goods receipts |
| Production | BOM, job cards, work stages and material issue |
| Quality | Inspection, defects, rework and release |
| Projects | BOQ, drawings, approvals and site records |
| Delivery & installation | Dispatch, proof of delivery, site visit and sign-off |
| Finance | Invoices, receipts, payables; accounting expansion by approval |
| Support | Tickets, returns, warranty and service history |
| CMS & SEO | Pages, blogs, navigation, metadata, redirects |
| Reporting | Sales, conversion, margin, stock and fulfilment |
| Integrations | Email, WhatsApp, SMS, payment providers and webhooks |

AI may classify, summarize, draft and recommend. It must not approve discounts, refunds, stock/financial changes, purchases, bulk campaigns or permission changes without human approval.

## 7. Laravel architecture

Suggested modular structure:

```text
app/
  Domain/
    Identity/ CRM/ Sales/ Quotations/ Catalogue/
    Configurator/ Commerce/ Payments/ Inventory/
    Procurement/ Production/ Quality/ Projects/
    Delivery/ Finance/ Support/ CMS/ Integrations/
  Http/
    Controllers/Admin/
    Controllers/Storefront/
    Controllers/Api/V1/
    Middleware/ Requests/ Resources/
  Policies/ Jobs/ Events/ Listeners/ Notifications/
resources/views/{layouts,admin,storefront,components}
routes/{web.php,api.php}
database/{migrations,factories,seeders}
tests/{Unit,Feature,Contract}
```

Follow Laravel conventions; do not force this exact tree if the approved implementation benefits from a different well-documented structure. Keep controllers thin. Use application/domain services for business rules, Form Requests for validation, Policies for authorization and API Resources for response shaping.

### Admin UX

Use a responsive role-aware sidebar, top bar, workspace identity, global search, notifications, breadcrumbs, useful KPIs, searchable/filterable tables, consistent forms, status indicators and activity timelines. Every screen must handle loading, empty, error, success and permission-denied states.

### Visual direction

Premium and calm: neutral surfaces, deep navy/charcoal structure, restrained wood-inspired accent, clear typography, consistent spacing, accessible contrast and strong product photography. Avoid excessive decoration and dashboard metrics that do not support decisions.

## 8. Database and integrity

Create a dedicated Furniture MySQL database and least-privilege user. Laravel migrations own the new schema. Use foreign keys, unique constraints, indexes and documented deletion rules.

Core domains include users/roles; customers/companies/leads; products/categories/variants/materials/media; configurations; carts/orders/order lines; quotations/revisions; payments/refunds; warehouses/stock ledger/reservations; suppliers/POs/goods receipts; BOM/production/quality; projects/BOQs/drawings; deliveries/installations; invoices/receipts/payables; support/returns/warranty; CMS/SEO; audit and integration events.

Rules:
- Transactions for orders, payments, stock movements, receipts, returns and financial posting.
- Idempotency for retries and webhooks.
- Auditable stock and financial ledgers; correct posted entries by reversal/correction, not silent deletion.
- Never change stock outside the ledger service.
- Recalculate totals, discounts, tax, shipping and balances on the server.
- Money must use integer minor units or one fixed-precision decimal strategy, never binary floating point.
- Snapshot accepted product names, SKUs, configuration, price, terms and delivery address on quote/order lines.
- Store timestamps consistently (UTC recommended), display Pakistan Standard Time.
- Define privacy, retention, export and deletion rules.
- Never run `migrate:fresh`, `db:wipe` or destructive seeders in production.

## 9. API architecture

Use `/api/v1`. Candidate endpoint families:

```text
/auth /customers /companies /leads /activities
/quotes /categories /products /configurations
/carts /checkout /orders /payments /returns
/inventory /suppliers /purchase-orders /goods-receipts
/production /projects /deliveries /installations
/support /cms /webhooks
```

For every endpoint document method, path, purpose, auth, permission, validation, response, errors, pagination, idempotency, rate limit, audit event and tests. Use Laravel Form Requests, Policies, API Resources, consistent JSON errors, OpenAPI and contract tests.

Verify webhook signatures and prevent replay. Rate-limit authentication, password reset, public forms, checkout and webhooks. Apply authorization server-side to every protected action. Restrict CORS. Protect file uploads. Never expose stack traces, secrets, SQL or private paths.

## 10. Tenancy and separation

Furniture launches as its own application and database. It may be designed for future Furniture branches/workspaces, but this is not shared tenancy with Interior. A `workspace_id` column alone is not sufficient: enforce scoping in policies, queries, jobs, exports and tests. Define whether branches share customers, catalogue, inventory, pricing and reporting before enabling multi-workspace operations.

## 11. Hostinger shared-hosting preflight

The exact plan is unknown. Verify before architecture lock:
- Plan name and resource limits
- PHP web and CLI versions and required extensions
- SSH/Terminal and Composer, or local/CI dependency build route
- MySQL version, database/storage/connection limits
- Cron support and frequency limits
- Document-root mapping to Laravel `public`
- Symlink and storage-link support
- Writable paths and file permissions
- Memory, upload, post-size and execution-time limits
- SSL, DNS and staging
- Backup retention and independent restore
- SMTP limits and outbound API access
- CPU, process and traffic limits

Avoid requiring persistent Node, Docker, Redis, WebSockets or always-running queue workers in the first release. Build Vite assets before deployment. Use cron for scheduler and only bounded, retry-safe queue processing if verified. Use polling where realtime is needed.

If the actual plan cannot safely support the selected Laravel version or workflows, stop and present an upgrade/alternative. Do not claim unsupported capabilities.

## 12. Security and resilience

Use HTTPS, secure sessions, password hashing, least privilege, separate environment credentials, secure upload validation, audit trails and dependency updates. Never store raw card data. Keep secrets out of code/logs. Back up database and media separately; test restoration and maintain an owner-approved off-account backup where feasible. Use anonymized staging data. Define incident response and emergency access.

## 13. Delivery phases

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | Owner scope and approvals | Scope approval |
| 1 | Read-only source/hosting audit and commit manifest | Audit accepted |
| 2 | UX, ERD, API, permissions, migration map | Architecture accepted |
| 3 | Laravel foundation, CI, MySQL, auth, RBAC | Foundation accepted |
| 4 | CRM, sales, quotes, CMS and documents | Admin workflow accepted |
| 5 | Catalogue, configurator, storefront, cart, checkout, orders | Commerce accepted |
| 6 | Inventory, procurement, production, quality, projects, delivery | Operations accepted |
| 7 | Finance and reporting | Finance owner accepted |
| 8 | Furniture-only data migration and reconciliation | Written migration approval |
| 9 | Hostinger staging, security, performance, backup/restore | Deployment readiness |
| 10 | Production cutover | Separate explicit approval |
| 11 | Stabilization, training and handover | Owner sign-off |

No phase automatically authorizes the next.

## 14. Required audit/design artifacts

Before broad feature coding, create in the new repository:
1. Executive audit/scope
2. Source protection and exact commit manifest
3. Source-to-target feature register
4. Laravel class/file reuse and dependency map
5. WOODEX 2030 commerce migration map
6. Current/target ERDs and data dictionary
7. Migration/data ownership plan
8. API inventory and OpenAPI
9. Role/permission matrix
10. Admin screen/component inventory
11. Storefront sitemap, URL preservation and redirect plan
12. Hostinger preflight/deployment runbook
13. Security/privacy/backup/restore plan
14. Test coverage and acceptance matrix
15. Risks, decisions and blockers register
16. Phased backlog with dependencies and estimates

## 15. Definition of done

A feature is complete only when business rules, authorization, validation, database integrity, error/empty states, audit trail, tests, API documentation (where relevant), accessibility, responsive behaviour and operational documentation are complete and owner-accepted.

Critical end-to-end journeys must pass on staging. Backups must be restorable. Data must reconcile. Production launch requires explicit owner approval.

## 16. Owner decisions still required

| Decision | Recommended starting point |
|---|---|
| Confirm new repo name `woodex-furniture-2030` | Approve |
| Selectively migrate WOODEX 2030 commerce after code review? | Yes |
| Reuse generic OS modules only, excluding Interior data? | Yes |
| One initial Furniture workspace, future-ready? | Yes |
| Checkout methods | Manual bank transfer/COD first; gateway after provider selection |
| Finance first release | Operational invoices/receipts/payables; full double-entry later |
| WhatsApp/email/SMS providers | Owner to select |
| Product migration | Import to staging, review, then publish |
| Which Furniture customers/orders may be imported? | Name source and fields; Interior excluded by default |
| Admin visual direction | Premium neutral/navy/wood accent |
| Release strategy | Phased |
| Exact Hostinger plan | Owner to confirm |

Already selected: Laravel Blade + Livewire admin with gradual Laravel storefront migration; Hostinger Shared Web Hosting category; preserve original repositories; separate Furniture and Interior domains/hosting/databases; new repository; planning before implementation.

## 17. Approval checkpoint

This is a plan, not authorization to modify systems. Before implementation, approve repository name, audit findings, reuse decisions, data boundaries, first-release checkout/finance scope, integration providers, exact Hostinger plan, phase-one backlog and acceptance criteria.

Until then, remain in read-only research/planning mode. Do not edit original repositories, production databases, DNS, live websites or deploy.

## 18. References

- Hostinger Laravel support: https://support.hostinger.com/en/articles/1583301-which-laravel-versions-are-supported-at-hostinger
- Hostinger server capabilities: https://www.hostinger.com/support/which-server-capabilities-are-supported-at-hostinger/
- Hostinger Node.js limits: https://www.hostinger.com/support/hostinger-node-js-limits-for-web-and-cloud-plans/
- Supported languages/frameworks: https://www.hostinger.com/support/which-programming-languages-and-frameworks-are-supported-at-hostinger/
- Laravel OS source: https://github.com/woodex420/woodexinterior
- WOODEX 2030 source: https://github.com/woodex420/woodex-2030
