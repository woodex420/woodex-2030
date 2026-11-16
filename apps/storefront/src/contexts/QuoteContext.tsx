import React, { createContext, useContext, useState, useEffect, useCallback } from "react";
import { keyOf, lineKey } from "./lineKey";
import { reconcile } from "./reconcile";
import { formatPKR } from "@/data/products";

export interface QuoteItem {
  id: string;
  name: string;
  category: string;
  /** Whole rupees; 0 and meaningless when `priceOnRequest` is true. */
  price: number;
  quantity: number;
  color?: string;
  image?: string;
  /** Which option of the product: its id for the request, its label for the customer. */
  variantId?: number | null;
  variant?: string;
  sku?: string | null;
  /** No list price: the line is quoted by sales and adds nothing to the estimate. */
  priceOnRequest?: boolean;
  /** Identity of the line — product, option and colour. See ./lineKey. */
  key?: string;
  /** Why this line cannot be submitted as it stands, in the customer's words. See ./reconcile. */
  issue?: string;
}

/**
 * The estimate of a basket, as it is written wherever a total is shown: the
 * drawer, the quotation page, the printed sheet, the e-mail draft.
 *
 * It is the sum of the lines that have a price. A line priced on request adds
 * nothing to it and is named beside it; when no line has a price the estimate
 * is "On request" — never "PKR 0", which reads as free. A line that can no
 * longer be quoted is left out altogether.
 */
export const estimateOf = (items: QuoteItem[]): string => {
  const live = items.filter((item) => !item.issue);
  const onRequest = live.filter((item) => item.priceOnRequest).length;
  const priced = live.filter((item) => !item.priceOnRequest);
  if (priced.length === 0) return onRequest > 0 ? "On request" : "—";
  const total = priced.reduce((sum, item) => sum + item.price * item.quantity, 0);
  return formatPKR(total) + (onRequest > 0 ? ` + ${onRequest} on request` : "");
};

interface QuoteContextType {
  items: QuoteItem[];
  addItem: (item: Omit<QuoteItem, "quantity"> & { quantity?: number }) => void;
  /** `key` is `keyOf(item)`: the line, not the product. */
  removeItem: (key: string) => void;
  updateQuantity: (key: string, quantity: number) => void;
  clearQuote: () => void;
  totalItems: number;
  /** The sum of the priced lines only. To print an estimate use `estimateText`. */
  totalPrice: number;
  /** `estimateOf(items)`: the estimate as words — see there. */
  estimateText: string;
  /** How many lines have no list price and are left out of `totalPrice`. */
  onRequestCount: number;
  /** How many lines cannot be quoted as they stand (see `QuoteItem.issue`). */
  issueCount: number;
  isOpen: boolean;
  setIsOpen: (open: boolean) => void;
}

const QuoteContext = createContext<QuoteContextType | undefined>(undefined);

export const QuoteProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [items, setItems] = useState<QuoteItem[]>(() => {
    /* What is in the browser was written by an earlier version of this site,
       or by nobody: keep only what is recognisably a line. */
    try {
      const saved: unknown = JSON.parse(localStorage.getItem("woodex-quote") ?? "[]");
      if (!Array.isArray(saved)) return [];
      return saved.filter((line): line is QuoteItem =>
        !!line && typeof line === "object" && typeof line.id === "string" && typeof line.name === "string"
        && Number.isFinite(line.price) && Number.isInteger(line.quantity) && line.quantity > 0);
    } catch {
      return [];
    }
  });
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    /* Storage can be full or switched off; the basket then lasts for the visit. */
    try { localStorage.setItem("woodex-quote", JSON.stringify(items)); } catch { /* kept in memory */ }
  }, [items]);

  /* Each time the catalogue arrives or changes, check the lines against it:
     current prices, and a reason on any line that can no longer be sold. */
  useEffect(() => {
    const check = () => setItems((prev) => reconcile(prev, { forOrder: false }));
    check();
    window.addEventListener("woodex:runtime", check);
    return () => window.removeEventListener("woodex:runtime", check);
  }, []);

  const addItem = useCallback((item: Omit<QuoteItem, "quantity"> & { quantity?: number }) => {
    const key = lineKey(item);
    setItems((prev) => {
      const existing = prev.find((i) => keyOf(i) === key);
      if (existing) {
        return prev.map((i) =>
          keyOf(i) === key ? { ...i, quantity: i.quantity + (item.quantity || 1) } : i
        );
      }
      return [...prev, { ...item, key, quantity: item.quantity || 1 }];
    });
    setIsOpen(true);
  }, []);

  const removeItem = useCallback((key: string) => {
    setItems((prev) => prev.filter((i) => keyOf(i) !== key));
  }, []);

  const updateQuantity = useCallback((key: string, quantity: number) => {
    if (quantity <= 0) {
      setItems((prev) => prev.filter((i) => keyOf(i) !== key));
    } else {
      setItems((prev) =>
        prev.map((i) => (keyOf(i) === key ? { ...i, quantity } : i))
      );
    }
  }, []);

  const clearQuote = useCallback(() => {
    setItems([]);
  }, []);

  const totalItems = items.reduce((sum, i) => sum + i.quantity, 0);
  const totalPrice = items.reduce((sum, i) => sum + (i.priceOnRequest || i.issue ? 0 : i.price * i.quantity), 0);
  const onRequestCount = items.filter((i) => i.priceOnRequest && !i.issue).length;
  const issueCount = items.filter((i) => i.issue).length;
  const estimateText = estimateOf(items);

  return (
    <QuoteContext.Provider
      value={{ items, addItem, removeItem, updateQuantity, clearQuote, totalItems, totalPrice, estimateText, onRequestCount, issueCount, isOpen, setIsOpen }}
    >
      {children}
    </QuoteContext.Provider>
  );
};

export const useQuote = () => {
  const ctx = useContext(QuoteContext);
  if (!ctx) throw new Error("useQuote must be used within QuoteProvider");
  return ctx;
};
