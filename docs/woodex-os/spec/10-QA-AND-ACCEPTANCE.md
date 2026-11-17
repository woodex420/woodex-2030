# 10 — QA, Testing and Acceptance Strategy

## 1. Quality objective

Every feature must be proven in the actual workflow, not merely compiled or visually rendered. Tests must cover business rules, permissions, data integrity, user experience and deployment constraints.

## 2. Test layers

### Unit tests
- Calculations, state transitions, validators and domain rules.
- Permission policy helpers.
- Puck schema validators.
- Money/tax/discount logic.

### API feature tests
- Authentication and authorization.
- CRUD validation.
- Approval and publish actions.
- Idempotency and conflict handling.
- Error response contract.
- Pagination/filtering.

### Database tests
- Migrations from clean MySQL.
- Foreign keys and unique constraints.
- Transaction rollback.
- Financial precision.
- Stock ledger invariants.
- Revision immutability.

### Frontend tests
- Component behavior.
- Forms and validation.
- Route guards.
- Search/filter interactions.
- Empty/loading/error states.
- Keyboard accessibility.

### Browser end-to-end
- Lead capture.
- Lead assignment and conversion.
- Estimate to quotation.
- Quotation approval and issue.
- Invoice and payment allocation.
- Product browse and order creation.
- Purchase order and goods receipt.
- Stock transfer/reservation.
- Project task and handover.
- Page edit, preview, approval and publish.
- Permission-denied scenarios.

### Operational tests
- Hostinger staging deployment.
- Cron execution.
- Queue processing strategy.
- Upload limits.
- Backup and restore.
- Error logging and failed job handling.
- Resource consumption under expected load.

## 3. Critical acceptance scenarios

### CRM and sales
- A public enquiry creates one lead.
- Duplicate detection identifies likely existing contact.
- Lead assignment is permission checked and audited.
- Estimate totals are calculated server-side.
- Quote approval is required for configured roles/thresholds.
- A rejected quote cannot be converted.
- Issued document snapshot remains stable after product price changes.

### Finance
- Payment amount cannot exceed allowed allocation unless overpayment policy is defined.
- Partial payments calculate outstanding balance correctly.
- Reversal requires permission and reason.
- Financial reports reconcile to source transactions.
- Currency and tax behavior are explicit.

### Inventory and production
- Goods receipt posts stock once only.
- Duplicate receipt submission is idempotent.
- Transfer creates matched source and destination ledger movements.
- Reservation reduces available stock but does not corrupt physical stock.
- Adjustment requires reason and appropriate approval.

### Puck / Website Studio
- Unauthorized user cannot access editor or draft preview.
- Unsupported component types are rejected by server.
- Draft save creates a new revision.
- Preview uses same renderer as published page.
- Publish requires appropriate role/approval.
- Rollback restores previous valid revision.
- Product block uses canonical catalogue data.
- Media deletion is blocked or warns when referenced.

## 4. Security tests

- Direct API access without authentication.
- Cross-role access attempts.
- Cross-client portal data access.
- Mass assignment.
- CSRF/CORS/session behavior.
- Rate limits on public forms and authentication.
- Upload MIME/content validation.
- Webhook signature and replay handling.
- SQL injection and XSS handling.
- Sensitive data in logs and error messages.
- Puck raw HTML/script injection.
- Insecure direct object references.

## 5. UX and accessibility tests

- Sidebar keyboard navigation.
- Command palette open/search/selection/escape.
- Focus management in dialogs and drawers.
- Form labels and error announcements.
- Color contrast.
- Mobile navigation and page actions.
- Table usability at narrow viewport.
- Consistent empty/error/loading states.
- No hidden primary action at mobile breakpoints.

## 6. Performance checks

- Paginate large collections.
- Check query count and N+1 behavior.
- Index high-selectivity filters.
- Compress and resize images.
- Lazy-load non-critical media.
- Avoid blocking page requests with integrations.
- Test dashboard data aggregation.
- Measure real pages with representative data.
- Respect shared-hosting CPU/memory/request limits.

## 7. Release gates

### Gate A — Design foundation
- Approved design tokens.
- Shared component examples.
- Responsive shell prototype.
- Accessibility review.

### Gate B — Module readiness
- Requirements, API and schema approved.
- Unit/API/browser tests pass.
- Permission matrix tested.
- Data reconciliation ready.

### Gate C — Puck readiness
- Compatibility prototype accepted.
- Round-trip editing and renderer parity.
- Draft security, publish and rollback tested.

### Gate D — Hostinger readiness
- PHP/MySQL compatibility verified.
- Cron/queue strategy proven.
- Backup and restore tested.
- Security and resource checks passed.

### Gate E — Production cutover
- Owner approval.
- Migration snapshot.
- Rollback plan.
- Monitoring and support plan.
- No unresolved critical defects.

## 8. Defect severity

| Severity | Definition | Release rule |
|---|---|---|
| Critical | Data loss, privilege bypass, financial corruption, site unavailable | Block release |
| High | Core workflow broken, wrong totals/status, major security or privacy issue | Block affected release |
| Medium | Workaround exists; significant UX or non-core defect | Owner acceptance required |
| Low | Cosmetic/minor inconvenience | May schedule later |

## 9. Evidence report

Each release report includes:
- Commit/branch/build identifier.
- Environment.
- Test command and result.
- Browser/device coverage.
- Defects open/closed by severity.
- Migration reconciliation.
- Backup/restore status.
- Performance observations.
- Screenshots or recording of key flows.
- Explicit owner approval status.
