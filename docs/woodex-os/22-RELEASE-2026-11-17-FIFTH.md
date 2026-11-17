# WOODEX OS — release report, 17 November 2026, fifth merge

The report of pull request #41, in the ten points of document 10 §9, written **before** the merge (document 16
§8; the lesson of defect D-014).

**On whose word.** The owner's message of 17 November, 19:29 UTC: "Next create list panding task plan test". It
was read as: next, make the list of the pending tasks, with a plan and the tests (DL-032). The merge itself
stands on his answer that the agent merges into `main` when every check is green (DL-026).

**What this round is, and what it is not.** It is a document, a check and the records. Nothing was built: no
screen, no rule and no setting of either site is different after it. The next line of the list (T-043, with
T-086) was not started; "start next" begins it.

## What this merge changes on each live site

Compared file by file, `dfc8369` (today's `main`) against the head of the pull request: one workflow (`ci.yml`),
two new scripts (`scripts/verify-plan.mjs`, `scripts/test-verify-plan.mjs`), `AGENTS.md` and the documents under
`docs/woodex-os/`. **No file of the Laravel admin is changed** (`platform/`: none), and **no file of the React
shop, of its API or of its dashboard** (`apps/`, `api/`, `vercel.json`, `package.json`, `package-lock.json`:
none). The two new scripts read three documents and a file of the plan; the shop's build does not use them.

| Site | What is different after the merge |
|---|---|
| **The hosted admin demo**, `woodex-os.vercel.app` | Rebuilt by Vercel from `main`, from the same files of the admin as today. Nothing changes for anybody |
| **The React shop**, `woodex-2030.vercel.app` | Rebuilt by Vercel from `main`, from the same shop files as today. Nothing changes for a visitor or for staff |
| **The shop's database** (Supabase) | Nothing. It was read once, at 20:04 UTC, for the plan: the names of its tables and functions and the count of its ledger rows. No row of a business table was read and nothing was written |
| **Vercel** | Nothing. The names of the four settings of the shop's project were read, to see whether the shop's forms are switched to the Laravel API: they are not. No secret was decrypted |
| **GitHub** | The shop's gate (`ci.yml`) has one more step: the plan of the pending work must agree with the master list, and the check itself is tried. From this merge on, a push that leaves the list, the plan and document 21 in disagreement fails |
| **Hostinger** | Nothing |

**The way back.** Putting the merge back removes a document, two scripts and one step of CI. No site and no data
is concerned.

## 1. Commit/branch/build identifier

| | |
|---|---|
| Branch | `arena/70e3b81e-woodex-2030`, pull request #41, from `main` at `dfc8369` |
| Commits | One: the plan file, the check and its test, document 21, the records and this report |
| Merge commit | Not known when this was written. It is on pull request #41 |
| Live now | Shop: Vercel deployment `dpl_H5V5BWfzsHEaGUPzmamBFto3xD6Y`, ready at 19:16:14 UTC. Admin demo: `dpl_AykSQj6z8emJA3CPjmHoCuueXeVz`, ready at 19:16:51 UTC. Both from `dfc8369`, the merge of pull request #40 |

## 2. Environment

| Where | What ran there |
|---|---|
| Sandbox | PHP 8.5 (Vercel's own build, from npm), SQLite, Node 22. It reaches GitHub and npm only: not the live sites. Built at 19:32 UTC by `bash platform/tools/preview/sandbox/setup.sh` |
| GitHub Actions | The shop's gate with the new step. The Laravel suite on MySQL, the rehearsal and the browser do not run for this pull request: their workflows start only when a file of the admin or of its browser scripts changes, and none does. They last ran on `dfc8369`, all green |
| GitHub, read | The runs and their findings on `dfc8369`; the list of timed runs; pull request #29 and its comparison with `main` |
| Vercel, read | The names of the settings of the project `woodex-2030` |
| Supabase, read | One query on the database's own catalogue, 20:04:04 UTC |

## 3. Test command and result

| Command | Result |
|---|---|
| `node scripts/verify-plan.mjs` | Passes. 145 lines on the list: 29 done, 19 can start now, 69 wait for an earlier line, 28 wait for the owner. The 19 stand in 10 rounds; each names what is built, its tests, what the tests are tried against and the way back. 116 lines that are not done have a short title |
| `node scripts/test-verify-plan.mjs` | **24 of 24 refused.** It copies the check and the files it reads into a temporary folder, changes one thing at a time, and requires the check to fail and to name the reason: a line that can start taken out of its round; a line marked done, or waiting again, and still in a round; the same line in two rounds; a round's line with no test, nothing built, nothing its tests are tried against, no way back or nothing found; a title missing, kept for a done line, or too long; a waiting line that waits only for a done line, for a line that does not exist, or for nothing named; a status that is none of the four; rows out of order; a row twice; the list's numbers changed by hand; "Order of work" leaving a line out or naming one that cannot start; a block of document 21 changed by hand; a mark of a block damaged |
| That test, tried in its turn | With each of four rules taken out of the check, one at a time, the test goes red and names the case. So it does not pass whatever the check does |
| The Laravel suite, `sh tools/preview/sandbox/run-suite.sh`, on SQLite, on `dfc8369` | 2,484 passed, 36 skipped, 0 failed (14,695 assertions), 19:32 to 19:39 UTC. Run to know the ground the plan stands on; this pull request changes no file it reads |
| `node scripts/verify-acceptance.mjs`, `npm run docs:verify`, `node scripts/secret-scan.mjs` | Clean |
| What the plan says about the code and the sites | Counted or read on the day, not taken from memory. Each figure of "Found" in document 21 was read from the views, the routes, the migrations, the catalogue file, GitHub, Vercel or the database; where a figure is an older measurement of the list or of document 13, the line says so |

The checks of the commit that carries this report are on pull request #41. The merge waits for all of them.

**Not tried, because nothing they test is changed:** the two browser journeys and the crawl of every screen in
the sandbox. Their last run is CI on `dfc8369`: 28 of 28 steps hold, both ways, and 248 pages open as four roles.

**What the outside check answered after merge #40**, which report 20 had to leave open: asked on the live demo at
19:21 UTC (run 44156136251), a content policy is sent and a script needs the number of that one answer; the
policy lets no script in from elsewhere and refuses plug-in content; an answer carries a request number, and the
error page shows the same one. 41 questions to the demo and 14 to the shop, and all hold. Still not tried on
Vercel's own network: the signed-in screens (T-151), and what the log holds there.

## 4. Browser/device coverage

None in this round: no screen is changed. Document 21 says for each planned line what a browser will do, at
1366 px and at 390 px. Not covered by any check today, as before: Safari and Firefox; a real phone; a screen
reader and the keyboard alone (T-054, planned as round 7).

## 5. Defects open/closed by severity

33 in the register (document 16 §5). Not closed: 1 critical, 0 high, 6 medium, 4 low. Closed: 22.

| In this pull request | |
|---|---|
| New | D-033 low: the check of the live sites has never started by its timer. Two times due passed on 17 November, and GitHub's list holds no timed run of any workflow. The check after every merge has run each time (T-142) |
| Made exact | D-029 low: read from the database itself, it is nine functions and one aggregate without a fixed search path, none belonging to an extension |
| Open as before | D-002 critical: the old dashboard's password was printed in a public document (T-021, the owner's step) · D-011 medium: no backup known (T-187) · the others of document 16 §5 |
| Found, and a line of the list, not a defect | Pull request #29 of 9 November is still open, and its work has been in `main` since that day (new line T-189) |

## 6. Migration reconciliation

No migration is added, changed or applied: neither Laravel's nor the shop's 20. Read from the shop's database at
20:04 UTC: its ledger holds 20 rows for the 20 files. It holds 73 tables: the 72 of the schema files, name for
name, and the ledger itself. None is missing and none is unknown.

## 7. Backup/restore status

Unchanged: no backup of the shop's database could be confirmed (D-011, T-187). Nothing in this merge writes to
any database.

## 8. Performance observations

Nothing was measured in this round. From the check after merge #40: the first byte of the live sign-in page came
after 0.13 s, the function being warm. A cold start has never been measured (T-154, planned as round 5).

## 9. Screenshots or recording of key flows

None: no screen is changed.

## 10. Explicit owner approval status

| | |
|---|---|
| This merge | The owner's standing answer of 17 November: the agent merges when every check is green (DL-026); and his message of 19:29 UTC |
| How that message was read | The agent's reading (DL-032): the list, the plan and the tests, and not yet the next line. The owner corrects it with a word |
| The order of the rounds | The agent's proposal, by the three rules and the two exceptions stated in document 21 §2 (DL-032). The owner can change it with a word; it is one file |
| Any module, any gate | Not accepted. No acceptance sheet is signed (document 16 §4) |
