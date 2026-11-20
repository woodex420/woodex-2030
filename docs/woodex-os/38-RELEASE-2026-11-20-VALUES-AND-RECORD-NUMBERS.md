# WOODEX OS — release report, 20 November 2026: values and record numbers

The report of pull request #51, in the ten points of document 10 §9, written **before** the merge (document 16 §8;
the lesson of defect D-014).

**On whose word.** The owner's message of 20 November, about 09:44 UTC: "continue  and start next phae". The merge
stands on his answer that the agent merges into `main` when every check is green (DL-026). What the agent decided
on the way is DL-042.

**Why this round.** The first round of the plan is still the shop's reading of its products (T-105), which is built
in pull request #48, from another conversation, and waits there for the owner. This was the next round that pull
request #48 does not touch, and the map of the tables (T-175, merged the same morning) had set it free.

**What this round is.** Two lines of the master list, both about moving data from the old system (the React shop
and dashboard with their Node API, on PostgreSQL) into the admin (Laravel, on MySQL or MariaDB):

| Line | What is so now |
|---|---|
| T-176 | One class takes a value as the old system holds it and gives it as the admin holds it, or refuses it by name: a time, a day, money, a list inside a cell, yes and no, a status, a text that is too long, a value that must be unique. Tested on the three database servers the admin is promised for. Document 36 §8 |
| T-177 | One class is the only place that writes the old number of a record beside its new one for this old system. The rule for the name, the key, the checksum and a row that becomes two records is written down and held by tests. Document 36 §9 |

**What it is not.** No screen changed, in the admin or in the shop. **No record moved.** Nothing uses the two
classes, and they run against no data. No migration, of either system. Nothing was done on a live system: no
database, no setting of either Vercel project, nothing on Hostinger. The Supabase connection was switched off
for this message, so nothing was read from the live database either.

## What was built

**What the admin is told about the old system.** The admin runs on a host that has neither the old schema files
nor a PostgreSQL. What an importer has to know and cannot read from a row is written into two files inside
`platform/`:

- `resources/data/old-system-shape.json`, **never written by hand**: for each of the 73 tables the columns of its
  key, the words its columns may hold, the columns held to 0 or 1, and where the map sends its rows.
  `node scripts/verify-old-shape.mjs --write` builds the old schema on a test database from the schema files,
  asks the database's own catalogue, and reads the map. CI fails when the file says anything else.
- `resources/data/old-system-rules.json`, **written by hand**: the decisions. Where each word of the old system
  goes in the admin's lists, the rule of every unique column, and which records get no entry of their own. The
  same script holds it to the old system and writes it into document 36.

**The conversions (T-176).** `App\Domain\System\Import\OldSystem\Convert`. It rounds nothing, cuts nothing and
guesses nothing; a value that does not fit is thrown as a refusal that says what the value was meant to be, the
value, and why. The rules are document 36 §8. The ones that were not obvious before the tests were written:

- A time is returned on the workspace's clock, not in UTC. The admin writes the clock of the object it is given
  and the database takes it for Pakistan time, so a time handed over in UTC is kept five hours early, on every
  server and without an error. A test pins that.
- A day stays a day. `2026-11-08` made into a moment and read in UTC is 7 November.
- Floating money: the noise of a floating number is taken (0.1 + 0.2 is 30 paisa), a part of a paisa is
  refused (1234.565), and nothing is rounded. The line between the two is a millionth of a paisa.
- MySQL rounds a part of a second up into the next second and MariaDB cuts it, so the conversion drops it
  before either sees it.
- A time column of the admin ends on 19 January 2038. MySQL and MariaDB refuse a later moment; the sandbox's
  SQLite keeps it. The conversion refuses it itself, so the answer is one on all of them.

**The old number of a record (T-177).** `App\Domain\System\Import\OldSystem\Refs`. An importer asks it whether a
row was brought over and whether it changed since, and it writes the answer into `legacy_refs`. The rule is
document 36 §9: the name is `node.`, the old table, `>` and the admin's table; the key is the row's own key as one
text in which capital and small letters cannot meet; a row that becomes two records is entered once for each;
the checksum is SHA-256 of the row as it was read.

**Document 36** gains §8 and §9. The tables in them are written from the two files and checked on every push.

**One sentence of report 37 was made exact**: the two measurements on the live database were 40 to 60 minutes
apart, not "an hour".

## What a person sees differently

Nothing. No screen, no address and no answer of either site changed.

## What this merge changes on each live site

| Site | What changes |
|---|---|
| The shop, `woodex-2030.vercel.app` | Vercel builds it again from `main`, as at every merge. No file of its code is in this pull request |
| The shop's database (Supabase) | Nothing |
| The admin's demo, `woodex-os.vercel.app` | Vercel builds it again. `platform/` gains seven classes and two data files that no screen, route or command uses |
| Hostinger | Nothing is installed there. CI writes the release branch from `main` when the admin changes, so the branch will carry the new files |

## 1. Commit/branch/build identifier

Branch `arena/d1c858f4-woodex-2030`, from `main` at `43636a4`. Pull request #51.

| Commit | What |
|---|---|
| `71104db` | The file that tells the admin the old system's shape, the script that writes and checks it, the test of the script, its step in CI |
| `fc9c5bc` | The two classes with their helpers, the rules file, 66 tests |
| the commit that carries this report | The script also checks the rules file and writes document 36 §8 and §9; its test grows from 30 checks to 64; this report |
| the last commit | The records of the round: the master list, the plan, the decision log, the index of documents |

## 2. Environment

- Sandbox: PHP 8.5 on SQLite, which is where the whole suite runs here. A MariaDB 10.2.11 built in the sandbox,
  for a first look only: it is older than anything the admin is promised for.
- CI (`os-ci.yml`): PHP 8.4 on **MySQL 8.4.13, MariaDB 10.11.19 and MariaDB 11.4.13**. This is the proof.
- The old schema: built from its files on the embedded PostgreSQL the old system's tests use (18.3), by Node.
- No live system.

## 3. Test command and result

| What | Where | Result |
|---|---|---|
| `tests/Feature/OldSystem` (66 tests: 40 for the conversions, 26 for the record numbers) | CI, `fc9c5bc`, on each of the three servers | All pass. The group they are in went from 530 tests to 596 on each server |
| The same | Sandbox: SQLite, and MariaDB 10.2.11 | 66 of 66 on both |
| The whole Laravel suite | Sandbox, SQLite, 20 November, 10:38 to 10:46 UTC, on `fc9c5bc` | 2,772 pass, 36 skipped, none fails. Before the round: 2,706 and 36 |
| The whole Laravel suite | CI, `fc9c5bc`, on each of the three servers | 2,773 pass, 35 skipped. Before: 2,707 and 35 |
| Two writers at once; the release as the host gets it; Vercel's runtime; the browser on the rehearsal | CI, `fc9c5bc` | All pass |
| `node scripts/verify-old-shape.mjs` | Sandbox; CI in `build-and-test` | The shape file, the rules file, the old schema, the map and document 36 agree |
| `node scripts/test-verify-old-shape.mjs` | Sandbox; CI in `build-and-test` | 64 of 64: the check fails, and says why, for each mistake made for it |
| The shop's gate (`build-and-test`) | CI, `71104db` and `fc9c5bc` | Passes on both |
| The code style (Pint) on the new files | Sandbox | Passes |
| PHPStan, level 6, on the new classes | Sandbox, by hand: it is not in CI | No errors |
| The plan, the acceptance files, the documents, the secret scan | Sandbox and CI | Pass |

The result of the last two commits is on the pull request.

**What the tests hold**, kind by kind. Each value is written to a table of the admin and read back.

1. **Times.** `2026-11-08T20:54:28.341Z` and the bare day `2026-11-08` arrive as the same moment on every
   server, through a model and through the query builder, and are shown in Pakistan time (9 November, 01:54;
   8 November, 00:00). On MySQL and MariaDB the server itself is asked for the seconds since 1970. An empty text
   arrives as nothing. Twelve texts that are no time are refused by name and nothing is written: words,
   `Invalid Date`, 30 February, a time of day without a zone, a year the admin cannot hold.
2. **Money.** 0.1 + 0.2 arrives as 30 paisa, Rs 99,999,999.99 as 9,999,999,999, and 1234.565 is refused. A
   thousand lines: the floating sum has drifted, the sum of the converted lines is exact, and the database adds
   the thousand rows to the same paisa. Every money column of the 28 tables an importer writes to is 64 bits
   wide.
3. **JSON.** A block with Urdu, an emoji, 9,007,199,254,740,993 and names in a given order reads back as the
   same value. What MySQL does with the order of the names is pinned, and a list keeps its order everywhere.
4. **Upper and lower case.** Two e-mail addresses and two product codes that differ only in capitals: MySQL
   and MariaDB refuse the second, SQLite takes both. The written rule decides before the database is asked,
   with the same answer on every server. Every unique column the database reports for those tables has a
   written rule: the test compares the two lists.
5. **Vocabularies.** All 185 words the old system lists are sent through the conversion. 50 arrive in a list
   of the admin; 14 are refused with their written reason; 121 belong to tables that do not move yet and are
   refused with the line they wait for. A word with neither a place nor a reason is named.
6. **Once.** A row brought over twice is one record; the second run sends no statement that writes. A changed
   row is seen as changed, and stays so until the importer says what to do with it.
7. **Names.** The enquiry 17, the task 17 and the enquiry 17 of the studio's older CRM are three entries. Every
   name the other importers use is read out of their code and compared with a list; none begins with `node.`.
8. **Keys.** 224 keys made to meet if anything could (the joining sign, capitals, accents, spaces, Urdu, a byte
   that is nothing) are 224 different texts, and the database itself counts 224 under one unique index.
9. **Length.** A text key of 64 characters is kept whole; 65 are refused by name and nothing is made.
10. **Nobody else writes the table.** Every PHP file of the admin is read; one that names the table and is not
    on the list is named.

**Each claim, tried against its protection taken away.** On a copy in the sandbox, one change at a time, the
tests run on SQLite; the repository was not touched. 38 changes, each noticed and named by a test:

| What was changed | Which test said so |
|---|---|
| Floating money is rounded instead of refused | Money: the refusal did not come |
| Decimal money is cut after two decimals | Money, exact: the refusal did not come |
| One word taken out of a list of places | Vocabularies: `invoices.status: Paid` |
| A word sent to a value the admin's list does not have | The places: the word, the value and the list |
| Words compared without regard to capitals | `paid` was taken for a status of an old invoice |
| The time handed back in UTC | Times: the zone is UTC, not Asia/Karachi |
| A moment after 2038 let through · a clock without a zone taken for Pakistan time · 30 February rolled over | Each: "was taken for a time" |
| A bare day made a moment and read in UTC | 7 November, not 8 November |
| What is not JSON arrives as nothing · a large number as a floating one | JSON: "was taken for JSON"; the digits differ |
| Any other number counts as yes | Yes and no: the refusal did not come |
| A text too long is let through · bytes are counted instead of letters | Text: no refusal; 322 "characters" counted for 160 |
| The rule holds capitals apart · the rule lets both through · a unique column loses its rule | Case: three tests, each by name |
| **The check for a changed row taken out** | "Sees a row whose content changed as changed": it said Same |
| A second run writes the time again | The two statements it sent are listed |
| A changed row is marked as dealt with when nothing was said | It said Same |
| The record and its entry are not written together | A record was left behind |
| The key written as it comes · the escaping sign not escaped · capitals let into a key | Keys: the keys that came out alike |
| A key longer than the column let through · a number with two writings | Length; keys |
| The names lose `node.` · the admin's table left out of the name · the lines of a quotation get entries · a table that waits gets a name | Names: four tests |
| The checksum ignores an empty cell · depends on the order of the columns | The checksum: both |
| **A seventh class writes the table**, through the model, and through the query builder | "Reads every file": `app/Domain/Crm/Import/SeventhWriter.php` |
| The command that only asks starts to write | The same test: listed as "reads" |
| Another importer takes a name of this system · a name that is not on the list | Names: the file; the list |

## 4. Browser/device coverage

None, and none was due: no screen changed. CI's browser ran the two journeys and every screen on `fc9c5bc`, as on
every push that touches `platform/`, and passed.

## 5. Defects open/closed by severity

The register is as it was: 44 on record, **33 closed**. Open are 1 critical (D-002, the old dashboard's owner
password: T-021, the owner's), 7 medium and 3 low. This round writes no new number into it, for the reason at the
end of this point. **It found two faults in code that was there before, and cured neither**: both are outside
what this round was to change, and one of them is on a screen.

**Found in code that runs, not cured here:**

1. **The month filter of the Leads list shows more than the month** (low). Choosing November shows the
   enquiries from 31 October, 19:00, to 1 December, 19:00, Pakistan time. The list makes the start of the month
   in UTC and hands it to the database, which takes that clock for Pakistan time, so the start is five hours
   early; and "one month later", counted from 31 October, is 1 December. Tried in the sandbox on SQLite and on
   MariaDB with six enquiries around the two ends of November: the ones of 31 October, 22:00, and of
   1 December, 10:00, are found with it. No record is wrong; a count by month can be. The place is
   `platform/app/Livewire/Admin/Crm/LeadsIndex.php`, where the month is turned into two times. It is a change of
   two lines and one test, on a screen, so it wants its own release note and was left for a round of its own.
2. **The time of a WhatsApp message would be kept five hours early** (medium, and it cannot happen today).
   The action that receives a message makes its time in UTC and hands it to the record
   (`ReceiveWhatsAppMessage`). By what this round's test shows of every server, the record keeps it five hours
   early: the 24 hours in which a customer may be answered freely would end after 19, and the conversation's
   "last message" would stand five hours off. The action itself was not run for this. The inbox is switched off
   and no WhatsApp account is connected (D-044), so nothing has been written this way. It has to be cured before
   the inbox is switched on (T-118).

Both come from the same thing, which this round's test pins for the first time: **the admin writes the clock of
the time it is given, and the database takes that clock for Pakistan time.** A time made in UTC and handed over
is kept five hours early, through a record and through a query alike, with no error. Every other place in
`platform/app` that makes a time from outside was looked at for this: 35 that read a time from a text, the ones
that make a time from a number of seconds, and the ones that turn a time into UTC. The screens read what a
person typed on the workspace's clock, which is right. The importer of a sheet of leads hands over a day at
midnight in UTC and reads it back the same way, so the day it shows is the day of the sheet.

**Found about the plan, not faults:**

1. The plan counted six classes that write `legacy_refs`; eight files name it. The two more are a command that
   only asks, and a migration that is kept out of use. Both are on the test's list with what they do.
2. A product's code can be longer than the column that keeps old numbers (80 characters against 64). No table
   with such a key moves today (document 36 §9, DL-042).

**Found inside the round and cured in it. None of it was ever merged:**

1. The first version of the test for two values that meet asked the database to compare two texts it was
   handed. That answer follows the connection, not the column. The test now writes the first value into a
   unique column and asks the database for the second, so the column answers.
2. A comment and a test's name said "five hours late"; the stored moment is five hours early.
3. A helper class named the table in a comment, which would have put a second file on the list of those that
   name it. It was reworded: one file of this system names the table.

**Owed to the register:** the two faults above, and from report 34 the finding about "newest first". The next
free numbers (D-045 to D-052) are taken by pull request #48, which is not merged; a number written here would
have to be changed when that pull request is brought up to date (DL-039, DL-040). Until then they stand in this
report and in DL-042.

## 6. Migration reconciliation

**The admin.** Nothing: 109 migrations and 126 tables, as on `main`. The column for an old key keeps its 64
characters (§10).

**The old system.** Nothing: 21 migrations in the repository. The live database was not read in this round; it
had received all 21 when it was read on 20 November at 07:21 UTC.

## 7. Backup/restore status

Nothing was written to any database, so nothing had to be backed up. That no backup of the shop's database is
known to exist (T-187, the owner's) is unchanged.

## 8. Performance observations

- The 66 tests take about four seconds on SQLite. In CI the group they are in took 57 to 109 seconds on the
  three servers; before the round, 60 to 99. The difference is inside what that job varies by from run to run.
- The script that checks the two files takes about four seconds; its test about 35, in `build-and-test`.
- **How fast an import is was not measured**, because there is none. Bringing one row over is one question to
  the database and, for a new row, one write beside the record's own. That is right for the live database as it
  stands (381 rows); an importer of a large table may want to ask for many rows at once, and that is its
  line's work (T-178).

## 9. Screenshots or recording of key flows

None: no screen changed. What stands in their place is document 36 §8 and §9, which show every decision in
tables written from the files the code reads.

## 10. Explicit owner approval status

- Merging when every check is green: approved (DL-026).
- Starting this round: the owner's word of 20 November ("continue and start next phae").
- **Every decision of this round is a proposal, and his to overrule** (DL-042). No record moves on its strength:
  the mapping of a module is approved by him before its first real record is brought over (T-181). The ones he
  is most likely to change:
  - where a word goes: an old invoice that is `Cancelled` becomes void; a conversation that is `resolved`
    becomes closed; a quotation in `Negotiation` becomes "changes requested"; a supplier's invoice that is
    `posted` becomes received;
  - the 14 words with no place, first of all `Approved` on a quotation, which means "the customer agreed" in
    the old system and "passed inside the company" in the admin;
  - that the import stops when two invoices, quotations, enquiries or projects come under one number, instead
    of choosing one;
  - that no column is widened now.
- **Asked with this round:** whether the month filter of the Leads list is to be cured next, ahead of the
  plan's order (§5). It is small, and it is on a screen people use.
- **Still open, asked before:** where the system that keeps data is to run (document 24, or a database of his
  for the Vercel site); whether Feature status may link to the old dashboard; the price list, a messaging
  account and a Facebook Page; whether the two old previews of the shop are to be deleted; pull request #48,
  which waits for his look; whether the facts on a card are the right ones (report 35); the proposals of the
  map (report 37); whether the handful of records in the live database are trials.
- No module is accepted and no gate is passed.

## What is not proved

- **A real row.** Nothing was read from the live database in this round. The values in the tests are written
  by hand after the old API's code and the old schema: how it writes a time, which words it allows. The dry run
  of each importer (T-179) is where real rows meet these rules.
- **The longest key in the live database.** Not read: the connection was switched off. In the sample data the
  longest product code has 31 characters.
- **How a PHP driver hands over each kind of PostgreSQL value.** The admin has no connection to a PostgreSQL in
  the sandbox or in CI; the old schema is built from Node. The conversions take a decimal number as text, a
  floating one as a number or as text, yes and no as `t` and `f` or as true and false. Which of these an
  importer meets is settled when the first one reads a row (T-178).
- **Another set of rules for comparing text.** The admin's tables compare text by `utf8mb4_unicode_ci`. A host
  whose tables were made otherwise would compare otherwise, and the tests that pin the servers would fail
  there, which is what they are for. They were not run on Hostinger's server.
- **Other versions of the servers** than CI's three.
- **What only the database's own table of letters knows** (`Strasse` and `Straße`). The rule does not foresee
  it; the unique column refuses the second value and the import stops. That is pinned, not cured.
- **Two runs at the same moment.** The second run's entry was written by hand inside the first run, in one
  connection. Two processes were not started. What holds is the unique index, on every server.
- **The places of the words.** They are proposed by somebody who has read both codes, not by somebody who does
  the work.
- **The two pull requests together.** Pull request #48 changes the same records (the state table of list 14,
  documents 16 and 21): whichever is merged second has to be brought up to date first.

## What comes next

By the plan (document 21): the shop's reading of its products is round 1 and is in pull request #48. The next
rounds that need nobody are packages, the demo's housekeeping and the documents the code points at (T-071 and
six more), then the dashboard (T-044). The importers themselves (T-178, with their dry runs, T-179) come wave by
wave, and each needs the mapping of its module approved first (T-181). The owner says which.
