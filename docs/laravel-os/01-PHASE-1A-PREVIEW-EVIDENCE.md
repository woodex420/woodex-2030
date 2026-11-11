# Phase 1a — synthetic preview of the WOODEX OS source: evidence

**Status: evidence record for an evaluation run. Not an acceptance, not a release.**
The Laravel application is **not** in this repository. It ran from an isolated copy outside the git tree.

**Run:** 2026-11-11 · **Approved by the owner as:** "Run the preview now" (scope: section 9 of `00-REUSE-PROPOSAL-AND-ADMIN-MASTER-PLAN.md`)
**Source:** `woodex420/woodexinterior` @ `603428cca1e45f9da2ffd9c4a0d88eb741285339`, archive sha256 `5e8c271b…2ee25c`

---

## 1. What this run proves, and what it does not

| Proven here | Not proven here |
|---|---|
| The source boots on PHP 8.4 with its locked packages, without the Interior website content | **Anything about MySQL.** The database was SQLite |
| 72 reviewed migrations + 21 settings migrations apply cleanly to an empty SQLite file | Behaviour in a real browser: rendering, desktop and mobile layout, JavaScript, loading states |
| Sign-in, CSRF, session handling, role checks and the lead → quotation → approval → PDF path behave as recorded below, over HTTP | Sign-in through the platform's preview frame as the owner's browser sees it |
| Stored money totals equal independent arithmetic and equal the text printed in the PDF | Provider integrations, mail delivery, queue workers, the scheduler |
| | The source's own test suite (not run), Hostinger, performance, security posture |

HTTP status codes alone were not treated as proof. Where a row below says a screen "renders", the page title and visible text were read; where it says a value was "stored", it was re-read from the database.

---

## 2. What was installed, from where

Everything is under `/home/user/.cache/woodex-os/` (outside git, not persisted). No `sudo`, nothing global.

| Component | Exact source | Verification |
|---|---|---|
| PHP 8.4.21 CLI, static | `NativePHP/php-bin` @ `c4d0b4ad6df1f0fef196c22b2cac0d26b4658b38`, `bin/linux/x64/php-8.4.zip`, through the GitHub API | git blob id recomputed locally = `cc2591752b49bca8c73b897b5c933cb8122ab5d8`; zip sha256 `7d6f8623d6fb3600c01b81a92249558a9f84a042f407c947158ec3f96f0337bd`; binary sha256 `e66af25c7a78985ca69becdb6e4ea4a08c8e122e0fd3755a446c91699e55d578` |
| Composer 2.11.2 | `composer/composer` tag `2.11.2`, run from source with the 30 packages in its own lock | `composer --version` → 2.11.2 on PHP 8.4.21 |
| 166 PHP packages | exact commits in the source's committed `platform/composer.lock`, from `api.github.com` | installed versions and references compared with the lock: 0 mismatches, 0 extras |
| Admin assets | the committed `public/build` | no `npm install`, no build |

Extensions the binary lacks: `pdo_mysql`, `exif`, `xmlwriter`, `xmlreader`, `posix`, `pcntl`.
Not installed: headless Chromium (it was optional and was not approved).

---

## 3. Commands actually run

```
gh api repos/NativePHP/php-bin/git/blobs/cc25917… -H "Accept: application/vnd.github.raw" > php-8.4-linux-x64.zip
python3 bootstrap_composer.py <dir> 2.11.2
python3 make_runtime_copy.py <archive>/platform <runtime>/platform
composer install --no-interaction --prefer-dist --no-scripts --no-progress \
         --ignore-platform-req=ext-exif --ignore-platform-req=ext-xmlwriter
php artisan key:generate --force
php artisan package:discover
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force     (also PipelineStage, NumberSequence, ChartOfAccounts)
php synthetic_fixtures.php <runtime>/platform staff
php synthetic_fixtures.php <runtime>/platform data
php -S 0.0.0.0:8080 -t <runtime>/platform/public front.php
```

Not run: `composer setup`, `woodex:install`, the web installer, `DatabaseSeeder`, `DemoSeeder`, any Interior import command, any test command, any build.
The scripts are kept outside the repository in `/home/user/_woodex_os_source/phase-1a/`, with a `REBUILD.sh` assembled from the commands above. `REBUILD.sh` has not yet been executed end to end as one script.

---

## 4. Runtime copy and database

| Item | Result |
|---|---|
| Files copied from `platform/` | 1,501 of 2,059 |
| Files excluded | 558 — website bundle and legacy data 108; Interior pages and page media 159; photographs 277; site stylesheets, scripts and data 8; public root files 2; price data 1; client list 1; Interior logo 1; Interior 404 template 1 |
| HTML pages left in `public/` | 0 |
| Migrations quarantined | 11 (listed in the proposal, finding F2); none of them ran |
| Migrations applied | 93 = 72 + 21, one batch, 0 failures, 2 seconds |
| Schema | 106 tables, 152 foreign keys, 188 indexes |
| After migration | `integrity_check` ok; `foreign_key_check` 0 rows; all website tables empty |
| Rows written by migrations | 87 settings, 12 description starters (7 Interior, 5 Furniture), 2 document designs (Interior, Furniture), 3 number series |
| Reference seeders run | roles (8) and permissions (45), pipeline stages (8), number series (9 in total), chart of accounts (33) |
| Fixtures | 4 staff on `@woodex.test`; 10 contacts and 10 leads named `SYNTHETIC …`; 5 catalogue lines named `SYNTHETIC — …` |
| Identity | Business name, address, phone, e-mail and terms overridden with synthetic placeholders after the settings migrations had written the Interior values |

---

## 5. Results against the brief's acceptance list (§11)

| Item | How it was checked | Result |
|---|---|---|
| Sign-in, sign-out, session continuity | 21 checks: guest redirect, CSRF token, missing and wrong token (419), wrong password returns to the form with a message and keeps the e-mail but not the password, session id regenerated at sign-in, session carried across pages, sign-out, old cookie rejected | **21 / 21** |
| Sign-in throttle | 7 wrong attempts for an address with no account | 6th and 7th answered 429; another account could still sign in |
| Role-based allowed and denied | Every parameterless admin GET route (97) requested as owner, sales manager, sales and accountant = 388 requests. The expectation for each was derived from the route's own permission middleware and the role's permissions in the database | **352 / 352** derived expectations met; 36 recorded without an expectation; no 5xx |
| Screens render | Owner: 67 pages answered 200 with 66 distinct titles; visible text scanned for error markers | 0 pages with an error marker; median 70 ms, slowest 131 ms |
| Server-side validation | Invalid API body; quotation without a template choice; negative quantity; non-numeric rate | each refused with a field message; database unchanged each time |
| CRM → quotation → approval → PDF | Driven through the real Livewire endpoint as three roles: log a call, move the lead's stage, create a furniture quotation from the lead, add catalogue lines, set quantities, 8 % discount, send refused for the sales role, request approval, accountant and requester both refused (403), sales manager approves, send, PDF, locked after sending, revise, re-send, second PDF | **33 / 33** on a fresh lead and quotation |
| Money | Subtotal, discount and total re-read from the database and compared with independent arithmetic; PDF text extracted and searched for the total and number | 27,600,000 and 30,875,000 minor units as expected; "276,000" and "308,750" found in the PDFs |
| API authentication and contract | `POST /api/public/leads`: invalid 422, valid 201 with `ok`, `reference`, `message`, `whatsapp_url`, honeypot 202, too-fast 202, repeat 200 merged, sixth in a minute 429; bridge and MCP endpoints without or with a wrong key 401 | **16 / 18** — see D1 and D3 |
| Database persistence and integrity | After everything: `integrity_check`, `foreign_key_check`, orphan lines, subtotal = sum of lines for every quotation | ok; 0 violations; 0 orphans; 0 mismatches |
| Assets | Every stylesheet, script and icon linked from an admin page fetched and its content type checked | all load; no absolute self-URL left in pages |
| Empty, success, failure states | Empty leads list text read before fixtures; success and refusal messages read from Livewire responses | present |
| Desktop and mobile interface, loading states | needs a real browser | **not tested** |

Corrections made during the run, stated plainly:

- The first sign-in run scored 20 / 21. The failure was a bug in the preview wrapper written for this run (sign-out redirects to `/`, which the wrapper turned into an empty redirect). The application had signed out correctly. Wrapper fixed; re-run 21 / 21.
- The first workflow run scored 29 / 33. Three failures were mistakes in the checks themselves (a wrong column, looking for a message in the wrong place, expecting the wrong name on the PDF). The fourth is observation D2. Checks corrected and the whole workflow re-run on new records: 33 / 33.
- One exploratory quotation (`WF-10050`) was created while learning the Livewire protocol and remains a draft in the synthetic database.

---

## 6. Defects and observations in the source

| # | Finding | Evidence | Consequence |
|---|---|---|---|
| D1 | An unsigned `POST /webhooks/whatsapp` answers **500** when WhatsApp is not configured | `TypeError: signatureMatches(): Argument #1 ($secret) must be of type string, null given`, `WhatsAppWebhookController.php` line 51 | Nothing is processed, but a public endpoint throws on any request. Supports unregistering the routes of switched-off modules |
| D2 | A not-found raised inside a Livewire action is answered **301**, not 404 | Unknown pipeline stage → 301 to `/livewire-2a76fda2/update/`. The website router's reserved list contains `livewire`; Livewire 4 mounts under `livewire-<hash>` | The lead did not move. The user would see a broken response instead of a message |
| D3 | `GET` on the POST-only lead endpoint answers 404, not 405 | The website fallback route answers every unmatched GET | To decide when the `/api/v1` contract is written |
| O1 | The seeded `accountant` role cannot open Accounts | 403 on all nine `admin/accounts/*` screens; the role has no `accounts.view` | Consistent with the seeder, but needs an owner decision for Furniture |
| O2 | Interior identity is hard-coded in the layouts | After the settings were changed, 64 of 66 owner screens still show "Interior": footer "Woodex Interior, Lahore" in `layouts/admin.blade.php`; "Woodex Interior · Lahore" and an Interior photograph in `layouts/auth.blade.php` | Confirms the branding work in the proposal |
| O3 | Furniture quotation PDFs print commercial wording and names that come from code and default settings | "75% advance payment with work order", "15–20 days" delivery time (`CreateQuote.php`), transport wording (`FurnitureDocument.php`), signatories "Imtiaz Ahmad — Director" and "Nabeel Afzal — Marketing Manager" (`create_sales_settings`) | Must be replaced by wording the owner approves. The delivery time is a promise |
| O4 | The Interior phone number is the example in validation messages | "for example 0322 4000768" in at least 8 files | Branding work |
| O5 | A blank furniture quotation starts with Interior payment milestones | "On completion of civil and wood work", plan `execution_50_40_10` | Furniture terms needed from the owner |
| O6 | Lead capture wrote 4 notification e-mails to the log and 4 in-app notifications; 6 queued jobs were never processed | mail driver `log`; no worker, by design | Expected in this preview |
| O7 | The furniture quotation PDF embeds Inter and DejaVu Sans only | font names read from the PDF | The unconfirmed Nohemi font is not used by this document |
| O8 | What the platform proxy sent | One probe: `GET /`, Host `8080-<sandbox>.e2b.app`, headers `accept`, `host`, `user-agent` only | The public host name reaches the application; no forwarded-protocol header on that probe |

No Interior identity appeared in the furniture PDFs: "Woodex Interior", the Interior address, phone number and domain were searched for and not found.

---

## 7. Differences from the approved scope

| Approved text | What was done | Why |
|---|---|---|
| `--ignore-platform-req=ext-exif` | also `--ignore-platform-req=ext-xmlwriter` | required only by the test packages (PHPUnit); tests were not run |
| "its 29 locked packages" | 30 | Composer 2.11.2's lock has 30 |
| Sign-in, CSRF and permission checks stay on | they did; in addition `X-Frame-Options` is not sent, and PDFs allow any `https` frame ancestor | the live preview shows the application inside a frame on another site |
| Assets and self-links root-relative | done by a front controller outside the application that reuses the source's own `NativePreviewTransport.php`, unchanged; it also sends `/` to `/admin` and treats non-loopback requests as HTTPS | keeps navigation on whichever host the browser uses; there is no public website in this preview |
| Session cookies | `SameSite=None; Secure; Partitioned` | required for a framed, cross-site preview |

The source's "direct synthetic admin" launcher, which skips sign-in, was not used.

---

## 8. Still open after this run

- Sign-in from the owner's browser through the preview frame. If cookies do not reach the application, sign-in will fail with "Page expired"; the request log records which cookie names arrive.
- A real-browser pass (desktop and mobile), which needs headless Chromium to be approved.
- MySQL: migrations and the source's test suite on MySQL 8, in CI.
- Every assumption about Hostinger.
