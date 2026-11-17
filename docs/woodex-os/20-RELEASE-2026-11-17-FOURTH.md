# WOODEX OS — release report, 17 November 2026, fourth merge

The report of pull request #40, in the ten points of document 10 §9, written **before** the merge (document 16
§8; the lesson of defect D-014).

**On whose word.** The owner's "start next" of 17 November, and his standing answer that the agent merges into
`main` when every check is green (DL-026). The two lines taken are the first two of "Now, needing nobody" in the
master list: the Content-Security-Policy (T-068) and request numbers with structured logs (T-062).

## What this merge changes on each live site

Compared file by file, `1b7e78c` (today's `main`) against the head of the pull request: 11 files of the admin's
code and settings, 14 views, 2 test files, 2 files of the sandbox's tooling, 3 browser scripts, 1 workflow,
`AGENTS.md` and the documents. **No file of the React shop, of its
API or of its dashboard is changed** (`apps/`, `api/`, `vercel.json`, `package.json`, `package-lock.json`: none).
Of the shared `scripts/` folder only the three scripts that drive a browser through the admin are changed
(`os-journeys.mjs`, `os-screens.mjs`, `os-notice.mjs`); the shop's build does not use them.

| Site | What is different after the merge |
|---|---|
| **The hosted admin demo**, `woodex-os.vercel.app` | Rebuilt by Vercel from `main`. (1) Every page is sent with a Content-Security-Policy, and the browser enforces it: a script runs only if it is the site's own file or carries the number made for that one answer. A person sees no difference; the three Print buttons of the reports and two "click to select" fields work as before, by another mechanism. (2) Every answer carries a request number in the header `X-Request-Id`, and every error page shows it under the time, with "Give this number when you ask for help with this page". (3) Vercel's log of the demo is one JSON object a line, each with the request number; a page that does not exist or may not be opened now leaves one line there |
| **The React shop**, `woodex-2030.vercel.app` | Rebuilt by Vercel from `main`, from the same shop files as today. Nothing changes for a visitor or for staff |
| **The shop's database** (Supabase) | Nothing. It was not read or written in this round |
| **GitHub** | The check from outside asks five more things of the admin demo on every run: the policy is sent, it lets no script in from elsewhere, it refuses plug-in content, the answer carries a request number, and the error page shows that same number. On a branch they are only written down; on `main` a failure opens an issue |
| **Hostinger** | Nothing |

**If a screen breaks on the demo because of the policy.** Set `SECURITY_CSP=report` in the project's settings on
Vercel and redeploy: the browser then blocks nothing and only writes to its console what it would have blocked.
`SECURITY_CSP=off` sends no policy.

**The way back.** Vercel keeps the deployments that are live now and can put either back; in the repository,
`git revert -m 1` of the merge commit.

---

## 1. Commit/branch/build identifier

| | |
|---|---|
| Branch | `arena/0e8b01c0-woodex-2030`, pull request #40, from `main` at `1b7e78c` |
| Commits | `12c0e45` the policy, the request number, the log's form, their tests, the browser scripts and the outside check · then the commit that carries this report and the records |
| Merge commit | Not known when this was written. It is on pull request #40 |
| Live now | Shop: Vercel deployment `dpl_8vADdne29yD6or3tV7sxUxxZa7dc`. Admin demo: `dpl_3ZFU3sgBNqmzvKDG1k8cbAW9bxqx`. Both from `1b7e78c`, read at 18:50 UTC |

## 2. Environment

| Where | What ran there |
|---|---|
| Sandbox | PHP 8.5 (Vercel's own build, from npm), SQLite, Node 22, headless Chromium. It reaches GitHub and npm only: not the live sites. One command now rebuilds it: `bash platform/tools/preview/sandbox/setup.sh` |
| Rehearsal | The `platform/` tree served by Vercel's PHP runtime and launcher, with the demo's settings; once more with the client's pages switched off, as the product ships |
| GitHub Actions | PHP 8.4 with MySQL 8.4 for the Laravel suite; Node for the shop's gate; the rehearsal with a browser; the outside check of both live sites |
| Vercel | Read through the connection the owner switched on: the two production deployments. Nothing was changed there |

## 3. Test command and result

| Command | Result |
|---|---|
| The Laravel suite, `sh tools/preview/sandbox/run-suite.sh`, on SQLite | **2,484 passed, 36 skipped, 0 failed** (14,694 assertions). After that count one name was added to a list of routes and one assertion to its test; the files concerned were run again: 132 pass. Before this round: 2,435 (report 19 says 2,434; it was counted before that pull request's last test was added) |
| `tests/Feature/Security/ContentPolicyTest.php` | 16 pass. The header is pinned word for word; every screen of the menu is opened as owner, sales and accountant and read as a browser under the policy would read it; every view file is read for a script without the number, a handler in the markup and a `javascript:` address. Tried against 15 ways of taking the protection away (no policy sent, the same number every time, a script left unmarked in each layout, an `onclick` put back, scripts in the page allowed again, the setting ignored, a route forgotten, and others): each fails at least one test |
| `tests/Feature/Observability/RequestNumberTest.php` | 33 pass. Ten kinds of answer carry the number; six error pages and the update notice show the number the answer carries; the number on a fault's page and on a refusal's page finds its lines in the log; a token, a client's public address, an e-mail address and a query string never reach the log; a queued job's line keeps the number. Tried against 21 ways of taking the protection away: each fails at least one test |
| `node scripts/os-journeys.mjs` with `OS_STRICT=1`, on the rehearsal, policy enforced | **28 of 28 steps**, nothing refused by the browser; the same with the client's pages off. One step is new: the quotation's PDF is drawn in the on-screen preview (a script fetched when the button is pressed, a worker, the document's fonts), the part a policy breaks first, which no check had pressed |
| `node scripts/os-screens.mjs`, on the rehearsal, policy enforced | 248 pages as four roles, each on a desktop and at 390 px: nothing refused, no console error, none not fine |
| The same two scripts with one layout script left unmarked | The crawl: 3 of 3 pages not fine, exit 1. The journeys: exit 1 although every step held. Both scripts now fail on anything a browser refuses under the policy |
| By hand in the browser, policy enforced | The three Print buttons call the browser's print once each. A click selects the whole of a link field, in each of the three ways the views write it. A handler written into the markup does not run. A screen part that fails shows the error page, with its number, inside the screen and nothing is refused; with the page's own script left in, one thing is |
| A form that is answered with a redirect to another site | Tried in the browser on a small page: with `form-action 'self'` the browser stops the redirect and the button does nothing. "Connect Google" and "Link Google sign-in" are such forms; where the Google module is on, the policy therefore names Google's sign-in address. The module is off in the product and in the demo |
| The outside check's five new questions (`vercel-live-check.yml`) | Run by hand against the rehearsal as if on `main`: all five hold. Against a bare server that sends neither header: four fail (the fifth asks that the policy lets no script in from elsewhere, which an absent policy cannot fail), and the check fails. Against the live demo they can hold only after this merge; the run after the merge asks them |
| `node scripts/verify-acceptance.mjs`, `npm run docs:verify`, `node scripts/secret-scan.mjs` | Clean |

The checks of the commit that carries this report are on pull request #40. The merge waits for all of them.

**Not tried:** the policy and the request number on Vercel's own network — the sandbox cannot reach it; the run
"Live check after a merge" asks for both about eight minutes after the merge. Safari and Firefox. A screen of a
switched-off module in a browser (the tests read their views; a browser did not open them).

## 4. Browser/device coverage

Desktop at 1366 × 900 and a phone at 390 px, headless Chromium, with the policy enforced: every screen as owner,
sales manager, sales and accountant after the journeys, 248 pages; both journeys, each step by the role that does
it. Not covered: Safari and Firefox; a real phone; a screen reader and the keyboard alone (T-054).

## 5. Defects open/closed by severity

32 in the register (document 16 §5). Not closed: 1 critical, 0 high, 6 medium, 3 low. Closed: 22.

| In this pull request | |
|---|---|
| Closed | D-012 medium: the admin sent no Content-Security-Policy |
| Open as before | D-002 critical: the old dashboard's password was printed in a public document (T-021, the owner's step) · D-011 medium: no backup known (T-187) · the others of document 16 §5 |
| Left open on purpose | The policy still allows `eval`: Alpine, which every moving part of a screen uses, needs it. It stops a script that was put into a page, not one that an expression builds. New line T-072 |
| Read in the code, not tried | "Connect" of a connector that signs in at another site (System → Integrations) asks Livewire's in-page navigation to follow a redirect to another site, which it cannot do, with or without the policy. The Google module is off. New line T-073 |

## 6. Migration reconciliation

No migration is added, changed or applied: neither Laravel's 106 nor the shop's 20. The shop's database was not
read in this round; at 17:35 UTC its ledger held 20 rows for the 20 files.

## 7. Backup/restore status

Unchanged: no backup of the shop's database could be confirmed (D-011, T-187). Nothing in this merge writes to a
database that holds real records.

## 8. Performance observations

On the rehearsal, not on Vercel's network, with the policy and the request number: the first byte of a page at a
median of 50 to 58 ms and a 95th percentile of 83 to 93 ms, by role; a page ready at a median of 119 to 132 ms.
Before this round: 55 to 59 ms, 82 to 95 ms and 110 to 125 ms. The journey step that opens a quotation
and draws its PDF on the screen takes about 3 seconds. Not measured: a cold start on Vercel (T-154).

## 9. Screenshots or recording of key flows

No new picture is stored. Each run of the job `browser-on-vercel-runtime` keeps for 14 days what the browser did:
the account of every journey step and the report of every screen, now with what the browser refused, if anything.

## 10. Explicit owner approval status

| | |
|---|---|
| This merge | The owner's standing answer of 17 November: the agent merges when every check is green (DL-026); and his "start next" |
| What a page may run, and what stays allowed for now | The agent's decisions inside the line (DL-030). Each can be returned by the owner, and the setting `SECURITY_CSP` undoes the enforcement without a new build of the code |
| The request number, and what a log line may hold | The agent's decisions inside the line (DL-031). Each can be returned by the owner |
| Any module, any gate | Not accepted. No acceptance sheet is signed (document 16 §4) |
