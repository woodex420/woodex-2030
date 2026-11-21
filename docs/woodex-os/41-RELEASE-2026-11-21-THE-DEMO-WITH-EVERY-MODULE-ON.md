# WOODEX OS — release report, 21 November 2026: the demo with every module on

The report of pull request #55, in the ten points of document 10 §9, written **before** the merge (document 16 §8;
the lesson of defect D-014).

**On whose word.** The owner's message of 21 November, about 12:24 UTC: "contine". It was read as: go on where the
last turn stopped, at the merge of pull request #53. The merge stands on his answer that the agent merges into
`main` when every check is green (DL-026). What the agent decided on the way is DL-045.

**Why this round.** Before the next round of the plan, what the live sites said after that merge was read. The
check that asks the hosted workspace from outside had failed at 11:49 UTC and had opened issue #54. A failed live
check comes before planned work.

**What this round is.**

| What | What is so now | State |
|---|---|---|
| Issue #54: three files of the application answered a redirect | A file's name is never sent to a page's address: it is "not found" at once, whatever is switched on | Cured; proved on the rehearsal. On the live site after the merge |
| The demo ran for four hours with settings that nothing had rehearsed | One file says what the Vercel project sets. CI rehearses the demo with it, and the outside check fails when the live demo does not run with it | Built |
| Two faulty screens of the Website Studio, seen for the first time with every module on | The Forms screen's dead link, and the page builder on a site without pages and on a phone | Cured |
| T-154 | Three cold starts read from the live site: 3.89 s, 2.55 s and 2.52 s to the first byte | **Done** |
| T-142 | The timer brought two runs for four times due | **Open** |

**What it is not.** Nothing was changed on Vercel: the two settings that were found there stand as they were found.
No table of any database changed, and no record. No file of the shop changed. Nothing was written on Supabase or
on Hostinger. The next round of the plan (T-054, T-046) is not in this pull request.

## What was found

### On the live sites, after the merge of pull request #53

| When (UTC, 21 November) | What |
|---|---|
| 05:55 | Pull request #53 merged: `86db34d` |
| 06:02 | Every check of `main` green. The release for the host published: branch `hostinger-release` at `4a43382`, 10,850 files |
| 06:04 | The outside check after the merge: 52 of 52 for the hosted workspace, 15 of 15 for the shop. The eight files it asks by name: all "not found" |
| 07:12 | **On Vercel:** the demo owner's password (`WX_PW_OWNER`) is read |
| 07:32 | **On Vercel:** `WOODEX_MODULES=*` is added to the project `woodex-os`, for production and preview. At 07:33 production is deployed again from the same commit |
| 07:50 | **On Vercel:** `LEGACY_DASHBOARD_URL=https://woodex-2030.vercel.app/dashboard` is added. At 07:51 production is deployed again |
| 11:49 | The outside check, started by the timer 2 h 02 min after it was due: 49 points hold, **3 fail**. `/.env` and the two files of the demo's database answer 301. Issue #54 is opened |
| 12:24 | The owner: "contine" |

### On Vercel

Read from Vercel's own record of the account's activity at 12:26 UTC. The five entries of 07:12 to 07:51 UTC (a
setting read, two settings added, two deployments) each carry the mark of one and the same access token of the
account `woodex420`. A deployment that a push starts carries none. So
they were made through Vercel's interface for programs, by somebody who holds a token of the owner's account:
the owner with a tool, or an assistant of his in another conversation. **They were not made by this
conversation**, which wrote nothing on Vercel, and no commit of the repository asked for them.

Both settings are ones the entry file reads on purpose. A `WOODEX_MODULES` set for the project takes the place of
the demo's own choice of modules, and `LEGACY_DASHBOARD_URL` is the address the admin links its old-dashboard
entries to. Their values are right for what they do: `*` means every module, and the old dashboard is served under
`/dashboard` of the shop's address. The decision log had recorded both as not done: "nothing was switched on", and
the link "proposed and **not done**" (DL-035 (3), 18 November).

**The agent took both for decisions of the owner and changed neither.** He is asked to say that they were his. If
they were not, somebody else holds a token of his Vercel account, and with such a token the settings of both
projects, the shop's among them, can be read and changed.

### Why the check failed

With the public website switched on, its router owns every address that nothing else does. Its first rule was:
an address without a slash at its end is sent, for good, to the same address with one. It applied that rule before
it looked whether anything was there. So `/.env` was sent to `/.env/`, and only that second address was "not
found". Nothing of any file was handed out, on the live site or anywhere.

The outside check asks for files of the application by name and takes anything but "not found" or "forbidden" for
a fault. It is right to: a redirect on a settings file says that something behind that name is worth following.
Until 07:33 UTC the website was off in the demo, and every such address was "not found" at once.

The same fault stood in the product for any installation that switches the website on. On a host, the web server
refuses dot-files before Laravel is asked; the files of the application outside its public folder were redirected
there too.

## What was built

### A file's name is never sent to a page's address

A page of the website is a folder: its file is `public/<address>/index.html`. So no part of a page's address has
a dot. An address with a dot names a file: `/.env`, `/composer.json`, `/config/app.php`, `/database/demo.sqlite`,
`/wp-login.php`.

The router (`platform/app/Domain/Website/Services/SiteRouter.php`) now gives the form with a slash only to an
address that could be a page. An address that names a file is "not found" at once. The same holds with
`?preview=1`, which has a redirect of its own for a page.

Two things stay as they were:

- **A redirect that the owner enters for an old address is honoured**, also when the old address was a file
  (`/services.html`). It is now answered in one step, where it took two.
- **A name without a dot is still treated as a page's**: `/artisan` is sent to `/artisan/`, which is not found.
  It cannot be told from the address of a page called "Artisan".

### The repository says what the project on Vercel sets

`platform/tools/vercel/as-deployed.conf` holds the two settings, with where they came from. Three checks read it:

1. **The rehearsal that a browser works on in CI** ("The demo as it is deployed") starts the demo with these
   settings. It ran with the demo's own choice of modules, which is not what is live since 07:33 UTC. With every
   module on it opens 390 pages as four roles, where it opened 335.
2. **The check of the entry** (`platform/tools/vercel/check-entry.sh`, a new section 7) serves the demo with these
   settings and asks for 18 files of the application by name. None may be handed out, and none may be offered
   another address.
3. **The outside check** (`.github/workflows/vercel-live-check.yml`) reads the file and asks the live site for an
   address that nothing owns and that could be a page. With the website on, that is a redirect to the same
   address with a slash; with it off, "not found". **When the live site and the file disagree, the check fails
   and names the file.** It also asks for two more files by name: a file of the configuration and the history's
   settings, ten in all.

When a setting is changed on Vercel, the file is changed in the same hour. A setting that is taken away there is
taken out of the file.

`platform/tools/vercel/serve-demo.sh` hands `LEGACY_DASHBOARD_URL` on to the demo it serves, as it did
`WOODEX_MODULES`, so that the demo can be served on a development machine exactly as it is deployed.

### Found with every module on, and cured

The crawl opened the demo with every module on for the first time: 391 pages, **5 not fine**, all in the Website
Studio, a module the product ships switched off.

1. **The Forms screen linked to an address that nothing owns.** Its two links to "Automations" named
   `/admin/automations`; the screen is `/admin/automation`. They opened "This page does not exist".
2. **The page builder asked for a home page on a site that has none.** This product ships no page of a website.
   The builder asked for `index.html`, was refused, and showed the refusal as a red error. It now asks for
   nothing where there is no page, and the canvas says: "This site has no pages yet, so there is nothing to edit
   here."
3. **On a phone the page builder was 419 px wider than the window.** It is a desktop tool: a page between two
   panels, in four columns. Below 760 px it now says that it needs a wider screen, with a way back.

A new test reads every address of the admin that the Website Studio's scripts name, and asks that each is a
screen or an address of the admin.

## What a person sees differently

In the product as it ships: nothing. Every change is in a module that ships switched off, or in what an address
that names a file is answered.

On the hosted workspace, where every module is on:

- **Website → Forms:** the two links to "Automations" open Automations.
- **Website → Page builder:** the canvas says that the site has no pages yet, in place of a red error. On a phone
  the screen says that the builder needs a wider screen.
- **A visitor who asks for a file of the application by name** gets "not found" at once.

The two changes of the Website Studio are on the screen under Feature status, as a release note of 21 November
headed "where it is switched on".

## What this merge changes on each live site

| Site | What changes |
|---|---|
| The shop, `woodex-2030.vercel.app` | Vercel builds it again from `main`. No file of the shop changed |
| The shop's database (Supabase) | Nothing. It was not read in this round |
| The hosted workspace, `woodex-os.vercel.app` | Vercel builds it again, with the settings that stand on the project: every module on, the old dashboard linked. The three cures above go live. Issue #54 can be closed when the check after the merge passes |
| Hostinger | Nothing is installed there. CI writes the release branch from `main` |
| Vercel's settings | Nothing. The agent wrote nothing on Vercel |
| GitHub | The outside check has two more files to ask for and one more point: whether the live demo runs with the modules that were rehearsed |

## 1. Commit/branch/build identifier

Branch `arena/db67c5a5-woodex-2030`, from `main` at `86db34d`. Pull request #55.

| Commit | What |
|---|---|
| `effb443` | The router and its fallback; `as-deployed.conf`; the rehearsal in CI, the check of the entry and the outside check; the three cures in the Website Studio; 23 tests; DL-045 |
| the commit after it | This report and the records of the round: the master list, the plan, documents 13 and 21, the index of documents, four pictures; and the release note that Feature status shows. Beside that note it changes no file of the application |

15 files in `effb443`: 2 workflows; 3 files of the application (the router, the routes, the builder's view); 3
files the browser loads (the Forms script, the builder's script and stylesheet); 3 test files; 3 files of the
rehearsal, one of them new; the decision log. No migration, no setting, no package, no built file. The commit
after it adds the release note (`platform/app/Support/ReleaseNotes.php`) to the records.

## 2. Environment

- Sandbox: PHP 8.5 on SQLite for the suite; Vercel's own PHP 8.5 runtime and launcher for the rehearsal; Chromium
  for the journeys, the crawl and the pictures.
- CI (`os-ci.yml`): PHP 8.4 on **MySQL 8.4.13, MariaDB 10.11.19 and MariaDB 11.4.13**.
- CI (`vercel-livewire.yml`): a browser on the rehearsal of Vercel's runtime, on the demo as it is deployed and as
  the product ships.
- CI (`ci.yml`): Node, the embedded PostgreSQL and a browser, for the shop.
- The live sites: read by GitHub's outside check at 06:04, 11:49 and 13:10 UTC on 21 November.
- Vercel: its lists of deployments, of the project's settings and of the account's activity, read at 12:26 UTC.
  Reading only. The values of the four demo passwords were not read.

## 3. Test command and result

Every result below is of commit `effb443`.

| What | Where | Result |
|---|---|---|
| The Laravel suite | CI, each of the three servers | **2,833 pass, 36 skipped.** Before the round: 2,810 pass |
| The same | Sandbox, SQLite, 21 November, 13:03 to 13:08 UTC | **2,833 pass, 36 skipped, none fails** |
| Both journeys, on the demo as it is deployed (every module on) | CI and sandbox, a browser on the rehearsal | **40 of 40** steps |
| Both journeys, on the product as it ships | CI | **40 of 40** steps |
| Every screen as four roles, on the demo as it is deployed | CI and sandbox | **390 pages** at 1366 px and at 390 px: none fails, nothing is wider than the window, no console error |
| The deployment as Vercel builds it (`check-entry.sh`) | CI | Passes: 130 points, where it had 103. The function is 188.6 MB in 11,258 files |
| The same, without installing the packages anew | Sandbox | 125 of 125 |
| The outside check, run from the branch against the live sites | GitHub, 13:10 UTC (run 44907513806) | Passes: 39 points hold, 16 are written down as "after the merge", none fails. See below |
| Two writers at the same moment | CI, each of the three servers | 33 steps of 50 runs hold |
| The release for a host, installed and deployed again | CI, Apache and MariaDB 10.11 | 9 of 9 and 5 of 5 steps; 10,850 files, 103 MB |
| The shop's gate | CI, `build-and-test` | Passes. The round changes no file of the shop |
| The documents' links and numbers, the secret scan, the plan, the acceptance files, what the code names | CI and sandbox | Pass |
| The code style (Pint) on the 5 changed PHP files | Sandbox | Passes |
| Vercel's own build of this branch, as a preview | Vercel, both projects | Built. **The preview was not opened**: the sandbox reaches no address of Vercel |

**The 23 new tests.** Fourteen files of the application are asked by name, of the router itself and through the
whole application, with and without `?preview=1`: each is "not found", and no answer names another address. Five
addresses that could be a page are still sent to their form with a slash. An old address that was a file is
answered by its redirect in one step. The page builder opens on a site without a page and asks for none, and says
on a narrow window that it needs a wider one. Every address of the admin that the Studio's scripts name is a
screen or an address of the admin.

**What the outside check said from the branch.** It ran against the live site as it stood at 13:10 UTC, which
still runs the code of `main`. Five of the ten files it asks by name answered "301 to itself with a slash"; from a
branch that is written down and not counted, because only the merge can change the live site. It read
`WOODEX_MODULES=*` from the file and found the live demo running so: the address that could be a page was sent to
its form with a slash. **That is the first reading from outside that every module is on at
`woodex-os.vercel.app`.**

**Each new check, tried against its protection taken away:**

| The check | What was taken away or changed | What happened |
|---|---|---|
| The router's tests | The router as it was on `main` | 15 tests fail: the 14 files, and the old address that was a file |
| The same | The router cured, the fallback's own redirect for `?preview=1` as it was | 14 tests fail: each file with `?preview=1` is sent to itself with a slash |
| The check of the entry | The router and the fallback as they were on `main` | 12 of 125 points fail: eleven files answer 301 with the project's settings, and so does `/.env?preview=1` |
| The outside check's files | The same old code served with every module on, read as `main` | 5 of the 10 files fail, the three of issue #54 among them. Read as a branch: the five are written down, none is counted |
| The outside check's question about the modules | The demo served with its own choice of modules, while the file says every module | Fails: "the live demo runs with the modules that were rehearsed: the public website is on … (expected on, got off)" |
| The test of the Studio's addresses | The old address put back into the Forms script | Fails |
| The crawl, on the demo as it is deployed | The code of `main` | 391 pages, 5 not fine: the page builder at both widths as two roles, and the dead link |

## 4. Browser/device coverage

- **CI's browser** ran both journeys and opened every screen as four roles (owner, sales manager, sales,
  accountant) at 1366 px and at 390 px, on the demo with every module on: owner 186 pages, sales manager 83,
  sales 66, accountant 55. It then ran both journeys on the product as it ships.
- **The agent looked** at Feature status, at the Forms screen and at the page builder at 1366 px and at 390 px,
  on the rehearsal with the project's settings.
- **The same on a folder that cannot be written**, as the function's folder on Vercel cannot, run once in the
  sandbox (21 November, 13:26 to 13:37 UTC): the journeys hold 40 of 40, and all 390 pages open without an error.
  CI's browser works on a folder that can be written.
- Chromium only. No other browser, no real phone, no screen reader (T-054).

**Counted by the crawl with every module on, and not failures.** They are in modules the product ships switched
off, and they are for the keyboard and screen-reader round (T-054) and for each module's own wave:

- fields without a label on 7 pages as the owner: the page builder, Blog, Portfolio, Redirects, the new
  estimation (twice) and one quotation;
- one picture without a description, and one link of the page builder that leads nowhere ("View page", while no
  page is open);
- four screens of the Website Studio that do not carry the notice that nothing typed is kept (the page builder,
  Blog, Portfolio, Forms), and two without a first heading.

## 5. Defects open/closed by severity

44 on record, 35 closed: as before. Open are 1 critical (D-002, the old dashboard's owner password: T-021, the
owner's), 7 medium and 1 low (D-033, the timer).

**Found in this round, all cured in this pull request, each held by a test or by the crawl:**

| What | Severity |
|---|---|
| With the public website on, an address that names a file of the application was answered with a redirect to itself | Medium. Nothing was handed out |
| The Forms screen of the Website Studio linked to an address that nothing owns | Low |
| The page builder asked for a page that is not there and showed the refusal as an error | Low |
| The page builder was 419 px wider than a phone's window | Low |

**Still owed to the register:** these four; the six of report 40; the time of a WhatsApp message that would be
kept five hours early (medium; cannot happen today, and not cured); and "newest first" from report 34. The
register allows no gap in its numbers, and the next free ones (D-045 to D-052) are taken by pull request #48,
which is not merged (DL-044, point 15).

**D-002 is more urgent than it was.** The old dashboard's owner password was printed in a public document and is
not changed. Since 07:51 UTC the public demo links every visitor of Feature status to that dashboard's sign-in.
The link gives nobody the password, and the dashboard's address was public before. But it is one more place that
points there.

## 6. Migration reconciliation

Nothing. The admin keeps its 109 migrations and 126 tables. No migration is in this pull request.

## 7. Backup/restore status

Nothing was written to any database, so nothing had to be backed up. That no backup of the shop's database is
known to exist (T-187, the owner's) is unchanged.

**The way back** is to revert the merge. No record, no table and no setting has to be put back.

## 8. Performance observations

- **A cold start on Vercel: three readings, and line T-154 is done.** 3.89 s (20 November, 22:22 UTC), 2.55 s
  (21 November, 11:49 UTC) and 2.52 s (13:10 UTC) to the first byte of the sign-in page, each from a GitHub
  runner, each marked by the entry as an answer for which the function had to be started. Later requests of the
  same runs took 0.11 to 0.18 s. A check eight minutes after a merge met a running copy both times it was read.
- **With Conversations on, every screen asks the server again every 10 seconds while it is in front**: 182 of the
  owner's 186 pages carry that timer, beside the one of 30 seconds they had. On Vercel each asking is one more
  request for the function. Nothing was measured on Vercel.
- **A file's name costs one question to the database**, the look for a redirect, where it cost a redirect and
  then the same question.
- **On the rehearsal with every module on**, the first byte came after 56 to 60 ms in the middle and within 77
  to 83 ms for 95 pages in 100, as with the demo's own modules.
- **The function: 188.6 MB of the 250 allowed**, one small file more.
- **The browser job in CI took 13 minutes** (12 min 57 s and 13 min 36 s), where it took 12: the crawl opens 55
  pages more.

## 9. Screenshots or recording of key flows

Four pictures, taken on the rehearsal with the project's settings, and looked at. All are in `evidence/`:
`demo-2026-11-21-feature-status-every-module-on.jpg` (Feature status: 52 available here, 13 in the old dashboard,
none switched off, 3 not built; the menu with Conversations, Chat Bot and Estimations in it),
`demo-2026-11-21-builder-before.jpg` (the page builder on the code of `main`: the red "There is no page at / yet"),
`demo-2026-11-21-builder-no-pages.jpg` (the same screen with the cure) and
`demo-2026-11-21-builder-on-a-phone.jpg` (390 px: "The page builder needs a wider screen").

## 10. Explicit owner approval status

- Merging when every check is green: approved (DL-026).
- Going on: the owner's word of 21 November, "contine".
- **Every decision of this round is his to overrule** (DL-045). What he is asked:
  - **Were the two settings on Vercel his?** `WOODEX_MODULES=*` at 07:32 UTC and `LEGACY_DASHBOARD_URL` at
    07:50 UTC, with an access token of his account. If not, the token should be withdrawn on Vercel at once.
  - **Is every module to stay on in the demo?** Thirteen of the twenty screens that came on run a website that
    this product does not have, and they are empty. The other seven each wait for one thing of his: Furniture's
    rates, a WhatsApp or Telegram account, a Facebook Page (DL-035 (3)). To switch on only what can work, the
    setting would name those modules in place of `*`. The agent changes it on his word, on Vercel and in the
    file.
  - **Change the old dashboard's owner password (T-021).** The demo now links to that dashboard.
- **His words answered none of the questions that were open before.** They stand: pull request #48, which waits
  for his look; where the system that keeps data is to run; the price list, a messaging account and a Facebook
  Page; whether the two old previews of the shop are to be deleted; whether the facts on a card are the right
  ones (report 35); the proposals of the map (report 37) and of the words (report 38); whether the handful of
  records in the live database are trials; and the decisions of report 40 about the dashboard.
- **One question of the earlier reports is settled by what was found:** whether Feature status may link to the
  old dashboard. It does, on the hosted workspace.
- No module is accepted and no gate is passed.

## What is not proved

- **The cure on the live site.** The live site runs the code of `main` until the merge. The check after the merge
  asks the ten files by name; issue #54 is closed when it passes.
- **Who set the two settings.** Vercel's record names the account and a token, and no person.
- **The twenty screens that came on, when a button of theirs writes a file on Vercel.** The function's folder on
  Vercel cannot be written; only `/tmp` can. Opening every screen on such a folder was tried once in the sandbox
  and holds (§4). Pressing is another matter: saving or publishing a page in the builder writes into the public
  folder, which cannot work on Vercel, and no check presses those buttons. This product has no such pages to
  save.
- **The preview that Vercel built of this branch.** It was built; nobody opened it.
- **A screen reader**, and four of the eight roles in a browser (as in report 40).
- **The live demo, signed in.** The outside check does not sign in (T-151, the owner's).
- **Hostinger.** Nothing is installed there.
- **The two pull requests together.** Pull request #48 changes the same records and already conflicts with
  `main`.

## What comes next

By the plan (document 21), with nobody needed: the shop's reading of its products is built in pull request #48
and waits there for the owner (T-105); the timer is read again at the start of the next round (T-142); then the
keyboard journey and the help, pinned and recent screens of the top bar (T-054, T-046).
