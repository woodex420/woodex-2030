# WOODEX OS — release report, 21 November 2026: the twenty screens that ship switched off

The report of pull request #56, in the ten points of document 10 §9, written **before** the merge (document 16 §8;
the lesson of defect D-014).

**On whose word.** Three messages of the owner on 21 November, between about 16:15 and 16:30 UTC: "Start next 20
off fix first then next"; "Start 20 on the demo until they are fixed now upgrade and deep analayize qa start
complete project reimagin nd laraval admin and report"; "Fix do not reply demo upgrade it and production ready
start". They were read as: the twenty screens stay on in the demo; cure them first; do the work instead of asking;
the merge brings the demo up to date. The merge stands on his answer that the agent merges into `main` when every
check is green (DL-026). What the agent decided on the way is DL-046.

**Why this round.** Since 07:33 UTC on 21 November the public demo runs with every module switched on (DL-045).
Twenty screens that the product ships switched off are in its menu for everyone who signs in. Report 41 had opened
each of them once and found that none fails. Nobody had pressed their buttons, and nobody had read them.

**What this round is.**

| What was wrong | What is so now | State |
|---|---|---|
| The twenty screens spoke for another business: the Interior studio whose application this one came from | No screen of the menu, for any of the eight roles, with every module on, names "Interior" or calls the business "the studio" | Cured; held by a test that opens every screen both ways |
| Three screens that **do** ship said "the studio" too, and the statement of account a client is sent said "the studio's records" | They say "Woodex" and "our records" | Cured. The test had never read a hub's tabs; it does now, and a second test reads every sentence that is not a screen |
| The website assistant would have introduced itself as "Woodex Interior, an interior design and furniture studio"; the website chat asked a visitor about "a wardrobe, a full home interior … or a kitchen"; captions were drafted "for an interior design studio" | Each takes the name from System → Settings and the trade from the line that is sold: office furniture | Cured |
| The time of a WhatsApp message would have been kept five hours early (report 38) | It is kept as the moment it was sent | Cured |
| With Conversations on, every screen asked the server every ten seconds | Only while the chat bubble on the website is switched on | Cured |
| Estimations, Rates: a red error printed twice, with a folder of the server in it | The tab says that there is no rate list yet, and offers nothing that cannot work | Cured |
| Four Website screens showed no demo notice, six headings that nobody sees, fields without a name, a link that led nowhere | One heading, the notice, no dead link; a name on every field a screen shows when it opens, and on the fifteen search fields their scripts draw later | Cured |
| Redirects: choosing a page from the list did not fill the form | It does | Cured |
| Thirteen Website screens show empty lists and do not say why | Nine say in one line what these screens manage. The three of them that list pages say "No page yet"; they said that no page matched a search nobody had typed. The other four draw their own document (see "What is not cured") | Partly |

**What it is not.** Nothing was changed on Vercel, on Supabase or on Hostinger. No table of any database changed,
and no record. No file of the shop changed. No package changed. The product ships with the same modules off as
before. The next round of the plan (T-054, T-046) is not in this pull request.

## What was found

### By pressing every button

A browser signed in as the owner on the demo as it is deployed, opened each of the twenty screens, the form of a
new estimate and the Client portal, and pressed every button and tab on it, one at a time, each on a fresh copy of
the page.

| Run | Presses | Failed |
|---|---|---|
| The first, before anything was changed (16:25 UTC) | 67 | none |
| The last, on the code of this pull request (17:20 to 17:26 UTC), 22 screens | **187** | **none** |

"Failed" would have been an error page, a failed request, or a message in the browser's console. Two presses ask
"are you sure" and were answered yes; one of them restored the built-in sequences into the demo's records, which
are thrown away.

**The first run did not reach the Page builder.** It looked for buttons inside the main part of a page. The Page
builder draws its own document and keeps its buttons outside that part, so nothing in it was pressed. The last
run looks at the whole document on the four screens that do this: 111 of its 187 presses are the Page builder's.
An earlier run also asked for WhatsApp Templates at a wrong address; the last asked at the right one and pressed
its nine buttons. All pages and Forms have links and no button.

So what was wrong with the twenty was not that they break. It is what they say.

### By reading them

The application came from the Interior studio's. On 17 November every screen of the menu was read for the word
"Interior" and for "studio", and the test `FurnitureOnlyTest` has held that since. But it opened the screens as
the product ships: with the twenty off. It had never seen them. With every module on it found the words on
**seven** screens for the owner: Automations, Sequences, Theme, Speed, and Blog, Portfolio and Forms (which carried
the hidden sign-in panel of the studio's old admin: "Woodex Interior — Run the whole studio from one place").

The same test follows the tabs of a screen "one level deep". A hub writes its tabs as `?tab=aging`, without the
screen's address before it, and the test looked for the address. So **no tab of any hub had ever been read**.
Three screens that ship said "the studio": Due payments ("What the studio is owed"), Payables by age ("Every rupee
the studio still owes") and Saved replies (two variables named `{studio_name}` and `{studio_hours}`).

A screen is not the only place a sentence is read. Every string of the application and every view was then read
by a script. What it found that no screen shows on opening:

| Where | What it said |
|---|---|
| The statement of account, a PDF a client is sent | "This statement reflects the studio's records" |
| The chat window on the website | "Chat with the Woodex studio", "Connecting to the studio…", "Private studio conversation" |
| The built-in instructions of the website assistant | "You are the website assistant of Woodex Interior, an interior design and furniture studio in Lahore … interiors, furniture, wardrobes, kitchens, office fit-outs" |
| The website chat, after the visitor's number | "what do you need done — for example a wardrobe, a full home interior, an office fit-out, or a kitchen?" |
| The instructions for a caption draft | "You draft social media captions for an interior design studio in Lahore … write about thoughtful interiors" |
| What a client reads on the portal about their project | "Our designers are working on the drawings and the 3D views of your space"; "polishing, fixtures, paint"; "Your space is ready" |
| Messages to staff | "the studio was told instead" (a reminder that could not be sent), "Meta moved the studio number", "run the studio from here" (the Telegram bot) |
| Fields, as examples | "Renovation of DHA Phase 6 house" (the title of a new quotation), "Send revised kitchen layout" (a task), "Client wants a modern kitchen with island" (a visit), "10 marla lounge", "rates per sq ft of covered area", "civil structure work, MEP by others" (an estimate), "Yes — Woodex Interior designs and installs across DHA Lahore" (the assistant's knowledge), `/apartment-interior-design/` (a redirect), "Studio electricity", "Studio rent" (an expense) |
| The Website Studio's scripts | The title of a new article ended "\| Woodex Interior", and its publisher, as search engines read it, was "Woodex Interior" |

The assistant, the chat and the captions cannot have said any of this to anybody: the modules are off as shipped,
no model key is set, and the demo keeps nothing. They would have said it on the first day the owner switched them
on.

### Four faults that are not words

1. **Estimations, Rates.** The tab printed "The estimator rate book is missing: /…/public/assets/js/estimator-rates.js"
   twice, in red, with the folder of the server. The file is the Interior website's price calculator, which
   Furniture's copy does not have. The editor under it is in square feet and 3D views, and its "Publish" writes a
   file that nothing here serves.
2. **Redirects.** The list "…or pick a published page" wrote its choice into the field above and did not tell the
   form. The form sent what had been typed before, or nothing.
3. **The chat button.** It stands in the top bar of every screen and asked the server every ten seconds, for anyone
   who may read the inbox: 182 of the owner's 186 pages (report 41). On a real system that is six requests a
   minute from every open tab of every member of staff, for a chat no visitor can start, because the bubble on
   the website is a switch of its own and is off.
4. **The time of a WhatsApp message** (report 38, not cured until now). The moment was made in UTC and the record
   writes the clock of what it is given: five hours early.

## What was built

### Words

71 lines that spoke to a person as "the studio", or for the Interior business, were rewritten in 29 files of the
application, 34 views and 5 files the browser loads. The business is "Woodex", "we" or "the team"; an example in a
field is a furniture one ("Workstations for the head office, DHA Phase 6").

Where the words drive something, they are not a constant any more:

- **The assistant's built-in instructions** (`App\Settings\BotSettings::prompt()`), **the caption draft**
  (`App\Domain\Marketing\Social\ProposeCaptions`) and **the chat's question**
  (`App\Domain\Conversations\Actions\ReceiveWebChatMessage::askWhatIsNeeded()`) take the name from System →
  Settings and the trade from the line that is sold first. For Furniture: "which designs and makes office
  furniture in Lahore, Pakistan … desks, chairs, workstations, storage and complete office fit-outs". The words
  are the shop's own description of itself. Where the Interior brand is sold first, the old words stand. The rules
  that keep a model from inventing a price or promising a date are the same for both.
- **The Website Studio's scripts** are given the brand by the server and write it into an article's title and
  publisher.
- **The two variables** of a saved reply are `{business_name}` and `{business_hours}`. A reply or a sequence step
  saved under the old names is filled as before.

### Two tests that read

- `FurnitureOnlyTest` opens every screen of the menu for each of the eight roles **as the product ships and with
  every module on**, follows the tabs of a hub, and lists every place on a page, not the first.
- `EveryModuleOnTest` reads every string of `platform/app` and every view for the word, with a list of nine files
  where it is kept on purpose, each with its reason (the Interior words where that brand is sold, the old variable
  names, the importer of the studio's old records). A file that goes takes its line with it.

### The four faults

- **Estimations.** Without a rate list the tab "Rates" says so in two sentences and shows no editor. The reader of
  the file no longer puts a folder into its message. The form of a new estimate names every field (ten had a label
  that was tied to nothing), and the field "Service" says that no estimate can be saved until a service has a rate.
- **Redirects.** The list tells the form what was chosen. The list and the box for a pasted sheet have names.
- **The chat button** asks the server only while the chat bubble is switched on (Website settings). A click opens
  it as before.
- **The WhatsApp time** is put on the workspace's clock before it is handed on. A test sends a message written at
  one o'clock at three, and reads one o'clock from the record and from the table; the 24 hours end at one the next
  day.

### The Website screens

- Nine of them (All pages, Theme, SEO, SEO agent, Speed, Site health, Website Settings, Redirects, Find &
  replace) say under their heading: "These screens are for pages this admin serves itself. Woodex's shop is a
  separate application, and nothing here changes it."
- The four that draw their own document (Page builder, Blog, Portfolio, Forms) show the demo's notice, have one
  heading (the hidden sign-in panel of the old admin had six), and name their search fields. "View page" in the
  builder is shown once a page is open.
- **Pages, SEO and SEO agent** list the pages this admin serves, and it serves none yet. They said "No page
  matches — Clear the search or pick another section" and "Every page passes the rules this filter covers". Each
  now says "No page yet", and that a page is made in the Page builder. Where there are pages and a search finds
  none, the old words stand.
- **The search fields a script draws.** The Page builder and the Website Studio draw fifteen search fields after
  a screen has opened: the library's, the icon picker's, the picture picker's. Fourteen had a grey example and no
  name. All fifteen have a name. The crawl saw one of them, because it reads a page as it opens; a test now reads
  the scripts. The example in the search for free photographs was "modern office interior, living room,
  kitchen"; it is "modern office, meeting room, office chair".

## What a person sees differently

On the product as it ships (the twenty off):

- Due payments, Payables by age and Saved replies say "Woodex" where they said "the studio". Saved replies offers
  `{business_name}` and `{business_hours}`.
- The statement of account says "our records".
- Several fields show a furniture example: the title of a new quotation, a task, a visit's notes, a lead's kind
  of property, an expense, a repeating expense, and what the assistant may be taught under Settings. A message of
  the Telegram bot and two notices to staff changed a word.
- What a client reads about the stage of their project names no room, no 3D view and no paint.

On the demo (every module on), beside that: everything in the table at the top.

## What this merge changes on each live site

| Site | What changes |
|---|---|
| The admin demo, `woodex-os.vercel.app` | It is built again from `main`. The twenty screens read as above. The top bar stops asking every ten seconds |
| The shop, `woodex-2030.vercel.app` | It is built again from `main`. No file of it changed |
| Hostinger | Nothing is installed there. `hostinger-release` is written by the workflow, as after every merge |

## 1. Commit/branch/build identifier

Branch `arena/1e99816c-woodex-2030`, from `main` at `412e8f0`. Pull request #56.

| Commit | What |
|---|---|
| `6fd8f74` | The words; the assistant, the captions and the chat's question; the WhatsApp time; the chat button; Estimations; the Website screens; Redirects; the two tests; the release note; DL-046; the notes on five lines of the master list |
| the commit after it | What the crawl and the pictures of `6fd8f74` showed: "No page yet" on three Website screens, the names of the search fields the scripts draw, two more tests, one more line of the release note. And this report, three pictures and the index of documents |

73 files in `6fd8f74`: 29 of the application, 34 views (one new: the line the Website screens share), 5 files the
browser loads, 3 test files (one new), the decision log and the master list. The commit after it: 3 views, 8 files
the browser loads, the release note and the new test file, beside the documents. No migration, no setting, no
package, no workflow, no built file: `platform/public/build` is as it was, because no file it is built from
changed in a way the build reads.

## 2. Environment

- Sandbox: PHP 8.5.12 on SQLite for the suite; Vercel's own PHP 8.5 runtime and launcher for the rehearsal, run
  with what the Vercel project sets (`platform/tools/vercel/as-deployed.conf`: every module on); Chromium for the
  pressing, the journeys, the crawl and the pictures.
- CI (`os-ci.yml`): PHP 8.4 on **MySQL 8.4, MariaDB 10.11 and MariaDB 11.4**.
- CI (`vercel-livewire.yml`): a browser on the rehearsal of Vercel's runtime, on the demo as it is deployed and as
  the product ships.
- CI (`ci.yml`): Node, the embedded PostgreSQL and a browser, for the shop.
- Vercel: its record of the account's activity, read at 16:21 UTC. Reading only. Its newest entry was of 13:58
  UTC, three minutes after pull request #55 was merged: nothing had been changed there since.

## 3. Test command and result

| Command | Result |
|---|---|
| The whole suite in the sandbox on `6fd8f74`, `sh tools/preview/sandbox/run-suite.sh` | **2,865 passed, 36 skipped, 0 failed** (20,499 assertions). 32 more than before: 24 in the new file, 8 in `FurnitureOnlyTest` |
| The same suite in CI on `6fd8f74` | **2,865 pass, 36 skipped, none fails, on each of MySQL 8.4, MariaDB 10.11 and MariaDB 11.4** |
| The whole suite in the sandbox on the commit after it | **2,867 passed, 36 skipped, 0 failed** (20,531 assertions; 17:35 UTC). Two more: both in the new file |
| `tests/Feature/FurnitureOnlyTest.php`, against the code before the cure | 11 of its 16 cases fail: the five roles that reach one of the three shipped screens fail as the product ships, and six fail with every module on |
| `tests/Feature/EveryModuleOnTest.php` | 26 pass; against the code before the change, 26 fail |
| `npm run docs:verify`, `verify-code-references.mjs`, `verify-plan.mjs`, `verify-acceptance.mjs`, `npm run secret-scan` | Pass |
| Pint on the changed PHP files | Pass |

**One check failed on `6fd8f74`, and why.** `ci.yml` stopped at the step that looks up every document the code
names: a test of that commit names this report, and the report is in the commit after it. No other check failed.
The steps of `ci.yml` that come after that one did not run on `6fd8f74`: the shop's batteries and its journeys in
a browser. They run on the commit after it, and the merge is not made unless every check of that commit is green.

**Each new test was tried against the code before the change.** The 26 tests of the new file were run on a copy
of the tree with `platform/app` and the views as they are on `main`: all 26 fail there, each for its own reason.
The one about the time reads 08:00 from the record for a message of 13:00. The ones about the headings count six
on Blog, Portfolio and Forms and two in the builder. The one about the search fields lists the fields without a
name; in the files of `main` they are 14 of 15.

## 4. Browser/device coverage

Chromium, on the rehearsal of the demo as it is deployed.

| What | Result |
|---|---|
| Every button and tab of the twenty screens, the form of a new estimate and the Client portal, pressed one at a time as the owner, 1366 px, on the last code | 22 screens, 187 presses, none fails. No screen shows a field without a name when it opens |
| The journeys (`scripts/os-journeys.mjs`, strict), on `6fd8f74` | 40 of 40 steps held, none failed, none left out (17:01 UTC) |
| The crawl (`scripts/os-screens.mjs`, strict), on `6fd8f74`: every screen a role can reach, at a desk and on a phone | **390 pages, none fails** (owner 186, sales manager 83, sales 66, accountant 55; 17:10 UTC). Two downloads, both fine. No page wider than its window, no control smaller than 24 px, no link that leads nowhere |
| An idle screen watched for 45 seconds, on the last code | Leads and Quotations: one request each, the notification bell's |

**What the crawl counted, beside "none fails".**

| What | Report 41, on `main` | Now |
|---|---|---|
| Pages that ask the server every 10 seconds, as the owner | 182 of 186 | 2, both of them the Inbox, the screen where messages arrive. One more asks every 15 seconds: Leads, the tab of the website chats |
| Pages with a field without a name, as the owner | 7 | 1: the Page builder, a search field its script draws. Named in the commit after the crawl |
| Links that lead nowhere | 1 | 0 |
| Pages without the demo's notice | 4 named: the screens that draw their own document | 2, and neither is a screen: a picture opened on its own (`/admin/media/1`), and the preview of a product's page |
| Pages without exactly one first heading | 2 | 1: the picture opened on its own |
| Pictures without a description | 1 | 1: the picture opened on its own. The browser draws that page, not the admin |

The crawl ran on `6fd8f74`. What changed after it (three empty lists, the names of fifteen search fields, one
line of the release note) was proved by the last run of the pressing, by the pictures, by the suite, and by the
crawl that CI makes of the last commit.

Not covered: Firefox and Safari; a real phone; a screen reader. The crawl checks names and headings, not how a
screen sounds.

## 5. Defects open/closed by severity

44 on record, 35 closed: as before. Open are 1 critical (D-002, the old dashboard's owner password: T-021, the
owner's), 7 medium and 1 low (D-033, the timer).

**Found in this round, all cured in this pull request, each held by a test:**

| What | Severity |
|---|---|
| The website assistant, the website chat and the caption draft spoke for another company and another trade | Medium. Nothing was said to anybody: the modules are off as shipped and no model key is set |
| The time of a WhatsApp message would be kept five hours early (found by report 38) | Medium. No message has arrived |
| The chat button asked the server every ten seconds from every screen | Medium, with Conversations on |
| The statement of account a client is sent said "the studio's records" | Low. On the product as it ships |
| Three shipped screens and seven of the twenty called the business "the studio" or named "Interior" | Low |
| Estimations, Rates printed a folder of the server, twice | Low. To a signed-in member of staff who may edit the website |
| Redirects: a page chosen from the list did not reach the form | Low |
| Pages, SEO and SEO agent said that no page matched a search, where there is no page and nothing was searched | Low |
| Fourteen search fields that a script draws had no name | Low |
| Four Website screens without the demo's notice; fields without a name on four screens; six headings nobody sees; a link that led nowhere | Low |
| The test of decision D-008 never read a hub's tabs, nor a screen of a module that ships off | A gap in a test, not a fault of the product |

**Still owed to the register:** these; the four of report 41; the six of report 40; and "newest first" from report
34. The register allows no gap in its numbers, and the next free ones (D-045 to D-052) are taken by pull request
#48, which is not merged (DL-044, point 15).

**D-002 is as urgent as report 41 said.** The demo links every visitor of Feature status to the old dashboard's
sign-in, and that dashboard's owner password was printed in a public document and is not changed.

## 6. Migration reconciliation

Nothing. The admin keeps its 109 migrations and 126 tables. No migration is in this pull request. The two
variables of a saved reply were renamed in the code, not in the records: a reply saved under the old names is
read as it is.

## 7. Backup/restore status

Nothing was written to any database, so nothing had to be backed up. That no backup of the shop's database is
known to exist (T-187, the owner's) is unchanged.

**The way back** is to revert the merge. No record, no table and no setting has to be put back.

## 8. Performance observations

- **Requests from an idle screen.** Leads and Quotations were each left open for 45 seconds as the owner, with
  every module on: **one request** each, the notification bell's, which asks every 30 seconds. Before this round
  the chat button asked every 10 seconds beside it, on 182 of the owner's 186 pages (report 41): four or five more
  requests in the same 45 seconds. That "before" is reckoned from the timer report 41's crawl read; it was not
  measured again in this round.
- **The suite is slower.** The job that holds the two tests took 145, 150 and 171 seconds in CI on `6fd8f74`
  (MariaDB 10.11, MariaDB 11.4, MySQL 8.4), where it took 122, 107 and 128 on `main`: 23 to 44 seconds more. The
  test that opens every screen of the menu now does it twice for each of the eight roles.
- Nothing else that takes time changed. No query was added to a screen; the chat button's four counts are still
  made when a screen is drawn.

## 9. Screenshots or recording of key flows

In `docs/woodex-os/evidence/`:

| Picture | What it shows |
|---|---|
| `twenty-2026-11-21-rates.jpg` | Estimations, Rates now. Before, it printed the red error twice, with the folder; no picture was taken of that, the words are quoted under "Four faults that are not words" |
| `twenty-2026-11-21-website-screen.jpg` | Pages, a Website screen: the line that says what these screens manage, and "No page yet" |
| `twenty-2026-11-21-forms.jpg` | Forms, one of the four that draw their own document, with the demo's notice in its top bar |

## 10. Explicit owner approval status

- **Merging:** on the owner's standing answer (DL-026), when every check is green.
- **What the owner has not approved, and may overrule** (DL-046): the words the assistant, the chat and the
  captions are given for Furniture; the new names of the two variables; that the Rates tab shows no editor until
  there is a rate list; the sentence on the Website screens.
- **Still not answered:** whether the two settings put on the Vercel project on 21 November were his (DL-045).
- **Nothing here switches anything on.** The product ships with these modules off, and the demo is the only place
  they are on.

## What is not cured, and why

These are on the twenty screens today, and no rewording cures them.

1. **The ready-made content of the Website Studio is the Interior studio's.** The block library and the page
   templates of the Page builder ("Woodex Interior · Lahore — Spaces shaped by purpose and identity"), the starter
   questions of an article ("How much does interior design cost in Lahore?"), the list of forms on the Forms
   screen ("3D brief", "Estimator", "Book a visit"), the three ready-made themes, the areas of Find & replace
   ("Project studies", "Lahore areas"). They are that business's website, kept as a tool. Furniture's own come
   when the shop's pages are built there (T-115, T-116). Until then a person who opens the library sees another
   company's pages.
2. **Blog, Portfolio, Forms and the Page builder do not carry the sentence about what the Website screens
   manage.** They draw their own document; the sentence would have to be laid into that. The other nine say it.
3. **Estimations knows square feet and 3D views.** Furniture is priced by the piece. The rates and their unit come
   with the price list (T-003, T-085).
4. **A lead still has a size in square feet, and the lead rules score by it** ("1 marla = 225 sq ft"). On the
   product as it ships. It is a field of the record and of the scoring, not a word: it wants a decision of what a
   furniture enquiry is measured by.
5. **The stages of a project** are Planning, Design, Procurement, Execution, Finishing, Handover. Their names were
   not touched; only what a client is told about them.
6. **The article template's contact details.** A page an article is published to carries an e-mail address and a
   telephone number written into the template. They were not checked against Woodex Furniture's own.
7. **The Theme screen says "v1 palette".** It is the first palette of the website these screens manage. Left.
8. **What the Page builder draws after a click was not read.** The pressing and the crawl read a screen as it
   opens, and the pressing reads it once more after each press for an error. The fields the builder shows when an
   element is selected, and the windows it opens, were not read for a name on every field, nor for their words.

And as before, code gives none of these: Furniture's rates for Estimations; a WhatsApp or Telegram account for
Conversations, Chat Bot, WhatsApp Templates, Automations and Sequences; a Facebook Page for Marketing.

## The whole project, and what stands between the demo and production

The owner asked for the complete project to be looked at: the React shop (which
`docs/laravel-os/00-REUSE-PROPOSAL-AND-ADMIN-MASTER-PLAN.md` calls "the reimagined front end") and the Laravel
admin.

**What was run in this round, over the whole.** For the admin: the suite, the journeys and the crawl above. For
the shop: its own gate in CI on the last commit of this change (`ci.yml`: the API's batteries on SQLite and on
the embedded PostgreSQL, the storefront's and the old dashboard's journeys in a browser), and the outside check
of both live sites after the merge. **The shop was not explored by hand in this round**, as the twenty screens
were: nobody pressed its buttons one by one or read its pages for what they say. That is the next piece of this
request.

**What stands between today and production is not in the code of this round.** The master list
(`14-PRODUCTION-TODO.md`) has 153 lines: 51 done, 10 that can start now, 63 that wait for an earlier line, 29
that wait for the owner. Fifty of the 63 stand behind an answer of his. The ones the most stand behind:

| What only the owner can give | Lines behind it |
|---|---|
| Where the system that keeps data is to run (T-006) | 20 |
| Furniture's price list, terms and signers (T-003) | 8 |
| A domain and its mail (T-004) | 4 |
| The old dashboard's owner password changed (T-021, defect D-002, critical) | The one critical defect |

The demo keeps nothing: it has no permanent database. Until T-006 is answered there is no place for a real record
to be kept, and "production ready" has no place to be true in.

## What is not proved

- That the live demo reads as the rehearsal does. It is read after the merge by the outside check, and the
  result goes to the owner in the message that follows the merge: this report is closed by the merge.
- That a model answers a visitor well with the new instructions. No model key is set anywhere; the test reads the
  instructions, not an answer.
- The time of a WhatsApp message against a real message of Meta's. The test builds the message Meta documents.
- Urdu. The assistant is told to answer in the visitor's language; nothing was changed or tried there.

## What comes next

1. The shop, by hand, as the twenty were: every page pressed and read.
2. Round 3 of the plan: T-054 and T-046.
3. T-142, the timer of the outside check. Three runs were due on 21 November by 17:29 UTC (03:47, 09:47 and
   15:47). One had come, at 11:49. It failed, and opened issue #54 nineteen seconds later, which pull request #55
   cured and closed at 13:55. The count goes on until the run due at 09:47 UTC on 22 November.
