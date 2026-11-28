import { useCallback } from "react";
import { useCart } from "./CartContext";
import type { CartItem } from "./CartContext";
import { keyOf } from "./lineKey";
import { useQuote } from "./QuoteContext";

/**
 * Moves a line of the cart to the quote basket: the same product, option, colour and quantity.
 *
 * For a line that cannot be ordered and can be quoted (./reconcile marks it `quoteInstead`): a product priced on
 * request, and a product that is bought by quotation whatever it costs. The cart said "add it to a quote
 * instead" and left the visitor to find the product again; this is the button for that sentence.
 */
export function useMoveToQuote(): (item: CartItem) => void {
  const { addItem } = useQuote();
  const { removeItem } = useCart();

  return useCallback((item: CartItem) => {
    addItem({
      id: item.id, name: item.name, category: item.category, image: item.image, color: item.color,
      variantId: item.variantId ?? null, variant: item.variant, sku: item.sku ?? null,
      price: item.priceOnRequest ? 0 : item.price, priceOnRequest: !!item.priceOnRequest, quantity: item.quantity,
    });
    removeItem(keyOf(item));
  }, [addItem, removeItem]);
}
