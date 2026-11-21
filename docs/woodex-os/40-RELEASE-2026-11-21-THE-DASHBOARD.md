# WOODEX OS — release report, 21 November 2026: the dashboard

The report of pull request #53, in the ten points of document 10 §9, written **before** the merge (document 16 §8;
the lesson of defect D-014).

**On whose word.** The owner's messages of 20 November, about 15:55 UTC, and of 21 November, about 04:28 UTC; the
second reads "Start next what is panding". The merge stands on his answer that the agent merges into `main` when
every check is green (DL-026). What the agent decided on the way is DL-044.

**Why this round.** The first round of the plan is still the shop's reading of its products (T-105), which is built
in pull request #48, from another conversation, and waits there for the owner. The second round needed nothing
built: three lines to be read from the live site. They were read first. This was the round after them
(document 21): the dashboard, the first screen every person sees.

**What this round is.**

| Line | What is so now | State |
|---|---|---|
| T-044 | The dashboard is thirteen widgets under one contract. Every number opens a list of exactly the records it counts. Six widgets are new, among them the sales funnel, money owed by age, payables due and the health of the projects. One widget that fails says so itself and the rest of the page is drawn | Done |
| T-152 | The built stylesheets and scripts are sent with a life of one year. Read from the live site twice | Done; defect D-013 closed |
| T-154 | The first cold start with the mark was read from the live site: 3.89 s to the first byte | **Open**: one reading of three |
| T-142 | The timer at its new minute brought one run for three times due | **Open** |

**What it is not.** No table of the database changed, and no record. No module was switched on. No file of the shop
changed. Nothing was written on Vercel, on Supabase or on Hostinger. The widgets that the owner's document 05 §7
lists for orders, production, deliveries, stock and purchase receipts are not here: those parts are not built.

## What was built

### The contract

A widget is a class in `platform/app/Domain/System/Dashboard/Widgets` and a view in
`platform/resources/views/admin/dashboard/widgets`. The class says four things, and nothing else decides them:

- **who may see it**, by the person's rights, and **which optional module it belongs to**;
- **its numbers**. Each number carries how many records it counted and the address of the list of those records;
- **whether there is nothing to count**, and the sentence it then shows;
- **everything that can fail**: every question to the database is asked in the class, before anything is drawn.

The page asks each widget in turn (`Board`). The order of its rules:

1. its module is switched off: the widget is absent;
2. the person may not see it: absent;
3. working it out fails: this widget says so, with the request number and "Try again"; the fault is written to the
   log under that number; every other widget is drawn;
4. there is nothing to count: the widget says so in a sentence, not in a row of noughts;
5. otherwise it is drawn.

"No access" is said in two places only: in a widget whose right was taken away while the page stood open, and in
one box for a person to whom none of the numbers are open. **Loading** is the layer a widget shows while it asks
again, for another period or after "Try again". The page is drawn whole in one answer, so no widget is ever sent
without its numbers.

A new widget is a class, a view and one line in the list of widgets. The test then asks of it what it asks of
every widget. The parts are described in `platform/resources/views/components/admin/README.md` §6 to §8.

### The thirteen widgets

| Widget | For | What it shows | Where its numbers lead |
|---|---|---|---|
| Business performance | The chosen period | Enquiries made; the value of quotations accepted, of invoices issued and of payments verified | Leads, Quotations, Invoices and Payments, each cut to the period |
| **Sales funnel** (new) | The chosen period | Enquiries made, quotations sent, quotations accepted; the conversion rate; the value accepted | Leads and Quotations, cut to the period |
| Live operating queues | Now | Enquiries today, follow-ups overdue, quotations awaiting approval, money due, jobs running, automations failing | The list of each |
| Needs attention | Now | Only what waits, with the five follow-ups to touch first | The list of each |
| Go-live checklist (its own widget now) | Now | The six things to set up before going live | No number and no link: each says where it is set up |
| **Money owed, by age** (new) | Now | The balance of issued invoices: not due yet, 1 to 30 days late, 31 to 60, 61 to 90, more than 90; all that is owed; what is past its due date | Invoices, cut to that age |
| **Payables due** (new) | Now | What we owe our vendors: past the due date, due within 7 days, all | Payables by age, cut to that group |
| **Project health** (new) | Now | Every active project in exactly one of four: past its target date, a milestone behind, on schedule, no target date | Projects, cut to that state |
| **Coming milestones** (new) | The next 14 days | The milestones of active projects that fall due, the nearest first | The project of each; Projects with a milestone coming |
| New leads | The last 14 days | Enquiries by day, as a chart | Leads of those 14 days |
| Pipeline | Now | Leads by stage | Leads in that stage |
| Recent activity | Now | The last things that happened, with who did them and when | No number and no link, as before |
| **Inbox** (new) | Now | Conversations unread, conversations open | The inbox. Only where Conversations is switched on; it is off on the demo |

Who sees which, as the seeded roles stand:

| Role | Widgets |
|---|---|
| Owner, administrator | All |
| Sales manager, sales | All but the go-live checklist and payables due |
| Designer and project manager | As sales, without money owed |
| Accountant | Business performance, the queues, needs attention, money owed, payables due, project health, milestones |
| Support | Business performance, the queues, needs attention, new leads, pipeline, recent activity, the inbox |
| Editor | None: one box says that none of the dashboard's numbers are open to this role |

Inside a widget a person sees only the numbers of their rights: a salesperson's "Business performance" holds the
enquiries and not the three amounts of money. A person who sees only their own leads and quotations gets their own
numbers for leads and quotations. Invoices, payments and projects are not kept by person; their numbers are the
company's for everybody who may open those lists.

### A number and its list are one question

The rule for what a number counts is written once, on the record it counts: "an invoice that is still owed", "a
lead made between two days", "a project that is behind a milestone". The dashboard asks that rule to count. The
list asks the same rule to show the rows. So a number cannot say 12 and open 11.

Six lists were given the filters this needs:

| List | New in its address | Means |
|---|---|---|
| Leads | `from`, `to` | Enquiries made in those days. `created=today` and `due=overdue` were there already |
| Quotations | `dated=sent` or `accepted`, with `from` and `to` | Sent, or accepted, in those days |
| Invoices | `dated=issued` with `from` and `to`; `age=…`; `line` | Issued in those days; owed, by how late |
| Payments | `dated=received` with `from` and `to`; `line` | Verified payments received in those days |
| Projects | `health=…`, `milestone=soon` | Active projects in that state; with a milestone in the next 14 days |
| Payables by age | `due=overdue` or `soon` | Bills past their date; bills due within 7 days. Without it the list holds all that are owed, as before |

A list that is cut in this way says so above its rows: "Filtered: Enquiries made 1 Nov – 21 Nov 2026", and "Clear"
gives the whole list back. A list that fits on one page now says how many rows it has. A day that is not a day is
refused, as the dashboard refuses a period it does not know: a list that quietly ignored it would show rows the
number never counted.

**A period is whole days on Pakistan's clock, the first and the last both counted.** "Today" ends at midnight.

### Every chart says what it shows

Under each widget stands one line: its dates, whether its amounts are in PKR or it only counts, whose records it
counts, and what is left out. Two examples, as they stand on the screen:

- Sales funnel: "1 Nov – 21 Nov 2026 · all business lines · everybody's leads and quotations · value in PKR.
  Conversion is quotations accepted ÷ quotations sent, both in the period. A quotation sent before the period and
  accepted in it counts as accepted here. Void quotations are left out."
- Money owed: "As it stands on 21 Nov 2026 · amounts in PKR. The balance of every issued or part-paid invoice, by
  days past its due date; an invoice without a due date is not due yet. Drafts, paid and void invoices are left
  out. The five ages add up to all that is owed."

### What was found on `main` on the way, and cured

1. **The Leads list read a month on the UTC clock**, five hours off. An enquiry of the last evening of October stood
   under November. Reports 38 and 39 asked whether this should be cured ahead of the plan; it had to be cured
   here, because a number of the dashboard could not open the right list without it.
2. **"Enquiries today" had no end**, on the tile and in the list. A lead dated tomorrow was counted as today's.
3. **The number beside "Approvals" in the menu, and "Approvals waiting" in the morning message on Telegram,
   counted a request whose quotation had been deleted.** The Approvals screen did not list it. All of them now
   count what the screen lists.
4. **The chart of new leads showed 1, 2, 3 … for its days, and steps of 0.2 for the leads**, since 18 November
   (`1ebe72c`). When the chart was told its colours for the dark theme, the whole axis was replaced by the colours
   alone. It is handed the whole axis now.
5. **"Overdue invoices" kept its own list of statuses** beside the one for "still owed". They said the same, and
   could have come apart with the next change. There is one now.
6. **Two tests asked for the approval of quotations that did not exist.** They passed because the count did not
   look. They make real quotations now.

### The journey step, and document 36

- **A browser follows two numbers to their lists** (`scripts/os-journeys.mjs`, step 5 of 40): the enquiries of the
  period and the overdue follow-ups. It reads the number on the dashboard, presses it, and counts the rows the list
  says it holds.
- **The crawl opens the lists behind the numbers**, because it follows every link: 335 pages as four roles, where
  there were 253.
- **Document 36 now holds the longest key of the live database.** When T-177 was written the connection was
  switched off. It was read on 21 November at 04:53 UTC, reading only: the longest product code has 31 characters,
  and the column holds 64.

## What a person sees differently

- **The dashboard has more on it**, in six groups: the period, what is live now, money, projects, enquiries, the
  record. A role sees its own widgets only.
- **Every number that counts records is a link**, and the link says how many: "Open the 12 enquiries". It opens
  twelve enquiries.
- **Above a list that was opened from a number** stands what the list is cut to, with "Clear".
- **Under each widget** stands what it counts.
- **An empty widget says so in a sentence**: "Nothing is owed to a vendor: every received bill is paid, or none
  has been entered yet."
- **If one widget cannot be worked out**, that widget says so. Before, the whole page failed.
- **A number whose list the person may not open** is shown without a link, and says why when pointed at.
- **The chart of new leads** has its days and whole numbers again.
- **The number beside "Approvals"** can be smaller than before: it no longer counts a request whose quotation is
  deleted.
- **Leads, by month**: an enquiry of a month's last evening now stands in that month.

The same is on the screen under Feature status, as the release note of 21 November.

## What this merge changes on each live site

| Site | What changes |
|---|---|
| The shop, `woodex-2030.vercel.app` | Vercel builds it again from `main`. No file of the shop changed |
| The shop's database (Supabase) | Nothing. It was read twice in this round, reading only (§7) |
| The admin's demo, `woodex-os.vercel.app` | Vercel builds it again, with the dashboard and the lists as described here. Its inbox widget is absent: Conversations is switched off there |
| Hostinger | Nothing is installed there. CI writes the release branch from `main` when the admin changes, so the release will carry the dashboard |
| GitHub | Nothing |

## 1. Commit/branch/build identifier

Branch `arena/816527f9-woodex-2030`, from `main` at `5e2aa26`. Pull request #53.

| Commit | What |
|---|---|
| `a2445c9` | T-044: the contract, the thirteen widgets, the filters of six lists, the test, the journey step, the built files; what was found on `main` and cured; DL-044; the reading of document 36 |
| `61b1f35` and the commit after it | This report and the records of the round: the master list, the plan, the defect register, documents 13, 15, 16, 21 and 25, the index of documents, five pictures. They change no code of the application: one sentence is added to the description of the shared parts |

88 files in `a2445c9`: 43 of the application (20 new), 29 views and parts of views (16 new), the admin's script,
the description of the shared parts, 3 test files, 6 built files, the journeys, 4 documents. No migration, no
route, no setting, no package.

## 2. Environment

- Sandbox: PHP 8.5 on SQLite for the suite; Vercel's own PHP 8.5 runtime and launcher for the rehearsal; Chromium
  for the journeys, the crawl and the pictures.
- CI (`os-ci.yml`): PHP 8.4 on **MySQL 8.4.13, MariaDB 10.11.19 and MariaDB 11.4.13**.
- CI (`vercel-livewire.yml`): a browser on the rehearsal of Vercel's runtime, with the demo's settings and as the
  product ships.
- CI (`ci.yml`): Node, the embedded PostgreSQL and a browser, for the shop.
- The live sites: read by GitHub's outside check on 20 November (15:10 and 22:22 UTC). The live database: one
  question on 21 November, reading only.

## 3. Test command and result

Every result below is of commit `a2445c9`.

| What | Where | Result |
|---|---|---|
| The Laravel suite | CI, each of the three servers | **2,810 pass, 36 skipped.** Before the round: 2,773 pass, 35 skipped |
| The same | Sandbox, SQLite, 21 November, 05:10 to 05:18 UTC | **2,810 pass, 36 skipped, none fails.** Before the round: 2,772 pass |
| The dashboard's own test (`tests/Feature/Overview/DashboardWidgetsTest.php`) | Part of the suite | 38 tests. On the three servers 37 run; one takes a table away, which only SQLite lets a test do, and is left out there with its reason |
| Two writers at the same moment | CI, each of the three servers | 33 steps of 50 runs hold, and the check still bites with each lock taken out |
| Both journeys | CI and sandbox, a browser on the rehearsal | **40 of 40** steps as the demo is deployed and 40 of 40 as the product ships |
| Every screen as four roles | CI and sandbox | **335 pages** at 1366 px and at 390 px: none fails, nothing is wider than the window, no console error |
| The release for a host, installed and deployed again | CI, Apache and MariaDB 10.11 | 9 of 9 and 5 of 5 steps; 60 addresses asked from outside; 10,850 files, 103 MB |
| The deployment as Vercel builds it (`check-entry.sh`) | CI; sandbox | Passes. The function is 188.6 MB in 11,257 files |
| The shop's gate | CI, `build-and-test` | Passes. The round changes no file of the shop |
| The documents' links and numbers, the secret scan, the plan, the acceptance files, what the code names, the map of the old tables | CI and sandbox | Pass |
| The code style (Pint) on the 46 changed PHP files | Sandbox | Passes |
| Vercel's own build of this branch, as a preview | Vercel, both projects | Built: "Deployment has completed" for the demo and for the shop. **The preview was not opened**: the sandbox reaches no address of Vercel |

**What the dashboard's test asks**, in the order of the plan:

1. *Each number opens a list of exactly that many rows.* The test makes a small company by hand: for every period
   one record in its first second and one in the second before, one in its last second and one in the second
   after; for every age of money one invoice on each of its two edges. It names the count it expects for 40
   numbers, then follows every number of the owner's dashboard to its list and reads how many rows the list holds.
   The same for a salesperson, whose numbers are their own; for another period; for one business line.
2. *Every widget in every state.* Ready; empty, with its sentence; error, for each widget in turn, with the rest
   drawn; no access, for a right taken away while the page stood open. The server knows these four states and
   no fifth, and every widget carries the layer for loading. The browser cannot tell the page which widgets it
   holds.
3. *As each role, only that role's widgets*: eight roles. With a module off, its widget is not in the page, and is
   not called "no access" either.
4. *The number of questions to the database, as a limit*: 52 for the owner, whose page holds every widget. No role
   asks more than the owner. Twenty more of every record that a widget lists add none.

**The first whole run of the suite found nine failures, all of this round's own, before anything was pushed.**
Eight were one word: a widget and the release note said "the studio", which the test for a Furniture-only
installation refuses on every screen. One was a part that printed a name into a script attribute, which the test
against stored scripts refuses. Both were cured, and the suite was run whole again.

**Each new check, tried against its protection taken away:**

| The check | What was taken away or changed | What happened |
|---|---|---|
| The dashboard's test | One widget's count changed to include one more state: "All that is owed" counts drafts too | Four tests fail. "money_owed.open says 10 and opens …/admin/invoices?age=open", which holds 9. The count at the edges reads 10 where it expects 9. The limit of questions is passed: 53 |
| The same | The rule itself widened: "still owed" takes drafts, for the number and for the list alike | The count at the edges fails: money due reads 10 where it expects 9. **"Opens a list of that many rows" still passes**, because the number and the list are one rule and are wrong together. That is why the test also names the counts it expects |
| The journey step | The Leads list no longer applies `due=overdue` | The step fails: "live_queues.overdue_followups says 4; the list it opens (/admin/leads?due=overdue) holds 11" |

## 4. Browser/device coverage

- **CI's browser** ran both journeys and opened every screen as four roles (owner, sales manager, sales,
  accountant) at 1366 px and at 390 px.
- **The agent looked at the dashboard** in a browser on the rehearsal, with the records of a sale in it: as the
  owner at 1366 px, at 390 px and in the dark theme, and as a salesperson at 1366 px. Twelve widgets for the owner
  and ten for the salesperson; nothing wider than the window; no console error.
- **Seen and left as it is:** beside the taller pipeline, the chart of new leads leaves a band of about 145 px
  empty under it at 1366 px. A salesperson's "Business performance" holds one number in a box made for four. Both
  are for the screen pass (T-045).
- Chromium only. No other browser, no real phone, no screen reader (T-054).

## 5. Defects open/closed by severity

44 on record. **35 closed**, one more than before: D-013 (low), the built files sent with no caching. Open are 1
critical (D-002, the old dashboard's owner password: T-021, the owner's), 7 medium and 1 low (D-033, the timer).

**Found in this round:** the six of "What was found on `main` on the way, and cured" above. All are cured in this
pull request, each held by a test.

**Still owed to the register:** those six; the time of a WhatsApp message that would be kept five hours early
(medium; cannot happen today, and not cured); and "newest first" from report 34 (cured then). The register allows
no gap in its numbers, and the next free ones (D-045 to D-052) are taken by pull request #48, which is not merged
(DL-044, point 15).

## 6. Migration reconciliation

Nothing. The admin keeps its 109 migrations and 126 tables; CI's installation on MariaDB 10.11 counts 126 tables.
No migration is in this pull request. The dashboard and the lists only read.

## 7. Backup/restore status

Nothing was written to any database, so nothing had to be backed up. That no backup of the shop's database is
known to exist (T-187, the owner's) is unchanged.

**The live database was read twice, reading only.** On 21 November at about 04:53 UTC: one question, which
returned the number of rows and the length of the longest key of seven tables, and no value (document 36). On
20 November at 15:57 UTC: one question at the start of the round, whose answer was not kept.

**The way back** is to revert the merge. No record and no table has to be put back.

## 8. Performance observations

- **The dashboard costs more than it did, because it shows more.** Measured in the test's small company, each page
  drawn a second time, in the sandbox on SQLite:

  | Role | Questions to the database | HTML | Time, median of seven |
  |---|---|---|---|
  | Owner | 36 → 47 | 258 KB → 311 KB | 127 ms → 147 ms |
  | Sales | 23 → 32 | 147 KB → 192 KB | 109 ms → 120 ms |
  | Accountant | 14 → 26 | 123 KB → 158 KB | 94 ms → 104 ms |
  | Support | 9 → 10 | 90 KB → 100 KB | 80 ms → 95 ms |

  The first drawing in a process asks five more: the roles, the rights and the settings. That is the 52 of the
  limit.
- **The number of questions does not grow with the records.** Twenty more leads, invoices, activities, milestones
  and conversations add none.
- **One widget reads rows and not a sum:** "Payables due" reads every open bill to add up its balance, as the
  payables screen does. With hundreds of open bills that is small. With many thousands it would not be.
- **The page asks again every minute while it is in front**, as before. Each asking is now the 47 questions.
- **On the rehearsal**, in the crawl, the owner's dashboard was 254 KB of HTML and its first byte came after
  184 ms, where the median of all pages was 86 ms. It is among the three slowest pages there. Lighter pages are
  T-052.
- **The function: 188.6 MB of the 250 allowed**, 0.2 MB more than before.
- **A cold start on Vercel: 3.89 s to the first byte** (20 November, 22:22 UTC, from a GitHub runner); the next
  request took 0.15 s.
- The browser job in CI took 12 minutes where it took 10 and a half: the crawl opens 82 pages more.

## 9. Screenshots or recording of key flows

Five pictures, taken on the rehearsal on the code of `a2445c9`, with the records of a sale in it, and looked at.
All are in `evidence/`:
`dashboard-2026-11-21-owner-desktop.jpg` (1366 px, the whole page: twelve widgets),
`dashboard-2026-11-21-list-behind-a-number.jpg` ("Open the 12 enquiries" pressed: the Leads list says
"Filtered: Enquiries made 1 Nov – 21 Nov 2026" and holds 12),
`dashboard-2026-11-21-owner-phone.jpg` (390 px, the top of the page),
`dashboard-2026-11-21-owner-dark-desktop.jpg` (the dark theme) and
`dashboard-2026-11-21-sales-desktop.jpg` (a salesperson: ten widgets, one number under "Business performance").

## 10. Explicit owner approval status

- Merging when every check is green: approved (DL-026).
- Starting this round: the owner's words of 20 and 21 November.
- **Every decision of this round is his to overrule** (DL-044). The ones he is most likely to want to look at:
  - **the conversion rate** is quotations accepted ÷ quotations sent, both counted in the period. It is the "win
    rate" of the sales report. It is not "of the enquiries of this period, how many became a sale";
  - **the funnel has three steps.** Document 05 §7 also names qualified leads and estimates. The pipeline has no
    stage that means "qualified", and Estimations is switched off;
  - **the ages of money owed** are 1 to 30, 31 to 60, 61 to 90 and more than 90 days; "due soon" for a bill is 7
    days; a "coming" milestone is 14 days;
  - **a salesperson sees the company's numbers** for invoices and projects, as far as that role may open those
    lists, and their own for leads and quotations;
  - **what a role sees** follows the rights the roles have today. The owner's own matrix of roles (T-004) will
    move it.
- **One question of the earlier reports is settled by this round:** the month filter of the Leads list is cured.
- **His words answered none of the others.** They stay open: pull request #48, which waits for his look; where the
  system that keeps data is to run; whether Feature status may link to the old dashboard; the price list, a
  messaging account and a Facebook Page; whether the two old previews of the shop are to be deleted; whether the
  facts on a card are the right ones (report 35); the proposals of the map (report 37) and of the words
  (report 38); whether the handful of records in the live database are trials.
- No module is accepted and no gate is passed. The dashboard's sheet waits with the other seven (T-171).

## What is not proved

- **The dashboard, seen by the owner.** The agent looked at it, and a browser measured it. Whether these are the
  numbers he wants first on the page is his to say.
- **The live demo's dashboard after the merge.** The outside check asks the sign-in page, the files and the API of
  the live site; it does not sign in. That the dashboard works as Vercel runs it is proved on the rehearsal of
  Vercel's runtime, in CI, and not on Vercel.
- **Large numbers of records.** The count of questions is held; the time with ten thousand invoices or leads was
  not measured. "Payables due" would be the first to slow.
- **A screen reader.** Each widget has a heading and each number a name; nobody has listened to the page (T-054).
- **Four of the eight roles in a browser.** The crawl signs in as the owner, a sales manager, a salesperson and an
  accountant. The other four are read by the test, which reads the page as it is sent.
- **The widget that loses a table, on MySQL and MariaDB.** That test runs on SQLite only. On the three servers a
  stand-in that fails stands for every widget in turn.
- **The morning message on Telegram.** Its count is held by a test. No Telegram account is connected, so no
  message was sent.
- **The inbox widget in a browser.** Conversations is switched off on the demo and in the product as it ships;
  the widget is drawn in the tests only.
- **Hostinger.** Nothing is installed there. The release with the dashboard was installed and deployed again on a
  server laid out like the account, in CI.
- **The two pull requests together.** Pull request #48 changes the same records (the master list, documents 16
  and 21) and already conflicts with `main`. It has to be brought up to date before it can be merged.

## What comes next

By the plan (document 21): two lines that need nothing built, only reading, two more cold starts and the timer
(T-154, T-142). Then keyboard and screen-reader tests, and help, pinned and recent screens (T-054, T-046). The
shop's reading of its products stays in pull request #48 until the owner has looked at it.
