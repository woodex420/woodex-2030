# The Interior admin: what of it is in use, what is switched off, and the plan to finish the upgrade

**Written 18 November 2026** · decision DL-034 · master list T-027, T-093, T-174 and the lines named below ·
release report 26

You attached the Interior studio's admin and wrote: analyse it completely, make the plan to reuse it for the
upgrade of this admin, do not use its theme, and begin with a list of what is still held, pending or missing.
This page does that, in that order.

## The short version

1. **The attachment is the archive this admin was built from on 11 November.** Same file, same checksum. Checked
   again today, file by file: every one of the 2,059 files of its admin is accounted for here. 1,475 were taken,
   573 were left out because they are the studio's website, photographs and typeface, and 11 were set aside.
   The studio's repository has not changed since. **So the archive holds no code that is still to be brought
   in.**
2. **The reuse that is left is switching on, not copying.** Of the 68 destinations of this admin, 29 are open
   and 23 of those came from the Interior admin. Another **21 are in the code and switched off**: estimations,
   the client's pages, the inbox with WhatsApp, automations, marketing and thirteen website tools. §5 says for
   each what is still the studio's in it and what must be true before it opens. §9 is the order.
3. **Its theme is not used, and will not be.** The studio's website look, its pages, photographs, logos and
   typeface were never copied. The admin's own look is the one you chose on 17 November (DL-017). The studio's
   theme screen, its colour set and its page templates are in the switched-off website part and stay there. A
   switched-off screen is brought to this admin's look and Furniture's wording before it opens (§9).
4. **Three things were found that the list did not have**, each now a line: the ledger was never tried with two
   people writing at once (T-093); the code points about 950 times at documents that are not in this repository
   (T-174); three switches have no decision of yours (T-027).
5. **What is still open** is §1: 119 of 152 lines. 78 of them need an answer of yours first. 32 need nobody: 21
   stand in eleven rounds and 11 follow them. 9 wait for a wave, a date or an event.

## 1. What is still held, pending or missing

Counted from the master list ([`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md)) by its check on 18 November:
**152 lines: 33 done, 119 not done.** Every line, with its plan and its tests, is in
[`21-PENDING-WORK-PLAN-AND-TESTS.md`](21-PENDING-WORK-PLAN-AND-TESTS.md). This is the summary.

### 1.1 Held: waiting for you (29 lines, and 49 more that stand behind them)

| Your answer | Lines behind it | What it holds up |
|---|---|---|
| **T-006** Install the trial site on Hostinger: ten steps, about half an hour ([document 24](24-HOSTINGER-STEP-BY-STEP.md)) | 20 | Everything on the production host: staging, mail, the timed jobs, uploads, backups and a proven restore, the domain, and through them real data and the cutover |
| **T-003** Furniture's price list, terms, quotation wording and who signs | 8 | Documents issued in Furniture's name, quotation templates, estimations |
| **T-004** Who may approve what, and each role's discount limit | 4 | The role matrix, approvals as one queue |
| **T-008** Currency (PKR) and the tax rules | 3 | Tax on quotations and invoices, credit notes |
| **T-011** Product options and the stock rule: from stock or made to order | 3 | Orders, stock, production |
| **T-021** Change the old dashboard's password; make the repository private | — | The one critical defect (D-002). Gate E cannot pass while it is open |
| **T-171** Read the eight module sheets: approve or return each | — | No module is accepted without your sign-off. None is today |
| **T-181** Written approval before any real record moves | — | The data move and production |
| T-009 a mailbox for sending mail · T-007 the addresses · T-090, T-091 the catalogue reviewed and loaded into the shop · T-005, T-026 a look at the demo and at the shop | | Mail, the domain, real products in the shop, gate A |
| T-010, T-012 to T-018, T-022 to T-025, T-027, T-151, T-187 | | Rules for parts not built yet, smaller choices, a secret for a signed-in check, backups of the shop's database |

### 1.2 Pending: can start now, needing nobody (21 lines, in this order)

| Round | Lines | What |
|---|---|---|
| 1 | T-043, T-086 | A warning before leaving an unsaved form; a quotation line chosen from the catalogue keeps its product and price |
| 2 | T-093 | The ledger with two people writing at once (new, §8) |
| 3 | T-063 | The rules of `/api/v1`, which the shop will read |
| 4 | T-041 | One table for every list |
| 5 | T-172, T-175 | The shop's database: its functions, and every table mapped to its new owner |
| 6 | T-071, T-152, T-153, T-154, T-142, T-189, T-174 | Packages, the demo's housekeeping, the documents the code points at (new, §6) |
| 7 | T-044 | The dashboard |
| 8 | T-054, T-046 | Keyboard and screen-reader tests; help, pinned and recent screens |
| 9 | T-052, T-049 | Lighter pages; no text below 12 px |
| 10 | T-072 | The content policy without `eval` |
| 11 | T-045 | The screen pass, area by area |

Eleven more lines wait only for one of these.

### 1.3 Missing: not built, or built and not in use

| What | Where it stands | Lines |
|---|---|---|
| **13 destinations exist only in the old dashboard**: orders, delivery and installation, support and warranty, the operations board, suppliers, purchase orders, goods receipts, supplier invoices, production, the stock ledger, warehouses, stock transfers, reservations | To be built in this admin. The Interior admin has none of them: there is nothing to reuse | T-106 to T-111 |
| **5 destinations are built nowhere**: site overview, navigation, sections and templates, workspace preferences, team onboarding | To be built. **Since 18 November, later the same day: 3.** Workspace preferences and Team onboarding are built (T-056, report 27) | T-116, T-056 |
| **21 destinations are in the code and switched off** | §5 and §9 of this page | T-085, T-112, T-118, T-119, T-120, T-115 to T-117 |
| The shop reading its products from this admin, and its forms writing to it | The rules of the API come first | T-063, T-100, T-105 |
| Moving the old system's records, and the cutover | The old database holds almost none (1 lead, 1 client, 148 sample products) | T-175 to T-181, T-121 |
| The admin on Hostinger with real data | A release is ready and proved away from the host; nothing is on the account | T-006, T-130 to T-141 |

### 1.4 Not proved

- **No release gate is passed** (document 16): A design, built and not approved · B modules, none accepted ·
  C page builder, not started · D Hostinger, not started · E cutover, blocked by the critical defect.
- **Defects not closed:** 1 critical (D-002, yours: T-021), 6 medium, 4 low. 25 are closed.
- **Nothing on the Hostinger account**: its web server with the folder's rules, its PHP and database versions,
  the timed-jobs line, hPanel's Git page with a branch of 10,742 files, mail, a backup and a restore
  ([document 24 §2](24-HOSTINGER-STEP-BY-STEP.md)).
- **Lost on 18 November and to be written again:** the code of round 1. See §10.

## 2. The attachment, and how it was checked

| | |
|---|---|
| File | `WOODEX_OS_FULL_SOURCE_MASTER_v2_603428c.zip`, 44,331,669 bytes, read from your Google Drive through the connection you switched on |
| Checksum | sha256 `5e8c271b5460fc5bb5a25f3aa478ac362f81f357b43cae7bb690df92752ee25c`: the value in the file's own description, and the value [`platform/PROVENANCE.md`](../../platform/PROVENANCE.md) recorded on 11 November |
| What it is | The source of the Interior studio's system, repository `woodex420/woodexinterior`, branch `master`, commit `603428c`. 2,329 source files and three notes by whoever packed it |
| Is there anything newer? | No. Read from GitHub on 18 November: the studio's `master` still stands at `603428c` (3 November). One older pull request is open there (#5, 29 October, a website version); it is not part of the archive and was not read |
| The check | Each of the 2,059 files under the archive's `platform/` was hashed and compared with the row that [`platform/PROVENANCE/import-manifest.tsv`](../../platform/PROVENANCE/import-manifest.tsv) holds for it. **2,059 of 2,059 agree.** None is new, none is missing, none differs |
| Where it was read | In the sandbox, outside the repository's folder. Nothing was run from it: it was read, counted and compared. No file of it was added to this repository; the description on Drive says "keep private" and this repository is public |

## 3. What the archive holds, in numbers

| Part | Files | What |
|---|---|---|
| `platform/` | 2,059 | The Laravel application: the admin, the studio's public website, the API, migrations, tests |
| `docs/` | 170 | 35 documents (analysis, plan, requirements, decisions, database, design, Hostinger, pending lists) and 135 audit reports and CI notes |
| `tools/` | 92 | A development sandbox of 58 files, 31 of them browser checks; release and backup aids; a Docker set for tests; an AI bridge; the studio's Composer hook for Hostinger |
| Root | 8 | The studio's `.htaccess`, `composer.json` and lock, its two status files, a workflow |
| Not in it | 157 | Withheld by whoever packed it: old screenshots, PDFs and a lead-import report that may show customers or money. Not seen |

The application itself, counted in the source by the same script on both sides. The second column is this
admin at `115e29b`.

| | Interior admin | This admin today |
|---|---|---|
| Parts of the business (`app/Domain`) | 11: Accounts, Assistant, Automation, Bot, Conversations, Crm, Marketing, Projects, Sales, System, Website | 13: the same, with Commerce and Media |
| Files in them | 417 | 444 |
| Records (models) · actions | 82 · 119 | 88 · 126 |
| Screen classes (files under `app/Livewire`) · views | 121 · 209, of which 50 for PDFs | 131 · 234 |
| Database changes (migration files) · tables they make | 83 · 99 | 84 · 118 (CI counts 125 tables in a fresh database, with those of the settings and the packages) |
| Addresses (route declarations) | 157 | 188 |
| Commands · timed entries | 37 · 30 | 40 · 30 |
| Connections to other services | 8 groups, 45 files: WhatsApp, Telegram, Google, connectors, outgoing webhooks, MCP, Turnstile | The same 45 |
| Rights named in the roles' seed file | 45 | 48 |
| Test files · test cases written | 197 · 1,633 | 245 · 2,002 (2,509 pass in CI, where one case runs with several sets of data) |
| Public website | 142 pages, 637 public files | None of it |

## 4. What of it is in this admin today

**By file.** `platform/` holds 1,669 files at `115e29b`:

| | Files | |
|---|---|---|
| Taken from the archive and unchanged since | 1,250 | 85 of every 100 files taken |
| Taken and changed here | 217 | Furniture only, the company's name in messages and on the client's pages, times in UTC, the content policy, request numbers, the security tests, the installer and the release for Hostinger, the admin's own look |
| Taken and removed since | 8 | Two built files replaced by newer builds, the studio's holding page, five tests of the studio's website |
| Set aside | 11 | Migrations that load, publish or edit the studio's website content. Kept for reference in `database/quarantine/`, never run |
| Added here | 191 | The catalogue and its 170 products, categories, series, shop customization, the central media library, the release tooling, the rehearsals, 49 test files |

**By destination.** Asked from the application itself, with the switches as a Furniture installation has them:

| State | Destinations | Which |
|---|---|---|
| **Open** | 29 | 23 from the Interior admin: Dashboard, Approvals, Reports · Leads, Contacts, Tasks & Visits · Quotations, Invoices, Payments, Documents · Projects, Board, Vendors & costs, Handovers · Ledger & reports, Payables, Income & expenses · Media · Settings, Access & team, Connections, Platform, Audit & activity. 6 built here: E-commerce, Products, Categories, Series, Shop customization, Feature status |
| **In the code, switched off** | 21 | All from the Interior admin: §5 |
| **Only in the old dashboard** | 13 | §1.3 |
| **Built nowhere** | 5 | §1.3 |

The open 23 can be used on the demo, and none is accepted yet: the eight module sheets wait for you
(T-171), and Furniture's own terms, signers and wording are not in (T-003, T-033, T-084).

## 5. Module by module: what is reused, and what it needs

**The open parts** (always on).

| Part | From the Interior admin | Still to do before it is Furniture's | Lines |
|---|---|---|---|
| Workspace | Dashboard, approvals, reports | The dashboard as widgets; approvals as one queue; report definitions | T-044, T-081, T-082 |
| Customers | Leads with pipeline and import, contacts, tasks and visits | Furniture's wording in lists and fields; several numbers per contact | T-033, T-083, T-101 |
| Sales | Quotations with versions, approval and PDF, invoices, payments, document designs | **An installation starts with no quotation template and no price book: the studio's are loaded only in tests, and nothing was invented in their place.** Furniture's terms and signers; a line from the catalogue; tax | T-003, T-084, T-086, T-087 |
| Operations | Projects, board, vendors and costs, handovers | Made for the studio's fit-out jobs. Whether delivery and installation are jobs of their own is yours to say | T-010, T-107, T-112 |
| Accounts | Ledger, payables, income and expenses, periods, journals | Who reviews the accounting; **two writers at once, never tried** | T-013, T-093, T-113 |
| System | Settings, access and team, connections, platform, audit, the installer | One role matrix; business settings in one screen; sessions | T-060, T-064, T-065, T-073 |

**The switched-off parts.** Each is closed at the door: its addresses answer "not found", its screens are out of
the menu and the search, its timed commands do not run.

| Switch | Screens | What it does | What is still the studio's | Needs first | Lines |
|---|---|---|---|---|---|
| `estimator` | Estimations | Costs a job from a rate book | The rate book and the decor list are the studio's prices. Not loaded outside tests | Furniture's rates | T-003, then T-085 |
| `client_portal` | Client portal | The client's own pages: accept a quotation, report a payment, follow a project | Already carries Furniture's name (T-032). On in the demo only, to try it (DL-026) | Your yes or no | T-015, then T-112 |
| `inbox` | Conversations, Chat Bot | One inbox for website chat, WhatsApp and Telegram; prepared replies; a bot that answers from a list of knowledge | The prepared replies and the bot's knowledge are the studio's. Not loaded outside tests | A provider and an account; Furniture's replies | T-014, then T-118 |
| `whatsapp` | WhatsApp Templates | WhatsApp Cloud messages in and out, templates, delivery checks | — | The WhatsApp account | T-014 |
| `telegram` | — | Alerts and a daily summary to staff on Telegram | — | The bot and who is paired with it | T-014 |
| `automation` | Automations, Sequences | Message sequences after an event; the on-and-off switch of every timed job | The sequences' messages are the studio's. Not loaded outside tests | The inbox first; a dry run and approvals | T-119 |
| `marketing` | Marketing | A planner for social posts, a helper that drafts them, a publisher | — | A Facebook Page connection | T-120 |
| `website` | All pages, Page builder, Blog, Portfolio, Forms, **Theme**, SEO, SEO agent, Speed, Site health, Website Settings, Redirects, Find & replace | Runs a website that Laravel itself serves | **Everything.** These thirteen work on pages kept in the admin's tables and on the studio's HTML files. The Furniture shop is a React application (DL-003) and keeps its pages elsewhere. None can open as it is. The theme screen, its colour set and its page templates are the theme you ruled out | Gate C decides what is rebuilt against the shop | T-115, then T-116, T-117 |
| `google` | — | Staff sign in with Google; a Google Workspace connection | — | Your decision | T-027 |
| `mcp_bridge` | — | An address through which an AI assistant can read the admin | — | Your decision | T-027 |
| `legacy_bridge` | — | `forms.php` and `chat.php` of the studio's old website | All of it. Proposed: removed at cutover | Your decision | T-027 |

Two switches are Furniture's own and not from the archive: `lead_api` (the shop's enquiry address, on) and
`quote_requests` (basket requests, off until T-100).

One thing to know about `automation`: of the 30 timed entries, 22 run in the open admin and 8 start with a
switch. The screen on which a person pauses any single one is the Automations screen, which is off. Until
T-119 the defaults stand; what could reach a client goes through WhatsApp, which is off (read in the code, to
be seen on the trial site under T-134).

## 6. The rest of the archive: documents and tools

Not one of the 170 documents is in this repository, and of the 92 tools one. What is worth taking:

| In the archive | Use here | Line |
|---|---|---|
| `docs/07-DECISIONS.md`, `docs/02-MASTER-PRD.md`, `docs/06-DEPLOYMENT-HOSTINGER.md` | **The code points at them and a reader here finds nothing**: 37 decisions by number (108 mentions), 200 mentions of a numbered document, 642 mentions of 120 requirement codes. The archive resolves 119 of the 120 codes and 31 of the 37 decisions. To be copied as reference, marked as the studio's, with a check | **T-174** (new) |
| `tools/sandbox/`: 31 browser checks of single flows (furniture documents, a quotation's revision with a check of its PDF, the payment ledger, estimations, messages, connections, the installer) | Read against this repository's two journeys when the remaining flows get theirs: what they press that ours do not becomes a step | T-165 |
| `tools/release/PRIVATE_BACKUP_RESTORE_CHECKLIST.md` | The studio's list for a backup and a restore in isolation. Input for the backup line and the runbook | T-137, T-185 |
| `tools/release/preflight.mjs`, `validate-locks.php` | Compared with what `build-release.sh` already refuses, when GitHub deploys by itself | T-132 |
| `docs/04-DATABASE-SCHEMA.md`, `docs/18` and `docs/19` (sales, accounts, documents, estimations) | Reference for the module sheets and for T-085 | T-171, T-085 |
| `docs/audit/` (130 files), the two status files | The studio's history of its own work. §8 takes from them what concerns this admin | — |
| `tools/docker/` | Not needed: CI runs the suite on three database servers since T-143 | — |
| `tools/mcp-bridge/` | Only with `mcp_bridge` | T-027 |

## 7. What is not reused

| What | Files | Why |
|---|---|---|
| The studio's photographs | 277 | Interior content |
| Its public pages and their media | 159 | Interior content: 142 pages |
| Its website bundle, old address map and import data | 108 | Interior content |
| **Its public look**: stylesheets, scripts, price data, client list | 15 | **The theme, and Interior content** |
| **Its typeface** (Nohemi) | 6 | **The theme.** Its licence was never confirmed |
| Its logos, root files and 404 page | 5 | Interior identity |
| Its project notes | 3 | The studio's own |
| Migrations that load or edit its website | 11 | Set aside, never run |
| Its price book, quotation templates, prepared replies, message sequences, **theme colours and page templates** | 6 sets | In the code for the tests that came with it; an installation loads none of them |
| Its design document and website plans (`docs/05`, `09` to `13`, `30`) | 7 | **The theme**, and a website Furniture does not have |
| Its Composer hook for Hostinger (`tools/deploy/`) | 1 | Replaced: a release reaches the host through the branch `hostinger-release` (DL-033) |

**The admin's own look** is DL-017 ("Woodex Reimagined": Inter, the WOODEX green, a light sidebar, white cards).
The sign-in page and the shared frame carry it since 17 November; the studio's dark glass sign-in is gone. The
screens area by area are T-045.

## 8. Open points the studio itself listed, and where they stand here

The archive holds the studio's own list of open work (`ox-pending.md`, 36 entries, 3 November). The code was
copied as it stood, so what was open there about the product was open here on the day of the copy. The entries
about the studio's own preview, workbooks and archives do not concern this admin.

| The studio's entry | What it says | Here | Line |
|---|---|---|---|
| F03, F04, V01, V02 | Posting, reversal and payments hardened; "real MySQL concurrent writer checks pending" | The suite passes on MySQL and MariaDB, one request at a time. No test with two writers | **T-093** (new) |
| Q03 | Whole actions in a browser and the frozen PDFs: partly accepted | Two journeys of 28 steps press a working day and a sale and read the PDF, in CI on every change. The other flows are not pressed | T-165 |
| Q01 | Every new migration tested fresh and as an upgrade | A fresh database on three servers; a release installed and then deployed again with a new migration, in CI | Done: T-143, T-144 |
| R01, A05 | No staging rehearsal; backup and restore only a checklist | A release rehearsed on Apache and MariaDB away from the host. No staging, no backup, no restore | T-130, T-137 |
| I01 | Connections: real actions on every card; signed outgoing calls | Signed calls for a new lead, a sent quotation and a paid invoice have 14 tests here. "Connect" that leads to another site is read as faulty and not tried | T-073, T-080 |
| C01 to C03 | The inbox: a draft is lost on reload; never show "delivered" unless the provider said so | Switched off. Both are conditions of opening it | T-118 |
| T01, T02 | Telegram answers the owner only; a real delivery never seen | Switched off | T-014 |
| S01, S02 | The assistant answers from the admin's own records; no AI provider is connected | As there: no provider, nothing leaves the admin | — |
| V03 | The project's money and delivery summary: delivery and budget not accepted | Open, and part of the Operations sheet | T-171, T-112 |
| W01 to W04, B01 | The website tools, the studio's theme and brand mark | Not used | §7 |

## 9. The upgrade plan

**A switched-off part opens when five things are true.** They are the same for each.

1. **You said Furniture uses it** (the line named in §5).
2. **Its content is Furniture's.** Rates, templates, replies, sequences: yours, never the studio's, and never
   invented.
3. **It looks and reads like this admin.** Each of its screens has passed the screen pass in the look of DL-017
   and in Furniture's wording. Nothing of the studio's theme is shown.
4. **Its outside service is connected and tried without reaching a client**: the WhatsApp account, the Telegram
   bot, the Facebook Page.
5. **It is proved**: the suite, the crawl of every screen and a journey in a browser with the switch on; its
   acceptance sheet written, and signed by you.

**The order**, which is the order of the master list's waves:

| Step | What | Waits for |
|---|---|---|
| Now | The eleven rounds of §1.2. None needs you | — |
| 1 | **Estimations** (`estimator`) | T-003: Furniture's rates |
| 2 | **The client's pages** (`client_portal`): the nearest to ready, tried in the demo since 17 November | T-015: your yes |
| 3 | **The inbox with WhatsApp** (`inbox`, `whatsapp`, `telegram`) | T-014: the provider and the account |
| 4 | **Automations** (`automation`), with a dry run before anything is sent | Step 3 |
| 5 | **Marketing** (`marketing`) | Step 3 |
| 6 | **The website tools**: decided at gate C, tool by tool, against the React shop. The theme screen is not among them | T-115 |
| Beside these | The 13 destinations of §1.3, built new, wave by wave; Hostinger; the data move; the cutover | T-006, T-011, T-181 and the others of §1.1 |

Nothing in this plan copies a file from the archive into the admin. The one copy proposed is three documents,
as reference (T-174).

## 10. What this analysis did not do, and what was lost on the way

- **Nothing of the archive was run.** It was read, counted and compared. What a switched-off screen does is
  taken from its code, its tests (which run with every switch on, in CI) and the earlier documents under
  `docs/laravel-os/`; no switched-off screen was opened in a browser for this page.
- **Not read:** the 157 withheld files (not in the archive); the 130 audit reports beyond their titles and the
  studio's current list; the studio's open pull request #5.
- **Licences** are as `platform/PROVENANCE.md` left them: the typeface is out; whether any paid Preline block is
  in the views has not been verified.
- **The reading of "do not use theme"** is the agent's (DL-034): the studio's theme, on its website and in its
  admin. If you meant that this admin's own look (DL-017) should change as well, say so: that is a different
  line.
- **Lost: the first code of round 1.** After the merge of pull request #42 the work on T-086 was written and its
  twelve tests passed in the sandbox, and a first draft of T-043 was written and never run. Neither was
  committed: the session's branch was closed by the merge, the next message started a new branch from `main`,
  and the sandbox with the files was gone. Nothing of it is in the repository. Both lines stand as "can start
  now" and are written again in round 1; what was decided while writing them is kept in the plan (document 21).
