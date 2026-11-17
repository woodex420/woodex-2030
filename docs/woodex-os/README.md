# WOODEX OS — the programme

One Laravel admin for Woodex Furniture, with the whole Agency OS (React + Node) migrated into it.

Read in this order:

| # | Document | What it is |
|---|---|---|
| 1 | [`12-DECISION-LOG.md`](12-DECISION-LOG.md) | The owner's decisions. The first source of truth. |
| 2 | [`00-MASTER-PLAN.md`](00-MASTER-PLAN.md) | Where both systems stand, the target, every admin destination and its migration wave, the UI/UX upgrade, the risks |
| 3 | [`01-WAVE-0-1-REPORT.md`](01-WAVE-0-1-REPORT.md) | What has been done so far: the two code lines joined, and the first slices of the UI/UX upgrade, with the evidence |
| 4 | [`13-VERCEL-DEPLOYMENT.md`](13-VERCEL-DEPLOYMENT.md) | The admin on Vercel: the preview workspace that runs today (the real catalogue, sample records), what a real deployment still needs, how it is checked |
| 5 | [`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md) | **The master to-do list to a complete admin** (version 2): every pending task, numbered T-001 onward, with its priority, who acts, its "done when" and its status; the checks made on the hosted admin are its last section |
| 6 | [`15-SPECIFICATION-ANALYSIS.md`](15-SPECIFICATION-ANALYSIS.md) | The owner's twelve documents read against the Laravel code, requirement by requirement: what is met, what is missing, where the documents and the decisions disagree. The source of the list's new lines |
| 7 | [`16-ACCEPTANCE-AND-RELEASE-GATES.md`](16-ACCEPTANCE-AND-RELEASE-GATES.md) | **Where the owner's own rules stand**: the five release gates, the 25 acceptance scenarios with the tests that prove them, the eleven browser flows, one acceptance sheet per open module, the defect register. Written from the files in [`acceptance/`](acceptance/) and checked in CI |
| 8 | [`17-RELEASE-2026-11-17.md`](17-RELEASE-2026-11-17.md) | The release report of 17 November 2026, in the ten points of document 10 §9: what went out, what was changed in the shop's database, the evidence, what was not done |
| 9 | [`18-RELEASE-2026-11-17-SECOND.md`](18-RELEASE-2026-11-17-SECOND.md) | The release report of pull request #37, written before its merge: what the merge changes on each live site, what was changed by hand on Vercel and in the shop's database, the evidence |
| 10 | [`19-RELEASE-2026-11-17-THIRD.md`](19-RELEASE-2026-11-17-THIRD.md) | The release report of pull request #39, written before its merge: the security tests and what they found, a trace for every sensitive action, a lead's value from its quotation |
| 11 | [`20-RELEASE-2026-11-17-FOURTH.md`](20-RELEASE-2026-11-17-FOURTH.md) | The release report of pull request #40, written before its merge: the Content-Security-Policy on every page, a request number on every answer, error page and log line |
| 12 | [`21-PENDING-WORK-PLAN-AND-TESTS.md`](21-PENDING-WORK-PLAN-AND-TESTS.md) | **What is still to do, the plan and the tests**: every line of the list that is not done. The lines that can start now stand in rounds, in order, each with what is built, the tests written, what a browser does and the way back; the lines that wait for the owner; the lines that wait for an earlier line, each with its "done when". Written from the list and `acceptance/plan.json` by `node scripts/verify-plan.mjs --write`, and checked in CI |
| 13 | [`22-RELEASE-2026-11-17-FIFTH.md`](22-RELEASE-2026-11-17-FIFTH.md) | The release report of pull request #41, written before its merge: the plan of the pending work and its check; no screen and no site behaves differently |
| 14 | [`spec/`](spec/README.md) | The owner's specification set, stored unchanged |

Related, elsewhere in this repository:

- `docs/furniture-2030/` — the audit of the React/Node system, its inventories, and the prepared real catalogue.
- `docs/laravel-os/` — the record of the Laravel work of 11–13 November (here since Wave 0).
- `platform/tools/preview/README.md` — how to run the Laravel application and its tests in a sandbox that can reach only GitHub and npm.
