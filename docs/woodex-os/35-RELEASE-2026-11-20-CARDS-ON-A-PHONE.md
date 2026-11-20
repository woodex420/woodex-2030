# WOODEX OS — release report, 20 November 2026: on a phone, the key lists are cards

The second report of pull request #49, in the ten points of document 10 §9, written **before** the merge (document
16 §8; the lesson of defect D-014). The first is report 34 (one table for every list, T-041): the two rounds are
merged together.

**On whose word.** The owner's message of 20 November: "Start next". The merge stands on his answer that the agent
merges into `main` when every check is green (DL-026). What the agent decided on the way is DL-040.

**Why in the same pull request.** When "Start next" came, the round before stood finished on this branch and was
not merged: the turn before had ended before its records were committed. A merge closes the branch of a
conversation, so nothing could have been started after it. The records of T-041 and report 34 were committed and
pushed first, with every check green (`698fd8e`), so that it could have been merged alone at any moment. This round
was built on top of it (DL-040, point 1).

**Why this line.** The first round of the plan is still the shop's reading of its products (T-105), which is built
in pull request #48, from another conversation, and waits there for the owner. This is the next line of the plan
that pull request #48 does not touch.

**What this round is.** One line of the master list, built and proved:

| Line | What a person can do now |
|---|---|
| T-042 | On a phone, read and work Leads, Quotations and Invoices without scrolling sideways. Each row is a card: its name on top, then what matters, each fact with its word before it. A lead is chosen, opened and moved to another stage from its card |

**What it is not.** Nothing changes from 640 px up, except that Invoices is now drawn by the shared table and looks
as it did. No query changed, no module was switched on and no destination changed its state. The other lists
still scroll inside their frame on a phone. Nothing of the React shop changed, and nothing on Hostinger, in Supabase
or in the settings of either Vercel project.

## What was built

| Part | What it is |
|---|---|
| `platform/resources/views/components/admin/table.blade.php` | One new word, `cards`. A list that says it is drawn as cards below 640 px |
| `platform/resources/css/admin.css` | "The shared table on a narrow screen": 17 rules inside one media query. The same cells are laid out one under the other; a cell with `data-label` gets that label before it; a cell without one is not drawn. Only a table that shows rows is laid out so: "nothing here yet" and the other states keep their one wide cell. The headings are not drawn in any state |
| `…/table/select.blade.php`, `select-all.blade.php` | A class each, for the stylesheet. "Select all on this page" carries its words, drawn on cards only |
| `…/livewire/admin/sales/invoices-index.blade.php` | Invoices moved to the shared table (44 views still write a table by hand), then drawn as cards |
| `…/livewire/admin/crm/leads-index.blade.php`, `…/sales/quotes-index.blade.php` | `cards`, and `data-label` on the cells that stand on a card |
| `platform/resources/views/components/admin/README.md` | The rules: "On a phone: a row is a card", with what is not so yet |
| `platform/tests/Support/Card.php` | Reads a card from its row as a screen reader does: the name, each fact with its word, where it leads |
| `platform/tests/Unit/AdminTableTest.php` | 3 new tests (17 in all): a row as a card; the choosing of rows on a card; every rule for cards inside one media query, in the source and in the built stylesheet |
| `platform/tests/Feature/SharedTableListsTest.php` | 4 new tests (15 in all): Invoices on the shared table; the cards of Leads, Quotations and Invoices |
| `scripts/os-journeys.mjs` | Step 39, on a phone: the three lists measured, Tasks measured, a search that finds nothing, a lead chosen, opened and moved from its card |

**A card is the row itself.** It is not a second drawing of each row for a phone: the stylesheet lays the same
cells out another way. So a card cannot show another value, another link or another order than its row; each row
keeps one tick box; and the server draws nothing more (§8). The price is that the facts stand on a card in the
order of their columns.

## What a person sees differently

Below 640 px only, unless said otherwise.

| Where | Before | Now |
|---|---|---|
| Leads | All eight columns, 1,036 px wide in a frame of 356 px: a person scrolled sideways inside the list to reach the stage or the next action | A card for each lead: the reference and its date; the contact with call and WhatsApp; the stage; the owner; the next action. Service, source and score are not on the card |
| Leads | The tick boxes of the rows, and "select all" in the headings | The tick box left of each card; "Select all on this page" in a line above the cards; "Assign to", "Move to" and "Delete" in their bar above, which wraps and stays inside the window |
| Quotations | Three of six columns; it fitted | A card for each quotation: number, title and client; status; value; when it was last changed |
| Invoices | Four of six columns, 391 px in 356: it scrolled by 35 px, and the client was not shown below 768 px | A card for each invoice: number, project or quotation, the PDF; client; status; amount; balance; due date |
| Leads, Quotations | The headings sorted the list | The headings are not drawn. The choice "Sort" above the list sorts, as before |
| Leads, after a search that finds nothing | The headings stood above an empty list 730 px wide in a frame of 356 px, and "No leads match these filters" stood half outside the window | No headings; the words stand in the middle of the window, with "Clear filters" under them |
| Invoices, at every width | A table written by hand | The shared table: a name a screen reader reads, a cell that names each row, its search named "Search invoices" inside "Filter invoices". It looks as it did |
| Feature status | — | The entry of 20 November under "What is new" says both rounds |

From 640 px up the three lists are the tables they were: measured for Leads (§4), and held by a test that reads the
stylesheet.

## What this merge changes on each live site

Together with report 34, which names what the first round changes.

| Site | Changes at the merge |
|---|---|
| Hosted workspace `woodex-os.vercel.app` | Rebuilt from `main`. On a phone its Leads are cards (ten sample leads); Quotations and Invoices show cards as soon as there is one. A new stylesheet file |
| Shop `woodex-2030.vercel.app` | Rebuilt from `main`. None of its files changed |
| Supabase | Nothing |
| Branch `hostinger-release` | Rewritten by CI with the release of the merge commit: views, one stylesheet and the notes; no migration |
| Pull request #48 | As in report 34: it will not merge as it stands afterwards, and its own conversation brings `main` in |

## 1. Commit/branch/build identifier

Branch `arena/efba15a9-woodex-2030`, from `main` at `dd7a308`. Pull request #49. The commits of the first round are
in report 34; its records are `698fd8e`.

| Commit | What |
|---|---|
| `3a681ac` | The shared table draws a row as a card below 640 px: the component, the stylesheet and its built file, the reader of cards, the tests |
| `95667e6` | Invoices moves to the shared table |
| `6c86cce`, `05a0513`, `ffb6627` | Leads, Quotations, Invoices as cards: one list each, so that each can be put back alone |
| `de5c66e`, `f7f97f5` | The rules; then one sentence of them and one note of the stylesheet made exact, after a measurement |
| `c00f90a` | The reader of cards in the tests, made to run on PHP 8.4 (§5) |
| the commit that carries this report | Journey step 39 with document 16, which prints the steps; the records of the round; the entry under "What is new"; five pictures |

Built files, in `platform/public/build`: the stylesheet `admin-BbwahaRb.css` is replaced by `admin-Ct8n60mi.css`
(121,067 → 123,278 bytes), `site.css` gains three utilities (77,462 → 77,653 bytes), `manifest.json` names the new
file. No script changed. Built again after the last change, the command gives the same files.

## 2. Environment

- Sandbox: PHP 8.5, the suite on SQLite. The rehearsal of the hosted workspace (Vercel's PHP runtime, the demo's
  settings) with Chromium, for the journeys, the crawl and the measurements.
- CI: the suite on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4; two writers on the same three; the rehearsal with
  the demo's settings and with the product's; the Hostinger release on Apache with MariaDB; the shop's gate.
- No live system was written to.

## 3. Test command and result

| What | Where | Result |
|---|---|---|
| The Laravel suite | Sandbox, SQLite, PHP 8.5, 20 November, 04:50 to 04:57 UTC, on the code of `f7f97f5` | 2,706 pass, 36 skipped (16,768 assertions); none fails. After `c00f90a`, which changes two test files, those two were run again: 32 pass |
| The Laravel suite | CI, PHP 8.4, `f7f97f5`, each of the three servers | **Failed**: two unit tests, for a reason of the tests and not of the product (§5) |
| The Laravel suite | CI, PHP 8.4, `c00f90a`, each of the three servers | 2,707 pass, 35 skipped. The result of the commit that carries this report is on the pull request |
| `tests/Unit/AdminTableTest.php` | part of the suite | 17 tests. New: a row with `cards` is read as a card (its name, "Client: Ahsan Traders", "Value: Rs 4,50,000", its link), and a word that differs from its column's heading is named; the box of a row and "Select all on this page" with its words; every rule that names the cards stands inside the one media query, in the source and in the built stylesheet, and the block holds nothing else |
| `tests/Feature/SharedTableListsTest.php` | part of the suite | 15 tests. New: Invoices is one named table, with its empty state; each lead, quotation and invoice is one row read as a card, with exactly the facts named above under the words of their columns; a column a person switched off is on neither the table nor the card |
| The other tests of the three lists, left as they were | part of the suite | 169 tests, in the 13 other files that draw one of the three lists and in the standing rule about scripts (`Security/StoredScriptTest`): all pass unchanged |
| Two journeys in a browser | Sandbox, the rehearsal, on the code of `de5c66e`, 04:35 to 04:38 UTC | 39 of 39 steps held; nothing was refused and nothing failed on the way |
| Every screen as four roles | Sandbox, the rehearsal, on the same code, 04:43 to 04:49 UTC | 253 pages at each of the two widths, none fails: no console error, no failed request, nothing refused by the content policy, no page wider than its window |
| What the browser was given | — | The rehearsal ran the code of `de5c66e`. The commit after it, `f7f97f5`, changes a note in the stylesheet's source and a sentence of the rules; the built stylesheet is the same file |
| Code style (Pint) | Sandbox | Passes |
| The shop's gate (`build-and-test`) | CI, `698fd8e` | Passes (it was red from the commit that added step 38 until that step's records: report 34). This round adds step 39 and rewrites document 16 in the same commit |

**Each new test, tried against its protection taken away** (in the sandbox):

- `cards` taken off the leads list, on the rehearsal: journey step 39 fails with "Leads: the list is 1036 px wide
  in a frame of 356 px: it scrolls sideways". That is the measurement the plan asked for.
- The label taken off the stage of a lead: the test of the leads' cards fails.
- `cards` taken off Invoices: the test of the invoices' cards fails.
- A card that calls the client "Customer" where the column says "Client": the same test fails and names the two.
- A rule for cards written outside the media query: the test that reads the stylesheet fails.
- The rules for cards missing from the built stylesheet, as when a change is committed without building: the same
  test fails.
- Invoices writing its own table again: the count fails and names the view.

## 4. Browser/device coverage

On the rehearsal, with Chromium, at 390 px and at 1366 px.

- **Journey step 39, at 390 px.** For Leads (12 cards), Quotations (2) and Invoices (1): the page is as wide as
  the window; the list is 356 px wide in its frame of 356 px; no heading of the table is drawn; every row is laid
  out as a card, has a name that leads to its record, shows exactly the facts of the list above, each with its
  word before it, draws no cell the list left off, and keeps every cell inside the card. Then Tasks: nothing of
  it scrolls sideways. Then on Leads: a search that finds nothing says "No leads match these filters" inside the
  window, in a list no wider than its frame. A press on the box of a card gives "1 selected" and a bar that
  stays inside the window (measured apart: from 16 to 374 px of 390); "Select all on this page" gives "12
  selected"; "Clear selection" clears. A press on the contact's name opens the lead beside the list; the stage is
  changed there from New to Negotiation; the card says Negotiation. A press on the name of the card opens the
  lead's page.
- **What Chromium hands a screen reader for a card** (read from its accessibility tree): a row; in it a tick box
  "Select L-2026-SYN001", the row's name "L-2026-SYN001 18 Nov, 7:59 pm" with its link, then "Contact SYNTHETIC
  Contact 01 …" with a button and the two links, "Stage New", "Owner SYNTHETIC Sales", "Next action WhatsApp 1 day
  ago". The cells left off the card are not in the tree. The step asks for a cell named "Stage …" every time.
- **Before and after, at 390 px.** Leads, on the ten sample leads, the code of `698fd8e` against this round:
  1,036 px in a frame of 356, now 356 in 356. Invoices: 391 in 356 before, with a row (measured on 19 November)
  and with none; now 356 in 356 with a row and with none. Quotations and Tasks fitted and fit. With nothing found,
  the leads list was 730 px wide and its words stood from 280 to 484 px in a window of 390 (measured on this
  round's code with the headings drawn again); now 356 px, and the words from 93 to 297.
- **Before and after, at 1366 px, Leads** (the same ten sample leads): the table 1,036 by 1,150 px both times,
  the eight columns 44, 155, 160, 98, 130, 143, 159 and 147 px both times, the rows 108.5 and 109 px both times.
  Quotations and Invoices have no rows on fresh sample data, so they could not be compared the same way: for them
  the proof is the test that reads the stylesheet, and for Invoices a picture (§9).
- **The crawl** at both widths, as four roles: the three lists and every other screen open without a console
  error, a failed request or a page wider than its window. At 1366 px it counts 98 controls smaller than 24 px on
  16 screens, as before this round; at 390 px none.
- **The dark theme**: the leads' cards looked at in one picture (§9). Not looked at: Quotations and Invoices in
  the dark theme; Firefox and Safari; a real phone.
- **Left as they are at 390 px**: the row of status chips above Quotations (1,243 px in 358) and Invoices (574 in
  358), and the tabs of the two hubs (458 and 414 in 358). They scroll sideways on purpose and are not part of the
  list.

## 5. Defects open/closed by severity

The register is as on `main`: 44 on record, 32 closed; open are 1 critical (D-002, the old dashboard's owner
password: T-021, the owner's), 7 medium and 4 low. This round opens none and closes none, and writes nothing into
the register (DL-040, point 9): the finding of report 34 about "newest first" is still owed to it.

**Found and cured inside the round, never released:**

- **The step's first run failed on its own words**, not on the product: the panel a card opens has two buttons
  named "Close", and the step did not say which. It names the one in the panel's head now.
- **An empty list on a phone kept its headings.** The first form of the stylesheet left every state but rows
  alone, so a search that found nothing still drew eight headings above nothing, 730 px wide, and the words that
  say so stood half outside the window. It was so before this round too. Found on reading this report's own
  claim that no list scrolls sideways; the headings are not drawn in any state now, and the step looks.
- **Two new tests passed in the sandbox and failed in CI.** The reader of cards in the tests took the cells of
  a row from the element's "children", a property that came with PHP 8.5. The sandbox runs 8.5; the product and
  CI run 8.4, and there each of the three servers failed the two unit tests that read a card. No code of the
  product was involved. The cells are walked in a way 8.4 knows now. Lesson: the sandbox's PHP is one version
  ahead of the product's, so what only the sandbox ran is not proved for 8.4 until CI has run it; that is why
  CI is waited for before a merge, and it is said in `AGENTS.md` now.
- **Six sign-ins in a minute are answered 429.** The pictures were first taken one sign-in each and the sixth
  was refused: that is the sign-in limit doing its work, not a fault. They were taken again in one session.
- **A stale compiled view made two tests fail that had nothing to do with the change tried** while the tests were
  being tried against their protection. The runs were repeated with the compiled views cleared; the figures in §3
  are from those.

## 6. Migration reconciliation

None. No table, no column, no migration: 109 migrations and 126 tables, as on `main`.

## 7. Backup/restore status

Nothing to back up: no data is touched.

## 8. Performance observations

Measured in the sandbox as in report 34: the leads list drawn 21 times by the server, the median taken; the round
before and this round in turn, three times, with nothing else running.

| | 25 rows | 100 rows |
|---|---:|---:|
| The table written by hand (`main`) | 35.9 ms | 109.6 ms |
| The round before (`698fd8e`): the shared table | 43.1, 43.1, 43.4 ms | 125.3, 125.5, 126.2 ms |
| This round: the same table, cards on a phone | 42.3, 41.1, 42.2 ms | 125.4, 121.5, 124.5 ms |

- **Cards cost nothing to draw.** The server writes four more attributes into each row of the leads and nothing
  else; the figures of the two rounds lie inside each other's spread. This is why a card is the row itself and
  not a second drawing: a second drawing would have written every row twice.
- The HTML of the leads list grows by about 92 bytes a row: 147.7 → 150.0 KB at 25 rows, 516.5 → 525.7 KB at 100.
  As the crawl got the pages at 1366 px: Leads 242,759 → 244,114 bytes, Quotations 178,514 → 178,655, Invoices
  170,837 → 171,775.
- The stylesheet grew by 2,211 bytes, for every page of the admin.
- A first measurement, taken while the journeys were running on the same machine, scattered between 47 and
  100 ms at 25 rows and is not used.
- Not measured: a real phone, where laying out 25 cards is the browser's work; and a real server.

## 9. Screenshots or recording of key flows

Five pictures, taken on the rehearsal after the last code change and looked at, all in `evidence/`:
`cards-on-a-phone-2026-11-20-leads.jpg` (390 px: "Select all on this page", then a card for each lead),
`cards-on-a-phone-2026-11-20-leads-dark.jpg` (the same in the dark theme),
`cards-on-a-phone-2026-11-20-quotations.jpg` (390 px: two quotations as cards under the search and the sort),
`cards-on-a-phone-2026-11-20-invoices.jpg` (390 px: one invoice as a card, with its client) and
`cards-on-a-phone-2026-11-20-invoices-desktop.jpg` (1366 px: Invoices on the shared table, as a table).

## 10. Explicit owner approval status

- Merging when every check is green: approved (DL-026).
- Starting: the owner's word of 20 November ("Start next").
- T-042 as built: **not yet seen by the owner.** On the hosted workspace after the merge, on a phone or in a narrow
  window: Leads; and the entry under "What is new".
- Decided by the agent and the owner's to overrule (DL-040): two rounds in one pull request; that a card is the
  row itself and not a second drawing; that the headings are not drawn on cards; **which facts stand on a card**;
  640 px; that Tasks is left as it is; that the other four lists of the shared table are left for their area's
  turn.
- **Still open, asked before:** where the system that keeps data is to run (document 24, or a database of his
  for the Vercel site); whether Feature status may link to the old dashboard; the price list, a messaging account
  and a Facebook Page; whether the two old previews of the shop are to be deleted; pull request #48, which waits
  for his look.
- No module is accepted and no gate is passed.

## What is not proved

- **Anything on the live sites, until the merge.** The outside check runs eight minutes after it.
- **A real phone.** Everything was measured in Chromium at 390 px on a desktop machine: no finger, no iPhone, no
  Android browser. Safari is the one most likely to differ.
- **A screen reader.** What Chromium hands to one was read from its tree; nobody listened with TalkBack or
  VoiceOver (T-054).
- **Whether these are the right facts for a card.** They are a judgement about what a salesperson or an accountant
  needs on a phone, and nobody who does that work has seen them.
- **Quotations and Invoices from 640 px up, by measurement.** Leads was measured before and after on the same
  data; these two were not, for want of rows on fresh sample data. A test holds that no rule for cards applies
  there, and Invoices was looked at in a picture.
- **A long list on a phone.** A card is about 225 px high: 25 leads are some 5,600 px to scroll. Whether a page of
  25 is the right size on a phone was not asked.
- **The lists that are not cards**: Projects, Users, Payables by age, Receivables and the 44 hand-written ones
  still scroll inside their frame on a phone (T-045).
- **The two pull requests together**, as in report 34.

## What comes next

By the plan (document 21): the shop's reading of its products is round 1 and is in pull request #48. The next
rounds that need nobody are the shop's database, its functions and the map of its tables (T-172, T-175), then
packages and the demo's housekeeping (T-071 and six more). The owner says which.
