# WOODEX OS — release report, 18 November 2026, fourth merge

The report of pull request #45, in the ten points of document 10 §9, written **before** the merge (document 16
§8; the lesson of defect D-014).

**On whose word.** The owner's message of 18 November, about 14:13 UTC: "start next". That is round 2 of the
pending work plan (document 21): one line, T-093. The merge stands on his answer that the agent merges into
`main` when every check is green (DL-026). What the agent decided on the way is DL-036.

**What this round is.** One line of the master list, built and proved:

| Line | What a person can do now |
|---|---|
| T-093 | Work in the accounts at the same moment as somebody else. Two people who issue invoices, record payments, pay bills or enter costs in the same second are both saved, one after the other. When two people do the same thing to the same record, the first is saved and the second is told in words what happened |

**What it is not.** No module was switched on. No screen and no table changed. Nothing on Hostinger, in Supabase
or in the settings of either Vercel project was changed. The hosted workspace still keeps nothing that is typed
(report 27, "The deployment").

## The question, and why the suite could not answer it

The Interior studio's own list of open work says of posting, reversing and payments: "real MySQL concurrent
writer checks pending" (document 25 §8). The same code is in use here. The suite passes on three database
servers, but it calls one action after another; in the sandbox it runs on SQLite, which lets one writer in at a
time. What two requests do to each other had never been tried, there or here.

`platform/tools/ci/two-writers.php` tries it. Two PHP processes, each with its own connection, load the same
records, wait at one gate and call the same action. Fifty runs a step. The second writer starts between nothing
and three quarters of the way into the first one's work, so the two meet at different places. Before the fifty
runs, the same work is done four times by one writer after the other: what the step expects must hold then too,
or two at once say nothing.

After every run the command reads the books: every entry balances · no entry number, receipt number or document
number is given twice, and none is left out · no document is in the journal twice · no entry is reversed twice ·
no invoice is paid beyond its total, and each agrees with its allocations · no payment is allocated beyond its
amount · each bill agrees with its payments · the receivable and the payable account say what the documents say.

| Kind | What must happen | The 29 steps |
|---|---|---|
| One record, two writers (16) | Exactly one writes. The other is refused in the action's own words, or waits and is shown what the first wrote | one payment allocated · allocated to an invoice chosen by hand · one payment verified · two payments, each for the whole of one invoice · the whole of one invoice collected · two payments recorded, each for the whole of one invoice · an invoice voided while its payment is verified · a payment verified while it is sent back · one bill paid · one payment of a bill sent twice · one payment request sent for two bills · one bill received · one invoice posted · one draft invoice issued · one entry reversed · one new bill saved twice |
| A record each (13) | Both write | two part payments of one invoice · the next numbers of one series · two journal entries · two draft invoices issued · two payments of two clients verified · two payments recorded for two invoices of one client · two invoices of two clients collected · two invoices of one client collected · two suppliers' bills paid · two new bills saved · two expenses · two paid costs of two suppliers · two bills of one supplier received |

## What two writers did to the code as it was

**Nothing wrong was ever written.** No money twice, no entry out of balance, no number given twice. The locks
that the studio's hardening put in do what they are for.

**But in ten of the 29 steps the database stopped one of the two.** Each writer waited for something the other
held. A database ends one of them when it sees that (a deadlock). That person got the page that says something
went wrong on our side. Nothing of theirs was saved. A second try worked.

Runs of 50 that held, with the classes of `main` under the new command, in CI (commit `e820e31`; the first
reading, `7fe80c2`, gave the same ten steps):

| Two people, at the same moment | MySQL 8.4.13 | MariaDB 10.11.19 | MariaDB 11.4.13 |
|---|---|---|---|
| each record a payment for the whole of one invoice | 0 | 0 | 0 |
| each record a payment, for two invoices of one client | 0 | 0 | 0 |
| post the same invoice to the ledger | 12 | 12 | 12 |
| each record an expense | 12 | 12 | 13 |
| each pay a supplier's bill | 16 | 18 | 13 |
| send one payment request for two bills | 17 | 18 | 16 |
| each collect an invoice of one client | 26 | 26 | 26 |
| each save a new bill | 26 | 26 | 22 |
| each record a paid cost of a supplier | 28 | 26 | 25 |
| each issue a draft invoice | 35 | 39 | 29 |

Every run that failed was a run ended by the database; in none of them was anything wrong in the books. The
other 19 steps held in all 50 runs on all three servers. On the sandbox's older server (MariaDB 10.2.11) the same
ten failed and one more: the whole of one invoice collected by two people.

**How often in real use.** The command makes the two meet on purpose. In an office the two saves must fall
within the same fifth of a second. With three people that is seldom; it gets likelier with more people, with
timed work such as recurring invoices, and once the shop's orders arrive while somebody is working. This is
defect D-040, medium: a workaround existed (do it again), and no figure was ever wrong.

## The four causes, and the cure

Three things a database does that the code had not reckoned with:

1. **A question asked with a lock about a row that is not there holds the empty place where the row would go.**
   Two writers may hold the same empty place. Each must then wait for the other before it can write into it.
2. **A lock on "the rows where …" locks every row the database reads on its way**, not only the ones it returns.
3. **Writing a row that points at another row holds that other row for reading.**

| Cause | Where | Cure |
|---|---|---|
| A posting asked "is this document in the journal already?" with a lock, and only then waited for the row every posting needs (the one that gives the next entry number) | `PostJournalEntry`, `PostBillToLedger` | The posting takes **the ledger's turn** first, and asks inside it (`LedgerTurn`, new). A bill that is already locked by its own row asks without a lock |
| The allocation locked "the open invoices of this client" as a list. Collecting held its own invoice first. Recording wrote the payment first | `AllocatePayment`, `CollectPayment`, `RecordPayment` | Every open invoice of the client and every invoice that is named is locked **by its own key, lowest first, before anything is written** (`InvoiceLocks`, new). Which invoices the money reaches is decided on the locked rows |
| Paying a bill asked for the request's key with a lock | `PayBill` | Asked without a lock: the bill's own row is held already. A key met twice is answered "This payment request was already used with different details." |
| A supplier's cost asked its two questions with locks before any turn was taken | `SupplierCostReference` | The supplier's row, then the turn, then the questions |

One order everywhere: **the document's own row, then the ledger's turn, then the question.** Seven classes
changed, two added. No migration: no fault needed a new key in the database.

**After the cure**, in CI on each of the three servers (`8549036`): all 29 steps hold in every one of 50 runs,
and the two writers were really at work at the same time in 47 to 50 of the 50.

**For every place no step reaches.** A request that the database ends because another writer was in its way (a
deadlock, a wait that ran out, SQLite's "database is locked") is now answered 409 with a page of its own:
"Somebody else was saving the same records in that moment. Nothing of what you just did was saved. Do it again."
It used to be the page for a fault of the server. It is still written to the log. The code does not try a second
time by itself (DL-036, point 2).

## What this merge changes on each live site

| Site | Changes at the merge |
|---|---|
| Hosted workspace `woodex-os.vercel.app` | Rebuilt from `main`. Its database is SQLite, one writer at a time, so the fault could not happen there and the cure cannot be seen there. Feature status shows a new entry under "What is new" |
| Shop `woodex-2030.vercel.app` | Rebuilt from `main`; nothing of the shop is in this pull request |
| Supabase | Nothing |
| Branch `hostinger-release` | Rewritten by CI with the release of the merge commit. No new migration. CI now publishes it only when the two-writers job is green as well |
| A real installation, once there is one | It runs on MySQL or MariaDB: this is where the round counts |

## 1. Commit/branch/build identifier

Branch `arena/3108d4dd-woodex-2030`, from `main` at `ea9dd71`. Pull request #45.

| Commit | What |
|---|---|
| `8549036` | T-093: the command, the repairs, the tests, the CI job, the 409 answer, a database server for the sandbox |
| `7fe80c2`, taken back by `d6d69e1` | An experiment, marked as not a state to keep: the seven classes as on `main`, under the new command, so that CI says what the three real servers do with them |
| `20eb093` | A red run names every step in its first line; a fault in the books is charged to the run that made it; the check of the bill's lock gets 150 runs; the release note; the rule in `AGENTS.md` |
| `e820e31`, taken back by `7f07f0a` | The experiment once more, read with the new first line: the first reading showed ten failed steps, and GitHub shows ten errors of a job step and no more |
| the commit that carries this report | The records of the round |

Built files: none changed. `platform/public/build` is as on `main`.

## 2. Environment

- Sandbox: PHP 8.5. The suite on SQLite. Two writers on MariaDB 10.2.11, the one database server the sandbox can
  reach, built by `platform/tools/preview/sandbox/database.sh` (DL-036, point 5).
- CI: the suite on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4. Two writers on the same three (8.4.13, 10.11.19
  and 11.4.13, each at its own default, REPEATABLE-READ). The rehearsal of Vercel's runtime with the demo's
  settings and with the product's. The Hostinger release installed on Apache with MariaDB.
- No live system was written to.

## 3. Test command and result

| What | Result |
|---|---|
| Whole suite in the sandbox (`tools/preview/sandbox/run-suite.sh`) | **2,568 passed, 36 skipped, none failing** (15,289 assertions). The skips are the tests that need MySQL; CI runs them |
| The suite in CI, on each of the three servers (`8549036`) | 2,569 passed, 35 skipped |
| Two writers in CI (`8549036`) | **29 steps, 50 runs each, all held** on MySQL 8.4.13, MariaDB 10.11.19 and MariaDB 11.4.13 |
| Two writers in the sandbox, on the code as merged | 29 steps, 50 runs each, all held on MariaDB 10.2.11; the two met in 45 to 50 of 50 |
| New tests | `Feature/Accounts/TwoWritersOrderTest` (10) · `Feature/ErrorPagesTest`, extended to 18 |
| Journeys in a browser, in CI (`8549036`) | 37 of 37 steps with the demo's settings and 37 of 37 with the product's; 253 pages opened as four roles. Not run in the sandbox in this round: the line has no screen |
| CI on the last pushed head | Read before the merge; every check-run green is the condition of the merge and is written in the pull request |

**That the check bites** is part of the same CI job, on each server. The writers are given a copy of one class
with its locks taken out, and the job fails unless the fault is then reported:

| Without the locks of | The fault that must be reported | Runs of 50 that reported it: MySQL 8.4 · MariaDB 10.11 · MariaDB 11.4 |
|---|---|---|
| `InvoiceLocks` | an invoice paid beyond its total (860,010 paisa on an invoice of 430,005) | 34 · 34 · 38 |
| `Numbering` | a number was given twice | 50 · 50 · 48 |
| `ReverseJournalEntry` | an entry reversed twice | 50 · 46 · 50 |
| `PayBill` | a bill paid twice | 6 · 6 · 6 |

The first line is what the plan asked for under "against". The last one bites in about one run of eight, because
without the lock the database stops one of the two in most runs; it gets 150 runs from now on, so that "never in
all of them" cannot happen by chance.

**What the new tests refuse.** A posting that asks whether the document is posted before it has the ledger's
turn. A payment written before its invoices are locked. A new lock in the accounts, sales or projects code that
is not on one row by its own key, until it is put on a list with its reason (the test reads the code). A request
ended by the database that is answered as a fault of the server.

**Tried against their protection taken away:** with six lines put back as on `main`, 7 of the 10 order tests
fail. With the line that turns the database's refusal into the 409 answer deleted, 5 tests fail. With the catch
of a key met twice broken, "one payment request sent for two bills" holds in 0 of 20 runs.

## 4. Browser/device coverage

None. The line has no screen. The 409 page is rendered by tests, as a page and as JSON; nobody looked at it in a
browser. A Livewire action that fails is shown by Livewire in a window of its own: how the 409 answer looks
there was not seen.

## 5. Defects open/closed by severity

40 on record. Closed in this round: **D-040**, medium (above). Open: 1 critical (D-002, the old dashboard's owner
password: T-021, the owner's), 6 medium, 4 low; none of them new.

**Found and cured inside the round, never released:**

- The writers ran the files as they were before an edit: this PHP keeps compiled scripts on disk, and the
  writers were started without the switch that turns that off. A repair seemed to change nothing. The command
  now starts its writers with that cache off.
- The command asked the books "is anything wrong?". After the first invoice paid twice, every later run
  reported the same invoice, and the next step stopped at its first run under that invoice's name: a reader was
  told the wrong step. It now asks what each run made wrong.
- A failing run wrote one error for each step. GitHub shows ten errors of a job step and no more, and here they
  are all a reader has: with eleven steps failing, the eleventh would not exist. The first error is now one line
  for every step.

## 6. Migration reconciliation

None. 108 migrations, as on `main`. No table, column or key changed, and no record of any live system is moved.

## 7. Backup/restore status

Nothing to back up: no live data is touched, and the hosted workspace holds none. Backups on Hostinger are
T-187, the owner's.

## 8. Performance observations

Measured in the sandbox, on MariaDB 10.2.11 on one machine; a host will give other figures.

- One writer alone: 6 to 26 milliseconds for a posting, a bill, an expense or a number; 140 to 260 milliseconds
  for the steps that also make a document (issuing an invoice, verifying, collecting or recording a payment).
- **Postings are written one at a time.** That was so before: every posting takes its number from one row. It
  now waits for that row first. Two people who verify payments of two different clients at the same moment: the
  second waited for the first and took 0.37 seconds instead of 0.2. Whether making the document has to be inside
  that wait was not looked at.
- The longest any writer took in 1,450 runs of two at once was 0.41 seconds.
- A request waits at most as long as the database allows (50 seconds unless the host changed it) and is then
  answered 409.
- The CI job takes four to seven minutes on each server.

## 9. Screenshots or recording of key flows

None: there is no screen. The record is the command's own output. CI keeps it for seven days with every run
(`two-writers.json` and the logs), and the first lines are on the run's page: what each step did in its fifty
runs, and how often each check bit.

## 10. Explicit owner approval status

- Merging when every check is green: approved (DL-026).
- T-093 as built: **not yet seen by the owner**, and there is nothing to see on the hosted workspace but the new
  entry under "What is new".
- Decided by the agent and the owner's to overrule (DL-036): one posting at a time; no silent second try; 29
  steps on three servers where the plan said six on two; the code of `main` run under the new command in CI,
  as commits that were taken back and are in the history; the sandbox's database server.
- **Asked of the owner on 18 November and still open:** where the system that keeps data is to run (the ten
  steps of document 24, or a database of his for the Vercel site); whether Feature status may link to the old
  dashboard; the price list, a messaging account and a Facebook Page.
- No module is accepted and no gate is passed; that is unchanged.

## What is not proved

- Anything on the Hostinger account, as before (document 24). Which database server and version that account
  runs is not known until the trial installation (T-006). Tried: MySQL 8.4, MariaDB 10.11 and 11.4, and by hand
  MariaDB 10.2. Not tried: MySQL 8.0, MariaDB 10.6.
- **Actions that have no step.** A milestone invoice, a recurring invoice, a recurring expense, a project's cost
  and its posting, a voided bill, a proof of payment sent by a client, quotations, and everything in the CRM,
  the catalogue and the inbox. Those of them that post to the ledger do it through the same turn; their own
  locks before it were not tried. For all of them the 409 answer is what stands.
- **Mixed pairs.** Only two were tried (an invoice voided while its payment is verified; a payment verified
  while it is sent back). A bill paid while it is voided, an invoice edited while it is collected, and the like,
  were not.
- More than two writers at one moment.
- A save ended by the database, seen in a browser (point 4).
- Any other setting of the database than its default.

## What comes next

Round 3 of the plan: the rules of `/api/v1`, which the shop will read (T-063).
