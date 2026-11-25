# Release report, 25 November 2026: the shop's cures

Written before the merge, on the owner's word of 25 November ("start complate", decision
[DL-048](12-DECISION-LOG.md)). It is the first of the two pieces he started. The second, the link that sends
products from the admin to the shop (T-105 with finding AU-13), is its own pull request and is **not** in this one.

Everything here is about the React shop. No file of the Laravel admin (`platform/`) changed.

## What was wrong

The audit of 23 November ([43](43-DEEP-AUDIT-2026-11-23.md)) opened the built shop in a browser, 24 pages at a
desk's width and at a phone's. This round takes nine of its findings and one question that had stood since 16
November.

| Finding | What a visitor met |
|---|---|
| AU-03 | "+92 300 1234567" as the telephone number, in nineteen places of nine files; three WhatsApp links to that number beside one to the real one; mailboxes at `woodex.pk`, which is not the business's domain; three different streets for one head office; four more showrooms with sample streets and numbers nobody can dial |
| AU-08 | On a phone every page was 44 px wider than the screen and slid sideways |
| AU-09 | The quotation page carried the bytes of its logo where the picture's address belongs, and showed no logo |
| AU-10 | Every visit asked the admin twice (categories, photographs) and was refused twice, on a shop that is connected to no admin |
| AU-11, AU-25 | An address that is no page answered "200 OK" with the home page's title; an unknown article showed the list of articles |
| AU-12 | Buttons that are only a drawing had no name for a screen reader; fields had no label; buttons smaller than a finger |
| AU-22 | Search engines were told of a logo at an address where no file is |
| AU-24 | The map on Contact led to `google.com/maps/undefined`; the photograph at its top was garbled |
| Q-11 (AU-02) | The demo, with sample products at sample prices, invites search engines in |

## What was built

**The facts in one place.** `apps/storefront/src/config/brand.ts` holds the telephone number, the WhatsApp
number, the e-mail address, the street, the point on the map and the site's address, each once, with the link
that dials, writes or opens the chat made from it. Every page reads them from there; none writes a number or a
mailbox out any more.

- The telephone and WhatsApp number **+92 322 4000768** and the e-mail address
  **woodexofficefurniture@gmail.com** are the owner's words of 25 November.
- The street, **LG 89, Zainab Tower, Model Town Link Road, Lahore 54700**, and the point on the map are **not**
  his words. They are read from the Google listing that his own site shows on `woodexfurniture.pk/contact`. The
  hours ("Mon–Sat: 9am–7pm") are the ones the shop already showed. Both wait for him.
- What nobody confirmed is gone, not replaced by a guess: the showrooms in Karachi, Islamabad, Rawalpindi and
  Faisalabad; the footer's five social buttons, which led nowhere; the newsletter field, which sent nothing.
  Contact and Showrooms show one showroom.

**A test that reads what the pages say.** `scripts/test-storefront-facts.mjs` (`npm run test:facts`): the facts
agree with each other; no other file of the shop writes out a telephone number, a mailbox, or a link that dials,
writes or chats; none of the samples that stood in the shop is anywhere in it; no picture is imported into the
code. With `--built` it reads every page of `dist/`: each carries the number and a link that dials it, none
carries a sample or a picture's bytes, and what search engines are told (the structured data) names the same
number, the same street and a logo that is really there. It runs inside `npm run vercel:build`, which CI and
Vercel both run: a sample cannot be deployed again.

**The phone.** The top line of the header gave way: the language and the currency, which are words and not
controls, are shown from a tablet's width. "Add to Cart" on a product was 20 px high on a phone and is 44. The
closed quote basket no longer throws its shadow down the right edge of every page, and its buttons are no longer
reached by the Tab key while it is closed.

**Names.** Every button that is only a drawing says what it does (search, quote basket with its count, cart with
its count, menu, the slider's arrows and dots, grid and list, one more and one fewer of a named product, the
light and dark switch). Every field on the 24 pages has a label. The search button of the header is a link to the shop.

**The estimate's logo** is a file at `/img/woodex-logo.jpg`. The script that writes the pages ahead of time
(`scripts/prerender.mjs`) read pictures as text; it no longer loads a picture at all, and the test above refuses
an imported picture.

**An address that is no page.** The page says "Page not found" (or "Series not found", "Product not found") in
its title and asks search engines not to keep it. An unknown article is "not found", not the list. Where the
shop's own server serves the shop (the Hostinger shape), such an address now answers **404**: the list of the
shop's addresses stands in `apps/api/public-routes.mjs`, and `scripts/smoke-hostinger.mjs` fails when the shop's
router and that list differ.

**The demo and search engines.** `vercel.json` sends "noindex, nofollow" for the address
`woodex-2030.vercel.app` and for no other. This is Q-11 as it was recommended on 16 November. It was done without
an answer because from this merge the demo shows the real telephone number beside sample products. The live
check asks for the header after every merge and four times a day.

**A shop connected to no admin does not ask one.** On Vercel the build knows whether the two links are on
(`scripts/build-vercel.mjs` reads the same settings the server reads) and tells the shop, which then sends no
request. Document [29](29-API-V1-RULES.md) is unchanged in what it rules: asked, the shop's server answers 503
`SWITCHED_OFF`, and a shop built anywhere else still asks.

## What a person sees differently

- One number to call and to chat, one address to write to, one showroom, the same on every page, in the footer,
  on the estimate and in what a search engine is told.
- On a phone the page stays still; the buttons are large enough to press.
- The estimate shows the logo.
- A mistyped address says so.

## What this merge changes on each live site

| Site | Change |
|---|---|
| `woodex-2030.vercel.app` (the demo shop) | Everything above. No setting on Vercel changed, no database touched |
| `woodex-os.vercel.app` (the admin demo) | Nothing. It is built again from the same files |
| `woodexfurniture.pk` (the owner's own site) | Nothing. It still shows sample numbers of its own, and still closes itself to search engines |
| Hostinger | Nothing is installed there |

## 1. Commit/branch/build identifier

Branch `arena/bd1e6bb4-woodex-2030`, from `main` at `f9e3662`. One commit carries the code, the tests, this
report, the decision and three pictures. 53 files: 33 of the shop's own code (one new), the logo moved from the
code to where pictures are served from, 2 of the shop's server, `vercel.json`, `package.json`, 6 scripts (one
new), one workflow, 5 documents and 3 pictures.

## 2. Environment

- Sandbox: Node 22, the shop built by `npm run vercel:build` twice: as CI builds it, and with `VERCEL=1` as
  Vercel builds it. Served by the shop's own server on SQLite. Chromium at 1366 × 900 and at 390 × 844.
- CI (`ci.yml`): Node 24, the embedded PostgreSQL and a browser. Its result is on the pull request; this report
  was written before it ran.
- Vercel: nothing written. One reading, of the shop's last deployment: it was ready 205 seconds after the push, so
  the live check, which waits 480, meets the new build.

## 3. Test command and result

| Command | On `main` (`f9e3662`) | On this branch |
|---|---|---|
| `node scripts/test-storefront-facts.mjs` | **fails: 92 findings** | passes: 134 files read |
| the same with `--built`, inside the build | not there | passes: 16 built pages |
| `node scripts/test-media-sync.mjs` | fails on the new case ("a shop built as not connected must not ask for the list of pictures") | passes |
| `node scripts/test-native-category-sync.mjs` | fails on the new case ("…must not ask for the categories") | passes |
| `npm run typecheck:all` | passes | passes |
| `npm run vercel:build` | passes, 16 of 16 pages written | passes, 16 of 16, as CI and as Vercel build it |
| `npm run smoke:render` | passes | passes |
| `npm run smoke:hostinger` | passes | passes, 28 checks. Eleven are new: five addresses the shop has a page for answer 200, four it has none for answer 404, the server's list of addresses and the shop's router are one, and the shop's shell is told apart from the dashboard's |

The admin's suite was not run: no file it reads changed. CI runs it all the same.

## 4. Browser/device coverage

Chromium, the built shop, 24 pages, each at a desk's width and a phone's. The same script, before and after.

| | `main` | This branch |
|---|---|---|
| Pages wider than a phone's screen | 23 (by 44 px) | **0** |
| Controls without a name | 228 at a desk, 251 on a phone, on 23 pages | **0** |
| Fields without a label | 25 | **0** |
| Buttons under 24 px | 34 | 2 at each width (the two consent boxes; each stands inside its label, which is what a finger presses) |
| Pages with a refused request | 24 of 24, two each | none (built as Vercel builds it). Two addresses asked on purpose answer 404 |
| Sample contact facts | on every page | none |
| Links that call, write or chat | `tel:+923001234567`, `wa.me/923001234567`, and a mailbox at woodex.pk | `tel:+923224000768`, `wa.me/923224000768`, and the owner's mailbox |
| `/this-page-is-not-there` | 200, the home page's title | **404**, "Page not found", "noindex" |
| An unknown series, article, product, published page | 200; only the product said "noindex" | 200, each with its own title and "noindex" |
| The logo's bytes in the quotation page | there | none; `/img/woodex-logo.jpg` answers 200 as a picture |

Not covered: a real telephone, Safari, Firefox; a screen reader itself (the names were read from the page, not
heard); the estimate as a printed sheet.

## 5. Defects open/closed by severity

No defect was entered: the register is blocked by pull request #48 (finding AU-19), so the cures are **still owed
to the register**. By the audit's findings:

- **Cured:** AU-08, AU-09, AU-10, AU-12, AU-22, AU-24, AU-25 (medium and low).
- **Cured in part:** AU-03 (high): the telephone, the WhatsApp number and the e-mail address are his; the street
  is read, not confirmed. AU-11 (medium): 404 on the shop's own server; on Vercel 200 as before, see below.
  AU-02 (high): the demo is closed to search engines; his own site is his decision.
- **Open, untouched:** AU-01 (**critical**: the old dashboard's password is public; only the owner can change
  it, and he asked to be guided through it), AU-04, AU-05, AU-06, AU-07, AU-13 to AU-19, AU-21, AU-23, AU-26,
  AU-27.

## 6. Migration reconciliation

No migration, no change to any database.

## 7. Backup/restore status

Nothing that needs a backup changed. No backup of the shop's live database is known (finding AU-05), as before.

## 8. Performance observations

On the demo each visit sends two requests fewer, and the server function is called twice less. The quotation
page no longer carries the logo's bytes (36 KB by the audit's measure); the logo is a file of 19 KB that a
browser keeps. Nothing was timed.

## 9. Screenshots or recording of key flows

- [`evidence/shop-2026-11-25-home-phone.jpg`](evidence/shop-2026-11-25-home-phone.jpg): the home page at 390 px.
- [`evidence/shop-2026-11-25-contact-desktop.jpg`](evidence/shop-2026-11-25-contact-desktop.jpg): Contact, with
  the number, the mailbox and the one showroom.
- [`evidence/shop-2026-11-25-product-phone.jpg`](evidence/shop-2026-11-25-product-phone.jpg): a product at
  390 px, with "Add to Cart" at its full height.

## 10. Explicit owner approval status

Ordered by the owner on 25 November ("start complate"). He has not seen the result. Merged by the agent when
every check is green, by his standing word of 17 November (DL-026).

## What is not cured, and why

- **The street and the hours are not confirmed.** They are the best reading there is, and they are on every
  page. If either is wrong, one line of `brand.ts` corrects all of them.
- **The public mailbox is the one the owner signs in to Vercel with.** A separate mailbox for the public would
  be safer: the address on a shop's page is the first one a stranger tries.
- **Named clients and counts** (AU-04) and the line "2025 WOODEX Collection" on the home page are the
  business's own claims: untouched.
- **On Vercel an unknown address still answers 200.** One rule of `vercel.json` hands every address to the shop.
  Changing it means listing every address there too, and a missed one would close a page that works. The page
  says "not found" and "noindex", and the whole demo is closed to search engines.
- **The Google font** (AU-23) still comes from Google's servers.
- **The old dashboard** at `/dashboard` is as it was.

## What is not proved

- That Vercel sends the header for the demo's address: its router cannot be run in the sandbox. The live check
  after the merge asks for it and fails loudly if it is missing.
- That search engines have dropped pages they already hold. That takes them days or weeks.
- How the pages sound in a screen reader, and how they look on a telephone that is not Chromium at 390 px.

## What comes next

1. The owner changes the old dashboard's password (AU-01). The clicks were given to him on 25 November.
2. T-105 with AU-13: the admin hands published products to the shop, with "price on request", as its own pull
   request.
3. The owner confirms the street and the hours, and says whether there is a showroom outside Lahore and which
   social accounts are the business's.
