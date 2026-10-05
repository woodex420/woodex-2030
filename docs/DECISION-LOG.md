# 35 · Decision Log

| ID | Date | Decision | Alternatives · Rationale | Status |
|---|---|---|---|---|
| D-001 | 2026-10-05 | Official roadmap = PRD §22 14 phases; interim 14-row table demoted to execution detail | My v3 split vs PRD text → §02 “never silently replace existing requirement” | accepted |
| D-002 | 2026-10-05 | Interiors (MarketingWoodex) repo = **patterns + data-model inspiration only**; no code/content/data reuse | repo has no license + leaked secrets; user owns both brands but no written grant yet | accepted, revisitable with written license |
| D-003 | 2026-10-05 | Page builder: typed-block custom renderer, class-allowlist (CSS-classes-only blocks); GrapesJS/Puck = bounded comparators, not default | their 2.6k-LOC vanilla builder proves custom viability; keeps one editor=storefront renderer contract | accepted |
| D-004 | 2026-10-05 | Preline: patterns + original wrappers under our tokens; no Pro assets, no code copy until written clearance | dual MIT+Fair-Use; builder-core derivative risk | accepted (open item: user clearance?) |
| D-005 | 2026-10-05 | One DB + one API; static HTML only as export artifact (rule A1) | their 3-service chain postmortem; Supabase-only design rejected as fragile | accepted |
| D-006 | 2026-10-05 | P5 finance first visible feature (invoices/payments/returns, PKR, 50% advance, Overdue computed on read, cancel≠delete) | user “start as you recommend” on honest-state basis | **implemented this turn** |
| D-007 | 2026-10-05 | Secret-scan script + CI gate before any further feature work | PRD §18; Phase-0 findings | **implemented this turn** |
| D-008 | 2026-10-05 | `woodex-ai-suite` = dead (404 ×4) → struck from active plans; `woodex420/woodex` = **BLOCKED** (secrets) → sanitized ZIP needed for its audit | §03: no invented findings | open question to user |
