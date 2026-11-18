# WOODEX OS — release report, 18 November 2026, third merge

The report of pull request #44, in the ten points of document 10 §9, written **before** the merge (document 16
§8; the lesson of defect D-014).

**On whose word.** Two messages of the owner on 18 November. About 05:50 UTC: "start", which is round 1 of the
pending work plan (document 21). About 10:44 UTC, under a picture of Feature status on the hosted workspace:
"analayize compalte and start to complate missing and reuse compalte … and fix this porducation deplyment",
followed by that site's notice word for word. How both were read is DL-035. The merge stands on his answer that
the agent merges into `main` when every check is green (DL-026).

**What this round is.** Three lines of the master list, built and proved:

| Line | What a person can do now |
|---|---|
| T-086 | Choose a quotation line from the catalogue. The line keeps the product's number, code, name and price as they were, on the quotation, on its sent version and on the invoice made from it |
| T-043 | Leave nothing typed behind without being asked. A form that was typed in and not saved asks before a link, a shortcut, the Back button, a reload or a click beside its window takes it away |
| T-056 | Set their own preferences (appearance, single-key shortcuts, the screen after sign-in); see how far each member of the team is on the way in; read on Feature status what changed. A first sign-in is led to a welcome |

**What it is not.** No module was switched on. Nothing on Hostinger, in Supabase or in the settings of either
Vercel project was changed. The hosted workspace still keeps nothing that is typed: see "The deployment" below.

## The owner's picture: where the 68 destinations stand, and what completes each

The picture showed the hosted workspace on `main` before this merge: 30 available, 13 in the old dashboard, 20
built and switched off, 5 not built. After this merge it shows **32, 13, 20 and 3**. (The product as it ships
has the client portal off: 31, 13, 21 and 3.)

| Stands | Which | What completes it | Who | Lines |
|---|---|---|---|---|
| **Not built: 5 → 3** | Workspace preferences, Team onboarding | **Built in this round** | — | T-056 |
| | Site overview, Navigation, Sections & templates | Part of the Website Studio that is rebuilt against the shop. A prototype can be built now; accepting it is gate C | Agent, then owner | T-115, T-116 |
| **Built, switched off: 20** | Estimations | Furniture's own rates. The screen costs a job from a rate book in three finishes; the book in the code is the Interior studio's and is not loaded. With rates it becomes "an estimate turns into a quotation with the same totals" | Owner gives the price list, then agent | T-003, T-085 |
| | Conversations, Chat Bot, WhatsApp Templates | A WhatsApp (and, for staff alerts, Telegram) account for Furniture, and Furniture's prepared replies | Owner gives the account, then agent | T-014, T-118 |
| | Automations, Sequences | The inbox first: what a sequence sends goes out through it. Then a dry run and approvals | Agent, after the inbox | T-119 |
| | Marketing | A Facebook Page connection | Owner, then agent | T-120 |
| | All pages, Page builder, Blog, Portfolio, Forms, Theme, SEO, SEO agent, Speed, Site health, Website Settings, Redirects, Find & replace | **These thirteen cannot open as they are.** They edit a website that Laravel itself serves from its own tables; Furniture's shop is a React application and keeps its pages elsewhere (DL-003). They are rebuilt against the shop | Agent, after gate C | T-115 to T-117 |
| **In the old dashboard: 13** | Orders, Delivery & Installation, Support & Warranty, Operations board, Suppliers, Purchase Orders, Goods Receipts, Supplier Invoices, Production, Stock Ledger, Warehouses, Stock Transfers, Reservations | To be built in this admin, waves 6 to 8. The Interior admin has none of them: there is nothing to reuse but the old dashboard's own screens, which can be linked from Feature status once its address is set. **Proposed, not done:** that dashboard's owner password is the one open critical defect | Agent; the link is the owner's yes | T-106 to T-111; T-021 |

**What "reuse" comes to, said plainly.** Everything of the Interior admin that could be reused without the
owner is already in use: it is the 30 open destinations. Of the 20 that are built and closed, 13 are a different
product's website, and 7 each stand behind one thing only the owner has: a price list, a messaging account, a
Facebook Page. Switching any of them on without that would open an empty screen with the studio's wording.

## The deployment: what the notice means, and what would change it

The notice on the hosted workspace ("Nothing typed here is kept: this site has no permanent database yet") is
true, and nothing in this round changes it.

- `woodex-os.vercel.app` is where the admin can be seen. Vercel builds it at every merge with sample records in
  a file of its own, and each running copy starts from that file. It cannot reach a database (document 13 §2).
- The site that keeps what is typed is the installation on Hostinger, by the owner's decisions (DL-020,
  DL-033). It is ready on the agent's side: every merge to `main` publishes a release on the branch
  `hostinger-release`, and CI installs that same release on Apache with MariaDB before it is published (9 of 9
  steps, then a second deployment, 5 of 5). What is missing is the account's side: the ten steps of document 24
  in hPanel (a subdomain, PHP 8.4, an empty database, the branch on hPanel's Git page, the installer, one cron
  line). They take about half an hour, and no password leaves Hostinger for them (T-006).
- Making the Vercel site itself keep data is possible in principle and is **not built**: it needs a MySQL or
  MariaDB database that Vercel can reach (the shop's Supabase database is PostgreSQL, which the admin is neither
  written nor tested for), a way to create its tables and the first owner, storage for uploaded files, and a
  caller for the 30 timed jobs. Hostinger gives all four at once.

**The choice is the owner's**, and it is the first question of the reply that carries this report.

## What this merge changes on each live site

| Site | Changes at the merge |
|---|---|
| Hosted workspace `woodex-os.vercel.app` | Rebuilt from `main`. Quotations offer "Add from the catalogue". A form that was typed in asks before it is left. System shows Workspace preferences and Team onboarding; the menu under the person's name has "Workspace preferences"; Feature status shows "What is new" and counts 32, 13, 20, 3. The sample people count as already working there, so sign-in opens the dashboard as before |
| Shop `woodex-2030.vercel.app` | Rebuilt from `main`; nothing of the shop is in this pull request |
| Supabase | Nothing |
| Branch `hostinger-release` | Rewritten by CI with the release of the merge commit: two more migrations (a product on document lines; `users.preferences`). Nothing is installed from it until the owner does document 24 |
| A real installation, once there is one | The owner's first sign-in after the installer opens the welcome, then the dashboard |

## 1. Commit/branch/build identifier

Branch `arena/39c46419-woodex-2030`, from `main` at `db91415`. Pull request #44.

| Commit | What |
|---|---|
| `3d915f7` | T-086: a quotation line chosen from the catalogue |
| `e3853b1` | T-086: the "Add" button works in a browser; the journey reads the product in the PDF |
| `06ae28e` | T-086: the button says numbers only (the cure of the red CI run, D-037) |
| `8adb809` | T-043: what is typed is not lost without a question; eight notices named (D-038) |
| `81cd076` | T-043: the field that holds the cursor is left before anybody is asked |
| `1ebe72c` | T-056: Workspace preferences, Team onboarding, release notes |
| the commit that carries this report | The sample people of a demo already work there; the records of the round |

Built files: `platform/public/build` rebuilt, the admin script is `assets/admin-CuP0HN98.js`.

## 2. Environment

- Sandbox: PHP 8.5, SQLite; the admin served by Vercel's own PHP runtime behind a stand-in for Vercel's
  network, with the demo's settings; a Chromium that draws without a graphics card.
- CI: the suite on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4; the same rehearsal with the demo's settings and
  with the product's; the Hostinger release installed on Apache with MariaDB.
- No live system was written to.

## 3. Test command and result

| What | Result |
|---|---|
| Whole suite in the sandbox (`tools/preview/sandbox/run-suite.sh`), on the code as merged | **2,551 passed, 36 skipped, none failing** (15,184 assertions). The skips are the tests that need MySQL; CI runs them |
| New test files | `Feature/Sales/QuoteProductLinesTest` · `Unit/BladeComponentTagsTest` · `Feature/UnsavedChangesTest` (10) · `Unit/ToastsCanBeReadTest` · `Feature/WorkspacePreferencesTest` (13) · `Feature/TeamOnboardingTest` (3) |
| Journeys in a browser (`scripts/os-journeys.mjs`) on the rehearsal | **37 of 37 steps**, nothing seen on the way. Nine steps are new in this round |
| CI on the last pushed head | Read before the merge; every check-run green is the condition of the merge and is written in the pull request |

**What the new tests refuse.** A form or a field of the admin that is neither in a part marked as holding work
nor on a written list of exceptions (it reads all the admin's views). A Blade directive inside a component tag
(the browser gets it as written). A notice sent without names. Typed text printed into an attribute the browser
runs as script (an old test, which caught this round's first cure of the "Add" button). A start screen the
person cannot open. A release note that names a list line or a commit.

**That the journey steps bite**, tried by taking the code out and running them:

| Taken out | The journey |
|---|---|
| The watch on unsaved work is not registered | 5 of 34 steps fail |
| A save no longer takes the mark away | 10 fail, 12 are left out (no lead can be created) |
| The field is not left before the question (the build of `8adb809`) | "the question came up once while the figure was being saved, and closed by itself" |

**Ten older tests were changed, not deleted.** They pinned what T-056 changed on purpose: six sign in as people
who now have a sign-in on record (a first sign-in goes to the welcome instead, and has tests of its own); three
count the two new destinations; one names them in the System group. Three assertions of the form "not to
contain a, b, c" held as soon as any one was missing; they are asked one by one now.

## 4. Browser/device coverage

Chromium only. T-043: at 1366 px and at 390 px, with the mouse and with the keyboard alone, light and dark (the
question fits a phone at 358 × 242 px; Tab stays between its two buttons and the browser's own bar). T-056: the
preferences in two browser contexts, one never switched by hand; Team onboarding at 1366 px with its one button
inside the list's frame, which the journey measures. Looked at by eye: the question (three pictures), Workspace
preferences with the welcome, Team onboarding.

**Not covered:** Firefox and Safari. Safari and Firefox keep no record of where a move in the history started
(`window.navigation`), so the Back button is let through there, as before this round; links, shortcuts, reload
and closing the tab ask in every browser. On a phone the Team onboarding list scrolls sideways inside its card.

## 5. Defects open/closed by severity

39 on record. Closed in this round: **D-037** (the "Add" button did nothing, then printed typed text into a
script: found by the journey and by CI before any release), **D-038** (eight notices of a failure were shown in
green as "Saved": on `main`), **D-039** (a figure typed into a quotation was lost when a menu link was pressed
straight away: on `main`). Open: 1 critical (D-002, the old dashboard's owner password: T-021, the owner's), 6
medium, 4 low; none of them new.

**Found and cured inside the round, never released:** the question flashed open for a moment when a link was
pressed with the cursor in a quotation field (the journey looked only after the move; it counts every opening
now). Livewire never called a hook registered by the application's own provider, so the browser was never told
of a refused save while every test passed (found by pressing it; a listener is used). The second press of Back
was let through. Leaving the moment a save's answer arrived threw errors on the page being left.

## 6. Migration reconciliation

Two migrations, both with a way back: `2026_11_18_010000_add_catalogue_product_to_document_lines` (a product's
number, code and name on quotation and invoice lines; two foreign keys) and
`2026_11_18_020000_add_preferences_to_users_table` (one column that may be empty). CI takes the newest migration
back and applies it again on MySQL and compares tables, columns and keys: done for the first on `e3853b1`, and
for the second on the head of this pull request. No record of any live system is moved.

## 7. Backup/restore status

Nothing to back up: no live data is touched, and the hosted workspace holds none. On a real installation both
migrations only add columns. Backups on Hostinger are T-187, the owner's.

## 8. Performance observations

- Leaving a screen with nothing unsaved costs nothing: the watch is asked and answers at once. With a save on
  its way, leaving waits for its answer, at most five seconds of the page's own time.
- The journey waited six seconds at one step. Read in the browser's own trace: the sandbox's Chromium stands
  still for four to seven seconds while it puts away the page that was replaced a second before, because it
  draws without a graphics card. It is not the admin's time, and the step no longer prints it.
- Team onboarding reads every active member with their roles and the table of password links in two queries.
- One sign-in on the rehearsal was refused once without an explanation found, about twenty seconds after a
  journey had ended; five in a row passed a minute later, and the sixth was refused as the rule says (five a
  minute for one address and one account). The journey signs in as the owner four times in two and a half
  minutes. Watched, not explained.

## 9. Screenshots or recording of key flows

Not kept in the repository. The journey's notes are the record: each step prints what it saw (the figure typed,
the code and the price of the product in the PDF, the size of the question on a phone, where sign-in opened, how
many members are through). CI keeps the journey's output of every run as an artifact.

## 10. Explicit owner approval status

- Merging when every check is green: approved (DL-026).
- T-086, T-043, T-056 as built: **not yet seen by the owner**. Each is on the hosted workspace after the merge.
- Decided by the agent in T-056 and the owner's to overrule (DL-035): preferences are each person's own and
  open to everybody; a first sign-in is led to the welcome even over a link that was followed; the owner after
  an installation too; the sample people of a demo count as already working there.
- **Asked of the owner in the reply:** where the system that keeps data is to run (the ten steps of document
  24, or a database of his for the Vercel site); whether Feature status may link to the old dashboard; and the
  three things the closed modules stand behind (price list, messaging account, Facebook Page).
- No module is accepted and no gate is passed; that is unchanged.

## What is not proved

- Anything on the Hostinger account, as before (document 24).
- The question before unsaved work in Firefox and Safari; the Back button there is let through.
- Not noticed by the watch, by design and said on the screen's own notes: a row added, moved or removed by a
  button while nothing was typed; two parts of one screen that share a name share one mark (the image cards of
  the media library: saving one clears all).
- Seen by hand only, not in the journey: a tab of Settings keeps its mark while another tab is open; a rate
  that is not a number is refused and the question then asks.
- The welcome of a first sign-in is pressed in a browser only by the installer's journey in CI (the job
  `hostinger`); in the sandbox it was seen once by hand and is covered by tests.
- PHPStan was not run on this round's code, and CI does not run it.
