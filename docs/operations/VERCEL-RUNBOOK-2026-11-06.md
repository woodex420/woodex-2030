# Woodex 2030 — single-project Vercel runbook

**Status: deployment shape implemented and Vercel build settings configured; no new deployment has been made.** This runbook records the user-approved shape for the Vercel project `woodex-2030`: one project for the Storefront, Dashboard, and API, with a persistent PostgreSQL database. It is not a production-readiness declaration.

The Vercel project is connected to `woodex420/woodex-2030`, uses `main` as its production branch, and retains the repository root as its project root. Its build command is `npm run vercel:build`, install command is `npm ci --legacy-peer-deps`, and output directory is `dist`. The connected project currently has no environment variables. No deployment was triggered by these settings changes.

## Request routing and build

- `/` — `apps/storefront` (Vite static build).
- `/dashboard/*` — `apps/dashboard` (Vite static build with `/dashboard/` base path).
- `/api/*` — Express API wrapped as Vercel Node functions. Vercel function instances do not share the local in-memory event bus; the dashboard refreshes data by polling every 15 seconds. The stream endpoint is owner-only and disabled in the serverless runtime.
- `/sitemap.xml` — rewrites to the API sitemap handler. Product imagery is copied to the static output at `/img`.
- `apps/woodex-reimagined-preview` is a separate imported preview and is intentionally not included in this production bundle.

`vercel.json` selects `npm ci --legacy-peer-deps`, `npm run vercel:build`, and output directory `dist`. The build script builds both frontends and assembles them under the paths above. The Express API remains in the repository root's `api/` function entry points.

## Database and first owner

Vercel cannot use the local SQLite file as durable storage. The serverless runtime fails closed unless `DATABASE_URL` points to PostgreSQL; PGlite and SQLite are not accepted there. The PostgreSQL connection pool defaults to one connection per function instance.

1. Choose/provision a PostgreSQL database and create Vercel environment variables with the platform UI. Keep credentials out of Git and chat. Use a separate Preview database; do not point Preview at Production.
2. Apply only the schema, without importing a local SQLite database:
   `DATABASE_URL="$DATABASE_URL" npm run db:apply-schema`
   The script rejects SQLite/PGlite and applies `scripts/schema.postgres.sql`. Do not run `scripts/pg-migrate.mjs` against Production unless a separately approved, backed-up data migration is planned.
3. For a new Production database with no users, set `INITIAL_ADMIN_EMAIL` and a unique `INITIAL_ADMIN_PASSWORD` of at least 16 characters in the Production environment for the first successful startup. The API inserts one owner only when the user table is empty; if users exist but no active owner exists, startup fails closed. Remove the bootstrap password from Vercel after confirming owner login.
4. Vercel also needs `DATABASE_URL` scoped to Preview before a Preview API function can start. A missing schema or database URL intentionally causes API initialization to fail rather than falling back to ephemeral SQLite.

No environment-variable values or database records were read, copied, or written during this preparation.

## Production-data safeguards in the code path

- Vercel does not create demo customers, demo orders/invoices, demo inbox threads, or the local `admin/admin` and `woodex123` accounts. It may idempotently insert repository-owned product/material/service catalog rows only; it does not import customer data.
- Storefront order creation now calculates prices from available catalog rows and ignores client-supplied prices/totals. Public status lookup needs the high-entropy private code returned once at checkout and returns progress only, not customer, purchase, invoice, payment, or return details.
- Protected GET routes enforce the same capability boundaries as writes; anonymous reads of the Dashboard datasets remain blocked.
- No payment gateway is integrated. The checkout remains an order-request flow; it does not capture payment, and the current delivery address/payment preference are explicitly not persisted.

## Release gates still required

1. **Local checks:** `npm run vercel:build` passed; the isolated SQLite and PGlite API/security suites each passed 92/92. `node scripts/ci-test.mjs` reached the browser suite but stopped because this sandbox has no installed Chromium binary; it did not run the four browser journeys. The GitHub workflow installs Chromium, so require its actual PR result before merging.
2. Commit the complete application to the active Arena branch and open a PR. The linked Vercel project's production branch is `main`, while the currently audited application files are local and untracked; GitHub `main` currently contains only the README.
3. Review and merge to `main` only after the PR checks and owner approval. Do not trigger Production while privacy, auth/RBAC, persistent-DB, and checkout acceptance tests are failing or unverified.
4. Verify the selected hosted database with the schema-only setup, backups/restore, real connection/pooling, and a canary. No hosted database or production secret is currently configured or tested.
5. Confirm the deployment from the correct repository/commit and verify Storefront, Dashboard, API, preview protections, monitoring, and recovery before calling it live or production-ready.

The existing audit's other findings—including unavailable payment processing and unpersisted checkout address/preference—remain explicit limitations until separately implemented and tested.
