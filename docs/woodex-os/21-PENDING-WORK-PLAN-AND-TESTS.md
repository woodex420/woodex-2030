# WOODEX OS — what is still to do: the list, the plan and the tests

**Written 17 November 2026, between 19:30 and 20:40 UTC, on `main` at `dfc8369`** (after the merge of pull request #40).

The owner asked for three things in one place: the list of what is pending, the plan for it, and the test of
each piece. This document is that. It adds no task of its own. Every line here is a row of the master list,
[`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md), under its number there; that list stays the one place where a
status is changed.

**How it stays true.** The tables and numbered blocks between the marks are written by
`node scripts/verify-plan.mjs --write` from two sources: the rows of the master list, and
[`acceptance/plan.json`](acceptance/plan.json), which holds a short title for every line that is not done and,
for the lines that can start now, the round, what is built and how it is tested. CI runs the same script as a
check and fails when the list, the plan and this document disagree (§6).

**What it does not hold:** dates. A round is the work between one "start next" and its merge. 17 November held
four of them. A large line can take more than one.

---

## The short version

<!-- generated:short — written by scripts/verify-plan.mjs --write; do not edit by hand -->
1. **116 lines of the list are not done.** 19 can start now and need nobody: they are planned in 10 rounds (§2). 29 wait for you (§3). 68 wait for an earlier line (§4), and 49 of those stand behind an answer of yours.
2. **The next round** is round 1: T-093, The ledger holds with two people writing at once.
3. **The answers of yours that the most lines stand behind:** T-006, Install the trial site on Hostinger (document 24) and send its address (20 lines) · T-003, Give Furniture's price list, terms, quotation wording and who signs (8 lines) · T-004, Say who may approve what, and each role's discount limit (4 lines) · T-008, Confirm the currency (PKR) and the tax rules (3 lines) · T-011, Decide product options, and the stock rule: from stock or made to order (3 lines).
4. **1 critical defect is open:** D-002, The owner password of the old dashboard was printed in a public document — T-021, which waits for you.
5. **Every line has its test.** A line that can start now says in §2 what is built, which tests are written, what a browser does, and what the tests are tried against. A waiting line carries the list's own "done when" (§4). §5 names the checks every round passes before it is merged.
<!-- /generated:short -->

---

## 1. The list in numbers

<!-- generated:numbers — written by scripts/verify-plan.mjs --write; do not edit by hand -->
|  |  |
|---|---|
| Lines on the list | 152 |
| Done | 36 |
| **Can start now, needing nobody** | **19**, in 10 rounds (§2) |
| **Wait for you** | **29** (§3) |
| Wait for an earlier line | 68 (§4): 49 stand behind an answer of yours · 11 stand behind lines that need nobody · 8 go with a wave, a date or an event |
| Not done, by priority | 24 P0, 55 P1, 27 P2, 10 P3 |
<!-- /generated:numbers -->

---

## 2. What can start now: the rounds, in order

Each round is one pull request: built, tested, its report written, merged when every check is green (DL-026).

The order follows three rules. Priority decides first. Within one priority, what serves a sale comes first, then
the line that the most other lines wait for. Lines that change the same files go together, so that nothing is
done twice. There are two exceptions, each explained where it stands: the small lines of the housekeeping round
are not left to the end, and the screen pass (T-045) is P1 and still last, because every round before it changes
a part it builds on. Say so if you want another order: the order is a file, and changing it is one edit.

A line leaves this section when it is done. A waiting line that a round sets free joins it: the check does not
let it stay "Later" in the list.

For each line: **Done when** is the list's own condition, word for word. **Found** is what was counted in the code
or read on the sites on the day the plan was written. **Tried against** is the proof that a test can fail: the
protection is taken away once, by hand, and the test must go red.

<!-- generated:rounds — written by scripts/verify-plan.mjs --write; do not edit by hand -->
### Round 1 — The ledger with two people writing at once

**Lines:** T-093 · **Why here:** P1 and about money. The code came from the Interior studio's system, whose own list says this was never tried on a real database server; the first place where two people work at the same moment is the trial site on Hostinger. It needs nobody, and CI has the two servers since T-143. · **When it is merged, these can start:** no waiting line.

#### T-093 · P1 · medium — The ledger holds with two people writing at once

- **Done when** (the list): In CI's database job, on MySQL 8.4 and MariaDB 10.11, two connections try the same step at the same moment, many times over: allocating one payment to one invoice, paying one bill, posting one document, reversing one entry. Each time exactly one succeeds, the other is refused or waits and then sees the first; every journal balances; no number of a document is given twice
- **Found on 18 November 2026:** Recording and verifying a payment, allocating it to an invoice, paying a supplier's bill, posting to the ledger and reversing an entry each run inside a database transaction and several lock the rows they change. Their tests call them one after another. The Interior studio's list of open work, written by the people who built this code, says of the same actions: "real MySQL concurrent writer checks pending" (document 25 §8). The suite runs on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4 since T-143, always one request at a time. SQLite, which the sandbox uses, lets one writer in at a time and cannot show the fault.
- **What is built:**
  1. A test command of its own, `platform/tools/ci/two-writers.php`, run by CI's database job against MySQL 8.4 and MariaDB 10.11: it starts two PHP processes that wait at the same gate and then call the same action on the same record, and it repeats that for each step fifty times.
  2. The steps: allocating one payment to one invoice; verifying one payment; paying one supplier's bill; posting one invoice to the ledger; reversing one journal entry; taking the next number of a document series.
  3. Where a run shows a second writer getting through, the action is repaired in the same round (a lock on the right row, or a unique key in a migration that only adds), and the run that showed it is kept as its test.
- **Tests written:**
  1. For each step and each of the fifty runs: exactly one of the two writers succeeds; the other is refused with the action's own message or, having waited, sees what the first one wrote.
  2. After every run: each journal entry balances, an invoice is never paid beyond its total, a bill is never paid twice, and no document number is given twice.
  3. The whole suite still passes on the three servers and on SQLite.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** With the lock taken out of the payment allocation in a copy, the two-writers run reports an invoice paid twice within the fifty runs; the test of the check requires that.
- **The way back:** A test command and, only if a fault is found, a lock or a migration that adds a unique key. A migration that adds a key says in its way back how the key is removed; no row is changed.

### Round 2 — The rules of the API the shop will use

**Lines:** T-063 · **Why here:** P1, and of the lines that need nobody the one the most other work stands behind: the shop's reading of the catalogue (T-105), and after it the page builder. · **When it is merged, these can start:** T-105 (The shop reads products, categories and series from `/api/v1`).

#### T-063 · P1 · medium — The rules of `/api/v1`, held by a contract test

- **Done when** (the list): The seven existing endpoints follow the rules; a contract test fails on a different shape
- **Found on 18 November 2026:** `/api/v1` has seven endpoints: the categories and their pictures, the media list and its files, an enquiry, the WhatsApp hand-off and a basket request. Each answers in its own shape. The React shop already holds a caller for each. On the live shop none of them reaches Laravel: the two switches of its forms and the address of the Laravel API are not among the settings of its Vercel project (read on 17 November). The shop's contract tests in CI hold today's shapes.
- **What is built:**
  1. The rules, written down in one document: the answer as `data` / `meta` / `error` with error codes, pages, the filters that are allowed, an idempotency key on every create, money as whole minor units, times in UTC, and how the shop or an outside client signs in. Recorded with it: staff need no JSON API.
  2. One builder for answers and one handler for errors, used by all seven endpoints, so that a refused field, a missing record, too many requests and a fault all arrive in the same shape, each with the request number of T-062.
  3. A create that is sent twice with the same key writes once and answers the same.
  4. The shop's callers move to the new shape in the same commit.
- **Tests written:**
  1. A contract test names every route under `/api/v1`: a route added without its contract fails it.
  2. For each endpoint the answer is compared key by key: success, a refused field (422), not found (404), wrong method (405), too many requests (429) and a fault (500).
  3. The same idempotency key twice gives one record and the same answer; the same key with other content is refused.
  4. Money arrives as whole numbers and times in UTC; the request number in the answer equals the one in the header.
  5. The existing tests of the seven endpoints, and the shop's contract tests of its callers, move to the new shape in the same commit; each change is listed in the round's report.
  6. After the merge the outside check asks one address of `/api/v1` on the live demo and requires the new error shape.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** With one endpoint left in its old shape, the contract test names it.
- **The way back:** On the live shop the switches are off, so no visitor's request reaches these endpoints. The round changes files of the React shop, its callers: the release report names them before the merge. The endpoints of the old website's bridge keep their shape; that module is switched off.

### Round 3 — One table for every list

**Lines:** T-041 · **Why here:** P1 and part of gate A. Three lines wait for it (T-042, T-050, T-051), and the screen pass (T-045) builds on it. · **When it is merged, these can start:** T-042 (On a phone, the key lists show cards instead of a wide table); T-050 (A reference screen showing every shared component and state); T-051 (Placeholders while a list loads; a notice when the connection is lost).

#### T-041 · P1 · large — One table component for every list, with the five states

- **Done when** (the list): One list per area uses it; a test renders each state
- **Found on 18 November 2026:** 50 screens write their own table: 14 in Sales, 10 in Accounts, 6 in CRM, 5 in System, the others in reports, projects and modules that are switched off. Commerce's lists are not tables. 29 lists turn pages: 15 through the shared page links, 14 through the framework's plain ones. Shared parts exist for buttons, cards, fields, dialogs, toasts, the empty state and the page links; none for a table. (36 more tables are in PDF and mail templates; those stay as they are.)
- **What is built:**
  1. One table component: headings that sort, a place for filters, pages, the choosing of rows with actions on all chosen, and the five states: loading, empty, error, no permission, success.
  2. One list of each area that has tables moves to it first: Leads, Quotations, Projects, Payables by age, Users, and one report. The other lists follow in the screen pass (T-045).
  3. The standards for dialogs and toasts are written beside it.
- **Tests written:**
  1. A test draws the component in each of the five states and reads its words and its roles for a screen reader: a named table, headings that say how they sort.
  2. For each list that moves: sorting, filtering, paging and an action on chosen rows give the same rows as before. The lists' existing tests pass unchanged.
  3. A test counts the screens that still write their own table. The number is written down and may only fall.
- **In a browser:**
  1. The crawl opens the moved lists as each role at 1366 and 390 px: no sideways scrolling, no console error.
  2. A journey step sorts the leads by a heading, by keyboard, and reads the first row.
- **Tried against:** With the empty state taken out of the component, the first test fails. With a hand-written table put back, the count fails.
- **The way back:** Views only. Each list moves in its own commit and can be put back alone.

### Round 4 — The shop's database: its functions and the map of its tables

**Lines:** T-172, T-175 · **Why here:** One reading of the live database serves both lines. T-175 is P1, and the two lines about moving data wait for it (T-176, T-177). · **When it is merged, these can start:** T-176 (The differences between PostgreSQL and MySQL, tested); T-177 (One rule for identifiers; old-to-new numbers kept).

#### T-172 · P2 · small — A fixed search path for the functions of the shop's database

- **Done when** (the list): No function of the public schema is without a search path; the module suites pass on PostgreSQL
- **Found on 18 November 2026:** Read from the live database on 17 November, 20:04 UTC: its public schema holds nine functions and one aggregate, and none has a fixed search path (defect D-029). Six of the functions come from the numbered migrations; three functions and the aggregate from the base schema file. None belongs to an extension. Only the database's owner can create objects there, so nothing can be put in their way today.
- **What is built:**
  1. Migration 021 sets the search path of each of the nine functions, named one by one, with its rollback file.
  2. The aggregate (`group_concat`) is expected to take no search path of its own in PostgreSQL; its two helper functions do. That is tried on the test database first, and the defect's text then says what was found.
  3. It is applied to the live database as migration 020 was: the tests first, then one transaction together with its row in the ledger, then a reading back.
- **Tests written:**
  1. On the PostgreSQL test database, after every migration no function of the public schema is without a search path, and the same test fails for a function added later without one. The rollback brings the earlier state back; applying twice changes nothing.
  2. The old system's module tests pass on PostgreSQL with 021 applied (CI).
  3. After it is applied: read back from the live database, no function is without a search path and the ledger has 21 rows; asked from outside, the shop still serves its products.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** With one function left out of the migration, the first test names it.
- **The way back:** No row changes, and the rollback file is tested: that is the safe way back your condition asks for (DL-028). No backup is known to exist (T-187).

#### T-175 · P1 · medium — Every table of the old system mapped to its Laravel owner

- **Done when** (the list): A table in the repository: source table, target, owner, wave
- **Found on 18 November 2026:** Read from the live database on 17 November, 20:04 UTC: 73 tables. 72 of them are the tables of the old system's schema files, name for name; the 73rd is the ledger that the migration tool keeps. None is missing and none is unknown. Laravel's migrations create 118 tables.
- **What is built:**
  1. One file with a row for each old table: where it goes in Laravel, or that it is not moved and why; the module that owns it; its wave.
  2. The same table as a document, written from the file.
  3. The names of the live database's tables are read again when the map is written, reading only, and compared with the files.
- **Tests written:**
  1. A check fails when a table of the old schema files is missing from the file or named twice, when a target table is not created by any Laravel migration, or when a wave is not a wave of the list.
  2. A table that the live database has and the map does not know is named by the comparison and listed in the round's report.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** With one row taken out of the file, the check names the table.
- **The way back:** A document and a check. Nothing runs against any data.

### Round 5 — Packages, the demo's housekeeping, the documents the code points at

**Lines:** T-071, T-152, T-153, T-154, T-142, T-189, T-174 · **Why here:** Small lines, P2 and P3, taken together. They stand here and not at the end because packages fall further behind with every week, and because every later merge then adds a reading of a cold start. · **When it is merged, these can start:** no waiting line.

#### T-071 · P2 · medium — Package updates, and the advisories the audit reports

- **Done when** (the list): Composer and npm report no advisory, or each one left has a written reason; the suite and both journeys pass on the new versions
- **Found on 18 November 2026:** By the audit of 17 November (defect D-028): Composer reports one low advisory (Laravel 13.39; corrected in 13.40) and npm eleven moderate ones in the shop's build tools. None fails the audit today, which stops at high. The sandbox cannot reach Composer's package index: on 17 November four packages were moved in the lock file itself, and CI proved the result on a clean machine.
- **What is built:**
  1. Laravel to 13.40 or newer, with what it then requires, and the other Composer packages to their newest corrected versions.
  2. npm: the corrections that stay inside the same major version. For `react-router`, whose correction is a new major version, either the move with the shop's own tests, or a written reason to wait.
  3. The audit gets a written list of accepted advisories, each with its reason. From then on an advisory of any severity that is not on the list fails the audit. Every Monday the audit also prints which packages have a newer version; that is read at the start of a round.
- **Tests written:**
  1. The whole Laravel suite on SQLite and on MySQL; both journeys and the crawl of every screen.
  2. The React shop's gate: build, types, its API and row-level-security tests on PostgreSQL, its browser acceptance.
  3. `composer audit` and `npm audit` report nothing that is not on the list of accepted advisories.
- **In a browser:**
  1. Both journeys and every screen on the new versions, as in every round.
- **Tried against:** With the reason of one accepted advisory taken out of the list, the audit fails and names the advisory.
- **The way back:** Putting the lock files back. The React shop is built with other versions of its tools afterwards: the release report names every package that moves, before the merge.
- **Not known yet:** How Composer's own update is run where the sandbox cannot reach the package index: in the lock file itself, as on 17 November, or by a job in GitHub that commits the lock. It is decided when tried.

#### T-152 · P3 · small — Long caching for the stylesheet and scripts

- **Done when** (the list): One year, immutable
- **Found on 18 November 2026:** The stylesheet is sent with `max-age=0, must-revalidate` (read on the live demo on 17 November, 19:21 UTC), so a browser asks for it again on every page. The names of the built files carry a mark of their content: a changed file has a new name.
- **What is built:**
  1. In `platform/vercel.json` and its copy: one year and `immutable` for the files under `/build/assets/` only. The pages and the list of built files stay as they are.
- **Tests written:**
  1. A test reads `vercel.json`: the long life is on the files with a marked name only. The two copies stay the same byte for byte (CI checks that today).
  2. The outside check, which prints the stylesheet's header today, requires one year after the merge.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** With the header taken out of `vercel.json`, the test fails. On the branch the outside check still reads `max-age=0`, because the live site is the old one; after the merge it must read one year.
- **The way back:** A file with a marked name never changes under that name, so a long life cannot serve an old file. One line to put back.

#### T-153 · P3 · small — A guard on the size of the Vercel function

- **Done when** (the list): The rehearsal prints the size and fails above 240 MB
- **Found on 18 November 2026:** By the measurement in document 13: the function Vercel runs holds 226 MB of the 250 MB allowed; 153 MB of it is Vercel's PHP runtime.
- **What is built:**
  1. The CI job that builds the demo as Vercel does prints the size of the function and fails above 240 MB.
- **Tests written:**
  1. The job itself, tried once with the limit set below today's size.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** With the limit at 200 MB the job fails and names the size.
- **The way back:** A check only.

#### T-154 · P3 · small — A cold start on Vercel, measured

- **Done when** (the list): Written into `13-VERCEL-DEPLOYMENT.md`
- **Found on 18 November 2026:** Warm, the sign-in page's first byte comes after 0.08 to 0.17 s from a GitHub runner, by the list; 0.13 s on 17 November, 19:21 UTC. A cold start has never been measured, and no answer says whether the function was started for it.
- **What is built:**
  1. The entry file marks an answer for which the function had to be started. It knows: its working folder under `/tmp` is not there yet. So a cold start is read, not guessed.
  2. The outside check times its very first request by itself and prints whether it carried the mark.
- **Tests written:**
  1. On the rehearsal: the first answer after a start carries the mark, the second does not.
  2. Three cold readings from the live demo, with their dates, are written into `13-VERCEL-DEPLOYMENT.md`.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** With the mark taken out of the entry file, the rehearsal's test fails.
- **The way back:** One header on an answer, and a line of output.

#### T-142 · P2 · small — The outside check on a timer: started once in three times due

- **Done when** (the list): It runs every six hours from `main`; a forced failure opens an issue
- **Found on 18 November 2026:** Read again on 18 November, 04:21 UTC, and corrected: until then this said that no timed run had ever started. GitHub's list holds one timed run, 44176315991, of 17 November, 21:06 UTC: green, 41 of 41 for the admin and 14 of 14 for the shop, started 2 h 43 min after the time due (18:23 UTC). The time due before it (12:23 UTC) and the one after it (00:23 UTC on 18 November) brought no run. So the timer works, and GitHub starts it late or drops it: one run in three times due. The check after a merge has run every time. Starting the check by hand is refused to this session (HTTP 403, tried on 17 November, 19:54 UTC).
- **What is built:**
  1. GitHub's list of timed runs is read at the start of every round, and how many of the times due brought a run is written into the list.
  2. If fewer than three of the last four times due brought a run, the timer is written again on `main` at another minute, and the list is read after the next times due.
- **Tests written:**
  1. Runs started by the timer are in GitHub's list for at least three of the last four times due, and they are green.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** Done on 17 November: a forced failure opened issue #38, and the next proof closed it.
- **The way back:** Nothing changes on any site.

#### T-189 · P3 · small — Close pull request #29: its work is already in `main`

- **Done when** (the list): Closed with a comment that says where its work is; GitHub lists no open pull request but the round's own
- **Found on 18 November 2026:** Pull request #29 of 9 November (deliveries in the old dashboard) is still open. Its work entered `main` eight minutes after it was opened, as commit `e82b386`. Its migration, its rollback file and its screen are in `main` byte for byte; what it adds to its other files is in `main` too, but for 26 lines in places `main` has rewritten since. GitHub reports it as conflicting.
- **What is built:**
  1. It is closed with a comment that says where its work is. Its branch is left as it is.
- **Tests written:**
  1. GitHub lists no open pull request but the round's own.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** Nothing to take away: it is one action, read back from GitHub.
- **The way back:** A closed pull request can be opened again.

#### T-174 · P2 · small — The documents the code points at are in this repository

- **Done when** (the list): The three documents are in `docs/laravel-os/source/`, unchanged, each headed by a note that it describes the studio's system and where this admin differs. A check fails when the code names a decision, a document or a requirement that none of them holds
- **Found on 18 November 2026:** Counted in `platform/app`, `routes`, `config`, `resources`, `database`, `tests` and `bootstrap`: 37 decisions named by number 108 times (D-58 the most), 200 mentions of a numbered document of the Interior studio (docs/09, docs/14 and docs/06 the most), 642 mentions of 120 requirement codes in seventeen families (QUO, CRM, WAP, PRJ and others). None of these documents is in this repository. Read against the archive the owner attached on 18 November: 119 of the 120 requirement codes are in the studio's requirements document and 31 of the 37 decisions in its decisions document; of the other six, four are named in other documents of the studio, two in none (D-111, D-112). The documents are also in the studio's public repository `woodex420/woodexinterior` at `603428c`, the commit this admin was copied from.
- **What is built:**
  1. Three documents copied unchanged from that commit into `docs/laravel-os/source/`: the decisions (`07-DECISIONS.md`), the requirements (`02-MASTER-PRD.md`) and the Hostinger guide (`06-DEPLOYMENT-HOSTINGER.md`). Each gets a first paragraph that says whose system it describes, the commit it was taken from and its checksum, and that this admin's own decisions are document 12.
  2. Before the copy each is read for a name, a number, an address or a password that should not be repeated here; the secret scan runs over them like over every file.
  3. A check, `scripts/verify-code-references.mjs`, lists what the code names and fails when a decision number, a numbered document or a requirement code is in none of the three and not in this repository's own documents. What it cannot resolve is listed once, by hand, with the reason.
- **Tests written:**
  1. The check passes on the repository as it is after the copy, and names every mention it had to leave unresolved.
  2. The check's own test changes one thing at a time in a copy: a decision number that does not exist, a requirement code of an unknown family, a document removed. Each time the check must fail and name the file and the line.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** With `07-DECISIONS.md` taken out of a copy, the check fails and names D-58 with the first file that mentions it.
- **The way back:** Three documents and one script; putting the commit back removes them. No code changes.

### Round 6 — The dashboard

**Lines:** T-044 · **Why here:** P1. The first screen every person sees. In the order you set for the design work it comes before the areas (DL-008). · **When it is merged, these can start:** no waiting line.

#### T-044 · P1 · large — The dashboard as a widget framework, with the missing widgets

- **Done when** (the list): Each figure's link opens the same number of rows; a widget whose module is not here is absent
- **Found on 18 November 2026:** The dashboard is one component with one view: figures for a period, six live queues, what needs attention, the pipeline, new leads, recent activity.
- **What is built:**
  1. Each block becomes a widget under one contract: who may see it and with which module, its figure, its link, and its states: loading, empty, error, no access.
  2. The missing widgets: the sales funnel with its conversion rate; overdue amounts by age and payables due; the health and milestones of projects. Every chart states its dates, its currency and its definition.
  3. A widget whose module is switched off is absent, not empty.
- **Tests written:**
  1. For every widget with a figure: its link opens a list of exactly that many rows. The records are made in the test, some of them at the edges of the period in Pakistan time.
  2. Every widget in every state. A widget that fails shows its own error, and the rest of the dashboard is still drawn.
  3. As each role: only the widgets that role may see. With a module off, its widget is not in the page.
  4. The number of database queries the dashboard makes is written down as a limit.
- **In a browser:**
  1. The crawl as four roles at both widths.
  2. A journey step follows two figures to their lists and compares the numbers on the screen.
- **Tried against:** With one widget's count changed to include one more state, the first test fails.
- **The way back:** Views and queries that only read. No record changes.

### Round 7 — Without a mouse; help, pinned and recent screens

**Lines:** T-054, T-046 · **Why here:** T-054 is P1, and the accessibility review of gate A waits for it. The new entries of the top bar get their keyboard tests from the first day. · **When it is merged, these can start:** T-047 (Search finds a screen by keyword and by its old name).

#### T-054 · P1 · medium — Keyboard and screen-reader tests in a browser

- **Done when** (the list): The tests run with the journeys; sign-in, a lead and a quotation done by keyboard only
- **Found on 18 November 2026:** The search opens with Ctrl+K and answers to the arrow keys and Escape. The journeys press Ctrl+K once and Escape three times. No task is done by keyboard alone: the sidebar, the focus inside a dialog and the announcing of a refused field are untested.
- **What is built:**
  1. A third journey, done by keyboard only: sign in; the sidebar, a group opened and a link followed; the search opened, a screen found, chosen, and Escape giving the focus back; a dialog and a drawer that keep the focus inside and return it; a lead and a quotation made; a refused field announced where it stands.
  2. What the journey finds is corrected in the same round.
- **Tests written:**
  1. The journey runs with the other two, in the sandbox and in CI.
  2. A test reads every dialog for its name, and every field with an error for the link to its message.
- **In a browser:**
  1. The journey itself, at 1366 px.
- **Tried against:** With the dialog no longer keeping the focus inside, the journey fails at that step.
- **The way back:** Tests, and small corrections to views.
- **Yours:** After it, one person of your team does the same without a mouse, and with a screen reader if one is at hand. Gate A asks for that review.

#### T-046 · P2 · medium — Help, pinned screens and recently opened screens in the top bar

- **Done when** (the list): Present, usable by keyboard, remembered per person, tested
- **Found on 18 November 2026:** The top bar has the search, "Create new", the colour theme, the notifications and the account. It has no help entry, and no pinned or recent screens. A person's record has no place for such preferences yet.
- **What is built:**
  1. A Help entry in the top bar: what this screen is for, the keyboard shortcuts, and the request number to give when asking for help.
  2. A screen can be pinned from its heading; pinned screens stand at the head of the menu. The screens opened last are listed, for each person.
  3. Both are kept with the person, in one small table added by a migration.
- **Tests written:**
  1. Pinned and recent screens are remembered for each person and are still there after signing out and in.
  2. Nobody is shown another person's; a screen the person may no longer open is not offered.
  3. All of it by keyboard, in the journey of T-054.
- **In a browser:**
  1. The crawl at both widths; the keyboard journey pins a screen and opens it from the menu.
- **Tried against:** With the check of the person's rights taken out, "is not offered" fails.
- **The way back:** One migration that only adds; its way back removes the table.

### Round 8 — Lighter pages; nothing smaller than 12 px

**Lines:** T-052, T-049 · **Why here:** Both change the same shared parts: the menu, the top bar, the search. · **When it is merged, these can start:** no waiting line.

#### T-052 · P2 · medium — Page weight: a near-empty page under 60 KB of HTML

- **Done when** (the list): A near-empty page is under 60 KB of HTML
- **Found on 18 November 2026:** By the crawl's figures in the list (§17): a near-empty page is 144 KB of HTML, 15.6 KB compressed. About 110 KB of it is the menu, the search's templates and the icons, sent again with every page. The median page is 161 KB.
- **What is built:**
  1. First a measurement, part by part.
  2. Then, as far as the measurement says: each icon drawn once and referred to; the search's list of screens fetched when the search is first opened; the phone's menu made of the same markup as the sidebar.
- **Tests written:**
  1. A test holds a near-empty page under 60 KB of HTML and prints the size of each part.
  2. The crawl prints the median size of all pages.
  3. The journeys that use the search, the menu and the phone's drawer pass.
- **In a browser:**
  1. Both journeys and the crawl at both widths.
- **Tried against:** The size test fails on today's page.
- **The way back:** Views only.

#### T-049 · P2 · small — No text smaller than 12 px

- **Done when** (the list): Nothing in the admin is set below 12 px, or the owner accepts named exceptions
- **Found on 18 November 2026:** 19 places in 14 views, and 7 rules of the stylesheets, set text below 12 px: from 8 px (the line under the logo) to 11 px.
- **What is built:**
  1. Each is raised to 12 px. Where a label then no longer fits, the layout gives way, not the size.
- **Tests written:**
  1. A test reads every view and every rule of the stylesheets: none sets text below 12 px.
  2. The crawl measures the text as it is drawn on every page at both widths and fails below 12 px.
- **In a browser:**
  1. The crawl, as above.
- **Tried against:** One 10 px label put back fails both.
- **The way back:** Views and the stylesheets; the bundle is rebuilt and committed.
- **Yours:** If a place cannot take 12 px, it is shown to you as a named exception to accept or refuse.

### Round 9 — The content policy without `eval`

**Lines:** T-072 · **Why here:** After the rounds that replace whole parts of the views (the table, the dashboard, the top bar, the menu), so that nothing is converted twice; and before the screen pass, which its test then holds to the strict form from the first view. · **When it is merged, these can start:** no waiting line.

#### T-072 · P2 · large — The content policy without `eval`

- **Done when** (the list): The policy has no `'unsafe-eval'`; the journeys and the crawl pass with nothing refused
- **Found on 18 November 2026:** About 200 Alpine expressions in the views: 21 `x-data`, 19 of them written out in the markup; 121 handlers of events; 30 `x-show`.
- **What is built:**
  1. Livewire's build for a strict policy is switched on, and every expression in the views is read against what it accepts.
  2. The longer ones become named components in the admin's own script; the bundle is rebuilt and committed.
  3. `'unsafe-eval'` leaves the policy.
- **Tests written:**
  1. The policy test pins the header word for word, without `'unsafe-eval'`.
  2. A test reads every view and refuses an expression the strict build cannot read.
  3. Both journeys and the crawl of every screen with the policy enforced: nothing refused.
  4. The outside check requires the header without `'unsafe-eval'` after the merge.
- **In a browser:**
  1. Both journeys, and every screen as four roles at both widths. The scripts already fail on anything the browser refuses.
- **Tried against:** With one arrow function left in a view, the crawl reports the refusal and fails.
- **The way back:** `SECURITY_CSP=report` or `off` on the site; or the strict build off and the word back in the policy, in one commit.

### Round 10 — The screen pass, area by area

**Lines:** T-045 · **Why here:** P1, and last all the same: it is the largest line, several rounds of its own, one for each area in the order you set (DL-008): E-commerce, CRM, Sales, then the others. Every round before it changes a part it builds on. · **When it is merged, these can start:** no waiting line.

#### T-045 · P1 · large — Screen pass, area by area: theme colours, page patterns, dark theme

- **Done when** (the list): Before-and-after screenshots of one screen per area at 1366 px and 390 px, light and dark
- **Found on 18 November 2026:** 3,109 uses of a grey written directly, in 145 views: the most in Sales, CRM and System. 1,807 dark-theme variants are written by hand. Colours written as numbers are mostly in the PDF and mail templates, which must carry their own; those stay as they are.
- **What is built:**
  1. In each area: greys move to the theme's roles; what is selected takes the theme's green; each page follows the patterns of document 05 §8; its lists use the table of T-041.
  2. The dark theme is looked at on every screen of the area.
- **Tests written:**
  1. A test holds the count of directly written greys for each area. It may only fall, and a finished area stays at none.
  2. Pictures before and after of one screen of each area, at 1366 and 390 px, light and dark, kept in `docs/woodex-os/evidence/`.
  3. The crawl: no sideways scrolling and no console error. The area's existing tests pass unchanged.
- **In a browser:**
  1. The crawl as four roles at both widths, and both journeys, after every area.
- **Tried against:** A grey put back into a finished area fails the count.
- **The way back:** Views and the stylesheet only; one area in one pull request.
- **Yours:** Your word on the design (T-005) can come before or after. Once the colours are roles, another tone is a change in one place.
<!-- /generated:rounds -->

---

## 3. What waits for you

These lines cannot be done by anybody else. They are in the order of their priority; within one priority, the
answer the most lines stand behind comes first.

**Can start the day you answer** names the waiting lines that need nothing else. **Stand behind it** counts every
waiting line that cannot be finished without this answer, directly or through another line; they are named in
§4. A line with nothing behind it can still matter more than its number says: T-021 holds back no line, and it
is the one critical defect.

Until you answer, the admin works by what the fourth column says.

<!-- generated:owner — written by scripts/verify-plan.mjs --write; do not edit by hand -->
| # | Pri | What is asked of you | In force until you answer | Can start the day you answer | Stand behind it |
|---|---|---|---|---|---|
| T-006 | P0 | Install the trial site on Hostinger (document 24) and send its address | Plan: "Business" (OD-3). Nothing verified on the account | T-130, T-131, T-135 (with T-009), T-140 (with T-007) | 20 lines |
| T-003 | P0 | Give Furniture's price list, terms, quotation wording and who signs | No template or rate is installed; issuing documents is blocked | T-033, T-084, T-085, T-103, T-155 | 8 lines |
| T-004 | P0 | Say who may approve what, and each role's discount limit | Quotations: by permission, with a limit per role set in Settings; the owner may approve anything | T-060, T-081 | 4 lines |
| T-008 | P0 | Confirm the currency (PKR) and the tax rules | PKR; one tax rate typed per document | T-064, T-066, T-087 | 3 lines |
| T-009 | P0 | Give a mailbox for sending mail (SMTP) on the Furniture domain | Mail is written to a log, not sent | T-135 (with T-006) | 2 lines |
| T-021 | P0 | Change the old dashboard's password; make the repository private | You will do both when the work is complete (DL-022). Until then the risk stands, and the repository is public | — | — |
| T-171 | P0 | Read the eight module sheets: approve or return each | — | — | — |
| T-181 | P0 | Approve in writing before any real record moves, and before production | — | — | — |
| T-011 | P1 | Decide product options, and the stock rule: from stock or made to order | Variants as described in the catalogue import; no stock rule | T-109 (with T-012) | 3 lines |
| T-012 | P1 | Say how many warehouses and branches there are, and where | One | T-109 (with T-011) | 3 lines |
| T-018 | P1 | Name one person and one document for warranty, delivery and returns | — | T-107 (with T-010), T-108 | 3 lines |
| T-010 | P1 | Say whether delivery and installation are two separate jobs | Two milestones of one job | T-107 (with T-018) | 2 lines |
| T-013 | P1 | Name who reviews the accounting; show the ledger screens at launch or not | A generic chart; ledger screens visible to owner and accountant | T-113 | 2 lines |
| T-005 | P1 | Approve the design on the live demo, or say what to change | Light sidebar (DL-017) | T-048 | 1 line |
| T-007 | P1 | Name the addresses for the shop, the admin and the API | `woodex-os.vercel.app` for the demo | T-140 (with T-006) | 1 line |
| T-017 | P1 | Say which existing customer and order records may be moved | None | T-101 | 1 line |
| T-022 | P1 | Say whether owner and admin must use two-factor sign-in | Optional | — | 1 line |
| T-026 | P1 | Look at the React shop as it was rebuilt, and say whether it stays | It stays as rebuilt | — | — |
| T-090 | P1 | Review the imported catalogue: points to check, prices, categories | — | — | — |
| T-091 | P1 | Load the 170 real products into the React shop; withdraw the samples | — | — | — |
| T-187 | P1 | Say whether Supabase keeps backups, or give the database address as a secret | — | — | — |
| T-014 | P2 | Choose the WhatsApp and Telegram provider and account | Disconnected; click-to-chat links only | T-118 | 2 lines |
| T-015 | P2 | Say whether the client portal is part of the product | Built, switched off | T-112 | 1 line |
| T-016 | P2 | Approve the list of redirects before cutover | Keep | — | 1 line |
| T-023 | P2 | Send the missing document `01-MASTER-PRD.md` | Documents 05 and 07 stand in | — | — |
| T-025 | P2 | The old dashboard's address: leave it, or add a link to the new admin | Left as it is. A link is a small change to the old dashboard; it would ship with the next merge into `main` | — | — |
| T-151 | P2 | Add the demo owner's password as a GitHub secret, for a signed-in check | — | — | — |
| T-024 | P3 | Send the AI-agent failure example, if you want that diagnosis | — | — | — |
| T-027 | P3 | Say whether Google sign-in, the AI bridge and the old site bridge are wanted | All three stay off. The bridge is proposed for removal at cutover (T-121): Furniture has no such website | — | — |
<!-- /generated:owner -->

How to answer: in the chat, in your own words. Each answer is recorded as a row of the decision log, the line is
marked done in the list, and the lines it sets free move to §2.

---

## 4. What waits for an earlier line

**Waits for** is what the list says in the line's status. **Done when** is the test of the line: the list's own
condition, word for word. Where the owner's table (§3 of the list) names a line as held back by an answer, that
answer is added here.

<!-- generated:waiting — written by scripts/verify-plan.mjs --write; do not edit by hand -->
#### Behind an answer of yours (49)

| # | Pri | Task | Waits for | Your answers it stands behind | Done when |
|---|---|---|---|---|---|
| T-033 | P1 | Interior lists and fields in open screens replaced by Furniture's wording | T-003 | T-003 | Replaced by Furniture's own wording (needs T-003, T-018) or hidden by the setting of T-030 |
| T-048 | P3 | The signed-in person and Settings at the foot of the sidebar | T-005 | T-005 | Present in the expanded sidebar and the phone drawer |
| T-053 | P2 | Phones with real amounts of data: no page wider than the window | T-155 | T-003 | The crawl on realistic data shows no screen wider than a phone |
| T-055 | P3 | The notification bell's request every 30 seconds, measured on the real host | T-130 | T-006 | Measured on the real host; slowed if it matters |
| T-060 | P0 | One role and permission matrix from the two systems | T-004 | T-004 | A matrix test proves each role's access on every route |
| T-064 | P1 | Business settings in one screen: currency, time zone, tax rules | T-008 | T-008 | Changing the setting changes new documents; issued documents keep their own values |
| T-065 | P1 | A screen for sign-in locks and open sessions | T-060; also T-022 (the list, §3) | T-004, T-022 | An owner can see and end a session and lift a lock |
| T-066 | P2 | A currency code on payments, bills, expenses and journal lines | T-008 | T-008 | Needed only if a second currency is ever used; decided with T-008 |
| T-080 | P0 | The sale end to end on staging, with real test records | T-133 — Done on the rehearsal on 17 November. What is left is the same journey on staging. | T-006 | One journey passes on the rehearsal with sample records — there the switch "the owner has confirmed the documents" (T-084) is set for the rehearsal only; then on staging with real test records (the phase's exit rule) |
| T-081 | P1 | Approvals as one queue for every kind | T-004 | T-004 | A second kind of approval appears in the same queue with the same rules |
| T-084 | P0 | Documents issued with Furniture's real terms and signers | T-003 | T-003 | The owner confirms once; a sent quotation carries them |
| T-085 | P1 | Estimations with Furniture's rates; the module switched on | T-003 | T-003 | An estimate becomes a quotation with the same totals |
| T-087 | P1 | Tax rules on quotations and invoices; credit notes; payment schedules | T-008 | T-008 | An invoice shows the rule it was issued under |
| T-088 | P1 | Commerce readiness: the command exits clean on staging | T-130 | T-006 | The command exits clean on staging |
| T-089 | P1 | The catalogue's photographs copied into the media library | T-130 | T-006 | Every imported product shows its own photograph |
| T-100 | P0 | The shop's enquiry and quote-request forms write to Laravel | T-130 | T-006 | An enquiry from the shop is in the Laravel CRM |
| T-101 | P1 | Import of leads, contacts, companies, tasks and meetings | T-017 | T-017 | Counts, statuses and duplicates agree with the source |
| T-102 | P0 | The old system stops writing CRM records: one writer | T-100 | T-006 | The old form endpoint answers "moved" |
| T-103 | P0 | Sales documents merged, with Furniture's templates | T-003 | T-003 | A quotation becomes an invoice and a receipt with totals computed on the server |
| T-106 | P1 | Orders, in the admin and from the shop | T-105, T-018 | T-018 | An order placed in the shop is delivered and closed in the admin |
| T-107 | P1 | Delivery and installation | T-010; also T-018 (the list, §3) | T-010, T-018 | A delivery is closed with its proof |
| T-108 | P1 | Support and warranty | T-018 | T-018 | A claim is opened against an order and resolved |
| T-109 | P1 | Stock: ledger, warehouses, transfers, reservations | T-012; also T-011 (the list, §3) | T-011, T-012 | Every stock change is a ledger row; ledger and balances agree; the oversell tests pass on MySQL |
| T-110 | P1 | Suppliers, purchase orders, goods receipts, supplier invoices | T-109 | T-011, T-012 | A receipt posts stock exactly once, also when sent twice |
| T-111 | P1 | Production: bills of material, job cards, the quality gate | T-109 | T-011, T-012 | The quality gate cannot be passed without an audited override |
| T-112 | P1 | Projects merged; the client portal switched on | T-015 | T-015 | The isolation tests pass |
| T-113 | P1 | Finance merged: expenses, credit notes, payment schedules | T-013 | T-013 | The trial balance balances; a posted entry changes only by reversal; the reviewer signs |
| T-114 | P2 | Reports merged | T-113 | T-013 | Figures agree with their source records |
| T-118 | P2 | An inbox for Furniture's channels | T-014 | T-014 | A message is answered from the admin; consent and opt-out are kept |
| T-119 | P2 | Automation with a dry run and approvals | T-118 | T-014 | A rule is tried without sending, then switched on |
| T-121 | P0 | Cutover: the final data move; the old system retired | all; also T-016 (the list, §3) | T-016 · and: all | Document 09 §9, steps 9 to 13 — **with separate written approval** (gate E) |
| T-130 | P0 | The Hostinger staging site starts | T-006 | T-006 | The workflow "Hostinger live staging smoke" passes |
| T-131 | P0 | Compatibility verified on the Hostinger account | T-006 | T-006 | The SSH check job passes and its findings are written into the plan |
| T-132 | P0 | A deploy job from GitHub, rolled back in one step | T-130 | T-006 | A push to a release branch updates staging; rolling back is one step |
| T-133 | P0 | First installation on staging: the tables and the first owner | T-132 | T-006 | The owner signs in on staging with their own account and second factor |
| T-134 | P0 | Timed jobs and the queue on Hostinger | T-132 | T-006 | The Health screen shows a heartbeat younger than two minutes for one hour; a queued notification leaves within a minute |
| T-135 | P0 | Mail through SMTP | T-009; also T-006 (the list, §3) | T-006, T-009 | "Forgot your password?" reaches a real mailbox |
| T-136 | P1 | Uploads that survive a deployment | T-132 | T-006 | A product photo survives a deployment |
| T-137 | P0 | Backups, and one proven restore | T-133 | T-006 | A backup has been restored into an empty database and the admin opened on it |
| T-138 | P1 | Errors and failed jobs reach a person | T-135 | T-006, T-009 | A forced error is seen within five minutes |
| T-139 | P1 | Behaviour within the hosting plan's limits | T-133 | T-006 | No request over 5 s; no limit hit |
| T-140 | P1 | https, the domain, the admin's own host name | T-007; also T-006 (the list, §3) | T-006, T-007 | The admin answers on its address; the old one redirects; the https-only header is seen on the real domain |
| T-141 | P1 | The shop's build served from the same Hostinger account | T-100 | T-006 | A form on the staging shop reaches the staging admin |
| T-155 | P2 | Richer sample data, Furniture's own | T-003 | T-003 | Every area's main list shows at least five sample rows |
| T-156 | P2 | The demo removed or moved once the real admin exists | T-133 | T-006 | `WOODEX_DEMO` is not set in production |
| T-161 | P1 | A sweep for records opened by their number as the wrong role | T-060 | T-004 | The route-access test covers records, not only screens |
| T-165 | P0 | Browser journeys for the remaining flows of document 10 | T-010, T-033 and the modules | T-003, T-010 · and: the modules | Each flow is one named journey that passes on the rehearsal |
| T-168 | P1 | Performance: query budgets and a run with realistic volumes | T-155 | T-003 | The budgets are tests; the run's findings are in the report |
| T-185 | P1 | A runbook: deploy, roll back, restore, passwords, accounts | T-137 | T-006 | Written, and each step done once on staging |

#### Behind lines that need nobody (11)

| # | Pri | Task | Waits for | Stands behind | Done when |
|---|---|---|---|---|---|
| T-042 | P1 | On a phone, the key lists show cards instead of a wide table | T-041 | T-041 (round 3) | At 390 px each row's main facts and its action are visible without sideways scrolling |
| T-047 | P3 | Search finds a screen by keyword and by its old name | T-046 | T-046 (round 7) | "stock" finds Stock Ledger; "clients" finds Contacts |
| T-050 | P2 | A reference screen showing every shared component and state | T-041 | T-041 (round 3) | One screen under System, for owner and admin |
| T-051 | P2 | Placeholders while a list loads; a notice when the connection is lost | T-041 | T-041 (round 3) | Seen in a browser with the network slowed and cut |
| T-105 | P1 | The shop reads products, categories and series from `/api/v1` | T-063 — It can be built and proven on the rehearsal as soon as T-063 is done. Switching the live shop over also needs the real admin on Hostinger (T-133). | T-063 (round 2) | The shop shows only what Laravel has published |
| T-115 | P2 | The page-builder prototype (gate C) | T-105 — Building the prototype needs nobody. Accepting it is yours: gate C. | T-063 (round 2) | The owner accepts the prototype before any broad build |
| T-116 | P2 | Website Studio on Puck | T-115 | T-063 (round 2) | A page is edited, approved, published and rolled back |
| T-117 | P2 | Today's pages moved into the page builder | T-116 | T-063 (round 2) | Selected pages are approved; the old content is kept |
| T-120 | P2 | Marketing: campaigns, attribution, landing pages | T-116 | T-063 (round 2) | A lead shows the campaign it came from |
| T-176 | P1 | The differences between PostgreSQL and MySQL, tested | T-175 | T-175 (round 4) | A test per kind on MySQL in CI |
| T-177 | P1 | One rule for identifiers; old-to-new numbers kept | T-175 | T-175 (round 4) | Every importer writes the mapping |

#### With a wave, a date or an event (8)

| # | Pri | Task | Waits for | Done when |
|---|---|---|---|---|
| T-073 | P3 | "Connect" of a connector that signs in at another site | when the Google module is opened | Pressed in a browser with the module on: the button opens the other site's sign-in page |
| T-082 | P1 | Reports: definitions, time zone, currency, exports by permission | wave 9 | Each report names its definition; an export leaves an audit row |
| T-083 | P1 | Contacts with several phone numbers and e-mail addresses | wave 3 | A contact holds any number of methods; duplicate detection reads all of them |
| T-178 | P1 | An importer per module, repeatable, with a dry run | per wave | Run twice, the second run changes nothing |
| T-179 | P1 | A reconciliation report per domain | per wave | Read by the owner before each switch |
| T-180 | P1 | One full rehearsal of the data move, and of going back | wave 10 | Written into the cutover plan |
| T-186 | P1 | Training and handover | wave 11 | Each role has done its main job in the new admin |
| T-188 | P0 | The shop's old previews are gone | 17 December — Nobody acts: Vercel deletes each preview 30 days after it was built. The outside check watches one of them. | The live check reads "gone" for the old preview it watches: by 17 December |
<!-- /generated:waiting -->

---

## 5. The checks every round passes before it is merged

The tests of §2 are written for one line each. These stand for every round, and a round is merged only when all
of them pass on its last commit.

<!-- generated:standing — written by scripts/verify-plan.mjs --write; do not edit by hand -->
| Check | Where it runs | On 18 November 2026 | What it stops |
|---|---|---|---|
| The Laravel test suite | In the sandbox on SQLite; in CI on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4 (`os-ci.yml`), on every push that touches `platform/` | 2,508 pass, 36 skipped, none fails: run in the sandbox on 18 November, 04:33 to 04:38 UTC, on `d11e504`. In CI on the same commit, on each of the three servers: 2,509 pass, 35 skipped | A rule, a screen or a permission that no longer does what its test says |
| A release installed and deployed again by a browser (`scripts/os-install.mjs`) | CI, job `hostinger` of `os-ci.yml`: Apache with the website folder's own rules, PHP 8.4 as a hosting account, MariaDB 10.11. In the sandbox on PHP's own server and SQLite | the installation 9 of 9 steps, the next deployment 5 of 5; 60 addresses asked from outside, nothing of the application handed out (CI, `d11e504`) | A release that does not start on a host; an installer its owner cannot get through; a file of the application handed out by the web server; a deployment that loses the settings, the sign-in or a database change |
| Two journeys in a browser (`scripts/os-journeys.mjs`) | On the rehearsal of Vercel's runtime, in the sandbox and in CI: once with the demo's settings, once with the product's | 28 of 28 steps hold, both ways (CI, `dfc8369`) | A step of a working day or of a sale that a person can no longer do; anything the content policy refuses |
| Every screen as four roles (`scripts/os-screens.mjs`) | The same rehearsal, at 1366 px and at 390 px | 248 pages, none fails (CI, `dfc8369`) | A screen that does not open, a console error, a link that leads nowhere, sideways scrolling, a field without a label, a control too small to touch |
| The deployment as Vercel builds it (`platform/tools/vercel/check-entry.sh`) | CI, job "Vercel's PHP runtime: build the demo, sign in, open every screen" | passes (CI, `dfc8369`) | A site that starts without its settings, hands out a file, or loses the session |
| The React shop's gate (`ci.yml`) | CI, on every push | passes (CI, `dfc8369`) | A broken build or type; a failing API or row-level-security test on PostgreSQL; a secret in the tree; the acceptance files or this plan out of step with the repository |
| Known weaknesses in the packages (`audit.yml`) | CI, when a lock file changes and every Monday | last run green on 17 November (`ef31589`); it reports one low and eleven moderate advisories (T-071) | A package with a known high or critical weakness |
| The live sites from outside (`live-check-after-merge.yml`) | GitHub, eight minutes after every merge into `main` | the admin: 41 questions, all hold; the shop: 14, all hold (run 44156136251, 17 November, 19:21 UTC) | A live site that does not answer as it should after a merge. A failure opens an issue |
| Each new test, tried once against its protection taken away | By hand, when the test is written; recorded in the round's report | done for the security, policy and request-number tests of 17 November | A test that would pass whatever the code does |
| Before the merge: the files changed against `main` are read, and every check is green | By hand; the release report is written first | done for pull requests #37, #39 and #40 | A change to the React shop that the report does not name; a merge with a check still red |
<!-- /generated:standing -->

**Not covered by any of them today**, each a line of the list: a signed-in visit to the live demo (T-151) ·
Safari and Firefox (the browser checks use Chromium) · a real amount of data in the screens (T-155, T-168) ·
mail, timed jobs and lasting uploads, which the demo does not have (T-134 to T-136) · a restore from a backup
(T-137, T-187).

---

## 6. What the check refuses

`node scripts/verify-plan.mjs` fails, and with it the CI job of every push, when:

- a row of the list has a status other than Done, Next, Later or Owner, or the rows are out of order;
- the list's own line "This list in numbers" does not agree with its rows (`--write` corrects it);
- the first point of the list's "Order of work" does not name exactly the lines that can start now;
- a line that can start now is in no round, or in two; or a round still holds a line that is done or waits;
- a line of a round does not say what is built, name a test, say what the tests are tried against, or give the
  way back;
- a line that is not done has no short title, or a title is kept for a line that is done;
- a waiting line names a line that is not on the list, or waits only for lines that are done: it can start then,
  and the list must say "Next";
- this document does not show what the list and the plan say.

So the work of a round includes three small steps for this document: mark the line done in the list, take it out
of its round and its title out of `plan.json`, and run `node scripts/verify-plan.mjs --write`.
