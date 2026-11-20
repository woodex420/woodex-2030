# WOODEX OS — release report, 20 November 2026: the shop's database

The report of pull request #50, in the ten points of document 10 §9, written **before** the merge (document 16 §8;
the lesson of defect D-014).

**On whose word.** The owner's message of 20 November, about 06:02 UTC: "Start next". The merge stands on his
answer that the agent merges into `main` when every check is green (DL-026). What the agent decided on the way is
DL-041.

**Why this round.** The first round of the plan is still the shop's reading of its products (T-105), which is built
in pull request #48, from another conversation, and waits there for the owner. This was the next round that pull
request #48 does not touch.

**What this round is.** Two lines of the master list, both about the database of the old system (the React shop
and its Node API, on Supabase):

| Line | What is so now |
|---|---|
| T-172 | Each of the nine functions of that database looks names up in one fixed place. **This was applied to the live database** on 20 November at 06:57 UTC |
| T-175 | Every one of its 73 tables has a row that says where it goes in the admin, or why it stays behind: document 36 |

**What it is not.** No screen changed, in the admin or in the shop. No file of the admin (`platform/`) and no file
of the shop's code changed. No record moved, and none moves on the strength of the map. Nothing was done on
Hostinger or in the settings of either Vercel project.

## What was built

**A fixed search path (T-172).** A function of a database finds a table by its name, and looks for that name along
a list of places, the "search path". Without a path of its own a function uses the path of whoever calls it. The
nine functions of the shop's database had none. One of them is the rule that an entry of the books must balance:
it adds up `journal_lines`, and it added up whichever table of that name stood first on the caller's path.

- `scripts/migrations/021_function_search_path.sql` gives each of the nine the path `public, pg_temp`: the schema
  of the tables, then the temporary schema. It names the nine one by one and changes nothing else. Its rollback
  is `scripts/migrations/rollback/021_function_search_path.rollback.sql`.
- `scripts/schema.postgres.sql`, the base file, sets the path of its own three functions each time it is run
  (see §5 for why).
- `scripts/test-search-path.mjs` builds the schema from every file and names a function without a path. It is a
  step of the shop's gate in CI and runs as `npm run test:search-path`.
- `deploy/supabase/2026-11-20-apply-021-function-search-path.sql` is the exact text that was sent to the live
  database, with what was read before and after.

**The map of the old tables (T-175).**

- `docs/woodex-os/acceptance/tables.json`: one row for each of the 73 tables. What it holds; whether its rows
  **move** into tables the admin has (29), **wait** for a table that a named line of the list still builds (35),
  or **stay** behind, with the reason (9); the screen of the admin that owns it; the line that brings it over
  and that line's wave.
- [`36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md`](36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md), written from that
  file: the short version first, then the tables by area, what the live database held, the names that stand on
  both sides, and what differs between the two databases.
- `scripts/verify-table-map.mjs` holds the file to the old schema files, the admin's migrations, its menu and
  the master list, on every push. `scripts/test-verify-table-map.mjs` tries that check against 29 mistakes.

**The round after this one.** T-176 and T-177 waited for the map and are free. Their round is written into the
plan (document 21): what is built, the tests, and what each test is tried against.

## What a person sees differently

Nothing. The shop serves the same products at the same addresses, and the old dashboard works as it did.

What is different cannot be seen: a session of the database that has another table called `journal_lines` in
front of the real one, in another schema or as a temporary table, can no longer post an entry that does not
balance. On the live database nobody but the database's owner could have arranged that (§5).

## What this merge changes on each live site

| Site | What changes |
|---|---|
| The shop, `woodex-2030.vercel.app` | Vercel builds it again from `main`, as at every merge. No file of its code is in this pull request: the changes are migrations, scripts, documents and one step of CI |
| The shop's database (Supabase) | **Already changed, before the merge**, by hand: migration 021, on 20 November at 06:57:44 UTC (§6). The merge brings `main` level with it |
| The admin's demo, `woodex-os.vercel.app` | Vercel builds it again. No file of `platform/` changed |
| Hostinger | Nothing. The release branch is written only when the admin changes |

Until the merge, one thing is out of step: the base schema file on `main` is the old one. Run by hand against
the live database (`npm run db:apply-schema`) it would take the path off three of the nine functions again.
Nothing runs it on a deployment.

## 1. Commit/branch/build identifier

Branch `arena/4fd7af56-woodex-2030`, from `main` at `f509ef7`. Pull request #50.

| Commit | What |
|---|---|
| `10ba087` | Migration 021, its rollback, and the base file's own lines |
| `5ebe6d7` | The suite, its step in CI, and the count of migrations in the old system's state document |
| `89a85b1` | The map, document 36, the check and the test of the check, and its step in CI |
| `a5f5904` | The migration's comments made exact against what was tried. No statement changed |
| `6716071` | The text for the live database, and its rehearsal in the suite. Not yet sent |
| `da09370` | After it was sent: the readings in that file's header, the map's second reading of the live database |
| the commit that carries this report | The records of the round, the plan's next round, two more checks of the suite (a temporary table) |

The migration file has the sha256 `c63b25d3aa3ed61b5a3fda2bd3ca269f43cc6365807b325ccc0e1963a941eec6`. Row 21 of
the live database's ledger holds the same.

## 2. Environment

- Sandbox: Node 22, the embedded PostgreSQL the old system's tests use, which is **PostgreSQL 18.3**.
- CI: the shop's gate (`build-and-test`), which runs the same suites on the same embedded PostgreSQL.
- The live database: Supabase project "woodex-2030", **PostgreSQL 17.11**, through the Supabase connection the
  owner switched on, as the role `postgres`.
- The live shop, asked from outside over the internet.

## 3. Test command and result

| What | Where | Result |
|---|---|---|
| `npm run test:search-path` | Sandbox, 20 November, 07:12 UTC | 59 checks pass, in seven seconds |
| The same suite | CI, `da09370` | 57 checks pass: the two about a temporary table were added after. The result of the commit that carries this report is on the pull request |
| The old system's module suites, with 021 applied | Sandbox, 06:33 to 06:35 UTC | On SQLite 13 of 13 suites (844 checks); on PostgreSQL 13 of 13 (862 checks) |
| The API's security rehearsal on PostgreSQL (`qa:pg`) | Sandbox, the same run | 109 of 109 |
| The other suites of the old system: the copy from SQLite, integrity, the drift audit, the restore drill, the audit log, the upgrade, the sign-in limit, row-level security | Sandbox, the same run | All pass |
| The shop's gate, all of the above with the build and the types | CI, `6716071` and `da09370` | Passes on both |
| `node scripts/verify-table-map.mjs` | Sandbox and CI | The schema files, the map, the admin's migrations, the master list and document 36 agree |
| `node scripts/test-verify-table-map.mjs` | Sandbox and CI | The check fails, and says why, in each of 29 cases; it passes where it should |
| The plan, the acceptance files, the documents, the secret scan | Sandbox and CI | Pass |

**What the suite holds**, part by part:

1. Built from the base file and every migration: nine functions and one aggregate; no function without a path;
   none whose path follows the role that calls; the six triggers attached and switched on.
2. The aggregate takes no setting: PostgreSQL refuses in each of three spellings. The two functions it runs have
   theirs.
3. A function added later without a path is named; one that states its path is not; one rewritten with
   `CREATE OR REPLACE` loses its path and is named.
4. What it is for (below).
5. The base file run a second time leaves every function as it was.
6. On a database as the live one was: applied, applied twice, rolled back row for row, a path that somebody
   else set is left alone, applied again; and the same through the runner a person would use.
7. The text that was sent: it is one statement; it holds the migration's block word for word and the checksum
   of the migration's file; sent to a database that is not as it was read, it does nothing; with a check that
   fails after the change, nothing is kept; sent as it is, it is kept, with the ledger row the runner expects;
   sent a second time, it is refused.

**Each claim, tried against its protection taken away:**

- **The migration taken off**, another schema in front of the session's path: an entry of 100 against 60 is
  **kept**. With the migration it is refused: "entry 9001 does not balance: debits 100.00 vs credits 60.00".
- The same for the comma-joined list: without the migration the other schema's function answers.
- **A path of `public` alone**, and a temporary table called `journal_lines` in the session: the entry is
  **kept**. With the migration's path, which names the temporary schema last, it is refused.
- One function taken out of the migration's list: the suite names it, "1 without: tickets_freeze_sla()".
- The base file without its own lines: a second run takes the path off three functions, and the suite names
  the three.
- The text for the live database, sent to a test database from which one trigger had been taken away: its
  check after the change stops it ("5 triggers are attached"), and nothing is kept: no function with a path,
  20 rows in the ledger.
- One row taken out of the map: the check names the table. And 28 more mistakes of that kind.

## 4. Browser/device coverage

None, and none was due: no screen changed. After the change to the live database the shop was asked from
outside, 19 seconds later (§6).

## 5. Defects open/closed by severity

The register after this round: 44 on record, **33 closed**. Open are 1 critical (D-002, the old dashboard's owner
password: T-021, the owner's), 7 medium and 3 low.

**Closed: D-029** (low), "Ten functions of the shop's database have no fixed search path". Nine have one. The
tenth is the aggregate `group_concat`, which PostgreSQL gives none and which needs none: an aggregate holds its
two functions by identity, not by name. Supabase's advisor counts rows of the catalogue and does not leave
aggregates out, so it is expected to name that one still: one name where there were ten. Its page was not read;
its rule was read from its source and asked of the live database by hand.

**Found inside the round and cured in it. None of it was ever on a live system:**

- **`CREATE OR REPLACE FUNCTION` takes away a setting it does not state.** The base schema file creates three
  of the nine functions that way, and it is run again and again: by the tests, and by `npm run db:apply-schema`.
  A second run would have taken the path off those three while the ledger said that 021 was applied. Found by
  the suite's fifth part before anything was sent. The file sets the path itself now, and `AGENTS.md` says that
  a later migration which rewrites a function must state the path.
- **The defect said less than was so.** It said nothing could be put in the functions' way, because only the
  owner can create objects in the schema. A temporary table is not in the schema, and any role may make one.
  Read from the live database: each of its 31 roles has that right, which is PostgreSQL's default. But only a
  role that signs in to the database itself can send such a statement, and of the ten that do, only `postgres`
  and Supabase's own administrator may write the books. So nobody else could have used it. That is why the path
  names the temporary schema, last.
- **Counts taken by name were wrong by a little.** A first count of the text columns that hold a time, made
  by the ending of the name alone, was too high: a name like `tickets.waiting_on` ends as a day does and holds
  none. The figures in document 36 and in the plan's next round were counted again from the schema, by rules
  that are written beside them: 105 text columns hold a time or a day, 23 floating columns hold money, 24 text
  columns are read as JSON by the old API. None of the first figures was pushed.
- **A new migration changes a documented count.** The old system's state document says how many migrations the
  repository holds, and a check reads it. It says 21 now, in the same commit as the suite.
- **The secret scan took an example for a credential**: two spellings of one e-mail address in a sentence of
  document 36. The example is two product codes now.

## 6. Migration reconciliation

**The old system.** The repository holds 21 migrations. The live database has received all 21.

| | |
|---|---|
| Read before, 06:57:06 UTC | 20 rows in the ledger, the last being 20. Nine functions and the aggregate, none with a setting. Six triggers, each switched on. 148 products; 2 entries and 4 lines in the books |
| Sent, 06:57:44 UTC | One statement, once. It took 29 ms |
| Read after, 06:57:56 UTC | Each of the nine functions has `search_path=public, pg_temp`. The aggregate has no setting. No body of a function changed: the same fingerprint of the ten bodies as before. The six triggers switched on. 21 rows in the ledger; row 21 holds the migration file's name and its sha256. 148 products; 2 entries and 4 lines in the books |
| Asked from outside, 06:58:03 UTC | The shop's products: 148, the first one published. The address that calls `datetime()` in the database, which is one of the nine: it answered with its prices. The API's health address: ok |
| Counted again, 06:58:56 UTC | 73 tables, 381 rows. One figure differs from 06:18 UTC: the ledger holds 21 rows where it held 20 |

The 20 earlier rows of the ledger were read at 06:06 UTC: each holds the sha256 of its file.

**How it was sent.** As migration 020 was (DL-028): the tests first, green in CI on the commit that carries the
text; then one statement, which is one transaction. It looks first, and does nothing unless the ledger holds 20
rows and the schema holds the ten routines that were read, none with a setting. It runs the migration's block,
word for word. It then checks that each of the nine has the path, that no body changed, that the six triggers
are switched on, that `datetime()` and `group_concat()` answer as before, that the name `journal_lines` under
the new path is the table of the books, and that the products count the same. Only then does it write row 21.
If any of that fails, nothing is kept.

**The admin.** Nothing: 109 migrations and 126 tables, as on `main`.

**The map and the live database.** Read on 20 November, reading only: the live database's 73 tables are the 73
of the schema files, column for column (765 columns, compared by a fingerprint of each table's columns). No
table is missing and none is unknown. 19 tables hold rows, 54 are empty.

## 7. Backup/restore status

**No backup of the shop's database is known to exist** (T-187, the owner's). That was so on 17 November too
(DL-028). What stands in its place for this change:

- The migration changes no row of any table. It adds one row to the ledger.
- The way back is tested: the rollback file brings back the earlier state row for row on the test database,
  alone and through the runner, and leaves alone a path that somebody else set.
- To take it back: `node scripts/migrate.mjs --dsn <postgres-url> --rollback 021`. It has not been run on the
  live database.

## 8. Performance observations

A function with a setting costs a little more to call: PostgreSQL changes the path on the way in and back on
the way out.

| Ordering 20,000 rows by `datetime()` | Without the path | With the path |
|---|---:|---:|
| The test database (PostgreSQL 18.3, in the sandbox) | 79 ms | 98 ms |
| The live database, made-up rows, no table touched | 116 to 119 ms (before, three runs) | 147, 148 and 161 ms (after, three runs) |

- That is about one to two millionths of a second a call. The shop's largest table holds 148 rows.
- The two measurements on the live database were made with statements of the same kind, an hour apart; the
  text of the first was not kept. Read the difference as a size, not as a figure.
- `datetime()` was never written into the calling query by the planner, before or after: the setting did not
  take that away.
- For `group_concat` no difference was measured on the test database.
- Not measured: the shop's own requests, before and after.

## 9. Screenshots or recording of key flows

None: no screen changed. What stands in their place is the reading of the live database before and after, in
the header of `deploy/supabase/2026-11-20-apply-021-function-search-path.sql`, and CI's log of the suite.

## 10. Explicit owner approval status

- Merging when every check is green: approved (DL-026).
- Starting this round: the owner's word of 20 November ("Start next"). The plan's text for T-172 says that the
  migration is applied to the live database as migration 020 was.
- **Applying it with no backup, on a tested way back: decided by the agent, as on 17 November, and the owner's
  to overrule** (DL-041, point 1). The command that takes it back is in §7.
- **The map: every row is a proposal.** No record has moved. A module's mapping is approved by the owner before
  its first real record is brought over (T-181). The proposals he is most likely to change: no account is
  carried over and people are invited instead; the 148 sample products stay behind; meetings become tasks and
  history; suppliers join vendors and their invoices join bills; the tables of the old page editor wait for
  the page builder.
- Also decided by the agent and his to overrule (DL-041): the path and its order; that the aggregate is left
  as it is; that the base file sets its own three; the round that comes after this one.
- **Still open, asked before:** where the system that keeps data is to run (document 24, or a database of his
  for the Vercel site); whether Feature status may link to the old dashboard; the price list, a messaging
  account and a Facebook Page; whether the two old previews of the shop are to be deleted; pull request #48,
  which waits for his look; whether the facts on a card are the right ones (report 35).
- No module is accepted and no gate is passed.

## What is not proved

- **PostgreSQL 17.** The suite runs on 18.3. What was seen on 17 is the change itself and the reading after it.
  That an aggregate takes no setting was tried on 18.3 only.
- **The rollback on the live database.** It was run on the test database, never there.
- **An entry of the books on the live database after the change.** Nobody posted one. The statement that was
  sent checked what the rule of the books finds under its new path, and no more.
- **The old dashboard, signed in, after the change.** The questions asked from outside are the catalogue's.
- **Supabase's advisor.** Its page was not read.
- **The map's judgement.** Where a table goes is a proposal by somebody who has read the two schemas, not by
  somebody who does the work. Records were counted, not read: whether the handful that are there are trials
  is the owner's to say.
- **Constraints and indexes of the live database** were not compared with the files the way the columns were.
- **Until the merge**, the base file on `main` is the old one (see above).
- **The two pull requests together.** Pull request #48 changes the same records (the state table of list 14,
  documents 16 and 21, the defect register): whichever is merged second has to be brought up to date first.

## What comes next

By the plan (document 21): the shop's reading of its products is round 1 and is in pull request #48. The next
rounds that need nobody are moving data, what differs between the two databases and one rule for the numbers of
records (T-176, T-177), then packages and the demo's housekeeping (T-071 and six more). The owner says which.
