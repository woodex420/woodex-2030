# WOODEX OS → Woodex Furniture 2030 — reuse proposal and admin-upgrade master plan

**Status: PROPOSAL.** The Laravel application is not in this repository and nothing here is implemented in it.
It does not supersede `docs/agency-os/` until the owner approves it and a decision is recorded.

**Owner decisions recorded on 2026-11-11:** D-1 approved (preview only, no repository copy yet); D-3 rebuild one module at a time;
D-4 keep the React storefront; D-7 "WOODEX OS" for the admin, "Woodex Furniture" on documents.
**Phase 1a has been run.** Results, defects and what remains untested are in `01-PHASE-1A-PREVIEW-EVIDENCE.md`.
Sections 0 and 14 below describe the position *before* that run.

**Prepared:** 2026-11-11 · **Branch:** `arena/69363bda-woodex-2030` · **Base:** `main` @ `9b8cdca8`

---

## 0. Evidence boundary — what was and was not done

| Done (read-only) | Not done |
|---|---|
| Verified repo, branch, HEAD, clean tree | No dependency installed (no Composer, no npm, no apt) |
| Downloaded the archive, verified sha256 and md5, extracted it **outside** the git tree | No script, Artisan command, migration, seeder or test executed |
| Re-hashed all 2,329 files against `SOURCE_MANIFEST.json` (0 missing, 0 mismatched) | No server started |
| Read the three `EXPORT_INFO` files, both Composer manifests and the lock, npm manifest, every hook, bootstrap, all five route files, navigation, installer state, security middleware, seeders | No application file changed in either codebase |
| Classified all 83 migrations and 21 settings migrations by what `up()` does | No connection to any database, Interior or Furniture |
| Measured cross-module coupling, Interior-identity footprint, money columns | No commit, no push |
| Probed which download hosts this sandbox can reach (HEAD requests only) | — |

Everything in sections 2–3 marked *measured* comes from a command run in this session.
Everything marked *source claim* is the Interior project's own statement and is **unverified**.
Reading code does not prove a feature works; that is what the Phase 1 preview is for.

The only files this proposal adds to the repository are this document and one index row in `docs/README.md`.

---

## 1. Provenance

| Item | Value |
|---|---|
| Target (Furniture) | `woodex420/woodex-2030`, branch `arena/69363bda-woodex-2030`, HEAD `9b8cdca8b4c2fc57502349bc16c566e7036633ba` |
| Source (Interior) | `woodex420/woodexinterior`, `master` @ `603428cca1e45f9da2ffd9c4a0d88eb741285339` — never modified, never connected to |
| Archive | `WOODEX_OS_FULL_SOURCE_MASTER_v2_603428c.zip`, 44,331,669 bytes |
| sha256 | `5e8c271b5460fc5bb5a25f3aa478ac362f81f357b43cae7bb690df92752ee25c` (matches Drive metadata) |
| md5 | `0b01efdef295c4c036c8d8b35cda5ba9` (matches Drive metadata) |
| Contents | 2,332 files, 68.4 MB; manifest status `SOURCE_ARCHIVE_ONLY_NOT_VALIDATED_RELEASE`; no database backup, no `vendor/`, no `node_modules/`, no `.env` |
| Working copy | `/home/user/_woodex_os_source/extracted/` — outside the repository, reference only |

Both Drive links in the brief resolve to the same file (identical size and hashes).

Existing work preserved: `main` is untouched. PR #29 (`arena/ca6087dc-woodex-2030`, delivery) is still open and reports merge conflicts; it was not touched.

**Both repositories are public on GitHub** (*measured*, `gh repo view`). The Drive file is described as "Keep private". See decision D-6 in section 13.

---

## 2. What exists today

### 2.1 Woodex 2030 — this repository (*measured*)

| Layer | Fact |
|---|---|
| Shape | npm-workspaces monorepo: `apps/api`, `apps/dashboard`, `apps/storefront`; 466 tracked files |
| API | Express 4.21 on Node ≥ 22.5; one file, `apps/api/server.mjs`, 8,912 lines; **214 route handlers**; **71 tables** |
| Databases | SQLite locally, PGlite in tests, PostgreSQL in production; 18 ordered SQL migrations in `scripts/migrations/` |
| Dashboard | React 19, Vite 8, Tailwind 4, react-router 8; 76 source files, 17,475 lines; branded "Woodex 2030 — Agency OS" |
| Storefront | React 19, Vite 8, Tailwind 3; 116 source files, 17,863 lines; 25 routes; calls **10 API endpoints** |
| Auth | Bearer sessions in the browser, scrypt hashes, role → capability map (owner, editor, sales, finance, purchasing, production, viewer) |
| Money | 21 `double precision` and 13 `numeric(14,x)` columns — floating point in places |
| Hosting | Vercel + hosted PostgreSQL (per `docs/agency-os/00-CURRENT-STATE.md`; not re-verified here) |
| CI | `.github/workflows/ci.yml` runs on every push to `main` and `arena/**` |

Modules with working API routes: catalogue (products, materials, services), orders and returns, CRM (leads, clients, companies, contacts, meetings, tasks, site visits), quotations and templates, invoices and payments, double-entry journal, expenses, credit notes, payment schedules, projects with BOQ lines, procurement (suppliers, purchase orders, goods receipts, supplier invoices with three-way match), production (BOMs, job cards, QC), inventory (warehouses, locations, stock ledger, transfers, reservations), delivery (dispatch runs, deliveries, installed assets), support (tickets, warranty claims, service visits), CMS (pages, blocks, saved sections, theme), inbox, audit log, login throttle.

Recorded decision that this proposal reverses: `docs/agency-os/02-TECH-STACK-DECISIONS.md` D-01, "do not rewrite; evolve the Express + Vite monolith". The same document already states the rule this plan follows: *never run Express and Laravel against the same business tables; exactly one writer per table at a time.*

### 2.2 WOODEX OS — the Laravel source (*measured by static reading; nothing executed*)

| Layer | Fact |
|---|---|
| Stack | PHP `^8.4.1`, Laravel 13.39, Livewire 4.4, Fortify 1.41, dompdf 3.1, brick/math, Spatie permission / activitylog / medialibrary / settings / backup |
| Front end | Blade + Livewire + Alpine, Tailwind 4, Vite 8; the built assets are committed in `platform/public/build` |
| Size | 700 PHP files, 75,310 lines in `app/`; 82 models, 119 actions, 49 enums, 93 Livewire components, 37 console commands |
| Domains | Accounts, Assistant, Automation, Bot, Conversations, Crm, Marketing, Projects, Sales, System, Website |
| Admin routes | 109 definitions in `routes/admin.php` (67 Livewire screens), every one behind `auth`, `active`, `db-current`, `two-factor` and a `can:` permission |
| RBAC | 8 roles (owner, admin, sales_manager, sales, designer_pm, accountant, editor, support), 45 permissions |
| Schema | 83 migrations + 21 settings migrations; about 105 tables |
| Money | Integer minor units (`*_minor` as `bigInteger`) and basis points (`*_bp`) throughout sales and accounts |
| Tests | 200 test files (Pest). *Source claim:* 2,033 tests / 11,282 assertions passed on hosted MySQL at their revision 107 |
| Hosting design | Hostinger shared hosting: cron-drained database queue, no Node on the server, web installer, state kept outside the web folder |
| Own status | *Source claim:* 36 release gates still open, deployment held |

It already contains Furniture-specific sales code: a `furniture` business line, a `quote.furniture` number series, a `furniture.invoice` series (`WFI-`), `CreateFurnitureInvoice`, `FurnitureDocument` and a furniture document style.

It contains **no** product catalogue for a store, orders, checkout, stock, warehouses, procurement, production, delivery or warranty. Those exist only in Woodex 2030.

### 2.3 Who has what

| Capability | Woodex 2030 (Node) | WOODEX OS (Laravel) | Proposed owner |
|---|---|---|---|
| Staff auth, roles, permissions, 2FA | basic | mature (Fortify, 2FA, Spatie) | Laravel |
| Leads, contacts, companies, tasks, visits | yes | yes, deeper (assignment rules, SLA, import/export) | Laravel |
| Quotations, revisions, approvals, PDF, client link | yes | yes, deeper (sections, versions, events, signed portal) | Laravel |
| Invoices, payments, proofs, allocations | partial | yes | Laravel |
| Chart of accounts, journals, periods, P&L, payables | yes (013) | yes | Laravel, after merging 2030 credit notes and schedules |
| Projects, client portal, handover | basic + BOQ | yes | Laravel, after merging BOQ lines |
| Product catalogue, materials, services | **yes** | no (only a quotation rate book) | rebuild in Laravel |
| Orders, returns, checkout | **yes** | no | rebuild in Laravel |
| Stock ledger, warehouses, transfers, reservations | **yes** | no | rebuild in Laravel |
| Suppliers, purchase orders, receipts, supplier invoices | **yes** | vendors and bills only | rebuild + merge |
| Production (BOM, job cards, QC) | **yes** | no | rebuild in Laravel |
| Delivery, installation, installed assets | **yes** | no | rebuild in Laravel |
| Support, warranty, service visits | **yes** | no | rebuild in Laravel |
| CMS, page builder, theme | yes (typed blocks, used by the storefront) | yes (built around the Interior HTML site) | rebuild against the storefront's block model |
| Inbox, chat bot, automation, WhatsApp | inbox only | yes, provider-dependent | Laravel, deferred |
| Public storefront | **yes — the reimagined front end** | Interior HTML site (reference only) | keep the 2030 storefront |

---

## 3. Findings that shape the plan

**F1 — The Laravel modules are not separable by copying folders.** Imports between areas, counted from `use` statements (*measured*):

| From → To | System | Crm | Sales | Accounts | Projects | Conversations | Automation | Integrations |
|---|---|---|---|---|---|---|---|---|
| Sales (111 files) | 21 | 58 | — | 7 | 9 | 14 | 8 | 5 |
| Crm (58 files) | 7 | — | 4 | 0 | 3 | 4 | 2 | 7 |
| System (70 files) | — | 47 | 40 | 2 | 5 | 5 | 0 | 15 |
| Projects (29 files) | 2 | 21 | 14 | 8 | — | 0 | 0 | 1 |

Extracting "just CRM and quotes" would mean stubbing dozens of classes and would invalidate the 200-file test suite. That drives recommendation R1 below.

**F2 — Eleven migrations load, publish or edit Interior website content.** All 83 were classified by what `up()` executes:

| Group | Count | Treatment |
|---|---|---|
| Pure schema (create / alter) | 64 | run |
| Generic reference rows or guarded fixes (`unique_invoice_project_milestone`, `add_block_key_to_page_blocks`, `add_recurring_invoice_sequence`, `add_input_tax_account`, `add_manual_invoice_sequence`, `align_default_document_accent`, `add_furniture_documents`) | 7 | run; each `up()` read in full |
| Mixed: `create_document_libraries` creates two tables, then inserts an Interior and a Furniture document design and 12 description starters (7 Interior, 5 Furniture) | 1 | run in the preview (reference wording, no customer data); **adapt** in the Furniture copy so only Furniture rows are written |
| Interior website content (`seed_legacy_redirects`, `import_website_content`, `ensure_website_content`, `repair_imported_image_alt`, `publish_imported_website_pages`, `add_enquiry_form_to_home`, `add_call_to_action_to_pages`, `link_city_pages_to_their_areas`, `correct_the_privacy_page_hosting_claims`, `reload_the_complete_website`, `remove_the_furniture_page`) | 11 | **quarantine — never run for Furniture** |

`reload_the_complete_website` empties the website tables and re-imports 143 Interior pages and 105 photographs from `database/data/website-bundle/`. The bundle itself (11.6 MB) is excluded.
The 21 settings migrations write Interior identity as defaults (legal name "Woodex Interior", the Interior address, phone and e-mail, Interior payment terms). They must be adapted before use.

**F3 — Interior identity in code is small.** `Interior` appears in 25 files in `app/`, 22 Blade views, 6 seeders and 1 settings migration. Live identifiers (`woodex.com.pk`, the phone number, the address, the Telegram bot name) appear in fewer than 10 files each. Most identity is data, not code.

**F4 — Money is safer in the Laravel source.** Integer minor units and basis points, versus floating point in parts of Woodex 2030. Any data carried over needs explicit rounding and a reconciliation report.

**F5 — Time zone conflicts with the brief.** The source stores local time (`APP_TIMEZONE=Asia/Karachi`, database session `+05:00`). The brief requires UTC storage with Pakistan-time display. Changing this touches every "today", reminder, report boundary and yearly number series. It is a design decision with a test cost, not a config flip.

**F6 — Hooks are benign but must not be run blindly.** Root `composer.json` post-install deletes cached bootstrap files and two public files. `platform/composer.json` has a `setup` script that installs, generates a key, migrates **and seeds** in one go. `woodex:install` seeds reference data and imports the bundled Interior website. None will be invoked as-is.

**F7 — Routing mixes concerns.** `routes/web.php` registers the admin, Google OAuth, Telegram and WhatsApp webhooks, three signed client portals, a legacy `forms.php` / `chat.php` bridge, an MCP endpoint and a CMS fallback that answers every unknown URL. Modules that are switched off must have their routes **unregistered**, not merely hidden from the menu.

**F8 — This sandbox cannot host MySQL.** *Measured:* only `github.com`, `api.github.com`, `codeload.github.com`, `registry.npmjs.org` and PyPI are reachable. Debian mirrors, Packagist, php.net, MySQL / MariaDB downloads, container registries and GitHub release assets are all blocked. PHP, Composer, MySQL and Docker are not installed. The Interior project hit the same wall and recorded that genuine local MySQL never ran in its sandbox. Consequence: section 9.

**F9 — Licences to carry or resolve.** Runtime packages are MIT / BSD / Apache, with dompdf under LGPL-2.1. `THIRD-PARTY.md` flags two open items: the Nohemi typeface ("commercial licence to be confirmed") and Preline Pro blocks ("only with a purchased licence"). The ported `admin-v21` front end is recorded as first-party.

---

## 4. Reuse proposal

### R1 — Adopt the whole application, then prune by exclusion and module switches

Copy the Laravel application as one unit into an isolated directory, exclude Interior content, and gate optional modules with a `config/modules.php` switch that controls route registration, navigation and scheduler entries.

Why not cherry-pick files: F1. Why this is still bounded: the exclusion list in 4.3 removes all Interior content, and modules that need providers start switched off with their routes unregistered (F7).

### 4.1 Proposed directory layout

```
woodex-2030/
├─ apps/api, apps/dashboard, apps/storefront   unchanged — the existing application keeps building and deploying
├─ api/, scripts/, deploy/, vercel.json        unchanged
├─ platform/                                   NEW — the Furniture copy of the Laravel application
│   ├─ app/ bootstrap/ config/ routes/ resources/ tests/ public/
│   ├─ database/migrations/                    72 reviewed migrations
│   ├─ database/quarantine/interior-content/   11 migrations kept for reference, outside the migration path
│   ├─ database/seeders/, settings/, factories/
│   ├─ PROVENANCE.md                           source repo, commit, archive hashes, exclusion list
│   └─ THIRD-PARTY.md
├─ docs/laravel-os/                            NEW — this proposal, decisions, migration register, acceptance evidence
└─ .github/workflows/os-ci.yml                 NEW, separate approval — PHP 8.4 + MySQL 8 job
```

`platform/` keeps the source's own directory name so its paths, tools and documentation references stay valid.
Runtime files (`vendor/`, `.env`, `storage/`, SQLite files) never enter git; in this sandbox they live under `/home/user/.cache/woodex-os/`.

### 4.2 Existing paths that would change

| Path | Change | When |
|---|---|---|
| `docs/README.md` | one index row for this proposal | now |
| `.gitignore` | add `platform/vendor`, `platform/.env`, `platform/storage/*` runtime rules | Phase 1b |
| `.github/workflows/` | add `os-ci.yml`; `ci.yml` itself unchanged | Phase 1b, separate approval |
| `docs/agency-os/02-TECH-STACK-DECISIONS.md`, `DECISION-LOG.md` | record the reversal of D-01 once the owner approves the direction | Phase 2 |
| `apps/storefront/src/lib/*` (API base path) | point the 10 endpoints at `/api/v1`, one module at a time | Phase 4 onward |
| `apps/dashboard/**`, `apps/api/**` | none until a module is cut over; then that module's screens and routes are retired | per phase |

### 4.3 Paths and data that remain untouched

- `main` and every other branch; the `woodexinterior` repository; the Interior production site and database.
- `apps/api/server.mjs`, `scripts/schema.postgres.sql`, `scripts/migrations/`, the hosted PostgreSQL database, Vercel configuration.
- `apps/api/public/img/` — the only copy of product photography.
- No Interior customers, leads, quotes, invoices, finance, projects, uploads or credentials exist in the archive, and none will be sought.

### 4.4 Source → target map

Source root: `WOODEX_OS_FULL_SOURCE_MASTER_v2/`. File counts *measured*.

| Source path | Files | Target | Action |
|---|---|---|---|
| `platform/app/` | 700 | `platform/app/` | copy; adapt identity strings (25 files) |
| `platform/bootstrap/`, `config/`, `routes/` | 27 | same under `platform/` | copy; add module-gated route registration; adapt domains and names in config |
| `platform/database/migrations/` | 83 | 72 → `migrations/` (one of them adapted), 11 → `quarantine/interior-content/` | per F2 |
| `platform/database/settings/` | 21 | same | adapt Interior defaults to Furniture values supplied by the owner |
| `platform/database/seeders/` | 19 | same | keep generic (roles, stages, number series, chart of accounts); replace Interior rate book, quote templates, canned replies, sequence copy, page templates |
| `platform/database/factories/` | 10 | same | copy |
| `platform/database/data/` | 107 | — | **exclude** (Interior website bundle and legacy URL map) |
| `platform/database/rehearsal/` | 1 | — | exclude (legacy importer) |
| `platform/resources/views/` | 209 | same | copy; adapt branding (22 files); `views/site` and `views/website` (7 files, Interior public templates) held back |
| `platform/resources/css`, `js`, `fonts` | 15 | same | copy; **Nohemi font blocked** until its licence is confirmed |
| `platform/public/index.php`, `.htaccess`, favicons, `build/`, `builder/`, `fonts/`, `sounds/`, `assets/admin-v21/` | about 85 | `platform/public/` | copy (admin runtime assets) |
| `platform/public/` — 142 Interior page folders, `index.html`, `assets/img/` (278 files, 22 MB), `img/`, `images/` | about 550 | — | **exclude** — reference only, never published as Furniture |
| `platform/tests/` | 200 | `platform/tests/` | copy; triage the 36 Website tests and anything that assumes Interior content |
| `platform/composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `vite.config.js`, `.npmrc`, `artisan`, `phpunit.xml`, `phpstan.neon`, `pint.json`, `.gitignore`, `.env.example`, `THIRD-PARTY.md` | 13 | `platform/` | copy; `.env.example` adapted (no Interior domains) |
| `platform/AGENTS.md`, `STATE.md`, `README.md` | 3 | — | exclude (Interior project history); cited from `PROVENANCE.md` |
| root `composer.json`, `composer.lock`, `.htaccess`, `tools/deploy/` | 4 | — | defer to the Hostinger staging design |
| `tools/sandbox/` | about 45 | `platform/tools/preview/` | adapt (paths are hard-coded to the Interior checkout) |
| `tools/docker/`, `tools/release/`, `.github/workflows/` | about 40 | `os-ci.yml` | rebuild as one slim Furniture workflow |
| `tools/mcp-bridge/`, `tools/website/` | about 10 | — | exclude |
| `docs/` | 170 | — | exclude (Interior history and audits; relative links would also fail this repo's `docs:verify` gate) |
| `EXPORT_INFO/` | 3 | `platform/PROVENANCE/` | keep as provenance |

Expected addition to the repository: roughly 1,300 files and 15 MB.
The existing `secret-scan` gate was simulated against the source with the same seven patterns: 0 matches.

### 4.5 Dependency map

| Dependency | Version locked | Purpose | Licence |
|---|---|---|---|
| `laravel/framework` | 13.39.0 | framework | MIT |
| `livewire/livewire` | 4.4.8 | admin interface | MIT |
| `laravel/fortify` | 1.41.0 | sign-in, 2FA, password reset | MIT |
| `spatie/laravel-permission` | 8.3.2 | roles and permissions | MIT |
| `spatie/laravel-activitylog` | 5.1.3 | audit trail | MIT |
| `spatie/laravel-medialibrary` | 11.23.11 | uploads and media | MIT |
| `spatie/laravel-settings` | 3.10.0 | typed settings | MIT |
| `spatie/laravel-backup` | 10.3.3 | backups | MIT |
| `barryvdh/laravel-dompdf` + `dompdf/dompdf` | 3.1.2 / 3.1.6 | quotation and invoice PDFs | MIT / LGPL-2.1 |
| `brick/math` | 1.0.0 | exact money arithmetic | MIT |
| `laravel/tinker` | 3.0.2 | console | MIT |
| Dev: Pest 5, Larastan, Pint, Faker | locked | tests, static analysis, style, fixtures | MIT |

The lock holds 118 runtime and 48 development packages; every download URL is on `api.github.com`, which this sandbox can reach.
PHP extensions required at runtime: ctype, dom, exif, fileinfo, filter, hash, iconv, json, libxml, mbstring, openssl, pcre, session, tokenizer, zip, zlib, plus `pdo_mysql` (production) or `pdo_sqlite` (preview and tests).
npm (admin assets only): Vite 8, Tailwind 4, alpinejs, apexcharts, pdfjs-dist, preline, sortablejs; `.npmrc` sets `ignore-scripts=true`. Not needed for a preview because the build is committed.
Likely new dependency later: `laravel/sanctum` for an authenticated `/api/v1`. The source has only public endpoints and a read-only bearer bridge.

---

## 5. Admin reuse review

Labels as defined in the brief.

| Area | Class | Basis and conditions |
|---|---|---|
| Authentication (Fortify, 2FA, active-user check, guest redirects) | **KEEP** | Generic. Fresh Furniture owner identity; no Interior accounts exist in the archive |
| Users, roles, permissions | **EXTEND** | Keep 8 roles / 45 permissions; add commerce, purchasing, production, inventory, delivery and support permissions as each module lands |
| Web installer and external state folder | **ADAPT** | Hostinger-specific; verify against the real plan before staging. Not used in the local preview |
| CRM — contacts, companies | **KEEP** | Generic |
| CRM — leads, pipeline, assignment rules, import / export | **ADAPT** | Stages and business-line defaults are Interior-shaped ("site visit", interior / project lines) |
| CRM — follow-ups, tasks, site visits | **ADAPT** | Rename and re-scope visits for showroom and measurement use |
| Sales pipeline (quotation tracking board) | **KEEP** | Generic |
| Quotes — builder, sections, items, revisions, events | **ADAPT** | Furniture line and number series already exist; link lines to the product catalogue once it exists |
| Quote approvals, discount limits | **KEEP** | Role-based limits are settings |
| Quote and invoice PDFs, document designer | **ADAPT** | Furniture style exists; branding, terms and signatories must come from the owner. Nohemi font **BLOCKED** |
| Signed client pages for quotes and invoices | **DEFER** | Public surface; enable after staging has a real domain and mail |
| Invoices, receipts, payment allocation, proofs | **ADAPT** | Keep the model; Furniture numbering and terms |
| Approved finance — chart of accounts, journals, periods, trial balance, P&L | **MERGE** | Laravel becomes the ledger authority; bring in 2030 credit notes and payment schedules; reconcile minor units |
| Payables — vendors, bills, bill payments, recurring expenses | **MERGE** | Join with 2030 suppliers and supplier invoices into one supplier master |
| Projects, milestones, updates, handover, cost requests | **ADAPT** | Keep for fit-out and installation projects |
| BOQ | **MERGE** | The source has BOQ in quotations; 2030 has `boq_lines` on projects |
| Project documents, client portal with access codes | **DEFER** | Public surface, as above |
| CMS pages, blocks, versions, templates, saved sections | **REBUILD** | Must manage the storefront's block model, not the Interior HTML files |
| Visual page builder for HTML files, find and replace over files | **EXCLUDE** | Built for the 142 Interior files, which are not reused |
| Media library | **KEEP** | Needed by the catalogue; product photography moves in during Phase 3 |
| SEO overview, redirects, site health | **ADAPT** | Generic once pointed at Furniture pages; the 54 Interior redirects are excluded |
| SEO agent, speed runs, social agent and publisher | **DEFER** | Need outbound providers and scheduled jobs |
| Theme builder and theme CSS routes | **MERGE** | Must emit what the storefront reads from `GET /api/theme` |
| Notifications (in-app) | **KEEP** | Generic |
| Notifications by Telegram, WhatsApp, mail digests | **DEFER** | Provider credentials; Interior bot identity |
| Audit log, activity log, recycle bin, change requests, health, database-update screen | **KEEP** | Generic platform tools |
| Reporting — funnel, revenue, receivables, monthly report | **EXTEND** | Add commerce, stock and production reporting; absorb 2030 Business Analytics |
| Integration foundations — connections screen, outbound webhooks, integration log | **ADAPT** | Keep the framework, start with everything disconnected |
| Google sign-in and Workspace, WhatsApp Cloud, Telegram, MCP bridge, Turnstile | **DEFER** | Each needs Furniture-owned credentials and its own approval |
| Inbox, canned replies, chat widget, chat bot, automations, sequences | **DEFER** | Message copy is Interior's; no provider messages in the preview |
| Legacy bridge (`/api/forms.php`, `/api/chat.php`), v2.1 importer, legacy database connection | **EXCLUDE** | Exists only to serve the Interior site and its old database |
| Interior HTML website, website bundle, Interior seed copy | **EXCLUDE** | Reference only |
| Rate book, description library, estimator rates | **BLOCKED** | Interior prices and wording. Furniture needs an owner-supplied price list; nothing will be invented |

---

## 6. Admin upgrade master plan

Goal: one Laravel admin, named and branded for Furniture, whose navigation is the list the owner supplied.
Items that exist only in Woodex 2030 are rebuilt in Laravel one module at a time.
Until a module is rebuilt and accepted, its menu entry opens the existing 2030 screen and is visibly badged, so nobody is told a screen is native when it is not.

### 6.1 Target navigation, item by item

"Phase" refers to section 8. "2030" means the screen exists only in the Node / React application today.

**Overview**

| Item | Today | Target | Class | Phase |
|---|---|---|---|---|
| Dashboard | OS `DashboardHub`; 2030 "Overview" | one dashboard; 2030 KPIs join as cards when their module lands | MERGE | 2, then each phase |
| Approvals | OS (quotes, payment verification, change requests) | add purchase-order, variance-override and production-override approvals | EXTEND | 2, 6, 7 |
| Reports | OS `ReportsHub`; 2030 "Business Analytics" | Business Analytics becomes a Reports tab | MERGE | 2, 9 |

**CRM**

| Item | Today | Target | Class | Phase |
|---|---|---|---|---|
| Leads | OS `LeadsHub`; 2030 `/crm` | Laravel; 2030 screen retired at cutover | ADAPT | 1 |
| Contacts | OS `ContactsHub` (people, companies); 2030 `/clients`, `/companies` | Laravel | KEEP | 1 |
| Tasks & Visits | OS `TasksHub`; 2030 `/tasks`, `/meetings` | Laravel; meetings become a task type | ADAPT | 1 |

**Sales**

| Item | Today | Target | Class | Phase |
|---|---|---|---|---|
| Estimations | OS estimator, Interior per-area rates | hold until Furniture pricing rules are supplied | BLOCKED | 3 |
| Quotations | OS `QuotationsHub`; 2030 `/quotations` | Laravel, furniture line | ADAPT | 1 |
| Invoices | OS `InvoicesHub`; 2030 `/invoices` | Laravel | ADAPT | 2 |
| Payments | OS `PaymentsHub` (all, dues, receivables) | Laravel; 2030 payment schedules map to milestones | MERGE | 2 |
| Documents | OS `DocumentsHub` (rate book, descriptions, design) | design and descriptions kept; rate book replaced by the catalogue | ADAPT | 2, 3 |

**Commerce & Fulfilment** — all 2030 today

| Item | Target | Class | Phase |
|---|---|---|---|
| Commerce Overview | Livewire hub over orders, stock and deliveries | REBUILD | 4 |
| Product Catalog | products, categories, materials, services, images; replaces the OS rate book so quotes and the store read one catalogue | REBUILD + MERGE | 3 |
| Orders | orders, returns, order → invoice, reservation | REBUILD | 4 |
| Delivery & Installation | dispatch runs, deliveries with proof, installed assets | REBUILD | 8 |
| Support & Warranty | tickets, warranty claims, service visits | REBUILD | 10 |
| Login locks | move to System → Access & team; it is a security screen, not commerce | MERGE | 2 |
| Operations | one operations board across order, production and delivery stages | REBUILD | 4–8 |
| Projects | duplicate of the Projects group below; keep one | MERGE | 8 |

**Procurement & Production** — all 2030 today

| Item | Target | Class | Phase |
|---|---|---|---|
| Suppliers | one supplier master, merged with OS vendors | REBUILD + MERGE | 6 |
| Purchase Orders | native | REBUILD | 6 |
| Goods Receipts | native; posts a stock-ledger receipt | REBUILD | 6 |
| Supplier Invoices | merged into OS bills, adding three-way match | MERGE | 6 |
| Production | BOMs, job cards, material issue, QC gate | REBUILD | 7 |
| Stock Ledger | append-only movements; levels derived | REBUILD | 6 |
| Warehouses | native | REBUILD | 6 |
| Stock Transfers | native | REBUILD | 6 |
| Reservations | native; written by orders | REBUILD | 6 |

**Accounts** — "upgrade with Finance"

| Item | Today | Target | Class | Phase |
|---|---|---|---|---|
| Ledger & reports | OS chart, journals, periods, trial balance, P&L; 2030 `/ledger` | Laravel is the ledger; 2030 journal retired at cutover | MERGE | 2, 9 |
| Payables | OS vendors, bills, payments | plus supplier invoices | MERGE | 6 |
| Income & expenses | OS expenses and recurring; 2030 `/expenses` | Laravel | MERGE | 9 |
| Credit notes (recommended addition) | 2030 only | add to Accounts | REBUILD | 9 |

**Projects**

| Item | Today | Target | Class | Phase |
|---|---|---|---|---|
| Projects, Board | OS; 2030 `/projects` with BOQ lines | Laravel, with BOQ lines merged in | MERGE | 8 |
| Vendors & costs | OS | Laravel, on the merged supplier master | ADAPT | 8 |
| Client portal, Handovers | OS, signed public links | Laravel; public links enabled only on staging | DEFER | 8 |

**Inbox, Automation, Marketing**

| Item | Today | Target | Class | Phase |
|---|---|---|---|---|
| Conversations | OS inbox (WhatsApp + web chat); 2030 "Shared Inbox" | Laravel; providers off until credentials exist | DEFER | 11 |
| Chat Bot | OS settings tab and knowledge base | Furniture knowledge only | DEFER | 11 |
| Automations, Sequences, WhatsApp Templates | OS | off; no provider messages before approval | DEFER | 11 |
| Marketing | OS social planner; 2030 "Landing Analytics" | one hub | DEFER | 11 |

**Pages & builder, Content, Website & Growth** — "frontend reimagine"

| Item | Today | Target | Class | Phase |
|---|---|---|---|---|
| All pages, Page builder | OS edits Interior HTML files; 2030 has a typed block editor the storefront already renders | Laravel screens over the storefront's block model | REBUILD | 11, or earlier on request |
| Find & replace | OS, over HTML files | over database content | REBUILD | 11 |
| Redirects | OS | kept; Interior redirects excluded | KEEP | 11 |
| Blog, Portfolio, Forms | OS studio screens | pointed at the storefront's `/blog`, `/projects` and enquiry forms | ADAPT | 11 |
| Media | OS media library | kept; product photography moves in | KEEP | 3 |
| SEO, Site health | OS | pointed at Furniture pages | ADAPT | 11 |
| SEO agent, Speed | OS, scheduled and provider-backed | off | DEFER | 11 |
| Theme | OS theme tokens; 2030 Theme Studio | one token set that the storefront reads | MERGE | 11 |
| Website Settings | OS | Furniture tracking IDs supplied by the owner | ADAPT | 11 |

**System** — "upgrade with Insights & Admin"

| Item | Today | Target | Class | Phase |
|---|---|---|---|---|
| Settings | OS settings; 2030 "Workspace Settings" | one page; Furniture identity | MERGE | 1, 2 |
| Access & team | OS users and roles; 2030 roles and login locks | extended role set; lockout view | EXTEND | 1, 2 |
| Connections | OS integrations, imports, tokens | everything disconnected to start | ADAPT | 2 |
| Platform | OS health, audit, change requests, recycle bin, updates | kept; 2030 schema-drift and diagnostics ideas join | KEEP | 1 |

**Workspace** — 2030 today

| Item | Target | Class | Phase |
|---|---|---|---|
| Overview (`/`) | merged into Overview → Dashboard | MERGE | 2 |
| Business Analytics | merged into Overview → Reports | MERGE | 9 |
| Header "Woodex Interiors · 1 workspace · Live" | Furniture identity; no workspace switcher (neither system is multi-tenant) | EXCLUDE | 2 |
| "UI Components (Dev)" | not shipped in the admin menu | EXCLUDE | 2 |

### 6.2 Recommended additions

| Addition | Why |
|---|---|
| Returns, under Commerce | 2030 already has returns that restock through the ledger; the owner's list omits it |
| Credit notes, under Accounts | Exists in 2030; needed to correct posted invoices by reversal |
| Module status page, under System → Platform | Shows which modules are native and which still open the 2030 screen, with the cutover date of each |
| Order status for customers | The storefront has `/order-status`; give it a signed link like quotes and invoices |
| Price lists and configurator options, under Product Catalog | The storefront has configurator components that need a server-side source |
| Backups, under System → Platform | The package is present; the screen should show last backup and last restore test |

### 6.3 Recommended final grouping

```
Overview      Dashboard · Approvals · Reports
CRM           Leads · Contacts · Tasks & Visits
Sales         Estimations · Quotations · Invoices · Payments · Documents
Commerce      Overview · Product Catalog · Orders · Returns · Delivery & Installation · Support & Warranty
Inventory     Stock Ledger · Warehouses · Transfers · Reservations
Procurement   Suppliers · Purchase Orders · Goods Receipts · Supplier Invoices
Production    Production · Operations board
Accounts      Ledger & reports · Payables · Income & expenses · Credit notes
Projects      Projects · Board · Vendors & costs · Client portal · Handovers
Inbox         Conversations · Chat Bot
Automation    Automations · Sequences · WhatsApp Templates
Marketing     Marketing
Website       Pages & builder · Content · Media · SEO · Site health · Theme · Website Settings
System        Settings · Access & team · Connections · Platform
```

Changes from the supplied list: Login locks moves to Access & team; the duplicate Projects entry is removed; "Procurement & Production" splits into three shorter groups; Workspace folds into Overview.

### 6.4 One writer per business object

Until the Laravel application is approved for production, it runs only locally and on staging with synthetic data, so no live object has two writers.
At each production cutover the rule is: freeze the 2030 writes for that object, migrate or archive its rows, switch the storefront endpoint, then retire the 2030 screen.

| Business object | Writer today | Writer after | Cutover phase |
|---|---|---|---|
| Staff accounts and sessions | 2030 (own table) | Laravel for the Laravel admin; separate identity stores, no sync | 1–2 |
| Leads, contacts, companies, tasks | 2030 | Laravel | 2 |
| Quotations | 2030 | Laravel | 2 |
| Invoices, payments, journal | 2030 | Laravel | 5, 9 |
| Products, materials, services | 2030 | Laravel | 3 |
| Orders, returns | 2030 | Laravel | 4 |
| Stock, suppliers, purchase orders, receipts | 2030 | Laravel | 6 |
| BOMs, job cards | 2030 | Laravel | 7 |
| Projects, deliveries, installed assets | 2030 | Laravel | 8 |
| Tickets, warranty claims | 2030 | Laravel | 10 |
| Pages, blocks, theme | 2030 | Laravel | 11 |

---

## 7. Storefront integration — "use the reimagined front end"

Recommendation: keep the React storefront as the public site. It is the reimagined front end, it is already built, and it depends on only ten endpoints (*measured*):

| Storefront call today | Proposed `/api/v1` contract | Laravel module | Phase |
|---|---|---|---|
| `GET /api/products` | `GET /api/v1/products` | Catalogue | 3 |
| `GET /api/products/overrides` | `GET /api/v1/products/overrides` | Catalogue | 3 |
| `POST /api/leads` | `POST /api/v1/leads` (the source's `POST /api/public/leads`, adapted) | CRM | 2 |
| `POST /api/leads/:id/whatsapp-handoff` | `POST /api/v1/leads/{id}/whatsapp-handoff` | CRM | 2 |
| `POST /api/quotes` | `POST /api/v1/quote-requests` | Sales | 2 |
| `POST /api/orders` | `POST /api/v1/orders` | Orders | 4 |
| `GET /api/orders/lookup` | `GET /api/v1/orders/lookup` | Orders | 4 |
| `GET /api/pages/:slug` | `GET /api/v1/pages/{slug}` | CMS | 11 |
| `GET /api/theme` | `GET /api/v1/theme` | Theme | 11 |
| `POST /api/track` | `POST /api/v1/track` | Analytics | 11 |

Delivery on Hostinger: the storefront is built to static files before deployment and served from the Laravel public folder, so no Node process runs on the server.
Individual pages move to Blade with Alpine or Livewire only where server rendering earns it (product, category and CMS pages). That is the "gradual Blade storefront" of the selected architecture, done as a strangler rather than a rewrite.

Components worth adapting from `apps/storefront/src`: the product grid and product page, the configurator, the quotation request flow, the theme tokens in `src/lib/theme.ts`, and the image manifest in `src/assets/img.ts`.
Nothing from the Interior HTML site is used.

---

## 8. Phases

Each phase needs its own scope and acceptance approval. This roadmap authorises nothing by itself.

| Phase | Scope | Exit evidence |
|---|---|---|
| **0** — done | Read-only inspection; this proposal | this document |
| **1a** — bounded preview | Bring the source up in an isolated copy with a synthetic database; exercise sign-in, roles, lead → quotation → PDF (section 9) | recorded commands and results for every item in 9.4 |
| **1b** — Furniture foundation | Copy the reviewed application into `platform/` per 4.4; quarantine 11 migrations; module switches; MySQL job in CI | CI green on MySQL 8; `secret-scan` and the existing gates still green |
| **2** — admin upgrade | Rename and rebrand; target navigation with badges for 2030-only items; Furniture settings; extended roles; invoices, payments, documents; `/api/v1` skeleton; leads and quote requests from the storefront | acceptance checklist per screen, on MySQL |
| **3** | Catalogue and configurator; media | 148 seeded products present and reconciled against `apps/api/seed-data.json` |
| **4** | Storefront, cart, checkout, orders | an order placed from the storefront reaches the admin; no stock promise that the ledger cannot back |
| **5** | Payments and reconciliation | no live payments; reconciliation report balances |
| **6** | Inventory and procurement | every stock change is a ledger row; three-way match enforced |
| **7** | Production and quality | QC gate cannot be bypassed without an audited override |
| **8** | Projects, delivery, installation | delivery with proof; installed asset recorded |
| **9** | Finance and reporting | trial balance balances; posted entries change only by reversal |
| **10** | Support, returns, warranty | a delivered order produces a claim that resolves, audited |
| **11** | CMS, SEO, integrations, inbox, automation | each provider behind its own approval |
| **S** | Hostinger staging (section 12) | may start after Phase 2 for the admin alone |
| **P** | Production gate (section 12) | separate written approval |

Honest sizing: Phases 3–10 re-implement about 35 tables and well over 100 API routes that already work in Node. That is the largest cost in this programme and is measured in months, not days.

---

## 9. Local preview — the exact scope proposed for approval (Phase 1a)

### 9.1 What would be installed, and where

Everything goes under `/home/user/.cache/woodex-os/`. Nothing global, no `sudo`, nothing inside the git tree, nothing persisted in the repository.

| Component | Source | Notes |
|---|---|---|
| PHP 8.4 command-line binary | `NativePHP/php-bin`, `bin/linux/x64/php-8.4.zip` (24.8 MB), pinned to one commit and fetched through the GitHub API; its sha256 is recorded | Static build made with static-php-cli. Listed extensions include `pdo_sqlite`, `gd`, `intl`, `mbstring`, `openssl`, `zip`. **No `pdo_mysql`, no `exif`** |
| Fallback PHP | `@php-wasm/node` from npm (PHP 8.4 on WebAssembly) | The route the Interior project used in the same kind of sandbox |
| Composer | run from `composer/composer` source at a release tag, with its 29 locked packages | Packagist and the official installer are unreachable |
| 166 PHP packages | exact commits from `platform/composer.lock`, from `api.github.com` | `--no-scripts`, `--ignore-platform-req=ext-exif`; then `package:discover` run explicitly |
| Admin assets | the committed `platform/public/build` | no `npm install`, no build |
| Optional | `@sparticuz/chromium` + `puppeteer-core` from npm | real-browser checks and screenshots; only if approved |

These routes are untested until approved. If the binary or the Composer bootstrap fails, that is reported as a blocker, not worked around silently.

### 9.2 Data

- A new SQLite file named `woodex-synthetic-furniture-preview.sqlite` in the runtime folder. No MySQL, no Interior database, legacy connection unset.
- Migrations: the 72 retained migrations (F2) and the 21 settings migrations. The 11 Interior content migrations are moved out of the migration path first.
- The settings migrations write Interior defaults and `create_document_libraries` writes one Interior document design; the preview overrides the business identity afterwards and reports exactly which Interior-labelled reference rows remain.
- Reference data: roles and permissions, pipeline stages, number series, chart of accounts.
- Fixtures: staff accounts on the reserved `@woodex.test` domain (owner, sales manager, sales, accountant), factory-generated fictional contacts and leads, and catalogue lines whose names begin with `SYNTHETIC`.
- Business identity overridden to "Woodex Furniture — synthetic preview" with placeholder contact details.
- Interior rate book, quote templates, canned replies and sequence copy are **not** loaded.

### 9.3 Runtime settings

`APP_ENV=local`; a locally generated `APP_KEY` that never leaves the runtime folder; mail to the log; database queue with no worker; no scheduler; no provider keys.
Sign-in, CSRF and permission checks stay on. The source's "direct synthetic admin" launcher, which skips sign-in, is **not** used.
Server bound to `0.0.0.0:8080`; assets and self-links root-relative.

### 9.4 Acceptance checks to run and record

| Brief §11 item | Check |
|---|---|
| Sign-in, sign-out, session continuity | Fortify sign-in with CSRF; session survives navigation; sign-out invalidates it; wrong password returns to the form |
| Role-based allowed and denied actions | owner, sales and accountant each request a permitted and a forbidden admin route; expect 200 and 403 |
| Server-side validation and errors | invalid lead and invalid quotation line are refused with field errors; unknown URL returns 404, not a stack trace |
| Selected CRM / quote / PDF workflow | lead → contact → furniture quotation → revision → approval → PDF; the PDF opens and its totals equal the stored minor-unit totals |
| API authentication and contract | `POST /api/public/leads`: valid, invalid, honeypot and throttled responses, each with status and JSON shape |
| Database persistence and integrity | rows exist after each step; `PRAGMA foreign_key_check` and `integrity_check` clean |
| Desktop and mobile interface, navigation, assets | every parameterless admin route answers; assets load with correct types; real-browser screenshots only if Chromium is approved |
| Empty, loading, success, failure states | empty lists before fixtures; success and failure flashes on create and on a refused action |

### 9.5 What this preview will not prove

- **MySQL correctness.** SQLite is not MySQL. MySQL evidence comes from the CI job in Phase 1b and later from Hostinger staging.
- Behaviour inside the Arena preview frame. The Interior project recorded, on 2026-10-15, a preview proxy that dropped the `Cookie` header, and separately a platform-level "Missing traffic access token" 403. Whether that still applies is unknown and cannot be tested from inside the sandbox, because the preview host is not reachable from here. The server will log request header **names** so the first real page load shows what arrives.
- Provider integrations, mail delivery, queues under load, performance, security posture, Hostinger compatibility.
- JPEG uploads that need `exif`.

---

## 10. Furniture database plan

| Rule from the brief | Position |
|---|---|
| Dedicated Furniture MySQL; Laravel migrations are the schema authority | The 72 reviewed migrations plus new Furniture migrations per phase. No shared database with Interior or with the 2030 PostgreSQL |
| Never execute original migrations wholesale | Register in F2; 11 quarantined |
| Fresh admin identity | Created at install from values the owner supplies out of band; never committed |
| Synthetic fixtures clearly labelled | Reserved `@woodex.test` domain; `SYNTHETIC` prefix; a seeder that refuses to run outside local and testing |
| Foreign keys, indexes, deletion rules | Source uses `restrictOnDelete` on finance and soft deletes with a recycle bin; to be verified table by table on MySQL |
| Transactions and idempotency | Source has idempotency keys on bill payments and cost requests; orders and stock movements need the same when rebuilt |
| Integer minor units | Already the source convention |
| Posted stock and finance history immutable | To be verified for finance in Phase 9; to be designed for stock in Phase 6 |
| Accepted quote and order snapshots | Source snapshots quotations and invoices; orders need it when rebuilt |
| UTC storage, Pakistan-time display | **Conflict** — see F5 and decision D-5 |
| A tested schema export | Only after an approved MySQL run |

---

## 11. Risks and blockers

| # | Item | Effect | Mitigation |
|---|---|---|---|
| B1 | No MySQL in this sandbox | Local preview is SQLite only | MySQL 8 job in GitHub Actions on this branch; Hostinger staging later |
| B2 | PHP and Composer must come through GitHub or npm | Runtime route untested | Two routes prepared; failure is reported |
| B3 | Preview proxy and cookies unknown | Sign-in may fail in the embedded preview for platform reasons | Header-name logging; owner test; no sign-in bypass without explicit approval |
| B4 | Reverses recorded decision D-01 | Two stacks maintained until cutover | Record the decision; cut over module by module |
| B5 | Local-time storage in the source | Violates the UTC rule | Decide before Phase 1b; audit every date boundary |
| B6 | Nohemi font and Preline Pro licences unconfirmed | Cannot ship those assets | Use Inter until confirmed |
| B7 | Both repositories are public | Copying publishes the Furniture copy too | Decision D-6 |
| B8 | Re-implementation size of Phases 3–10 | Months of work | Strict phase gates; the 2030 application stays live meanwhile |
| B9 | Interior prices, terms and message copy in seeders | Nothing usable for Furniture | Owner supplies price list, terms, warranty and delivery wording |
| B10 | Hostinger plan unknown | PHP 8.4, MySQL version, cron, SSH unverified | Section 12 checklist before staging |
| B11 | Existing CI runs on every push to this branch | About 1,300 new files must not break gates | `secret-scan` simulated clean; Interior docs kept out of `docs/` |
| B12 | Workspace snapshot limits | Runtime is not persisted across sandbox resets | Scripted rebuild |
| B13 | Tests assume Interior content in places | Some of the 200 test files will fail after pruning | Triage in Phase 1b; a skipped test is reported as skipped |
| B14 | No authenticated API in the source | `/api/v1` needs a design | Propose Sanctum in Phase 2 |
| B15 | Public surface of switched-off modules | Webhooks, OAuth, MCP, installer | Unregister routes, verify with a route list |

---

## 12. Hostinger staging and the production gate

Before any connection, the owner confirms the plan details: PHP version for web and command line, extensions, MySQL or MariaDB version, Composer and build route, cron, document root, writable storage, symlinks, limits, SSL, outbound access, backups and restore.
Assume no persistent Node, Redis, Docker, WebSockets or always-running workers. Front-end assets are built before deployment.
Staging uses its own database, credentials and storage, separate from both production systems. The exact repository, release ref and destination are named before connecting.

Production is out of scope for this task: no deployment, no DNS change, no live database access, no bulk migration.
It requires accepted staging tests, a performed backup restore, reconciliation, a rollback plan and separate written approval.

---

## 13. Owner decisions needed

| # | Decision | Recommendation |
|---|---|---|
| D-1 | Approve the Phase 1a preview scope in section 9 | Approve |
| D-2 | Accept SQLite for the visual preview, with MySQL proven in CI | Accept; there is no MySQL route in this sandbox |
| D-3 | How the 2030-only modules reach the Laravel admin | Rebuild natively, one module at a time, catalogue first |
| D-4 | Storefront path | Keep the React storefront; move pages to Blade gradually |
| D-5 | UTC storage versus the source's local-time storage | UTC, decided before Phase 1b, with a date-boundary audit |
| D-6 | The target repository is public | Confirm that is acceptable, or make it private before the copy |
| D-7 | Product name and legal business name for documents | "WOODEX OS" for the admin; legal name supplied by the owner |
| D-8 | Furniture price list, payment terms, warranty and delivery wording | Owner supplies; nothing is invented |
| D-9 | Whether live 2030 records (beyond the seeded catalogue) must be migrated | Catalogue yes; other data only if the owner confirms real records exist |
| D-10 | Hostinger plan details | Needed before staging |

---

## 14. Untested assumptions

- That the static PHP binary runs this application, and that Composer can be bootstrapped from source here.
- That the 72 retained migrations run cleanly on SQLite and on MySQL once the 11 content migrations are removed.
- That the application boots without the Interior website bundle and public pages.
- That the admin works without the reference seeders that carry Interior copy.
- The source project's test and audit claims.
- Everything about Hostinger.
