# WOODEX OS — release report, 18 November 2026, second merge

The report of the pull request that carries document 25, in the ten points of document 10 §9, written **before**
the merge (document 16 §8; the lesson of defect D-014).

**On whose word.** The owner's message of 18 November, about 05:12 UTC, with a link to an archive on his Google
Drive: "woodex interior admin are attach … analayize compalte and resuse plan to upgrate admin do not use theme
… start with create a list sumrize what is still holding pnaidng or missing to complate and upgrate todo list
plan complate project". How it was read is DL-034. The merge itself stands on his answer that the agent merges
into `main` when every check is green (DL-026).

**What this round is, and what it is not.** It is an analysis, a plan, three new lines of the list and the
records. Nothing was built: no screen, no rule and no setting of either site is different after it. Nothing of
the attached archive was copied into the repository and nothing of it was run.

**What was lost before this round, said plainly.** After the merge of pull request #42 the work of round 1 was
under way in the sandbox: T-086 written, its twelve tests passing there; a first draft of T-043 written and never
run. None of it was committed. The merge had closed the session's branch, the owner's next message started this
branch from `main`, and the sandbox with those files was gone. Round 1 is written again; it is still the next
work.

## What this merge changes on each live site

Compared with `115e29b` (today's `main`): documents under `docs/woodex-os/` and the plan file
`docs/woodex-os/acceptance/plan.json`. **No file of the Laravel admin is changed** (`platform/`: none), and **no
file of the React shop, of its API or of its dashboard** (`apps/`, `api/`, `vercel.json`, `package.json`,
`package-lock.json`: none), no script and no workflow.

| Site | What is different after the merge |
|---|---|
| **The hosted admin demo**, `woodex-os.vercel.app` | Rebuilt by Vercel from `main`, from the same files of the admin as today. Nothing changes for anybody |
| **The React shop**, `woodex-2030.vercel.app` | Rebuilt by Vercel from `main`, from the same shop files as today. Nothing changes for a visitor or for staff |
| **The branch `hostinger-release`** | Not rewritten. The job that writes it starts only when a file of the admin changes, and none does. It keeps the release of `115e29b`, which is the admin as it is after this merge too; its `RELEASE.json` goes on naming `115e29b` |
| **The shop's database** (Supabase) | Nothing. Not read, not written |
| **Vercel** | Nothing. The newest deployments of the two projects were read |
| **Google Drive** | Nothing. One file was read: the archive the owner linked. Nothing was written, shared or moved |
| **GitHub** | Nothing but the documents. The repository of the Interior studio was asked three things: whether it is public, where its `master` stands, and which pull requests are open |
| **Hostinger** | Nothing |

**The way back.** Putting the merge back removes two documents, one decision row, three lines of the list and
their plan. No site and no data is concerned.

## 1. Commit/branch/build identifier

| | |
|---|---|
| Branch | `arena/bd9d6ab9-woodex-2030`, from `main` at `115e29b` |
| Commits | One: document 25, the list, the plan, the decision, this report |
| Merge commit | Not known when this was written. It is on the pull request |
| Live now | Shop: Vercel deployment `dpl_HryJp8wYFSEpoJzQEMd4ZUTyy3Lu`, ready at 05:05:01 UTC. Admin demo: `dpl_FL7W61NwxaXZrLbKo8RSk6kkxXQc`, ready at 05:05:51 UTC. Both from `115e29b`, the merge of pull request #42 |
| The release for the host | Branch `hostinger-release` at `481b757`, written at 05:05:11 UTC from `115e29b`: 10,742 files |

## 2. Environment

| Where | What ran there |
|---|---|
| Sandbox | A new one, built from 05:17 to 05:20 UTC by `bash platform/tools/preview/sandbox/setup.sh`: PHP 8.5, SQLite, Node 22. It reaches GitHub and npm only. The archive was unpacked there, outside the repository's folder |
| GitHub Actions | The shop's gate (`ci.yml`) with the check of the plan. The Laravel suite on the three database servers, the rehearsal on Apache and the browser do not run for this pull request: their workflows start only when a file of the admin or of its browser scripts changes. They last ran on `115e29b`, all green |
| GitHub, read | The four runs on `115e29b` and their notices; the branch `hostinger-release`, its `RELEASE.json` and its tree; of `woodex420/woodexinterior`: public, `master` at `603428c`, one open pull request |
| Vercel, read | The six newest deployments of each of the two projects |
| Google Drive, read | The archive's name, size and description, and the file itself |

## 3. Test command and result

| Command | Result |
|---|---|
| The archive against the record of 11 November | sha256 and md5 of the file agree with its description and with `platform/PROVENANCE.md`. Each of the 2,059 files under its `platform/` hashed and compared with its row of `platform/PROVENANCE/import-manifest.tsv`: **2,059 agree**, none new, none missing, none different |
| The repository against the same record | `platform/` at `115e29b` holds 1,669 files: 1,250 as copied, 217 changed here, 11 set aside, 191 added here. 8 copied files are gone |
| The application asked for its own destinations, with a Furniture installation's switches | 68: 29 open, 21 switched off, 13 only in the old dashboard, 5 built nowhere. Asked through `AdminNavigation::registry()` and `AdminNavigation::state()` in the sandbox |
| `node scripts/verify-plan.mjs` | Passes. 152 lines on the list: 33 done, 21 can start now, 69 wait for an earlier line, 29 wait for the owner. The 21 stand in 11 rounds; each names what is built, its tests, what the tests are tried against and the way back. 119 lines that are not done have a short title |
| `node scripts/test-verify-plan.mjs` | 24 of 24 refused, each for its own reason |
| `node scripts/verify-acceptance.mjs`, `npm run docs:verify`, `node scripts/secret-scan.mjs` | Clean. Seven release reports carry the ten points |
| The figures of document 25 | Counted on the day by scripts over the two trees, not taken from memory: the files by state, the parts of the business, the screens, the migrations, the routes, the timed entries, the mentions of documents the code points at. Where a figure is CI's and not this count, the page says so |

The checks of the commit that carries this report are on the pull request. The merge waits for all of them.

**Not run, because nothing they test is changed:** the Laravel suite, the two browser journeys, the crawl of
every screen, the rehearsal of a release. Their last run is CI on `115e29b`, all green.

**What was read after merge #42**, which report 23 had to leave open:

- All four workflows on `115e29b` ended green, the admin's with its 15 jobs.
- **The job `publish` ran for the first time** and ended green at 05:05:20 UTC with the notice "branch
  hostinger-release holds main at 115e29b: 10742 files". Read back: the branch stands at `481b757`, one commit;
  its `RELEASE.json` names `115e29ba7db6473b308fb2ee4239459a052e1e9a` with `"uncommitted_changes": false`; its
  tree holds 10,742 files. T-146 is done.
- **Vercel built nothing from that branch.** The newest deployment of each project is the one of `main` at
  `115e29b`; nothing was created after the branch was written.
- The check of the live sites after the merge: all checks passed on both addresses. The browser on the rehearsal:
  28 of 28 steps held, both ways, and 248 pages opened as four roles.
- Still not proved, as report 23 said: anything on the Hostinger account, and that hPanel's Git page takes a
  branch of that size.

## 4. Browser/device coverage

None in this round: no screen is changed. Not covered by any check today, as before: Safari and Firefox; a real
phone; a screen reader and the keyboard alone (T-054).

## 5. Defects open/closed by severity

36 in the register (document 16 §5). Not closed: 1 critical, 0 high, 6 medium, 4 low. Closed: 25. None is new
and none is closed by this pull request.

| Found by the analysis, each a line of the list and not a defect | |
|---|---|
| T-093 | The ledger's actions were never tried with two writers at once, neither by the studio that wrote them nor here. No fault is known; none could have shown |
| T-174 | The code points about 950 times at documents that are not in this repository |
| T-027 | Three switches without a decision of the owner: Google sign-in, the AI bridge, the old website's bridge |
| Open as before | D-002 critical: the old dashboard's password was printed in a public document (T-021, the owner's step) · D-011 medium: no backup known (T-187) · the others of document 16 §5 |

## 6. Migration reconciliation

No migration is added, changed or applied: neither Laravel's nor the shop's. Read from the archive and from the
record of 11 November: of the studio's 83 migration files 72 are in this admin's path and 11 are set aside,
never run. The archive's own note lists 14 that may change data; 6 of them are among the 11, and the other 8
were read one by one on 11 November (`docs/laravel-os/03-MIGRATION-REGISTER.md`).

## 7. Backup/restore status

Unchanged: no backup of the shop's database could be confirmed (D-011, T-187), and the admin on Hostinger has
none because it is not there (T-137). Nothing in this merge writes to any database. The archive holds the
studio's checklist for a backup and a restore in isolation; it is named as input for T-137 and T-185.

## 8. Performance observations

Nothing was measured in this round.

## 9. Screenshots or recording of key flows

None: no screen is changed.

## 10. Explicit owner approval status

| | |
|---|---|
| This merge | The owner's standing answer of 17 November: the agent merges when every check is green (DL-026); and his message of 18 November |
| How that message was read | The agent's reading (DL-034): a list, an analysis, a reuse plan and the list brought up to date; "do not use theme" as nothing of the studio's theme. The owner corrects it with a word |
| The order of the rounds | Changed by the agent: the ledger with two writers is round 2, because it is about money (DL-034). The owner can change it with a word |
| Which switched-off part opens, and when | Not decided here. Document 25 §9 proposes an order; each step waits for an answer of the owner |
| Any module, any gate | Not accepted. No acceptance sheet is signed (document 16 §4) |
