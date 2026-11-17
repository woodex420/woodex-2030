# WOODEX OS — release report, 17 November 2026, third merge

The report of pull request #39, in the ten points of document 10 §9, written **before** the merge (document 16
§8; the lesson of defect D-014).

**On whose word.** The owner's "start next as you recommend and also update Vercel" of 17 November, and his
standing answer that the agent merges into `main` when every check is green (DL-026, DL-029).

**"Update Vercel."** Both Vercel projects were found already deployed from the merge of pull request #37
(`ef31589`): the shop at 16:26:17 UTC, the admin demo at 16:26:54 UTC, each ready and serving its own domain.
The check that asks both sites from outside eight minutes after a merge passed (run 44125098926). Nothing was
deployed by hand.

## What this merge changes on each live site

Compared file by file, `ef31589` (today's `main`) against the head of the pull request: 13 files of the admin's
code, 7 test files, the documents and one workflow. **No file of the React shop, of its API, of its dashboard or
of the shared scripts is changed** (`apps/`, `api/`, `scripts/`, `vercel.json`, `package.json`,
`package-lock.json`: none).

| Site | What is different after the merge |
|---|---|
| **The hosted admin demo**, `woodex-os.vercel.app` | Rebuilt by Vercel from `main`. (1) A lead takes the value of its quotation when the quotation is sent, unless somebody typed a figure; the client's yes sets it. The pipeline's "Won" then adds up to the sales made. (2) Settings → Sales rules keeps "follow up quotations automatically" as it is ticked; until now every save switched it off. (3) System → Audit log gains a row for an invitation, a change of role, a deactivation, a reactivation and every change of a setting. (4) Nothing else is different on a screen: the other changes are locks and tests |
| **The React shop**, `woodex-2030.vercel.app` | Rebuilt by Vercel from `main`, from the same shop files as today. Nothing changes for a visitor or for staff |
| **The shop's database** (Supabase) | Nothing. It was not read or written in this round |
| **GitHub** | The live check asks three more things on every run: the other address of each project's main branch, and one of the shop's old preview builds, must answer with Vercel's sign-in. On `main` a failure opens an issue |
| **Hostinger** | Nothing |

**The way back.** Vercel keeps the deployments that are live now and can put either back; in the repository,
`git revert -m 1` of the merge commit.

---

## 1. Commit/branch/build identifier

| | |
|---|---|
| Branch | `arena/3dd24f4b-woodex-2030`, pull request #39, from `main` at `ef31589` |
| Commits | `d779033`, `863a85e` the live check reads Vercel's sign-in · `45fb08e` the security tests and what they found; a lead's value from its quotation · `e3cc4b9` a trace for every sensitive action; the follow-up box · `660283d` the same content in another order is no change; the records · then the commit that carries this report |
| Merge commit | Not known when this was written. It is on pull request #39 |
| Live now | Shop: Vercel deployment `dpl_3cu5xk3c6YLNn3PdnLNUUQ8zKRsj`. Admin demo: `dpl_8DLqK2y7LrFV79GnqJ5sxAHHNDNa`. Both from `ef31589` |

## 2. Environment

| Where | What ran there |
|---|---|
| Sandbox | PHP 8.5 (Vercel's own build, from npm), SQLite, Node 22, headless Chromium. It reaches GitHub and npm only |
| Rehearsal | The `platform/` tree served by Vercel's PHP runtime and launcher, with the demo's settings |
| GitHub Actions | PHP 8.4 with MySQL 8.4 for the Laravel suite; Node for the shop's gate; the rehearsal with a browser; the outside check of both live sites |
| Vercel | Read through the connection the owner switched on: the deployments and the two projects' settings. Nothing was changed there in this round |

## 3. Test command and result

| Command | Result |
|---|---|
| The Laravel suite, `sh tools/preview/sandbox/run-suite.sh`, on SQLite | **2,434 passed, 36 skipped, 0 failed** (13,908 assertions). Before this round: 2,359 |
| The same suite on MySQL 8.4, in CI | Failed once, on `e3cc4b9`: two of the new tests. MySQL hands a stored object back with its names in its own order, and a settings screen saved as it was opened was written down as a change. Corrected in `660283d`, with a test that reproduces it on SQLite. Passes since |
| `platform/tests/Feature/Security/`, six files | 66 tests pass. Each kind was tried with its protection taken away and fails then |
| `tests/Feature/Sales/LeadValueFollowsQuotationTest.php` | 10 pass. Without the call in the sending 4 fail; without the call in the acceptance 3 fail |
| `node scripts/os-journeys.mjs` with `OS_STRICT=1`, then `node scripts/os-screens.mjs`, on the rehearsal | 27 of 27 steps, then 248 pages as four roles: nothing wrong. The same in CI |
| The outside check of both live sites, from the branch (run 44127032292) | Passed, with the three new questions: each answers "302 to https://vercel.com" |
| `node scripts/verify-acceptance.mjs`, `npm run docs:verify`, `node scripts/secret-scan.mjs` | Clean |

The checks of the commit that carries this report are on pull request #39. The merge waits for all of them.

## 4. Browser/device coverage

Desktop at 1366 × 900 and a phone at 390 px, headless Chromium: every screen as owner, sales manager, sales and
accountant after the journeys, 248 pages; both journeys, each step by the role that does it. Not covered: Safari
and Firefox; a real phone; a screen reader and the keyboard alone (T-054).

## 5. Defects open/closed by severity

32 in the register (document 16 §5). Not closed: 1 critical, 0 high, 7 medium, 3 low. Closed: 21.

| In this pull request | |
|---|---|
| Closed | D-006 high: the shop's old previews hold the live database's address — closed on what was read and checked, without deleting them · D-024 low: a lead's value did not follow its quotation · D-030 medium: saving the sales rules switched the follow-up of quotations off · D-031 low: three smaller things the security tests found · D-032 medium: six sensitive actions left no trace |
| Open as before | D-002 critical: the old dashboard's password was printed in a public document (T-021, the owner's step) · D-011 medium: no backup known (T-187) · the others of document 16 §5 |

## 6. Migration reconciliation

No migration is added, changed or applied: neither Laravel's 106 nor the shop's 20. The shop's database was not
read in this round; at 16:07:58 UTC its ledger held 20 rows for the 20 files.

## 7. Backup/restore status

Unchanged: no backup of the shop's database could be confirmed (D-011, T-187). Nothing in this merge writes to a
database that holds real records.

## 8. Performance observations

On the rehearsal, not on Vercel's network: the first byte of a page at a median of 55 to 59 ms and a 95th
percentile of 82 to 95 ms, by role; a page ready at a median of 110 to 125 ms. The security tests add about 16
seconds to the suite. Not measured: a cold start on Vercel (T-154).

## 9. Screenshots or recording of key flows

No new picture is stored. Each run of the job `browser-on-vercel-runtime` keeps for 14 days what the browser did:
the account of every journey step and the report of every screen.

## 10. Explicit owner approval status

| | |
|---|---|
| This merge | The owner's standing answer of 17 November: the agent merges when every check is green (DL-026); and his "start next as you recommend" |
| Closing D-006 without deleting the old previews | The agent's judgment on what was read from Vercel and checked from outside (DL-029). The owner can return it: the previews can still be deleted |
| Decisions the agent took inside the list lines | DL-029. Each can be returned by the owner |
| Any module, any gate | Not accepted. No acceptance sheet is signed (document 16 §4) |
