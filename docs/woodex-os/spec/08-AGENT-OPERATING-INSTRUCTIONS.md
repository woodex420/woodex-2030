# 08 — WOODEX 2030 AI Agent Operating Instructions

## 1. Mission

Implement WOODEX 2030 safely and incrementally using the existing repository. Improve the product without duplicating working functionality, damaging current production behavior or claiming unverified completion.

## 2. Source-of-truth hierarchy

When instructions conflict, use this order:
1. Latest explicit owner decision recorded in `docs/12-DECISION-LOG.md`.
2. Approved master requirements in `docs/01-MASTER-PRD.md`.
3. Architecture and data ownership in documents 02 and 03.
4. Module requirements in document 07.
5. Current source code and tests as evidence of implementation.
6. Older planning documents only when they do not conflict with current approved decisions.

Never silently resolve a conflict. Record it and ask for a decision if it changes data, security, scope or production behavior.

## 3. Mandatory read-before-edit

Before changing code:
1. Confirm repository and branch.
2. Inspect git status and recent relevant commits.
3. Read root `AGENTS.md` and scoped agent instructions.
4. Read the relevant master documentation.
5. Inspect the route, component, API, model, migration and tests involved.
6. Search for an existing implementation before creating a new one.
7. Identify current owner of the data and route.
8. Identify dependencies and downstream workflows.
9. State the smallest safe change.
10. Define tests before implementation.

Do not assume a filename or path exists. Discover it.

## 4. Implementation rules

- Preserve current working behavior unless a replacement has passed acceptance.
- No broad refactor mixed with a feature change.
- No duplicated route, component, model or business rule.
- No hardcoded business metrics or placeholder “success” responses.
- No direct database writes from React.
- No client-trusted totals, permissions or status transitions.
- No unreviewed destructive migrations.
- No arbitrary code execution through Puck content.
- No secrets in source, logs, frontend bundles or documentation.
- No production data/deployment action without explicit approval.
- One writer per business table.
- Use transactions for multi-record business operations.
- Add tests for every new state transition and authorization boundary.

## 5. Required task plan

Every implementation task begins with:
- Objective.
- Current behavior/evidence.
- Files/routes/tables likely affected.
- Existing reusable code found.
- Risks and dependencies.
- Acceptance criteria.
- Test plan.
- Rollback approach.

If task scope is too large, split into reviewable phases and complete the first bounded phase.

## 6. Frontend standards

- Reuse shared design tokens and components.
- Keep route IDs stable or provide redirects.
- Provide loading, empty, error, permission and success states.
- Ensure keyboard navigation and visible focus.
- Test responsive layouts.
- Do not introduce a second component library without approval.
- Do not copy data values into UI constants when an API exists.
- Do not use `any` or unsafe casts to silence contract errors without documented justification.

## 7. Backend standards

- Laravel Form Requests or equivalent validation.
- Policies/permissions for every protected action.
- API Resources/DTOs for stable response contracts.
- Explicit domain service for multi-step business transitions.
- Transactions for financial, inventory and order changes.
- Idempotency for retries and webhooks.
- Audit events for approvals, publishing, financial posting and stock movements.
- Queue expensive work only after verifying shared-hosting worker strategy.
- Avoid N+1 queries; paginate lists.

## 8. Database standards

- Review existing migrations before adding one.
- Never run imported/quarantined migrations without inspection.
- Add indexes based on query patterns.
- Define unique constraints and delete behavior.
- Use UTC timestamps.
- Use exact decimal/minor-unit money representation.
- Keep immutable snapshots for issued documents.
- Keep stock movement ledger append-only.
- Never run destructive production migrations without backup, rehearsal and owner approval.

## 9. Puck safety standards

- Use only allowlisted component types.
- Validate component props server-side.
- No arbitrary script, PHP, Blade or unrestricted HTML.
- Protect draft preview with expiring authorization.
- Version page data and component schema.
- Save drafts separately from published revision.
- Log publish and rollback events.
- Test editor preview against public renderer.

## 10. Testing requirements

Run relevant:
- Formatter/linter.
- Type checking.
- Unit tests.
- API feature tests.
- Authorization tests.
- Database migration tests.
- Frontend build.
- Browser smoke tests.
- Responsive/visual regression tests where available.

If a command cannot run, report why. Never invent a passing result.

## 11. Completion report format

```text
TASK
- Objective:
- Scope completed:

EVIDENCE
- Files changed:
- Routes/API:
- Database:
- Tests/commands run:
- Results:

NOT DONE
- Remaining work:
- Known limitations:
- Unverified assumptions:

SAFETY
- Data migrations:
- Production impact:
- Rollback:
- Approval required:

STATUS
- Complete / Partial / Blocked
```

A task is not complete because the code compiles. It is complete only when the acceptance criteria are evidenced.

## 12. Agent failure recovery

If an agent:
- edits wrong files: stop, inspect route ownership and revert only its own unapproved changes;
- duplicates a feature: compare current route/component/API and consolidate before adding more;
- cannot connect frontend/backend: inspect base URL, CORS, auth cookies/tokens, API contract and network errors;
- reports success while broken: reproduce with a browser/API test and correct the status report;
- loses project context: reread this documentation and `AGENTS.md`, then summarize verified state before continuing.

Never conceal failed attempts or overwrite unrelated user changes.
