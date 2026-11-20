# WOODEX OS — release report, 20 November 2026: packages and housekeeping

The report of pull request #52, in the ten points of document 10 §9, written **before** the merge (document 16 §8;
the lesson of defect D-014).

**On whose word.** The owner's message of 20 November, about 13:29 UTC: "start next continue compalte all task". The
merge stands on his answer that the agent merges into `main` when every check is green (DL-026). What the agent
decided on the way is DL-043.

**Why this round.** The first round of the plan is still the shop's reading of its products (T-105), which is built
in pull request #48, from another conversation, and waits there for the owner. This was the next round of the plan
(document 21): seven small lines.

**What this round is.**

| Line | What is so now | State |
|---|---|---|
| T-071 | The packages are up to date. Composer and npm report no advisory but one, which is accepted with a written reason. The audit fails from now on, on any advisory that is not on a written list | Done |
| T-153 | The rehearsal measures the function that Vercel runs, prints its size and fails above 240 MB. It is 188.4 MB, not the 226 that document 13 gave | Done |
| T-174 | The three documents of the Interior studio that the admin's code names are in this repository, unchanged. A check fails when the code names a decision, a document or a requirement that nobody can look up | Done |
| T-189 | Pull request #29 is closed, with a comment that says where its work is | Done |
| T-152 | The built stylesheets and scripts with a marked name are sent with a life of one year | Built. **Open** until the live site has been read after the merge |
| T-154 | An answer for which the function had to be started says so in a header, and the outside check times its first request | Built. **Open** until three cold readings are written down |
| T-142 | The outside check's timer moves to another minute | Written. **Open** until the timed runs have been counted again |

**What it is not.** No screen changed, in the admin or in the shop. No database was read or written. Nothing was
done on Vercel, on Supabase or on Hostinger by hand.

## What was built

### T-071 — the packages, and an audit that can no longer pass over an advisory

- **Composer: 48 packages moved**, each to the newest version that `composer.json` allows. `laravel/framework`
  goes from 13.39.0 to 13.43.0; its one advisory (GHSA-63hm-g96r-2m2q, low, corrected in 13.40.0) is gone, and
  Composer's audit reports none. The whole list is at the end of this report.
- **The sandbox cannot reach Composer's package index**, so the update is run by GitHub. The workflow
  `composer-update.yml` runs `composer update` on a GitHub machine, prints every package that moves, audits the
  result and commits the lock file to the branch it was started from. It refuses `main`. Its commit here is
  `28a19f6`, by GitHub's own account, within half a minute of being started.
- **npm: 18 packages moved, three are new, one is gone.** `react-router-dom` goes from 6.30.6 to 7.18.6, a new
  major version, because no version 6 carries the correction of its two advisories. The linter's eleven packages
  go from 8.71.0 to 8.73.0, which brings a corrected `minimatch`. No line of the shop's code had to change.
- **Of npm's four advisories three are corrected. The fourth is accepted, with its reason in writing:**
  GHSA-rj75-hqrm-r3gf (moderate). It is in a copy of `postcss-selector-parser` that the plugin
  `@tailwindcss/typography` uses while the shop's stylesheet is built. A very long selector can keep that parser
  busy. The selectors it reads are the ones written in this repository; nothing a visitor sends reaches it, and
  it is not part of what the shop serves. The plugin names exactly that version in every version it has
  published, so there is no corrected version to move to.
- **The audit fails on an advisory of any severity that is not on a written list.** Until now it stopped at
  high: the low and the eleven moderate reports of defect D-028 passed it for three days. The list is
  `docs/woodex-os/acceptance/advisories.json`. The audit also fails on an entry without a reason, on an entry
  that nothing reports any more, and on one whose severity has risen since it was accepted
  (`scripts/audit-advisories.mjs`).
- **Every Monday the audit also prints which packages have a newer version.** Today: none of Composer's, 64 of
  npm's. That is read at the start of a round.
- **One test had to be put right, and no code of the application.** From Laravel 13.43 a test closes every
  database connection when it ends, and asks the database manager for them. One test
  (`tests/Feature/Assistant/DiagnosticsTest.php`) puts a stand-in in the manager's place to see that an error's
  text is not shown to a person; the stand-in could not answer the new question, and the test failed when it
  ended. It now puts the real manager back before it ends. What it tests is unchanged.

### T-152 — one year for the built files with a marked name

Vite gives the stylesheets and scripts it builds a name that changes when their content changes. Those eight
files are now sent with `Cache-Control: public, max-age=31536000, immutable`. Until now a browser was told to ask
again for each of them on every page (`public, max-age=0, must-revalidate`, read from the live site on
20 November at 13:55 UTC).

- Not covered, on purpose: the two built files with a fixed name (`site.css`, `site.js`), the build's manifest,
  every page, and an address with a marked name that is no file.
- The rule stands in the part of `vercel.json` that Vercel applies after it has found a file to serve
  (`handle: hit`). A rule there can add headers and nothing else.
- Document 13 §8.

### T-153 — the size of the function

- `platform/tools/vercel/function-size.cjs` counts the files that Vercel's builder puts into the function. The
  rehearsal runs it on every change under `platform/`, prints the size as a notice on the run, and fails above
  240 MB. Vercel's limit is 250.
- **188.4 MB in 11,220 files**: Vercel's PHP runtime 115.6, the application's packages 53.6, `public/` 7.9,
  tests and tools 2.3, the rest 8.9. Before the packages moved it was 187.8.
- **Document 13 and the master list said 226 MB, 153 of it the runtime. That could not be reproduced**, and how
  it was measured is not recorded. Both are corrected.
- Document 13 §7.

### T-154 — a cold start says so

- The entry file sends `X-Woodex-Cold-Start: 1` on an answer for which the function had to be started. It
  knows, because its working folder under `/tmp` is not there yet. Every later answer of that copy has no such
  header.
- The outside check's first request is the sign-in page, timed by itself. Its notice says the time to the first
  byte and whether the answer carried the mark.
- Document 13 §9, where the readings are written as they are taken.

### T-142 — the timer

GitHub's list holds six timed runs of the outside check since the timer came to `main` on 17 November, for
thirteen times due. The times due at 06:23 and 18:23 UTC brought a run every time, between 1 h 08 min and
2 h 43 min late. The times due at 00:23 and 12:23 UTC never did. Of the last four times due, two brought a run;
the plan's rule was three. So the timer is written again: **03:47, 09:47, 15:47 and 21:47 UTC**.

### T-174 — what the code names can be looked up

The code of `platform/` came from the Interior studio's system, and its comments say why a thing is so by naming
that system's records: "D-58", "docs/06 §4", "QUO-006". None of them was in this repository.

- **Three documents are in `docs/laravel-os/source/` now, unchanged**, from the studio's public repository at
  the commit this admin was copied from (`603428c`): its decisions, its requirements, its instructions for
  Hostinger. Each begins with a note that says whose document it is and where this admin differs. Everything
  after the note is held to the checksum of the studio's file.
- **`scripts/verify-code-references.mjs` reads the code and fails, with the file and the line**, when it names a
  decision, a numbered document, a section of a kept document or a requirement that can be looked up neither
  there nor in this repository's own records.
- **1,065 names in 624 files.** Looked up: 47 decisions, 119 requirements, the three documents.
- **What cannot be looked up is listed once, by hand, with its reason** (`unresolved.json`):
  - 22 other documents of the studio. They define no number the code uses and are not copied.
  - **Five decisions the studio never wrote down: D-107, D-111, D-112, D-115, D-116.** Its record ends at D-104,
    at that commit and at its newest. What they decide can only be read from the comments that name them.
  - Four names that only look like a requirement code (SHA-256, AES-256, two sample values in tests).
- No comment in the code was changed to make that list shorter.
- **The documents are copied whole.** They hold the studio's telephone numbers, its e-mail addresses and the
  names of two of its people, as the studio published them. Each of these was in this repository already, but
  for one mailbox address. No password is in them.

### T-189 — pull request #29

Closed on 20 November at 14:24 UTC, with a comment. Read again before closing: its work is in `main` as commit
`e82b386`, which differs from the pull request's last commit in five lines of `package-lock.json` only. Its
migration, its rollback file and its screen are in `main` byte for byte. Its branch is left as it is.

## What a person sees differently

Nothing. No screen, no address and no wording changed in the admin or in the shop.

## What this merge changes on each live site

| Site | What changes |
|---|---|
| The shop, `woodex-2030.vercel.app` | Vercel builds it again from `main`. **Its pages are then built with `react-router-dom` 7 instead of 6.** This is the one change of the round that reaches a visitor of the shop. Nothing is meant to look or behave differently |
| The shop's database (Supabase) | Nothing |
| The admin's demo, `woodex-os.vercel.app` | Vercel builds it again, with Laravel 13.43.0 and 35 other packages of what it runs with moved. A browser keeps the eight built files for a year. The first answer of a newly started copy carries one more header |
| Hostinger | Nothing is installed there. CI writes the release branch from `main` when the admin changes, so the release will carry the new packages |
| GitHub | The audit fails on any advisory that is not on the list. The outside check's timer moves. Pull request #29 is closed |

## 1. Commit/branch/build identifier

Branch `arena/4d8d7988-woodex-2030`, from `main` at `b16fd49`. Pull request #52.

| Commit | What |
|---|---|
| `890b41d` | T-152, T-153, T-154, T-142: the rule for one year, the measurement of the function, the mark on a cold start, the timer |
| `6d0b463` | T-071: the shop's packages, the audit with its list, the workflow that runs Composer's update |
| `28a19f6` | T-071: Composer's new lock file, committed by that workflow |
| `cc9b695` | T-174: the three documents, the check and its test. T-071: the one test put right. Document 13 |
| the last commit | This report and the records of the round: the master list, the plan, the decision log, the defect register, the index of documents |

## 2. Environment

- Sandbox: PHP 8.5 on SQLite for the suite; Vercel's own PHP 8.5 runtime and launcher for the rehearsal.
- CI (`os-ci.yml`): PHP 8.4 on **MySQL 8.4.13, MariaDB 10.11.19 and MariaDB 11.4.13**. This is the proof for the
  new packages.
- CI (`ci.yml`): Node, the embedded PostgreSQL and a browser, for the shop.
- CI (`audit.yml`, `composer-update.yml`): the only places that reach the advisory databases and Composer's
  package index.
- The live sites: asked once from a GitHub runner, reading only, on 20 November at 13:55 UTC, before the merge.

## 3. Test command and result

Every result below is of commit `cc9b695` unless it says otherwise.

| What | Where | Result |
|---|---|---|
| The Laravel suite on the new packages | CI, each of the three servers | **2,773 pass, 35 skipped**, as before the update |
| The same | Sandbox, SQLite, 14:28 to 14:36 UTC | 2,772 pass, 36 skipped. The run before the test was put right: 2,771 pass, 1 fails |
| Two writers at the same moment | CI, each of the three servers | Passes, and the check still bites with each lock taken out |
| Both journeys and every screen | CI, a browser on the rehearsal | 39 of 39 steps as the product ships and 39 of 39 as the demo is deployed; 253 pages opened as four roles |
| The release for a host, installed and deployed again | CI, Apache and MariaDB 10.11 | 9 of 9 and 5 of 5 steps; 60 addresses asked from outside; 10,814 files, 103 MB |
| The deployment as Vercel builds it (`check-entry.sh --build --read-only`) | CI; sandbox | Passes. 103 checks, counted in the sandbox |
| The shop's gate: build, types, render check, API tests on PostgreSQL, browser acceptance | CI, `build-and-test` | Passes, for the first time on `react-router-dom` 7 |
| The audit | CI, the run of this pull request | Composer: no advisory. npm: one, accepted on the list |
| The audit's judgement, without a network (`scripts/test-audit-advisories.mjs`) | CI | 30 checks |
| `vercel.json` against the build's manifest (`scripts/test-vercel-livewire-root.mjs`) | CI | Passes |
| What the code names (`scripts/verify-code-references.mjs`) and its test | CI | Passes; 27 checks |
| The documents' links and numbers, the secret scan, the plan and the acceptance files | CI and sandbox | Pass |
| Vercel's own build of this branch, as a preview | Vercel, both projects | Built and ready: the demo with the new `vercel.json` and the new packages (`dpl_BP4y2YSWRyVvP2nvbAkFA6AYSszL`, of `cc9b695`), the shop with the new router. The demo's build log was read: one warning, about `builds` in the configuration file, and nothing about the routes. **The preview's headers were not read**: the sandbox reaches no address of Vercel, and a preview of this project asks for Vercel's sign-in |

**Each new check, tried against its protection taken away:**

| The check | What was taken away or changed | What happened |
|---|---|---|
| The audit, in CI, on `6d0b463` | Nothing: Composer's lock was still the old one | It failed and named the advisory in `laravel/framework`. The next commit carries the new lock, and it passes |
| The audit, on npm's real answer | The reason of the accepted advisory | It fails and names the advisory |
| The same | npm's answer from before the packages moved | It fails and names the three advisories that are corrected now |
| The root test of `vercel.json` | Eight mistakes, one at a time: the rule out; its place out; widened to every built file; widened to the whole folder; the header put on a route that decides; a shorter life; `continue` left out; a third fixed name in `vite.config.js` | It fails each time |
| The rehearsal | The one-year rule out; the rule widened to every built file | Two checks fail each time |
| The size guard | The limit set to 187 MB when the function was 187.8 | It fails: "the function is 187.8 MB, more than the 187 MB allowed here". The rehearsal repeats this on every run, 1 MB below the size it has just found |
| The rehearsal | The mark taken out of the entry file; the mark sent on every answer | One check fails; two checks fail |
| What the code names | 24 changes in a copy, one at a time, among them `07-DECISIONS.md` taken away | It fails each time. Without the decisions it names D-58 and the first file that mentions it. Three more checks must pass, and do: the copy as it is, a line of names that can all be looked up, and longer words that are no reference |
| The link check | A broken link put into the note of a copied document | It fails. The studio's own links below the note are not read: they lead to documents that are not copied |

**The plan asked for one test that cannot be done as written.** "With the limit at 200 MB the job fails" cannot
fail when the function is 188 MB. The limit 1 MB below the measured size is tried instead, on every run.

## 4. Browser/device coverage

No screen changed. CI's browser ran both journeys and opened every screen as four roles on the new packages
(§3), and the shop's browser acceptance ran on the new router. Nothing was looked at by a person.

## 5. Defects open/closed by severity

44 on record. **34 closed**, one more than before: D-028 (low), the advisories that the audit let pass. Open are
1 critical (D-002, the old dashboard's owner password: T-021, the owner's), 7 medium and 2 low.

**Found in this round:**

1. **Document 13 and the master list gave a size of the function that could not be reproduced** (226 MB, with
   153 for the runtime, against 188.4 and 115.6). Corrected in both. Nothing depended on the number but the
   plan's own test.
2. **The audit let a low and a moderate advisory pass for three days**, by its own rule (D-028). Closed.
3. **Five decisions that the code cites were never written down by the studio.** Listed; not a fault of this
   product, and nothing to cure here.
4. **A test that could not end on Laravel 13.43.** Put right in this round, before any merge.

**Still owed to the register, unchanged from report 38:** the month filter of the Leads list (low), the time of
a WhatsApp message that would be kept five hours early (medium; cannot happen today), and "newest first" from
report 34. The next free numbers (D-045 to D-052) are taken by pull request #48, which is not merged.

## 6. Migration reconciliation

Nothing. The admin keeps its 109 migrations and 126 tables; the old system its 21 migrations. No migration is in
this pull request, and no database was read or written. CI's installation on MariaDB 10.11 counts 126 tables
after the new packages.

## 7. Backup/restore status

Nothing was written to any database, so nothing had to be backed up. That no backup of the shop's database is
known to exist (T-187, the owner's) is unchanged.

**The way back for the packages** is to put the two lock files and `apps/storefront/package.json` back as they
were on `main` at `b16fd49`. The audit would then fail on the three advisories, which is right.

## 8. Performance observations

- **The function: 188.4 MB of the 250 allowed.** The package update added 0.6 MB.
- **A first request to the live demo took 2.29 s to its first byte** (20 November, 13:55 UTC, from a GitHub
  runner). Warm, the same page takes 0.08 to 0.17 s. The live build of that moment has no mark, so whether
  those 2.29 s were a cold start is likely and not known. After the merge the answer says it.
- **In the rehearsal on a development machine** a cold start took 0.53 s to the first byte and a warm answer
  0.02 s. That is the application's own share of the wait, not what a visitor of Vercel waits.
- **The built files:** a browser that has loaded the admin once no longer asks for its stylesheets and scripts
  until a new build gives them new names. How much time that saves a person was not measured.
- The suite's time in CI is inside what it varied by before.

## 9. Screenshots or recording of key flows

None: no screen changed.

## 10. Explicit owner approval status

- Merging when every check is green: approved (DL-026).
- Starting this round: the owner's word of 20 November ("start next continue compalte all task").
- **Every decision of this round is his to overrule** (DL-043). The ones he is most likely to want to look at:
  - **the shop is built with a new major version of its router.** It passed every test the shop has. If he sees
    anything in the shop that behaves differently after this merge, that is the first place to look;
  - one advisory is accepted, not corrected, with the reason in §"What was built";
  - the studio's three documents are in this public repository whole, with the studio's telephone numbers,
    e-mail addresses and two names, which were here already.
- **His words answered none of the questions that were open.** They stay open: whether the month filter of the
  Leads list is to be cured ahead of the plan; pull request #48, which waits for his look; where the system that
  keeps data is to run; whether Feature status may link to the old dashboard; the price list, a messaging
  account and a Facebook Page; whether the two old previews of the shop are to be deleted; whether the facts on
  a card are the right ones (report 35); the proposals of the map (report 37) and of the words (report 38);
  whether the handful of records in the live database are trials.
- No module is accepted and no gate is passed.

## Every package that moved

**npm** (`package-lock.json`; `apps/storefront/package.json` now asks for `react-router-dom` `^7.18.6`):

| Package | From | To |
|---|---|---|
| `react-router-dom` | 6.30.6 | 7.18.6 |
| `react-router` (inside it) | 6.30.6 | 7.18.6 |
| `typescript-eslint` | 8.71.0 | 8.73.0 |
| `@typescript-eslint/eslint-plugin`, `parser`, `project-service`, `scope-manager`, `tsconfig-utils`, `type-utils`, `types`, `typescript-estree`, `utils`, `visitor-keys` (ten packages) | 8.71.0 | 8.73.0 |
| `minimatch` (inside `typescript-estree`) | 10.2.6 | 11.0.9 |
| `brace-expansion` (inside `typescript-estree`) | 5.0.12 | 5.0.13 |
| `semver` (inside `typescript-estree`) | 7.8.5 | 7.8.6 |
| `ignore` (inside `eslint-plugin`) | 7.0.12 | 7.0.16 |
| `postcss-selector-parser` | 6.1.4 | 6.1.6 |
| New: `@isaacs/nonrecursive-glob-match` 1.1.0, `cookie` 1.1.1 (inside `react-router-dom`), `set-cookie-parser` 2.7.3 | | |
| Gone: `@remix-run/router` 1.23.4 | | |

Corrected by this: GHSA-g3hv-75wg-m249 (`minimatch`), GHSA-wrjc-x8rr-h8h6 and GHSA-337j-9hxr-rhxg
(`react-router`).

**Composer** (`platform/composer.lock`; `composer.json` is unchanged). 48 of the 166 packages moved; none was
added and none removed. Written from the two lock files, `main` at `b16fd49` against this branch.

In what the admin runs with (36 of 118):

| Package | From | To |
|---|---|---|
| `doctrine/deprecations` | 1.1.6 | 1.2.0 |
| `guzzlehttp/guzzle` | 8.3.1 | 8.5.2 |
| `guzzlehttp/promises` | 3.1.0 | 3.1.1 |
| `guzzlehttp/psr7` | 3.2.0 | 3.5.1 |
| `laravel/fortify` | v1.41.0 | v1.43.0 |
| `laravel/framework` | v13.39.0 | v13.43.0 |
| `laravel/passkeys` | v0.2.1 | v0.3.0 |
| `laravel/prompts` | v0.3.26 | v0.3.27 |
| `laravel/serializable-closure` | v2.1.0 | v2.2.0 |
| `laravel/tinker` | v3.0.2 | v3.2.0 |
| `league/uri` | 7.9.1 | 7.10.0 |
| `league/uri-interfaces` | 7.9.1 | 7.10.0 |
| `monolog/monolog` | 3.12.1 | 3.13.0 |
| `nunomaduro/termwind` | v2.4.0 | v2.4.1 |
| `paragonie/constant_time_encoding` | v3.1.3 | v3.1.5 |
| `ramsey/uuid` | 4.9.4 | 4.10.0 |
| `spatie/image` | 3.9.9 | 3.9.10 |
| `spatie/image-optimizer` | 1.10.0 | 1.10.1 |
| `spatie/laravel-activitylog` | 5.1.3 | 5.1.5 |
| `spatie/laravel-backup` | 10.3.3 | 10.5.0 |
| `spatie/laravel-medialibrary` | 11.23.11 | 11.23.13 |
| `spatie/laravel-permission` | 8.3.2 | 8.5.0 |
| `spatie/laravel-settings` | 3.10.0 | 3.10.2 |
| `symfony/console` | v8.1.10 | v8.1.11 |
| `symfony/deprecation-contracts` | v3.7.1 | v3.8.1 |
| `symfony/event-dispatcher-contracts` | v3.7.1 | v3.8.1 |
| `symfony/finder` | v8.1.10 | v8.1.11 |
| `symfony/polyfill-php85` | v1.43.0 | v1.44.0 |
| `symfony/property-access` | v8.1.10 | v8.1.11 |
| `symfony/property-info` | v8.1.10 | v8.1.11 |
| `symfony/service-contracts` | v3.7.3 | v3.8.1 |
| `symfony/translation` | v8.1.10 | v8.1.11 |
| `symfony/translation-contracts` | v3.7.1 | v3.8.1 |
| `symfony/type-info` | v8.1.10 | v8.1.11 |
| `web-auth/webauthn-lib` | 5.3.10 | 5.3.15 |
| `webmozart/assert` | 2.5.0 | 2.5.1 |

For development and tests only (12 of 48):

| Package | From | To |
|---|---|---|
| `brianium/paratest` | v7.27.5 | v7.29.0 |
| `filp/whoops` | 2.18.7 | 2.19.3 |
| `larastan/larastan` | v3.13.2 | v3.15.0 |
| `laravel/pail` | v1.2.7 | v1.3.0 |
| `laravel/pao` | v1.1.5 | v1.1.6 |
| `laravel/pint` | v1.32.1 | v1.32.3 |
| `nunomaduro/collision` | v8.9.5 | v8.9.6 |
| `pestphp/pest` | v5.3.4 | v5.3.7 |
| `phpstan/phpstan` | 2.3.4 | 2.3.7 |
| `phpunit/php-code-coverage` | 14.5.0 | 14.5.2 |
| `phpunit/phpunit` | 13.4.5 | 13.4.7 |
| `sebastian/version` | 7.0.1 | 7.1.0 |

## What is not proved

- **That Vercel's network sends the one year.** Vercel's own network cannot be run anywhere else. The rule was
  read against the code of Vercel's own tools and rehearsed with them, and Vercel built this branch's preview
  with the new `vercel.json`. Vercel's own builder for Next.js writes its one-year rule in the same place and
  form; it also sets a flag, `important: true`, which Vercel's routing schema calls deprecated and which this
  rule does not set. If the year is not sent, that flag is the first thing to try (document 13 §8). From `main` the outside check requires
  the header on the live stylesheet; it runs eight minutes after the merge. If the network does not apply the
  rule, that check fails and opens an issue. T-152 is open until it has been read.
- **A cold start on Vercel.** No answer with the mark has been read from the live site yet. T-154 is open until
  three have.
- **That GitHub keeps the new minute of the timer** any better than the old one. T-142 is open.
- **The shop on the new router, seen by a person.** Its build, its types, its render check, its router paths
  and its browser acceptance pass. Nobody has clicked through the live shop on it.
- **Each new version of a package, read.** 66 packages moved. Their tests are this repository's suites; their
  changelogs were not read one by one. Laravel's four minor versions changed one thing that a test here met.
- **The size as Vercel counts it.** The 188.4 MB are this repository's count of the files. Vercel prints none.
- **Whether a comment names the right decision.** The check knows that D-58 exists, not that the code beside
  it does what D-58 says.
- **The 22 documents of the studio that are not copied**, and what the five unwritten decisions decide.
- **Hostinger.** Nothing is installed there. The release with the new packages was installed and deployed again
  on a server laid out like the account, in CI.
- **The two pull requests together.** Pull request #48 changes the same records (the master list, documents 16
  and 21). Whichever is merged second has to be brought up to date first.

## What comes next

By the plan (document 21): reading the live site for T-152, T-154 and T-142, which needs nothing built. Then the
dashboard (T-044), then keyboard tests, help, and pinned and recent screens (T-054, T-046). The shop's reading of
its products stays in pull request #48 until the owner has looked at it.
