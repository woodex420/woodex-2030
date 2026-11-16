import React, { createContext, useContext, useState, useEffect, useCallback } from "react";
import { keyOf, lineKey } from "./lineKey";
import { reconcile } from "./reconcile";

export interface CartItem {
  id: string;
  name: string;
  category: string;
  price: number;
  quantity: number;
  color?: string;
  image?: string;
  /** Which option of the product: its id for the order, its label for the customer. */
  variantId?: number | null;
  variant?: string;
  sku?: string | null;
  /** Identity of the line — product, option and colour. See ./lineKey. */
  key?: string;
  /** Why this line cannot be submitted as it stands, in the customer's words. See ./reconcile. */
  issue?: string;
}

interface CartContextType {
  items: CartItem[];
  addItem: (item: Omit<CartItem, "quantity"> & { quantity?: number }) => void;
  /** `key` is `keyOf(item)`: the line, not the product. */
  removeItem: (key: string) => void;
  updateQuantity: (key: string, quantity: number) => void;
  clearCart: () => void;
  totalItems: number;
  /** The sum of the lines that can be ordered; a line with an issue is not counted. */
  totalPrice: number;
  /** How many lines cannot be ordered as they stand (see `CartItem.issue`). */
  issueCount: number;
  isOpen: boolean;
  setIsOpen: (open: boolean) => void;
}

const CartContext = createContext<CartContextType | undefined>(undefined);

export const CartProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [items, setItems] = useState<CartItem[]>(() => {
    /* What is in the browser was written by an earlier version of this site,
       or by nobody: keep only what is recognisably a line. */
    try {
      const saved: unknown = JSON.parse(localStorage.getItem("woodex-cart") ?? "[]");
      if (!Array.isArray(saved)) return [];
      return saved.filter((line): line is CartItem =>
        !!line && typeof line === "object" && typeof line.id === "string" && typeof line.name === "string"
        && Number.isFinite(line.price) && Number.isInteger(line.quantity) && line.quantity > 0);
    } catch {
      return [];
    }
  });
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    /* Storage can be full or switched off; the basket then lasts for the visit. */
    try { localStorage.setItem("woodex-cart", JSON.stringify(items)); } catch { /* kept in memory */ }
  }, [items]);

  /* Each time the catalogue arrives or changes, check the lines against it:
     current prices, and a reason on any line that can no longer be sold. */
  useEffect(() => {
    const check = () => setItems((prev) => reconcile(prev, { forOrder: true }));
    check();
    window.addEventListener("woodex:runtime", check);
    return () => window.removeEventListener("woodex:runtime", check);
  }, []);

  const addItem = useCallback((item: Omit<CartItem, "quantity"> & { quantity?: number }) => {
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

  const clearCart = useCallback(() => {
    setItems([]);
  }, []);

  const totalItems = items.reduce((sum, i) => sum + i.quantity, 0);
  const totalPrice = items.reduce((sum, i) => sum + (i.issue ? 0 : i.price * i.quantity), 0);
  const issueCount = items.filter((i) => i.issue).length;

  return (
    <CartContext.Provider
      value={{ items, addItem, removeItem, updateQuantity, clearCart, totalItems, totalPrice, issueCount, isOpen, setIsOpen }}
    >
      {children}
    </CartContext.Provider>
  );
};

export const useCart = () => {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error("useCart must be used within CartProvider");
  return ctx;
};
