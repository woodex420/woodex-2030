# Hostinger Business preflight

The owner chose a Hostinger **Business** plan, separate from the Interior site's hosting (OD-3 in [01](01-OWNER-DECISIONS.md)). This document states what that plan supports, what in this code base must change to run there, and how to choose the database.

Nothing has been deployed. The facts in §1 come from Hostinger's documentation; the claims in §2 come from this code base and from `npm run smoke:hostinger`, which passes locally. Everything marked **verify** must be tested on the real plan in staging.

## 1. What the plan provides

Read from Hostinger's support pages on 2026-11-16, except the resource row, read on 2026-11-11. Sources are listed in §7.

| Topic | Fact |
|---|---|
| Node.js apps | Supported on Business. Up to 5 Node.js websites. Each runs as one long-running Node.js process. |
| Node.js versions | 18, 20, 22 and 24. This code base needs 22.5 or newer; CI uses 24. |
| Frameworks | Express is a supported back-end preset; "Other" is available. |
| Deployment | From a GitHub repository with automatic deployment on every push, or by ZIP upload. Environment variables are set in the dashboard. A Restart button exists. |
| Deployment folder | Each deployment builds into a new folder. Files under `hbuilds/` and `public_html` are **overwritten on every deployment**; the last two versions are kept. |
| Limits | Process limit 120 and entry processes 60, shared by every website on the plan. A request is stopped after 120 seconds. |
| Not supported | Inbound WebSockets, always-running background workers, custom ports, Redis, Docker. |
| Outbound network | All outgoing ports are open except 0 and 25. |
| Databases | MySQL is included. A "Database Connect Wizard" connects a Node.js app to Supabase. |
| Scheduled jobs | Cron jobs for Node.js websites are available. |
| Host names | `domain.tld` and `www.domain.tld` each run a separate process. |
| Existing websites | A PHP website cannot be switched to Node.js in place. A Node.js website must be added as a new website. |
| E-mail | Server mail is capped at 10 per minute and 100 per day; port 25 is closed. Use SMTP on 465 or 587. |
| Plan resources | 2 CPU cores, 3 GB RAM, 50 GB disk, 600,000 files, for plans sold since 2026-09-22. Older plans differ: **verify** in the dashboard. |

## 2. Fit with this code base

### Works as built

- **One process serves everything.** `npm run start:hostinger` serves the prerendered storefront, the dashboard, the API, the sitemap and robots from one Node.js process. `npm run smoke:hostinger` passed on 2026-11-16 (10 checks).
  - **Corrected during Phase 1.** That pass was misleading for the dashboard: the smoke test fetched only the dashboard's HTML shell. In this shape the server answered every address under `/dashboard/` with that shell, including the dashboard's own script and stylesheet, so in a browser the dashboard was a blank page (finding F-16 in [02](02-AUDIT-FINDINGS.md)). It is fixed, the smoke test now fetches what each shell references (18 checks), and the browser check opens all 43 dashboard screens on this shape ([result](evidence/results/phase1-dashboard-routes-production-build-2026-11-16.json)). It was found by pointing the new browser check at the production build, and it would otherwise have been found on Hostinger.
- **No background workers are needed.** The app has no queue. Backups and clean-up can run from Hostinger cron.
- **Live updates.** The dashboard uses server-sent events over plain HTTP, not WebSockets, and falls back to polling. The 120-second request limit will cut the stream; the browser reconnects by itself. **Verify** that the stream is not buffered.

### Must change or be verified

| # | Issue | Why it matters | What to do |
|---|---|---|---|
| H-1 | The app folder is replaced on every deployment. | A SQLite file or uploaded media inside it would be wiped on each deploy. | Point `WOODEX_DATA_DIR` at a folder outside `hbuilds/` and `public_html`. **Verify** that it survives a redeploy. |
| H-2 | `www` and the bare domain run as two processes. | Two processes break the single-writer assumption for SQLite and split in-memory state such as the live-update fan-out. | Serve one host name and redirect the other before it reaches the app. **Verify** that only one process runs. |
| H-3 | Build and start commands. | The build must run `npm run vercel:build`; the start command must set `WOODEX_SERVE_STATIC` and, on SQLite, pass `--experimental-sqlite`. | **Verify** that the dashboard accepts these as custom commands for a monorepo. |
| H-4 | Automatic deployment on push. | A push to the deployed branch goes live without the gate. | Deploy from a release branch that only receives commits whose CI run passed. |
| H-5 | Pull-request previews. | Hostinger builds a preview for each pull request. | **Verify** which database a preview uses; it must never be production. |
| H-6 | The repository's Hostinger guide. | [`../DEPLOYMENT.md`](../DEPLOYMENT.md) §2 describes manual upload, which is no longer how Hostinger runs Node.js (F-13). | Rewrite §2 after the staging test, from what was actually done. |
| H-7 | The existing PHP shop on the domain. | It cannot be converted in place. | Build staging on a temporary domain or subdomain; move the domain at cutover (§5). |

## 3. Database choice

The app supports SQLite and PostgreSQL. It does not support MySQL.

| Option | For | Against |
|---|---|---|
| **PostgreSQL on Supabase** | Foreign keys and CHECK constraints are enforced (they exist only in the PostgreSQL schema). Managed backups. Hostinger's wizard supports it and outbound connections are allowed. It is what production uses today. | F-02, F-03 and F-04 in [02](02-AUDIT-FINDINGS.md) must be fixed first. Every query crosses the network between Hostinger and Supabase. A second vendor and its plan limits. |
| **SQLite file on the Hostinger plan** | One vendor, no network hop. Every module test passes on it today. Backup and restore scripts exist and the restore drill passes. | No foreign keys at all (F-09). Single writer. Depends on H-1 and H-2. Node's SQLite module is still marked experimental. Backups and off-site copies become our job. |
| Hostinger MySQL | Included in the plan and local to the app. | The app has no MySQL driver and its SQL is written for SQLite and PostgreSQL. Supporting it means touching every query. Not recommended. |

**Recommendation.** Fix F-02 to F-04 first; they are needed either way because production is PostgreSQL today. Then decide in staging with a measurement:

1. Create a Supabase project in the region nearest the Hostinger data centre.
2. Deploy the same build twice on staging, once per database.
3. Measure the time of the dashboard Overview and of a checkout on each.

Choose PostgreSQL unless it is clearly slower for staff, because the integrity constraints and managed backups are worth more to business data than one fewer vendor. Keep SQLite as the fallback. Production holds only demo data today, so starting a fresh database in a nearer region costs nothing.

## 4. Staging checklist

Each line is a test with a pass condition. All must pass before a production cutover is proposed.

| # | Test | Passes when |
|---|---|---|
| S-1 | Deploy from GitHub to a temporary domain | The build finishes and `/api/health` answers |
| S-2 | Public pages | The eight prerendered routes that `smoke:hostinger` checks return real HTML, and `/sitemap.xml` lists the staging host |
| S-3 | Dashboard | The owner signs in and every menu screen renders (the browser check in [`evidence/`](evidence/README.md)) |
| S-4 | Persistence (H-1) | A record created before a redeploy is present after it |
| S-5 | Single process (H-2) | Requests to `www` are redirected and one Node.js process is listed |
| S-6 | Live updates | A change made in one browser appears in another without a reload, across a 120-second boundary |
| S-7 | Database comparison (§3) | Timings recorded for both options and a choice written into [01](01-OWNER-DECISIONS.md) |
| S-8 | Backup and restore | A scheduled backup lands off the server, and a restore from it into a scratch database matches row for row |
| S-9 | E-mail | A test message is delivered through SMTP on 465 or 587 |
| S-10 | Search engines | Staging answers `Disallow: /` so it is never indexed |
| S-11 | Resource use | Process count and memory stay well under the plan limits during the tests |

## 5. Cutover constraints

- The current shop on woodexfurniture.pk is a PHP website. Hostinger requires the Node.js app to be added as a new website, so the domain has to move from one to the other.
- Take a full backup of the PHP website and its database before the move. That backup is the rollback.
- Redirects from the old URLs must be live at the moment of the move. See [05](05-LIVE-SITE-AND-CATALOGUE.md).
- Cutover is production work and needs its own explicit approval from the owner. Passing staging does not authorise it.

## 6. What the owner provides

- Access to the Hostinger account that holds the Business plan, or a staging website created on it.
- The data-centre region of the plan.
- A decision on whether Hostinger may connect to the GitHub repository. A plan connects to one GitHub account.

## 7. Sources

- <https://www.hostinger.com/support/how-to-deploy-a-nodejs-website-in-hostinger/>
- <https://www.hostinger.com/support/hostinger-node-js-limits-for-web-and-cloud-plans/>
- <https://www.hostinger.com/support/how-to-migrate-from-vercel-to-hostinger/>
- <https://www.hostinger.com/support/which-web-standards-and-connectivity-features-are-supported-at-hostinger/>
- <https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/>
