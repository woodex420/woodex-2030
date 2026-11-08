import React, { createContext, useContext, useState, useEffect, useCallback } from "react";

export interface QuoteItem {
  id: string;
  name: string;
  category: string;
  price: number;
  quantity: number;
  color?: string;
  image?: string;
}

interface QuoteContextType {
  items: QuoteItem[];
  addItem: (item: Omit<QuoteItem, "quantity"> & { quantity?: number }) => void;
  removeItem: (id: string) => void;
  updateQuantity: (id: string, quantity: number) => void;
  clearQuote: () => void;
  totalItems: number;
  totalPrice: number;
  isOpen: boolean;
  setIsOpen: (open: boolean) => void;
}

const QuoteContext = createContext<QuoteContextType | undefined>(undefined);

export const QuoteProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [items, setItems] = useState<QuoteItem[]>(() => {
    try {
      const saved = localStorage.getItem("woodex-quote");
      return saved ? JSON.parse(saved) : [];
    } catch {
      return [];
    }
  });
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    localStorage.setItem("woodex-quote", JSON.stringify(items));
  }, [items]);

  const addItem = useCallback((item: Omit<QuoteItem, "quantity"> & { quantity?: number }) => {
    setItems((prev) => {
      const existing = prev.find((i) => i.id === item.id);
      if (existing) {
        return prev.map((i) =>
          i.id === item.id ? { ...i, quantity: i.quantity + (item.quantity || 1) } : i
        );
      }
      return [...prev, { ...item, quantity: item.quantity || 1 }];
    });
    setIsOpen(true);
  }, []);

  const removeItem = useCallback((id: string) => {
    setItems((prev) => prev.filter((i) => i.id !== id));
  }, []);

  const updateQuantity = useCallback((id: string, quantity: number) => {
    if (quantity <= 0) {
      setItems((prev) => prev.filter((i) => i.id !== id));
    } else {
      setItems((prev) =>
        prev.map((i) => (i.id === id ? { ...i, quantity } : i))
      );
    }
  }, []);

  const clearQuote = useCallback(() => {
    setItems([]);
  }, []);

  const totalItems = items.reduce((sum, i) => sum + i.quantity, 0);
  const totalPrice = items.reduce((sum, i) => sum + i.price * i.quantity, 0);

  return (
    <QuoteContext.Provider
      value={{ items, addItem, removeItem, updateQuantity, clearQuote, totalItems, totalPrice, isOpen, setIsOpen }}
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
