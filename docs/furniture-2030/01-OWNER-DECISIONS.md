# Owner decisions

Recorded from the owner's answers on 2026-11-16, in two rounds: before the analysis, and before Phase 1 started. A decision here is the owner's; a default is an assumption made so work can proceed, and stays open until the owner confirms it.

## 1. Decided

| ID | Decision | Effect on existing records |
|---|---|---|
| OD-1 | WOODEX Furniture 2030 is built on this repository. It is not a Laravel project. | Confirms D-01 "do not rewrite" in [`../agency-os/02-TECH-STACK-DECISIONS.md`](../agency-os/02-TECH-STACK-DECISIONS.md). |
| OD-2 | The Laravel system in the `woodexinterior` repository, a separate `woodex-furniture-2030` repository, and all other alternatives are on hold. | No work is planned outside this repository. See §4. |
| OD-3 | Production hosting is a Hostinger **Business** plan, separate from the Interior site's hosting. | Answers D-2 in the [tracker](../WOODEX_2030_MASTER_IMPLEMENTATION_TRACKER.md) and question Q3 in [`../product/MASTER-PLAN.md`](../product/MASTER-PLAN.md). Changes the hosting half of D-02 ("stay on Supabase + Vercel"); the database half is open, see [04](04-HOSTINGER-BUSINESS-PREFLIGHT.md). |
| OD-4 | Payment at launch is cash on delivery. No payment gateway. | Answers D-4 in the tracker for launch. A gateway stays a later phase. |
| OD-5 | Finance scope at launch is invoices, receipts and payables. No full accounting. | No further work on the double-entry ledger. Whether its screens are hidden at launch is open, see Q-8. |
| OD-6 | Customer messaging uses a Furniture-only WhatsApp number and e-mail. | Provider credentials are not yet supplied (tracker D-5). |
| OD-7 | No customer or order data is imported until the owner names the sources. | Only catalogue data is in scope for migration. |
| OD-8 | Project documents are kept in this folder on the working branch. | The repository is public, so nothing secret may be written here. |
| OD-9 | Phase 1 in [03](03-LAUNCH-PLAN.md) is approved. | Answers Q-3. Delivered: see [06](06-PHASE-1-REPORT.md). It does not authorise Phase 2. |
| OD-10 | "The admin" means the React dashboard in this repository, served at `/admin` on woodex-2030.vercel.app. It is the one to fix and deploy. | The Laravel "WOODEX OS" on branch `arena/69363bda-woodex-2030` stays unmerged and undeployed. See §4. |
| OD-11 | Ship through `main`. Vercel deploys `main` to woodex-2030.vercel.app. | Phase 1 reaches the demo site by a pull request into `main`, after CI passes. |
| OD-12 | The repository should be private. | Not done yet: see Q-2. |
| OD-13 | Phase 2 in [03](03-LAUNCH-PLAN.md), the real catalogue, is approved ("start complate next", 2026-11-16). | Delivered: see [07](07-PHASE-2-REPORT.md). It does not authorise applying migration 019 to the hosted database, nor publishing any product. |
| OD-14 | **The Laravel WOODEX OS is the base. The whole Agency OS is migrated into it, by a master plan** (2026-11-16, later the same day). | **Changes the target** set by OD-1, OD-2 and OD-10: the destination is one Laravel admin, not the React dashboard. Those three remain true of the *live* system until each module is cut over. The plan is [`../woodex-os/00-MASTER-PLAN.md`](../woodex-os/00-MASTER-PLAN.md); later decisions are logged in [`../woodex-os/12-DECISION-LOG.md`](../woodex-os/12-DECISION-LOG.md). |
| OD-15 | Four answers to the plan's opening questions (2026-11-16): the admin's screens are Laravel Livewire; **Phase 2 is finished and merged first**; the look is the Agency OS look; the plan's order of work. | The Laravel branch is brought into the working branch after the Phase 2 merge (plan, Wave 0). |

## 2. Defaults assumed, not yet confirmed

| ID | Default | Why it is safe to assume for now |
|---|---|---|
| A-1 | Products are migrated to a staging site first, after cleansing, and go live only after the owner signs off the list. | Nothing reaches production without a second approval. |
| A-2 | One company, one workspace. Multi-tenancy stays deferred. | Matches tracker D-1 ("later"). |
| A-3 | Release in phases, each with its own approval. | No phase authorises the next. |
| A-4 | The storefront keeps its current visual design. | A restyle can be scheduled later without blocking launch. See Q-9. |

## 3. Open: needed from the owner

Ordered by how soon each one blocks work.

| ID | Question or action | Needed for | Recommendation |
|---|---|---|---|
| Q-1 | **Change the production owner password.** A document in this repository printed it. The line has been removed from the current files and the secret scan would now catch it, but anything committed to a repository stays in its history: treat the password as known to others until it is changed. Asked on 2026-11-16; waiting for the owner to confirm. See finding F-01 in [02](02-AUDIT-FINDINGS.md). | Security, today | Sign in to the production dashboard, open Settings → Your password, and change it. Then say so, so that this entry can be closed. Nothing else closes F-01. |
| Q-2 | **Make the repository private.** Decided (OD-12), but only the owner can do it: the agent's GitHub access can read and push, and GitHub refused its request to change the setting (HTTP 403, 2026-11-16). | Security | On GitHub: the repository → Settings → General → Danger Zone → Change repository visibility → Private. Nothing in the site loads files from GitHub, so the live site is unaffected. Vercel and Hostinger both deploy from private repositories. |
| Q-3 | ~~Approve Phase 1.~~ Answered: approved (OD-9). **Next: accept Phase 1** after reading [06](06-PHASE-1-REPORT.md), and decide whether Phase 2 starts. | Starting Phase 2 | Phase 2 needs Q-4 and Q-5 answered first. |
| Q-4 | Who supplies the written business facts: warranty terms, delivery areas and charges, return policy, tax treatment, company history and figures? | Storefront content, checkout, invoices | One named person and one document. The current sites contradict each other, see [05](05-LIVE-SITE-AND-CATALOGUE.md). |
| Q-5 | Is the product list on woodexfurniture.pk the base for the new catalogue? Is there a price list or spreadsheet? | Catalogue migration | Use the live list, cleansed, unless a better source exists. |
| Q-6 | Which staff roles will use the dashboard, and how many people in each? | Access rules | List them. Today 84 of 214 API routes work for the owner role only, see F-06. |
| Q-7 | E-mail sending account and the Furniture WhatsApp number. | Order and quotation notifications | An SMTP mailbox on the Furniture domain; WhatsApp stays a click-to-chat link until a provider account exists. |
| Q-8 | Hide the accounting screens (ledger, credit notes, payment schedules) at launch? | Launch scope | Hide them from the menu; keep the code. |
| Q-9 | Keep the current storefront design, or restyle before launch? | Design work | Keep for launch. |
| Q-10 | Database on Hostinger: PostgreSQL on Supabase, or a SQLite file on the Hostinger plan. | Staging | Decide after the staging test described in [04](04-HOSTINGER-BUSINESS-PREFLIGHT.md). |
| Q-11 | The public demo at woodex-2030.vercel.app shows demo products and invented reviews to search engines. Restrict it? | Brand and SEO | Block search engines on the demo until launch. |
| Q-12 | Access for cutover: Hostinger account, domain DNS, Google Search Console. | Staging and cutover | Grant when Phase 4 starts. |
| Q-13 | Is Vercel now the launch host instead of Hostinger (OD-3)? Phase 1 was shipped to the Vercel demo because that is where `main` deploys; that is not a decision about where woodexfurniture.pk will run. | Phase 4 | Keep OD-3 (Hostinger Business) unless the owner says otherwise. |
| Q-14 | What should happen to the Laravel "WOODEX OS" branch (`arena/69363bda-woodex-2030`, 60 commits, not merged, not deployed)? | Avoiding two admin systems | Leave it on hold (OD-2). It and Phase 1 both change `apps/api/server.mjs` and three dashboard screens, so merging it later will need those conflicts resolved by hand. |

## 4. On hold

The Laravel system in `woodexinterior` was audited on 2026-11-11 at commit `603428c`, by reading the code only; its tests could not be run in the audit environment. It has a sound sales and finance core and no shop, inventory or production modules. By OD-2 it is on hold and nothing in this plan depends on it.

A second Laravel effort exists in this repository: branch `arena/69363bda-woodex-2030` adds a `platform/` directory (a Laravel application called "WOODEX OS", about 1,600 files) with its own deployment files for Vercel and Hostinger. Its own readiness document says no deployment was performed and lists seven blockers. It was found on 2026-11-16, read, and left untouched: by OD-10 the admin to fix and deploy is the React dashboard. It needs PHP, Composer and a MySQL database, none of which exist in the environment this work is done in, so nothing in it could be run or tested here.

**Since OD-14 (2026-11-16) that branch is no longer on hold:** it is the base of the WOODEX OS programme, described in [`../woodex-os/00-MASTER-PLAN.md`](../woodex-os/00-MASTER-PLAN.md). The paragraph above is kept as the record of what was decided before.

The repository's own guidance on mixing stacks stays in force: do not run two back ends against the same business tables.
