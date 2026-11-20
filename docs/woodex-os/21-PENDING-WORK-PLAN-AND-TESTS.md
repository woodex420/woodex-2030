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
1. **105 lines of the list are not done.** 13 can start now and need nobody: they are planned in 8 rounds (§2). 29 wait for you (§3). 63 wait for an earlier line (§4), and 50 of those stand behind an answer of yours.
2. **The next round** is round 1: T-105, The shop reads products, categories and series from `/api/v1`.
3. **The answers of yours that the most lines stand behind:** T-006, Install the trial site on Hostinger (document 24) and send its address (20 lines) · T-003, Give Furniture's price list, terms, quotation wording and who signs (8 lines) · T-004, Say who may approve what, and each role's discount limit (4 lines) · T-008, Confirm the currency (PKR) and the tax rules (3 lines) · T-011, Decide product options, and the stock rule: from stock or made to order (3 lines).
4. **1 critical defect is open:** D-002, The owner password of the old dashboard was printed in a public document — T-021, which waits for you.
5. **Every line has its test.** A line that can start now says in §2 what is built, which tests are written, what a browser does, and what the tests are tried against. A waiting line carries the list's own "done when" (§4). §5 names the checks every round passes before it is merged.
<!-- /generated:short -->

---

## 1. The list in numbers

<!-- generated:numbers — written by scripts/verify-plan.mjs --write; do not edit by hand -->
|  |  |
|---|---|
| Lines on the list | 153 |
| Done | 48 |
| **Can start now, needing nobody** | **13**, in 8 rounds (§2) |
| **Wait for you** | **29** (§3) |
| Wait for an earlier line | 63 (§4): 50 stand behind an answer of yours · 5 stand behind lines that need nobody · 8 go with a wave, a date or an event |
| Not done, by priority | 24 P0, 48 P1, 25 P2, 8 P3 |
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
### Round 1 — The shop reads its products from `/api/v1`, behind a switch that stays off

**Lines:** T-105 · **Why here:** P1, and the owner's word of 19 November ("start as you recommend", DL-037). Website Studio and Marketing, 17 of the 36 destinations of Feature status that are not open, wait for this line and then for the page builder (T-115); the Orders screen waits for it too. It is the first address of `/api/v1` in pages and the first that hands out money, so the rules of T-063 are tried on it before anything else is built on them. · **When it is merged, these can start:** T-115 (The page-builder prototype (gate C)).

#### T-105 · P1 · large — The shop reads products, categories and series from `/api/v1`

- **Done when** (the list): The shop shows only what Laravel has published
- **Found on 20 November 2026:** Read in the code on 19 November; nothing of it is built. The shop reads its products from its own Node API (`/api/products`, and `/api/products/overrides` for changed prices and stock), which reads the shop's database: the 148 sample products (D-007). Categories, series and pictures it can already read from `/api/v1`, through two bridges of the Node API, behind a switch that is off. `/api/v1` has no address for products. Laravel keeps each of the 170 real products as a draft and a published copy; all 170 are drafts, and publishing one asks for a review, a category, a price, a description, the shipping and warranty texts, a picture with its description, and a preview seen by the person who publishes (D-009, T-090). The basket prices its lines from the sales catalogue by the shop's own product number, not from these products.
- **What is built:**
  1. `GET /api/v1/catalogue/products`, in pages, and one product by its address: only the published copy of a product that is not archived, never the draft. Prices as whole paisa with the currency, times in UTC, the filters the contract names (category, series, changed since) and no other. Written into `platform/resources/api/v1.openapi.json` and document 29 first.
  2. The pictures of a published product at an address of their own, as the categories' pictures are.
  3. A bridge in the shop's Node API and a reader in the React shop that turn the answer into the product the pages use today, behind a switch of the shop that stays off. With the switch off the shop reads exactly what it reads today.
  4. How a basket of products read from Laravel is priced: the round decides it, records the decision, and a test sends such a basket.
- **Tests written:**
  1. The contract test names the new routes, and each answer is compared with the contract key by key: success, a page beyond the last, a filter that is not allowed (400), a product that is not published (404), too many requests (429).
  2. A draft, a withdrawn product and an archived one are never handed out, by the list or by their own address; what is handed out is the published copy, also when the draft was changed after it.
  3. Money arrives as whole paisa; a product without a price is "on request" and carries no figure; a sale price is handed out only between its two dates.
  4. The shop's reader is run against the answers the contract documents, in CI's `build-and-test`, and against a real answer of Laravel on the rehearsal: the same name, price and pictures arrive in the product the pages use.
  5. With the switch off the shop asks Laravel nothing: its build and its existing scripts give what they gave before.
  6. After the merge the outside check asks the products address on the live demo.
- **In a browser:**
  1. On the rehearsal a product is published in the admin by a browser. The shop's bridge, pointed at that rehearsal, hands it to the shop's reader, and the product is shown with its name, price and picture. Withdrawn in the admin, it is gone from the shop.
- **Tried against:** With the draft handed out in place of the published copy, the test of the unpublished product names it; with the shop's reader left reading rupees where paisa arrive, its script names the price.
- **The way back:** The shop's switch stays off: the live shop reads what it reads today, and the round changes no setting of either Vercel project. The new addresses hand out only published products, and none is published on the demo. The Node catalogue is not closed in this round.

### Round 2 — What the merge of pull request #52 left to read from the live site

**Lines:** T-152, T-154, T-142 · **Why here:** Three small lines whose building is merged and whose proof can only come from the live site: Vercel's own network cannot be rehearsed, a cold start cannot be ordered, and GitHub starts a timer when it will. Nothing is built here; each line is closed by reading, and the readings are written down at the start of the next round. · **When it is merged, these can start:** no waiting line.

#### T-152 · P3 · small — Long caching for the built files: merged, to be read from the live site

- **Done when** (the list): One year, immutable
- **Found on 20 November 2026:** Built and merged with pull request #52: the eight built files with a marked name are sent with one year and `immutable` by a rule in the part of `vercel.json` that Vercel applies to a file it has found. The root test reads the rule against the build's manifest, and the rehearsal asks for each file through it. The live site was read before the merge, on 20 November at 13:55 UTC, when it still ran the old build: `max-age=0`. Vercel's own network cannot be rehearsed, so that it applies the rule is not proved until it is read from outside.
- **What is built:**
  1. Nothing more is built. The outside check from `main` requires one year on the stylesheet that the sign-in page names, and 404 without a long life for a built file with a marked name that does not exist.
- **Tests written:**
  1. The outside check's run after the merge of pull request #52, and the next timed run, are read: both must say one year for the stylesheet.
  2. If Vercel's network does not apply the rule, the check fails by itself and opens an issue. The first thing to try then is the flag `important: true` on the same rule: Vercel's own builder for Next.js sets it on its one-year rule in the same place (`@vercel/next` 31.0.1), and the routing schema calls it deprecated. The second is the rule as a route before the file is looked up, the older documented way, which may also reach a marked name that is no file. Vercel's `headers` setting is no way out while `vercel.json` uses `routes`: the two were not allowed together in the last version of Vercel's tool whose code can be read. Whatever is tried is rehearsed first.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** Done before the merge: with the rule taken out, the root test and the rehearsal fail; with the rule widened to every built file, both fail on `site.css`. On the branch the outside check read `max-age=0` and only noted it, because the live site was the old one.
- **The way back:** A file with a marked name never changes under that name, so a long life cannot serve an old file. One entry of `vercel.json` to take out.

#### T-154 · P3 · small — A cold start on Vercel: the mark is merged, three readings to take

- **Done when** (the list): Written into `13-VERCEL-DEPLOYMENT.md`
- **Found on 20 November 2026:** Built and merged with pull request #52: an answer for which the function had to be started carries the header `X-Woodex-Cold-Start: 1`, and the outside check times its first request and says whether it carried the mark. Read once before the merge, on the old build, which says nothing: 2.29 s to the first byte on 20 November at 13:55 UTC from a GitHub runner; warm it is 0.08 to 0.17 s. No cold reading with the mark exists yet.
- **What is built:**
  1. Nothing more is built. Each run of the outside check prints the time of its first request and whether that answer carried the mark.
- **Tests written:**
  1. Three readings that carried the mark, each with its date and where it was asked from, are written into `13-VERCEL-DEPLOYMENT.md` §9. A reading without the mark is a warm one and is not counted.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** Done before the merge: with the mark taken out of the entry file the rehearsal fails once; with the mark sent on every answer it fails twice.
- **The way back:** One header on an answer, and a line of output.
- **Not known yet:** How often the check meets a copy that has just started. After a merge it very likely does; a timed run may find the function warm. If three cold readings do not come by themselves within a week, the line says so and stays open.

#### T-142 · P2 · small — The outside check on a timer: moved to another minute, to be counted again

- **Done when** (the list): It runs every six hours from `main`; a forced failure opens an issue
- **Found on 20 November 2026:** Read on 20 November at 14:24 UTC: six timed runs since the timer came to `main` on 17 November, for thirteen times due. The times due at 06:23 and 18:23 UTC brought a run every time, 1 h 08 min to 2 h 43 min late; the times due at 00:23 and 12:23 UTC never. Four runs are green; the two of 18 and 19 November failed on a point of the check itself (a preview that Vercel had removed answered 410, where the check accepted only the sign-in: issue #46, cured with pull request #47). Of the last four times due, two brought a run, so the timer was written again with pull request #52: 03:47, 09:47, 15:47 and 21:47 UTC. Starting the check by hand is refused to this session (HTTP 403). The check after a merge has run every time.
- **What is built:**
  1. Nothing more is built. GitHub's list of timed runs is read at the start of every round, and how many of the times due brought a run is written into the list.
  2. If the new minute does no better after eight times due, the line says that GitHub does not keep a six-hourly timer for this repository, and the owner is asked whether twice a day is enough or another service should start the check.
- **Tests written:**
  1. Runs started by the timer are in GitHub's list for at least three of the last four times due at the new minute, and they are green.
- **In a browser:** nothing; this line has no screen.
- **Tried against:** Done on 17 November: a forced failure opened issue #38, and the next proof closed it.
- **The way back:** Nothing changes on any site.

### Round 3 — The dashboard

**Lines:** T-044 · **Why here:** P1. The first screen every person sees. In the order you set for the design work it comes before the areas (DL-008). · **When it is merged, these can start:** no waiting line.

#### T-044 · P1 · large — The dashboard as a widget framework, with the missing widgets

- **Done when** (the list): Each figure's link opens the same number of rows; a widget whose module is not here is absent
- **Found on 20 November 2026:** The dashboard is one component with one view: figures for a period, six live queues, what needs attention, the pipeline, new leads, recent activity.
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

### Round 4 — Without a mouse; help, pinned and recent screens

**Lines:** T-054, T-046 · **Why here:** T-054 is P1, and the accessibility review of gate A waits for it. The new entries of the top bar get their keyboard tests from the first day. · **When it is merged, these can start:** T-047 (Search finds a screen by keyword and by its old name).

#### T-054 · P1 · medium — Keyboard and screen-reader tests in a browser

- **Done when** (the list): The tests run with the journeys; sign-in, a lead and a quotation done by keyboard only
- **Found on 20 November 2026:** The search opens with Ctrl+K and answers to the arrow keys and Escape. The journeys press Ctrl+K once and Escape three times. No task is done by keyboard alone: the sidebar, the focus inside a dialog and the announcing of a refused field are untested.
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
- **Found on 20 November 2026:** The top bar has the search, "Create new", the colour theme, the notifications and the account. It has no help entry, and no pinned or recent screens. A person's record has no place for such preferences yet.
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

### Round 5 — Every shared part on one screen; a slow list and a lost connection

**Lines:** T-050, T-051 · **Why here:** Both waited for the shared table (T-041), and both are P2. The first is a criterion of gate A ("shared component examples"). The second finishes the five states: the table can say "loading" since 20 November and no screen says it. · **When it is merged, these can start:** no waiting line.

#### T-050 · P2 · medium — A reference screen showing every shared component and state

- **Done when** (the list): One screen under System, for owner and admin
- **Found on 20 November 2026:** No screen shows the shared parts side by side. The parts are 25 files in `platform/resources/views/components/admin` and the three parts of the table beside them; their rules are written there since 20 November (`README.md`). A new screen is a 69th destination: the registry of destinations, Feature status and the tests that count destinations change with it.
- **What is built:**
  1. One screen under System, for owner and admin: every shared part in each of its states, drawn by the part itself with sample values, never by a copy of its markup. Buttons, badges, cards, fields with and without an error, the empty state, the dialog, the toast, the page links, and the table in its five states.
  2. The destination is added to the registry with its permission, so that the menu, Feature status and the route agree.
- **Tests written:**
  1. The screen opens for owner and admin and is refused to the other roles.
  2. A test lists the files under `components/admin`: a part that is not drawn on the screen, and is not on a short written list of parts that cannot stand alone, fails it.
  3. The table's five states on the screen are read with the words and roles the component's own test reads.
- **In a browser:**
  1. The crawl opens it at 1366 px and 390 px: no console error, nothing wider than the window. It is looked at in the dark theme, and one picture of it goes into the report for gate A.
- **Tried against:** With one part taken off the screen, the test that lists the parts names it.
- **The way back:** A screen that changes no record. It can be taken out of the registry alone.

#### T-051 · P2 · medium — Placeholders while a list loads; a notice when the connection is lost

- **Done when** (the list): Seen in a browser with the network slowed and cut
- **Found on 20 November 2026:** No screen says "loading": a Livewire screen arrives with its rows, and no component is loaded late. `wire:loading` is written 117 times in 64 views, to dim rows or to stop a button being pressed twice. Nothing tells a person that the connection is lost: `wire:offline` is written nowhere and no script listens for it, so a press then does nothing and says nothing. The shared table has had a loading state since 20 November (T-041).
- **What is built:**
  1. A notice in the top bar while the browser is offline or the server does not answer, and that it is back when it is: said to a screen reader, and never laid over the form a person is typing in.
  2. A press that could not be sent says so, and what was typed stays.
  3. With the network slowed on the rehearsal, each list of the shared table is timed. A list that is slow there is loaded late and draws the table's loading state first; a list that arrives in time is left as it is, and the report names both kinds.
- **Tests written:**
  1. A test draws the notice and reads its words and its role.
  2. For each list that is loaded late: what stands there first is the table's loading state, and the rows that follow are the rows of before.
- **In a browser:**
  1. A journey step cuts the network with the browser's own switch, presses Save on a form, reads the notice, brings the network back and saves: nothing typed is lost.
  2. With the network slowed, the placeholder of a late list is seen and then replaced by its rows.
- **Tried against:** With the notice taken out, the journey step fails where it reads it.
- **The way back:** Views and one small script; the built files change. The notice can be taken out alone, and a late list made an early one again by one word.

### Round 6 — Lighter pages; nothing smaller than 12 px

**Lines:** T-052, T-049 · **Why here:** Both change the same shared parts: the menu, the top bar, the search. · **When it is merged, these can start:** no waiting line.

#### T-052 · P2 · medium — Page weight: a near-empty page under 60 KB of HTML

- **Done when** (the list): A near-empty page is under 60 KB of HTML
- **Found on 20 November 2026:** By the crawl's figures in the list (§17): a near-empty page is 144 KB of HTML, 15.6 KB compressed. About 110 KB of it is the menu, the search's templates and the icons, sent again with every page. The median page is 161 KB.
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
- **Found on 20 November 2026:** 19 places in 14 views, and 7 rules of the stylesheets, set text below 12 px: from 8 px (the line under the logo) to 11 px.
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

### Round 7 — The content policy without `eval`

**Lines:** T-072 · **Why here:** After the rounds that replace whole parts of the views (the table, the dashboard, the top bar, the menu), so that nothing is converted twice; and before the screen pass, which its test then holds to the strict form from the first view. · **When it is merged, these can start:** no waiting line.

#### T-072 · P2 · large — The content policy without `eval`

- **Done when** (the list): The policy has no `'unsafe-eval'`; the journeys and the crawl pass with nothing refused
- **Found on 20 November 2026:** About 200 Alpine expressions in the views: 21 `x-data`, 19 of them written out in the markup; 121 handlers of events; 30 `x-show`.
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

### Round 8 — The screen pass, area by area

**Lines:** T-045 · **Why here:** P1, and last all the same: it is the largest line, several rounds of its own, one for each area in the order you set (DL-008): E-commerce, CRM, Sales, then the others. Every round before it changes a part it builds on. · **When it is merged, these can start:** no waiting line.

#### T-045 · P1 · large — Screen pass, area by area: theme colours, page patterns, dark theme

- **Done when** (the list): Before-and-after screenshots of one screen per area at 1366 px and 390 px, light and dark
- **Found on 20 November 2026:** 3,109 uses of a grey written directly, in 145 views: the most in Sales, CRM and System. 1,807 dark-theme variants are written by hand. Colours written as numbers are mostly in the PDF and mail templates, which must carry their own; those stay as they are.
- **What is built:**
  1. In each area: greys move to the theme's roles; what is selected takes the theme's green; each page follows the patterns of document 05 §8; its lists use the table of T-041, and are drawn as cards on a phone (T-042) where people work the list from a phone.
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
| T-014 | P2 | Choose the WhatsApp and Telegram provider and account | Disconnected; click-to-chat links only | T-118 | 3 lines |
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
#### Behind an answer of yours (50)

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
| T-190 | P2 | The WhatsApp inbox takes the enquiry's turn before it asks who is writing | T-118 — Cannot happen today: the inbox is switched off and no WhatsApp account is connected (D-044). Done with the inbox's own acceptance (T-118), before it is switched on. | T-014 | `ReceiveWhatsAppMessage` takes the enquiry's turn before it asks; `tools/ci/two-writers.php` has a step that sends two messages of two strangers at once, and without the turn that step reports the fault |

#### Behind lines that need nobody (5)

| # | Pri | Task | Waits for | Stands behind | Done when |
|---|---|---|---|---|---|
| T-047 | P3 | Search finds a screen by keyword and by its old name | T-046 | T-046 (round 4) | "stock" finds Stock Ledger; "clients" finds Contacts |
| T-115 | P2 | The page-builder prototype (gate C) | T-105 — Building the prototype needs nobody. Accepting it is yours: gate C. | T-105 (round 1) | The owner accepts the prototype before any broad build |
| T-116 | P2 | Website Studio on Puck | T-115 | T-105 (round 1) | A page is edited, approved, published and rolled back |
| T-117 | P2 | Today's pages moved into the page builder | T-116 | T-105 (round 1) | Selected pages are approved; the old content is kept |
| T-120 | P2 | Marketing: campaigns, attribution, landing pages | T-116 | T-105 (round 1) | A lead shows the campaign it came from |

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
| Check | Where it runs | On 20 November 2026 | What it stops |
|---|---|---|---|
| The Laravel test suite | In the sandbox on SQLite; in CI on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4 (`os-ci.yml`), on every push that touches `platform/` | 2,772 pass, 36 skipped, none fails: run in the sandbox on 20 November, 14:28 to 14:36 UTC, on the code of pull request #52. In CI on `cc9b695`, on each of the three servers (MySQL 8.4.13, MariaDB 10.11.19, MariaDB 11.4.13): 2,773 pass, 35 skipped. Both on the packages as brought up to date on 20 November (Laravel 13.43.0) | A rule, a screen or a permission that no longer does what its test says |
| Two writers at the same moment: in the accounts, and two enquiries or baskets of the shop (`platform/tools/ci/two-writers.php`) | CI, job `two-writers` of `os-ci.yml`, on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4, on every push that touches `platform/`. In the sandbox on the server that `platform/tools/preview/sandbox/database.sh` builds (MariaDB 10.2) | 33 steps of 50 runs hold on each of the three servers; with a lock taken out, each of six checks reports the fault it is for (CI, `d93b112`). The four new steps send an enquiry and a basket as the shop sends them: one sent twice with its key, and two of two visitors at once | What the suite cannot see, because it calls one action after another: money written twice, a number given twice, an entry out of balance, an enquiry written twice, or one of two people stopped by the database with an error page |
| The contract of `/api/v1` (`platform/resources/api/v1.openapi.json`, held by `tests/Feature/Api/V1ContractTest.php`) | Part of the Laravel suite, so in the sandbox and in CI on the three servers. The file itself is read as OpenAPI 3.1 by a validator, by hand, when it changes | every route under `/api/v1` is in the contract, and each of the seven addresses answers key by key as the contract says, for every status it documents (CI, `d93b112`) | An address that answers in a shape its callers were not told; a route added without its contract; a key, a code or a header that is in the answer and not in the contract, or the other way round |
| The shared table: its five states, its headings, its cards on a phone, and the count of views that still write a table by hand (`platform/tests/Unit/AdminTableTest.php`, `platform/tests/Feature/SharedTableListsTest.php`) | Part of the Laravel suite, so in the sandbox and in CI on the three servers | 32 tests pass. 44 views write a table by hand; it was 51 before 20 November. Leads, Quotations and Invoices are cards below 640 px | A list that writes its own table again; a state without its words or its role; a heading that does not say how it sorts; a word printed into a script; a fact on a card without the word of its column; a rule for cards that reaches a wide screen, or that was written and not built |
| A release installed and deployed again by a browser (`scripts/os-install.mjs`) | CI, job `hostinger` of `os-ci.yml`: Apache with the website folder's own rules, PHP 8.4 as a hosting account, MariaDB 10.11. In the sandbox on PHP's own server and SQLite | the installation 9 of 9 steps, the next deployment 5 of 5; 60 addresses asked from outside, nothing of the application handed out (CI, `d93b112`) | A release that does not start on a host; an installer its owner cannot get through; a file of the application handed out by the web server; a deployment that loses the settings, the sign-in or a database change |
| Two journeys in a browser (`scripts/os-journeys.mjs`) | On the rehearsal of Vercel's runtime, in the sandbox and in CI: once with the demo's settings, once with the product's | 39 of 39 steps hold, with the demo's settings and as the product ships (CI, `cc9b695`, 20 November, on the packages as brought up to date). The last step is on a phone: no key list scrolls sideways at 390 px, and a lead is chosen, opened and moved from its card. CI runs them both ways on every push; its result for a merge is on the pull request | A step of a working day or of a sale that a person can no longer do; anything the content policy refuses |
| Every screen as four roles (`scripts/os-screens.mjs`) | The same rehearsal, at 1366 px and at 390 px | 253 pages at each of the two widths, none fails: no console error, no failed request, nothing wider than the window (CI, `cc9b695`, 20 November) | A screen that does not open, a console error, a link that leads nowhere, sideways scrolling, a field without a label, a control too small to touch |
| The deployment as Vercel builds it (`platform/tools/vercel/check-entry.sh`) | CI, job "Vercel's PHP runtime: build the demo, sign in, open every screen" | passes, 103 checks (CI, `cc9b695`). Since 20 November it also measures the function (188.4 MB; it fails above 240 and proves on each run that it can fail), asks for each built file through the one-year rule, and reads the cold-start mark on the first answer of a new copy | A site that starts without its settings, hands out a file, or loses the session |
| The React shop's gate (`ci.yml`) | CI, on every push | passes (CI, `cc9b695`, 20 November), for the first time on `react-router-dom` 7 | A broken build or type; a failing API or row-level-security test on PostgreSQL; a secret in the tree; the acceptance files, this plan, the map of the old tables or what the admin is told about the old system out of step with the repository |
| Every function of the old system's PostgreSQL schema has a fixed search path (`scripts/test-search-path.mjs`) | CI, in `build-and-test` on every push (a step of `scripts/ci-test.mjs`); in the sandbox with `npm run test:search-path`, in seven seconds. On the embedded PostgreSQL, which is 18.3; the live database is 17 | 59 checks pass (sandbox, 20 November; 57 of them in CI on `da09370`, before the two about a temporary table were added). On the live database, read on 20 November at 06:57 UTC: nine of nine functions have the path | A function that a later migration adds without a path, or rewrites with `CREATE OR REPLACE` and so loses it; a base schema file that takes the path off again when it is run a second time; a path that lets a temporary table stand in for a table of the books; a rollback that does not bring back what was there |
| The map of the old system's tables (`scripts/verify-table-map.mjs`, itself tried by `scripts/test-verify-table-map.mjs`) | CI, in `build-and-test` on every push. The live database is read by hand, reading only, when the map is brought up to date | 73 rows: 29 move, 35 wait, 9 stay; the map agrees with the old schema files, the admin's migrations, its menu, the master list and document 36. The check fails in each of the 29 mistakes made for it (CI, `da09370`). The live database was last read on 20 November at 06:58 UTC | A table of the old system without a place in the admin; a target table that no migration creates; a line or a wave that is not the list's; a screen that is not in the menu; a document that says something else than the file |
| What the admin is told about the old system: its shape, and the rules an importer follows (`scripts/verify-old-shape.mjs`, itself tried by `scripts/test-verify-old-shape.mjs`) | CI, in `build-and-test` on every push. In the sandbox the check takes four seconds and its test about 35 | 73 tables: the key is one number in 65, one text in 6, two columns in 2. 42 columns list their words (185 words); 13 of them are in tables that move, and of their 64 words 50 have a place in the admin and 14 a written reason for none. 32 unique columns have a rule. 35 names for the old numbers. Both files agree with the old schema, the old API's code and the map, and document 36 §8 and §9 show them. The test of the check holds 64 of 64 (sandbox, 20 November; in CI on the commit that carries report 38) | A migration of the old system that adds a table, a key or a word without the admin being told; a word of a table that moves with neither a place nor a reason; a word sent to a value the admin's list does not have; a rule about a column the old system does not have; document 36 saying something else than the two files |
| A value of the old system on each database server, and the old number of a record (`platform/tests/Feature/OldSystem`) | Part of the Laravel suite, so in the sandbox on SQLite and in CI on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4 | 66 tests pass on each of the three servers (CI, `fc9c5bc`) and in the sandbox. Each kind of value is written to a table of the admin and read back; where the servers differ, what each does is pinned. Tried against 38 mistakes, each noticed (report 38) | A server that treats a time, a long text, a JSON block or two spellings of one value otherwise than pinned; money rounded instead of refused; a time handed over on the wrong clock; a word of the old system without a place; a unique column of a table an importer writes to without a written rule; a file that writes `legacy_refs` beside the one class; an importer's name that could meet another's |
| Known weaknesses in the packages (`audit.yml`) | CI, when a lock file changes and every Monday | green on `cc9b695` (20 November, the run of pull request #52). Composer: no advisory, every package named in `composer.json` at its newest version. npm: one advisory, accepted with its reason on the list; 64 packages named in a `package.json` have a newer version. Before the update the same audit failed on the branch and named Laravel's advisory, as it must | An advisory of any severity that is not on the written list (`docs/woodex-os/acceptance/advisories.json`), an entry of the list without a reason, an entry that nothing reports any more, an advisory whose severity has risen |
| What the admin's code names can be looked up (`scripts/verify-code-references.mjs`, itself tried by `scripts/test-verify-code-references.mjs`) | CI, job `build-and-test` of `ci.yml`, on every push | 1,065 names in 624 files: 47 decisions, 119 requirements and the studio's three documents are looked up; 22 other documents of the studio, five decisions the studio never wrote down and four names that only look like a requirement code are listed by hand, each with its reason. The three documents have the checksums of the studio's files. The test changes one thing at a time in a copy, 24 times, and each is noticed; three more checks must pass, and do (CI, `cc9b695`) | A comment that explains the code by a decision, a document or a requirement nobody can read here; one of the studio's documents edited |
| The live sites from outside (`live-check-after-merge.yml`) | GitHub, eight minutes after every merge into `main` | after the merge of pull request #45 one point of the shop's fifteen failed: a preview that Vercel had removed answered 410, where the check accepted the sign-in or 404 (run 44343060920, issue #46); cured in `6b3789d`, and the shop's 16 points hold on the branch (run 44455587053, 19 November, 04:02 UTC). The admin's points held in every run. Since 19 November the admin is also asked six points of `/api/v1`, which can only hold once pull request #47 is on `main` | A live site that does not answer as it should after a merge. A failure opens an issue |
| Each new test, tried once against its protection taken away | By hand, when the test is written; recorded in the round's report | done for the security, policy and request-number tests of 17 November, for the journey steps of 18 November (report 27), for the two-writers command and its tests (report 28), for the contract test, the idempotency key and the enquiry's turn (report 30), for the shared table and its count (report 34), for the cards on a phone, in the tests and in the journey (report 35), for the search-path suite and the check of the table map (report 37), and for the conversions, the old numbers of records (38 mistakes, each noticed) and the check of the two files they read (report 38); for the one-year rule, the size of the function, the cold-start mark, the list of accepted advisories and the check of what the code names (report 39) | A test that would pass whatever the code does |
| Before the merge: the files changed against `main` are read, and every check is green | By hand; the release report is written first | done for pull requests #37, #39, #40, #47, #49, #50, #51 and #52 (the shop's changed files are named in report 30 and, for #52, in report 39; #49, #50 and #51 change none) | A change to the React shop that the report does not name; a merge with a check still red |
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
