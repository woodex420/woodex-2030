/**
 * What makes two lines of a cart or a quote basket "the same line".
 *
 * A line used to be identified by the product id alone. Two colours of one
 * chair were then two lines that the − / + / remove buttons could not tell
 * apart: removing one removed both. With options (a size, a finish) the same
 * mistake would have merged a 1600 mm desk into a 1800 mm one.
 *
 * The key names the product, the option and the colour. Lines saved before
 * this existed carry no key; `keyOf` computes the same value for them, so an
 * old basket in a visitor's browser keeps working.
 */
export interface LineIdentity {
  id: string;
  variantId?: number | null;
  color?: string;
  key?: string;
}

export const lineKey = (item: LineIdentity): string =>
  [item.id, item.variantId ?? "", item.color ?? ""].join("|");

export const keyOf = (item: LineIdentity): string => item.key ?? lineKey(item);
