# Phase 2 — the checks, tried against broken code

2026-11-16. The code was changed to do the wrong thing, one behaviour at a time, in a copy of the repository; the check was run; the copy was restored. A check that still passes has not noticed the fault.

The harness was a throwaway script and is not in the repository. What it did is fully described by the two columns below: the fault, and what the check then said.

## Server: `scripts/test-catalog.mjs` against a broken `apps/api/server.mjs`

22 faults, 22 noticed.

| # | The fault | Failed checks |
|---|---|---:|
| 1 | The public list returns products in every state | 9 |
| 2 | A draft can be read by its address | 2 |
| 3 | Stored ratings are served | 3 |
| 4 | The quantity on hand is public | 4 |
| 5 | The import's review notes are public | 4 |
| 6 | A product "on request" can be ordered | 3 |
| 7 | A product sold by option can be ordered without one | 2 |
| 8 | A draft can be ordered | 4 |
| 9 | A product may take a SKU that an option already has | 3 |
| 10 | Selling a made-to-order product takes stock | 3 |
| 11 | Withdrawing the demo catalogue also withdraws an imported product that reuses a demo address | 2 |
| 12 | The import inserts products as published | 8 |
| 13 | A demo product replaced by the import stays on sale | 5 |
| 14 | A quotation prices an "on request" line from the request | 2 |
| 15 | A quotation prices a priced line from the request | 2 |
| 16 | An order prices a line from the request | 2 |
| 17 | Many products can be published at once without a SKU | 4 |
| 18 | A fraction of a rupee is accepted as a price | 2 |
| 19 | A new product can be published without a SKU | 1 |
| 20 | A product with neither a SKU nor an option can be published by editing it | 5 |
| 21 | A product can be put "on request" while a price is sent with it | 2 |
| 22 | On a database without migration 019, a quotation from the shop is priced from the page (PostgreSQL only) | 1 |

**Two were not noticed at first.** Faults 15 and 16: the suite sent no price with its lines, so a server that trusted the page's price looked correct. The lines now carry a price of 1, and both faults fail.

**One had no check.** Fault 22: nothing sent a quotation to a database without 019. The gap was seen by reading the suite, the check was written, and the fault was then used to prove that the check notices it.

**Three attempts are not counted, because they changed no behaviour:**

- Changing `status` inside the stored product document during the import. The column decides the state, not the document.
- A replacement whose new text was identical to the old.
- Letting a product "on request" keep a price it was sent — unreachable, because sending a price with "on request" is refused earlier (fault 21 removes that refusal instead).

With the whole-rupee guard removed (fault 18) the server answers 500 instead of 400. The check fails either way; why the answer is 500 was not looked into.

## Storefront: `scripts/e2e-catalog.mjs` against a broken storefront

9 faults, 9 noticed. Each needed a full production build of the storefront.

| # | The fault | File |
|---|---|---|
| 1 | A catalogue that cannot be read is shown as an empty shop | `lib/runtime.ts` |
| 2 | A product without a price is listed at PKR 0 | `data/products.ts` |
| 3 | The basket's estimate reads PKR 0 | `contexts/QuoteContext.tsx` |
| 4 | A cart line is identified by product alone | `contexts/lineKey.ts` |
| 5 | An old cart orders a product that has lost its price | `contexts/reconcile.ts` |
| 6 | The checkout drops the chosen option | `pages/Checkout.tsx` |
| 7 | A withdrawn product is not marked in an old cart | `contexts/reconcile.ts` |
| 8 | A photograph that fails to load is left broken | `components/shop/ProductImage.tsx` |
| 9 | The page of a product "on request" offers the cart | `pages/ProductDetail.tsx` |

One of these runs first stopped at the sign-in screen instead of at its own fault. That was not a catch; it was repeated and then failed for its own reason. The stop itself turned out to be a defect of the sign-in form (see the Phase 2 report, B.5).

## Sign-in form: `scripts/e2e-login.mjs`

| The fault | What the journey said |
|---|---|
| The demo account overwrites an e-mail and password typed before the API answered | "the demo prefill replaced what had been typed" |
| The prefilled demo username is not selected, so typing adds to it | "the prefilled demo username is not focused and selected (… selection 5–5)" |
