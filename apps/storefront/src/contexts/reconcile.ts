import { boughtByQuotation, catalog, getProductById, optionPrice } from "@/data/products";

/**
 * Brings the lines of a cart or a quote basket up to date with the catalogue.
 *
 * A basket lives in the visitor's browser for days. In that time a product can
 * be withdrawn, lose its price, gain options or change price. The API decides
 * every one of these again when the order arrives — it never trusts the
 * basket — but a refusal at the last step, with no hint which line is wrong,
 * is a poor way to find out. So each time the catalogue arrives the lines are
 * checked against it:
 *
 *   · the price shown is the catalogue's price now, not the one remembered;
 *   · a line that can no longer be sold carries `issue`, the reason in the
 *     customer's words. The page shows it and holds the submit button until the
 *     line is removed.
 *
 * Nothing is removed for the customer: a line that silently disappears looks
 * like a lost order.
 *
 * `forOrder` — a product priced on request is a problem in a cart (an order is
 * a commitment to a price) and perfectly fine in a quote basket. So is a
 * product that is bought by quotation whatever it costs (one read from the
 * Laravel admin: data/products.ts, `boughtByQuotation`). Such a line carries
 * `quoteInstead`, and the page offers to move it to the quote basket: a
 * visitor who filled a cart before the catalogue changed hands loses nothing.
 *
 * Returns the SAME array when nothing changed, so it is safe inside setState.
 */
export interface ReconcilableLine {
  id: string;
  variantId?: number | null;
  price: number;
  priceOnRequest?: boolean;
  issue?: string;
  /** With `issue`, in a cart: the line cannot be ordered and can be quoted. The page offers to move it. */
  quoteInstead?: boolean;
}

export function reconcile<T extends ReconcilableLine>(items: T[], { forOrder }: { forOrder: boolean }): T[] {
  /* Only a catalogue that has actually been read may judge a basket. While it
     is loading — or could not be read — every line is left exactly as it is. */
  if (catalog.status !== "ready") return items;
  let changed = false;
  const next = items.map((item) => {
    const product = getProductById(item.id);
    let issue: string | undefined;
    let quoteInstead = false;
    let price = item.price;
    let priceOnRequest = !!item.priceOnRequest;
    if (!product) {
      issue = "No longer available";
    } else {
      const options = product.variants ?? [];
      const wanted = item.variantId ?? null;
      const variant = wanted === null ? null : options.find((option) => option.id === wanted) ?? null;
      if (wanted !== null && !variant) issue = "This option is no longer available";
      else if (options.length > 0 && !variant) issue = "Now sold in options — choose one on the product page";
      else if (!product.inStock) issue = "Not available right now";
      else {
        const current = optionPrice(product, variant);
        priceOnRequest = current === null;
        price = current ?? 0;
        if (forOrder && boughtByQuotation(product)) issue = "Ordered by quotation — add it to a quote instead";
        else if (current === null && forOrder) issue = "Priced on request — add it to a quote instead";
        quoteInstead = issue !== undefined;
      }
    }
    if (issue === item.issue && price === item.price && priceOnRequest === !!item.priceOnRequest && quoteInstead === !!item.quoteInstead) return item;
    changed = true;
    return { ...item, price, priceOnRequest, issue, quoteInstead };
  });
  return changed ? next : items;
}
