# 07 — Module Specifications

This document defines target module boundaries and minimum functional behavior. A module is not “live” merely because its navigation label or database table exists.

## 1. Overview / Dashboard

**Purpose:** role-aware daily command centre.

Requirements:
- Real KPI cards and linked filtered records.
- Sales, finance, commerce, operations and project summaries.
- Pending approvals and exceptions.
- Recent activity with actor and timestamp.
- Quick actions controlled by permission.
- Date range and currency context.
- Widget loading, empty, error and no-access states.

## 2. Approvals

Requirements:
- Unified queue across supported domains.
- Filter by type, requester, age, amount and status.
- Detail view with supporting record and audit history.
- Approve/reject/request changes according to workflow.
- Reason capture where policy requires it.
- Separation-of-duties rule for self-approval.
- Notification to requester.
- Idempotent action handling.

## 3. Reports

Requirements:
- Role-filtered report catalogue.
- Date range and relevant filters.
- Clear metric definitions and timezone/currency.
- Export permissions and audit.
- Avoid heavy synchronous report generation on shared hosting; use queued export where necessary.

## 4. CRM

### Leads
- Capture from public forms, manual entry and approved integrations.
- Fields: name, contact details, source, interest, status, owner, next follow-up, notes.
- Duplicate detection and merge review.
- Assignment and conversion.
- Activity timeline.

### Contacts
- Canonical person/customer record.
- Multiple contact methods with verification/primary flags.
- Company association if required.
- Consent and communication preferences.
- Related quotations, invoices, orders, projects and support cases.

### Tasks & Visits
- Owner, due date, status, priority and related record.
- Reminder scheduling subject to cron limits.
- Visit outcome and follow-up.
- Completion history.

## 5. Sales

### Estimations
- Reusable line items.
- Quantity, unit, rate, discount and tax.
- Versioned estimate snapshot.
- Convert to quotation without losing traceability.

### Quotations
- Numbering and document template.
- Customer and validity.
- Line items and totals calculated server-side.
- Configurable tax and discount policy.
- Draft, approval, sent, accepted, rejected, expired and cancelled states.
- Role-based approval.
- Revision history.
- PDF/document output.
- Conversion to order/project/invoice under defined policy.

### Invoices
- Issued document snapshot.
- Due date and status.
- Payment allocation.
- Credit/reversal process rather than silent mutation.
- Outstanding and aging calculations.

### Payments
- Record receipt, method, reference, date and amount.
- Allocate against invoices.
- Partial and multiple allocations if policy permits.
- Reversal with reason and audit.
- Manual payment recording is a provisional assumption pending confirmation.

### Documents
- Secure generated files and uploaded supporting documents.
- Ownership and access policy.
- Versioning and retention.

## 6. Commerce

### Store
- Store configuration and operational overview.
- Catalogue health and publishing status.

### Products
- Product master, SKU, title, description, status, attributes and media.
- Variant support where product types require it.
- Pricing and stock display rules.
- Product SEO.

### Categories and Series
- Hierarchy, ordering, visibility and SEO.
- Product associations.
- Avoid duplicate classification systems without a business need.

### Shop Design
- Move into Website Studio as Storefront / Shop Design.
- Manage page templates, product listing presentation and product detail layouts.
- Does not edit product price or stock.

### Orders
- Customer, lines, pricing snapshot, status and history.
- Manual/admin-created and storefront-created orders.
- Payment status separate from fulfilment status.
- Cancellation, return and refund policy to be confirmed.

### Delivery & Installation
- Schedule, address, assigned team/vendor, status and proof of completion.
- Customer notification and exception logging.
- Delivery and installation can be separate milestones.

### Support & Warranty
- Case number, customer, product/order, issue, evidence, status and resolution.
- Warranty terms snapshot.
- Escalation and response history.

## 7. Procurement & Production

### Suppliers
- Company/contact, terms, categories, status and documents.

### Purchase Orders
- Supplier, line items, quantities, prices, delivery date and approval.
- Revision and state history.
- Receiving against PO.

### Goods Receipts
- Received quantities, discrepancy, damage and evidence.
- Partial receipt support.
- Stock ledger posting only after authorized confirmation.

### Supplier Invoices
- Supplier invoice reference, amount, date and matching state.
- Match to purchase order/receipt according to policy.
- Exception handling.

### Production
- Job, order/project link, product/variant, planned quantity, stages and responsible team.
- Stage events and completion evidence.
- Material requirements and consumption if stock integration is enabled.

### Stock Ledger / Warehouses / Transfers / Reservations
- Append-only movements.
- Warehouse/location.
- Transfer request, dispatch and receipt.
- Reservation and release.
- Adjustment with reason and approval.
- Reconciliation report.

## 8. Accounts

### Ledger & Reports
- Account structure and transaction posting rules must be confirmed with accounting owner.
- Drill-down to source document.
- Period and currency filters.
- Reconciliation and export.

### Payables
- Supplier obligations, due dates, payments and aging.

### Income & Expenses
- Categorized entries, attachments, approval and reporting.
- Avoid presenting this as a complete statutory accounting package until validated.

## 9. Projects

- Project creation from quotation/order or manually.
- Client, owner, dates, status, budget and scope.
- Board and task management.
- Milestones and dependencies.
- Vendor assignments and costs.
- Change requests and approvals.
- Handover checklist and evidence.
- Client portal access scoped to own project.

## 10. Inbox

- Conversation threads across enabled channels.
- Contact/lead/order/project association.
- Assignment, status, priority and internal notes.
- Message delivery state.
- Human handoff.
- Channel connection health.

## 11. Automation

- Trigger, condition, action and schedule.
- Enable/disable switch.
- Test/dry-run.
- Execution history and failure reason.
- Retry with idempotency.
- Approval for high-impact actions.
- Respect communication consent and opt-out.
- Shared-hosting-friendly scheduler.

## 12. Marketing

- Campaign records and source tracking.
- UTM attribution.
- Landing page association.
- Lead conversion attribution.
- Email/WhatsApp campaigns only through approved connected providers and consent rules.
- Performance reports based on measured events.

## 13. Website Studio

See document 06. This module owns page composition, theme, navigation, media presentation, SEO and publication—not product catalogue authority.

## 14. System and Workspace

- Users, roles and permissions.
- Workspace preferences and business settings.
- Currency/timezone/tax configuration.
- Integrations and connection health.
- Audit and platform health.
- Team onboarding.
- Feature availability and release notes.

## 15. Module completion definition

A module is accepted only when it has:
1. Approved requirements.
2. Data ownership and schema.
3. API contract.
4. Permission matrix.
5. UI list/detail/create/edit/action flows.
6. Loading/empty/error states.
7. Unit and integration tests.
8. Browser acceptance tests.
9. Migration/reconciliation plan if replacing existing behavior.
10. Documentation and owner sign-off.
