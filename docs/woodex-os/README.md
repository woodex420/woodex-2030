# WOODEX OS — the programme

One Laravel admin for Woodex Furniture, with the whole Agency OS (React + Node) migrated into it.

Read in this order:

| # | Document | What it is |
|---|---|---|
| 1 | [`12-DECISION-LOG.md`](12-DECISION-LOG.md) | The owner's decisions. The first source of truth. |
| 2 | [`00-MASTER-PLAN.md`](00-MASTER-PLAN.md) | Where both systems stand, the target, every admin destination and its migration wave, the UI/UX upgrade, the risks |
| 3 | [`01-WAVE-0-1-REPORT.md`](01-WAVE-0-1-REPORT.md) | What has been done so far: the two code lines joined, and the first slices of the UI/UX upgrade, with the evidence |
| 4 | [`13-VERCEL-DEPLOYMENT.md`](13-VERCEL-DEPLOYMENT.md) | The admin on Vercel: the demo that runs today, what a real deployment still needs, how it is checked |
| 5 | [`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md) | **The master to-do list to a complete admin** (version 2): every pending task, numbered T-001 onward, with its priority, who acts, its "done when" and its status; the checks made on the hosted admin are its last section |
| 6 | [`15-SPECIFICATION-ANALYSIS.md`](15-SPECIFICATION-ANALYSIS.md) | The owner's twelve documents read against the Laravel code, requirement by requirement: what is met, what is missing, where the documents and the decisions disagree. The source of the list's new lines |
| 7 | [`spec/`](spec/README.md) | The owner's specification set, stored unchanged |

Related, elsewhere in this repository:

- `docs/furniture-2030/` — the audit of the React/Node system, its inventories, and the prepared real catalogue.
- `docs/laravel-os/` — the record of the Laravel work of 11–13 November (here since Wave 0).
- `platform/tools/preview/README.md` — how to run the Laravel application and its tests in a sandbox that can reach only GitHub and npm.
