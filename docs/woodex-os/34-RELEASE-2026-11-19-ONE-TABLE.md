# WOODEX OS — release report, 19 November 2026: one table for every list

The report of pull request #49, in the ten points of document 10 §9, written **before** the merge (document 16
§8; the lesson of defect D-014). It was written on 19 November, when the round was built and proved; the merge
is on 20 November, and the dates in the records are the merge's. The same pull request carries a second round,
built on 20 November on the owner's word "Start next": on a phone, the key lists are cards (T-042). Its report is
document 35. What this report says of a phone ("scrolls inside its frame", "the next line that can start") was
so when it was written and is changed by that round for Leads, Quotations and Invoices.

**On whose word.** The owner's messages of 19 November: "analyize complete before you start to next phase", and
then "start". The merge stands on his answer that the agent merges into `main` when every check is green
(DL-026). What the agent decided on the way is DL-039.

**Why this line and not the first of the plan.** The first round of the plan is the shop's reading of its
products (T-105). It was read before anything was started: that round is already built, on another branch, in
pull request #48, from another conversation of the owner's. It was open, green and not merged. It is not built
here a second time. This is the next line of the plan that pull request #48 does not touch. Decision 038, defects
D-045 to D-052 and documents 31 to 33 are written there; this round takes DL-039 and document 34 so that nothing
is renumbered whichever is merged first.

**What this round is.** One line of the master list, built and proved:

| Line | What a person can do now |
|---|---|
| T-041 | On Leads and Quotations, press the heading of a column and the list is sorted by it, with the keyboard too; an arrow says which way. Six lists are drawn by one shared table: Leads, Quotations, Projects, Payables by age, Users and the Receivables report. Each table has a name a screen reader reads, and each row a cell that names it. In the leads list "newest first" goes by the date of the enquiry, the date the list shows. The tick boxes of the leads list are easier to hit |

**What it is not.** No list sorts by something it could not sort by before. The round changes views, and one line
of one query: what "newest" means in the leads list (below).
No module was switched on and no destination changed its state. 45 views still write a table by hand. No screen
says "loading", "error" or "no-permission" yet, though the table can. Nothing of the React shop changed, and
nothing on Hostinger, in Supabase or in the settings of either Vercel project.

## What was built

| Part | What it is |
|---|---|
| `platform/resources/views/components/admin/table.blade.php` | The table: its name (a caption), a place for filters, the headings, the rows a list hands it, a foot for totals, the shared page links, the bar of what can be done with all chosen rows, and the five states of the owner's document 05 §6: loading, empty, error, no-permission, success. A list that hands over its rows gets "empty" and "success" by itself |
| `…/table/heading.blade.php` | A heading. Where the list can sort by the column it is a button that says how it is sorted: `aria-sort`, an arrow, and a sentence for a screen reader ("Sorted descending. Press to sort ascending."). The list keeps its own words for sorting; the component knows none |
| `…/table/select.blade.php`, `select-all.blade.php` | The tick box of a row and "Select all on this page". A label of 24 px stands around each box |
| `platform/resources/views/components/admin/README.md` | The standards: the table and its states, the dialog, the confirmation, the toast, the page links. What the code does not do yet is named under each, with its line |
| `platform/tests/Unit/AdminTableTest.php` | 14 tests: the component drawn in each state and read by names and roles; the views that still write a table by hand, listed |
| `platform/tests/Feature/SharedTableListsTest.php` | 11 tests: what moving could have broken, asked of the six lists; and what "newest" means |
| `platform/app/Livewire/Admin/Crm/LeadsIndex.php` | One line of its query: newest and oldest by the date of the enquiry |
| `scripts/os-journeys.mjs` | Step 38: the leads sorted by a heading with the keyboard only |
| `scripts/verify-acceptance.mjs` | One line: it reads a step's name in double quotes too, so document 16 counts every step |

**Rows and cells are plain, on purpose.** The first form of the component had a part for every row and every
cell. It was measured before the merge and taken out again (§8): a list writes `<tr>`, `<th scope="row">` and
`<td>` inside the table, as it wrote them before.

## What a person sees differently

| Where | Before | Now |
|---|---|---|
| Leads, Quotations | Sorted by the choice "Sort" only | Also by a press on a heading: Lead (newest, then oldest), Contact, Score, Next action; Quotation, Value, Updated. The headings that sort carry a small arrow. The choice stays: "Expected value" and "newest first" have no column |
| Leads | "Newest first" and "Oldest first" went by the order in which leads were entered. Leads brought in from a sheet keep their own dates, so they stood on top as the newest; on the hosted workspace the sample leads stood the wrong way round | By the date of the enquiry, which is the date shown under each reference. Two leads of the same moment: the one entered later is the newer |
| Leads | Tick boxes of 16 px | The same boxes; what a pointer hits is 24 px |
| Payables by age | No line under the headings | A hairline, as on every other table |
| Receivables | With nothing owed: one grey sentence. Hairlines between rows a shade lighter | The shared "nothing here" block with the same words; hairlines as on every other table |
| Users, Payables by age, Receivables | Rows did not dim while the server was asked | They dim, as Leads, Quotations and Projects did |
| Feature status | — | A new entry under "What is new" |

Everything else on the six screens is as it was: the same columns at each width, the same links, the same words.

## What this merge changes on each live site

| Site | Changes at the merge |
|---|---|
| Hosted workspace `woodex-os.vercel.app` | Rebuilt from `main`: the six lists as above, a new stylesheet file (three more utilities, one rule wider), the new entry under "What is new". Its ten sample leads turn round: the one of the latest date stands first under "newest first" |
| Shop `woodex-2030.vercel.app` | Rebuilt from `main`. None of its files changed |
| Supabase | Nothing |
| Branch `hostinger-release` | Rewritten by CI with the release of the merge commit: views, one stylesheet, the order of the leads list and the notes of "What is new"; no migration |
| Pull request #48 | It will not merge as it stands afterwards: its records touch the same lines (the state table of the master list, documents 16 and 21, the rows of the README, the release notes). Its own conversation brings `main` in and keeps both; and the styles must be built once more then, see "What is not proved" |

## 1. Commit/branch/build identifier

Branch `arena/efba15a9-woodex-2030`, from `main` at `dd7a308`. Pull request #49.

| Commit | What |
|---|---|
| `b01cdf5` | The component, its parts and the test that reads it |
| `21c569f`, `87665b8`, `0bc3ae0`, `895491d`, `cb30b2f`, `ec05fd0` | Quotations, Projects, Payables by age, Users, Receivables, Leads: one list each, so that each can be put back alone |
| `611c982` | The built styles |
| `1697525` | The test of the six lists |
| `e61f3b5` | The standards, and journey step 38 |
| `03473d1` | The tick boxes: 24 px to hit |
| `9c27089` | Rows and cells plain again, after the measurement of §8 |
| `3b8a331` | Leads: "newest" goes by the date of the enquiry, with its test |
| the commit that carries this report | The records of the round, the entry under "What is new", three pictures, and the count of the journeys' steps in document 16 |

Built files, in `platform/public/build`: the stylesheet `admin-B3DCJbRO.css` is replaced by `admin-BbwahaRb.css`
(120,933 → 121,067 bytes), `site.css` gains the same three utilities, `manifest.json` names the new file. No script changed.
Before any change of this branch the build command gave the committed files back, name for name; after the last
change it gives these.

## 2. Environment

- Sandbox: PHP 8.5, the suite on SQLite. The rehearsal of the hosted workspace (Vercel's PHP runtime, the demo's
  settings) with Chromium, for the journeys, the crawl and the measurements.
- CI: the suite on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4; two writers on the same three; the rehearsal with
  the demo's settings and with the product's; the Hostinger release on Apache with MariaDB; the shop's gate.
- No live system was written to.

## 3. Test command and result

| What | Where | Result |
|---|---|---|
| The Laravel suite | Sandbox, SQLite, 19 November, 20:36 to 20:43 UTC, on the code of `3b8a331` | 2,699 pass, 36 skipped (16,658 assertions); none fails. The entry under "What is new" was written after that run; the test that reads the notes was run on it and passes |
| The Laravel suite | CI, `3b8a331`, each of the three servers | 2,700 pass, 35 skipped. (On `9c27089`, before the test of "newest": 2,699.) The result of the commit that carries this report is on the pull request |
| `tests/Unit/AdminTableTest.php` | part of the suite | 14 tests: a named table; each of the five states with its words and its role; a state that is not one of the five is refused; headings that say how they sort; nothing printed into a script; tick boxes with names; filters, pages and a foot; the count |
| `tests/Feature/SharedTableListsTest.php` | part of the suite | 11 tests: each of the six lists is one named table; a heading of Leads or Quotations sends a word the list knows and the rows come in that order; pages, "select all" and the chosen rows are what they were; Delete is offered to an owner and not to a sales manager; a lead entered today with last year's date is not the newest |
| The lists' own tests, left as they were | part of the suite | `LeadsIndexTest`, `Crm/LeadDrawerTest`, `QuoteScreensTest`, `ProjectsTest`, `Accounts/PayablesTest`, `UsersAndSettingsTest`, `ReportsKitTest`, and every other test that opens one of the six lists: all pass unchanged |
| One written list changed | `tests/Feature/UnsavedChangesTest.php` | The field "selected" is no longer written in the leads view; the tick box of the component is on that list in its place |
| Two journeys in a browser | Sandbox, the rehearsal, on the last code, 20:44 to 20:46 UTC | 38 of 38 steps held; nothing was refused and nothing failed on the way. Step 38 reads: newest first a lead of 20 November, after Enter on the heading a lead of 6 November |
| Every screen as four roles | Sandbox, the rehearsal, on the last code, 20:47 to 20:53 UTC | 253 pages at each of the two widths, none fails: no console error, no failed request, nothing refused by the content policy, no page wider than its window |
| Two writers at once | CI, `9c27089`, the three servers | 33 steps, 50 runs each, all held; unchanged by this round |
| Code style (Pint) | Sandbox | Passes |
| The shop's gate (`build-and-test`) | CI | **Failed on `e61f3b5`, `03473d1`, `9c27089` and `3b8a331`**, for a reason of the records and not of the code. Run again on the tree of `9c27089` in the sandbox, the gate names one problem: document 16 prints the steps of the journeys, and it was not rewritten when step 38 was added. It is rewritten in the commit that carries this report. Its result on that commit is on the pull request |

**Each new test, tried against its protection taken away** (in the sandbox):

- The empty state taken out of the component: 3 tests of `AdminTableTest` fail.
- A table written by hand put back into a moved view: the count fails and names the view.
- `aria-sort` taken off the heading: 3 tests fail (one of the component, two of the lists).
- The sort word printed into the script, not handed over as data: 2 tests fail, one of them the standing rule
  about scripts (`Security/StoredScriptTest`).
- The order of entry put back into the leads' query: the test of what "newest" means fails.

## 4. Browser/device coverage

On the rehearsal, with Chromium, at 1366 px and at 390 px.

- **The journey, step 38** (keyboard only): Tab from "Select all on this page" lands on the heading "Lead", which
  shows an outline; Enter turns the list round and the heading says "ascending"; the focus is still on the
  heading after the server drew the list again; Enter puts it back. At 390 px the page is as wide as the window.
- **The crawl**: the six lists opened as every role that may open them, at both widths: each answers 200, with
  no console error, no failed request, nothing refused by the content policy, and no page wider than its window.
- **Measured at 390 px, as the owner.** The page never scrolls sideways. Inside the page: Quotations fits
  (three of six columns are drawn). Leads draws all eight columns, 1,036 px in a frame of 356 px, and scrolls
  inside its frame, as before the move. Projects (363 px), Users (837 px) and Receivables (916 px) scroll inside
  their frame too. Cards on a phone are the next line that can start (T-042).
- **Measured at 1366 px**: the tick boxes of the leads list (one a row and "select all"), none smaller than
  24 px to hit. Before, the crawl counted every one of them as too small. On 16 other screens it still counts
  98 controls smaller than 24 px at this width, 21 of them on the product list (it counted 252 before this
  round); at 390 px it counts none. No line of the master list takes the 98, and the owner's document 10 §5
  asks for no size at this width.
- Not looked at: the dark theme on the six lists by eye (their classes for it are unchanged); Firefox and Safari.

## 5. Defects open/closed by severity

The register is as on `main`: 44 on record, 32 closed; open are 1 critical (D-002, the old dashboard's owner
password: T-021, the owner's), 7 medium and 4 low. This round writes no entry into it: the next free numbers are
taken by pull request #48 (DL-039, point 2).

**Found on `main`, cured in this round, and owed to the register:**

- **"Newest first" in the leads list went by the order of entry, not by the date it shows** (medium by the
  register's rule: a workaround existed, another sort). It was so since the list was built. A lead brought in from
  a sheet keeps the date of its enquiry and is entered today: it stood on top as the newest. On the hosted
  workspace the ten sample leads stood the wrong way round under "newest first". Nothing was written wrongly.
  Found when the heading began to draw an arrow beside those dates, and the arrow pointed against them. Cured:
  the list goes by the date of the enquiry; `tests/Feature/SharedTableListsTest.php` holds it. It is entered in
  the register under the next free number once the numbers of pull request #48 are settled.

- **Document 16 gave the journeys one step fewer than they have** (a record, not the product; noted here and
  not owed to the register). The script that writes the document read the names of steps in single quotes only,
  and one name stands in double quotes: it said 36 where the journeys pressed 37, and would have said 37 for 38.
  Found on reading the document against the run. Cured in `scripts/verify-acceptance.mjs`; the document says 38.

**Counted by the crawl on `main`, put right on the way; a count and not an entry of the register:**

- **The tick boxes of the leads list were 16 px**, and the crawl counts what is smaller than 24 px to hit at
  1366 px. The component is now the one place where a list's tick boxes are drawn, so they are put right
  there. The crawl still counts small controls on other screens (§4).

**Found and cured inside the round, never released:**

- **A part for every cell nearly doubled the time to draw a list** (§8). Measured before the merge and taken out.
- **A new journey step changes document 16**, which prints how many steps the journey has. The shop's gate was
  red on every push after the step was added, until the records were written. Lesson: a commit that adds a step rewrites
  document 16 in the same commit.
- **A run of the crawl was started with the wrong watched words** ("Studio", which is in "Website Studio") and
  reported 211 pages as not fine. The pages were fine; the run was repeated without them.

## 6. Migration reconciliation

None. No table, no column, no migration: 109 migrations and 126 tables, as on `main`.

## 7. Backup/restore status

Nothing to back up: no data is touched.

## 8. Performance observations

Measured in the sandbox: the leads list drawn 21 times by the server, the median taken, with PHP's
compiled-code cache on and off (the figures differed by a few milliseconds at most).

| | 25 rows (the page size as it opens) | 100 rows (the largest) |
|---|---:|---:|
| The table written by hand (`main`) | 35 ms | 107 ms |
| The first form of this round: a part for every row, cell and tick box | 62 ms | 198 ms |
| As merged: plain rows and cells inside the shared table | 43 ms | 125 ms |

- A Blade part costs about a tenth of a millisecond each time it is drawn. A list of 100 rows and nine columns
  draws a thousand cells; the leads page is asked for again every 30 seconds and at every filter. So the parts
  for a row and a cell were removed, and a test names the parts the table has, so that they do not come back.
- What is left over `main`: about 7 ms at 25 rows and 19 ms at 100, for the frame, the headings and one tick box
  a row. On a host these figures will be others; the proportion is what was measured.
- The HTML of the leads page is smaller than on `main`: 142 KB against 152 KB at 25 rows.
- The stylesheet grew by 134 bytes.
- These figures were taken before the order of the leads was changed (`3b8a331`). The list now orders by the date
  of the enquiry and then by the order of entry; the date has an index. It was not measured again.
- Not measured: the other five lists (none has more than a few rows on the rehearsal), and a real server.

## 9. Screenshots or recording of key flows

Three pictures, taken on the rehearsal after the last code change and looked at:
`evidence/shared-table-2026-11-19-leads-desktop.jpg` (the leads after one press on the heading "Lead": the arrow
points up, the choice says "Oldest first", and the dates rise from 6 November),
`evidence/shared-table-2026-11-19-leads-phone.jpg` (390 px: newest first, the dates fall from 20 November; the
list scrolls inside its frame) and
`evidence/shared-table-2026-11-19-quotations-desktop.jpg` (after one press on "Value": the arrow stands before
the word, the higher value first).

## 10. Explicit owner approval status

- Merging when every check is green: approved (DL-026).
- Starting: the owner's word of 19 November ("start").
- T-041 as built: **not yet seen by the owner.** On the hosted workspace after the merge: Leads and Quotations,
  and the entry under "What is new".
- Decided by the agent and the owner's to overrule (DL-039): this line before T-105, which is in pull request
  #48; the six lists; that a heading sorts by the list's own words and no new sorting was added; that "newest" in the leads list goes by the date of the enquiry; that rows and cells are plain; that the
  standards name what is not so yet and leave it for its line; the 24 px around a tick box; where the three
  lines that can now start stand in the order.
- **Still open, asked before:** where the system that keeps data is to run (document 24, or a database of his
  for the Vercel site); whether Feature status may link to the old dashboard; the price list, a messaging account
  and a Facebook Page; whether the two old previews of the shop are to be deleted. **New:** pull request #48
  waits for his look.
- No module is accepted and no gate is passed. Gate A's criterion "shared component examples" stays open (T-050).

## What is not proved

- **Anything on the live sites, until the merge.** The outside check runs eight minutes after it.
- **The three states no screen says.** "loading", "error" and "no-permission" are drawn and read by a test; no
  screen of the admin shows one, so nobody has met them in a browser (T-051, T-050).
- **The 45 other lists.** The component was made to fit them: a foot, short tables inside a card, lists without
  pages. Only the six were moved; a list with a need the six did not have may ask for more (T-045).
- **A screen reader.** Names, roles and sentences are read from the page by tests and by a browser. Nobody
  listened with NVDA, JAWS or VoiceOver (T-054, the pass without a mouse).
- **The dark theme and other browsers**: not looked at for these six lists.
- **Leads brought in from a sheet.** That "newest" goes by the date of the enquiry is held by a test, on SQLite
  here and on the three servers in CI, and seen on the sample leads of the rehearsal. No list of really imported
  leads was looked at: the rehearsal and the hosted workspace hold samples only.
- **The two pull requests together.** Pull request #48 was read, not run with this one. Two things are known:
  its records and this round's touch the same lines; and its Feature status view uses four classes that are in
  no built stylesheet (`focus:border-brand-500`, `focus:ring-2`, `focus:ring-brand-500/20`,
  `dark:hover:border-brand-500/50`), because it changed a view and did not build the styles. Until the styles
  are built with both merged, the search field of that page has no coloured edge when it has the focus. The
  admin's own outline still shows the focus.

## What comes next

By the plan (document 21): the shop's reading of its products is round 1 and is in pull request #48; the next
line that needs nobody is the key lists as cards on a phone (T-042). The owner says which.
