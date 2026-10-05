# CI

GitHub blocks workflow-file pushes from this sandbox's app (missing `workflows` permission).
**To enable:** copy `github-actions.yml` into `.github/workflows/ci.yml` via the GitHub UI — it runs:
secret-scan → API boot smoke → dashboard build → storefront build → e2e finance curls.

Local equivalent right now: `npm run secret-scan` + build each workspace.
