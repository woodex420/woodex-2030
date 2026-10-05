import React, { useRef, useEffect } from "react";
import { X, Minus, Plus, FileText, Trash2, ShoppingBag } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { useQuote } from "@/contexts/QuoteContext";
import { formatPKR } from "@/data/products";
import { Link } from "react-router-dom";

const QuoteBasket = () => {
  const { items, removeItem, updateQuantity, clearQuote, totalItems, totalPrice, isOpen, setIsOpen } = useQuote();
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    if (isOpen) document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [isOpen, setIsOpen]);

  return (
    <>
      {/* Overlay */}
      {isOpen && (
        <div className="fixed inset-0 bg-primary/50 z-40 transition-opacity" onClick={() => setIsOpen(false)} />
      )}

      {/* Drawer */}
      <div
        ref={ref}
        className={`fixed right-0 top-0 h-full w-full max-w-md bg-background shadow-2xl z-50 flex flex-col transition-transform duration-300 ${
          isOpen ? "translate-x-0" : "translate-x-full"
        }`}
      >
        {/* Header */}
        <div className="flex items-center justify-between p-5 border-b bg-primary text-primary-foreground">
          <div className="flex items-center gap-3">
            <ShoppingBag className="h-5 w-5 text-accent" />
            <div>
              <h2 className="font-bold text-lg">Quote Basket</h2>
              <p className="text-xs text-primary-foreground/70">{totalItems} item{totalItems !== 1 ? "s" : ""} added</p>
            </div>
          </div>
          <Button variant="ghost" size="icon" onClick={() => setIsOpen(false)} className="text-primary-foreground hover:text-accent hover:bg-primary-foreground/10">
            <X className="h-5 w-5" />
          </Button>
        </div>

        {/* Items */}
        <div className="flex-1 overflow-y-auto p-5 space-y-4">
          {items.length === 0 ? (
            <div className="text-center py-16">
              <ShoppingBag className="h-16 w-16 text-muted-foreground/30 mx-auto mb-4" />
              <p className="font-semibold mb-1">Your quote basket is empty</p>
              <p className="text-sm text-muted-foreground mb-6">Browse our products and add items to request a quote</p>
              <Button asChild onClick={() => setIsOpen(false)} className="bg-accent hover:bg-hon-green-dark text-accent-foreground">
                <Link to="/shop">Browse Products</Link>
              </Button>
            </div>
          ) : (
            items.map((item) => (
              <div key={item.id} className="flex gap-4 p-4 border border-border rounded-sm hover:border-accent transition-colors">
                <div className="w-16 h-16 bg-section-light rounded-sm flex-shrink-0 overflow-hidden">
                  {item.image && <img src={item.image} alt={item.name} className="w-full h-full object-cover" />}
                  {!item.image && <div className="w-full h-full flex items-center justify-center text-accent font-bold text-xl">{item.name[0]}</div>}
                </div>
                <div className="flex-1 min-w-0">
                  <Link to={`/shop/${item.id}`} onClick={() => setIsOpen(false)}>
                    <h4 className="font-semibold text-sm leading-tight mb-0.5 hover:text-accent transition-colors line-clamp-2">{item.name}</h4>
                  </Link>
                  {item.color && <p className="text-xs text-muted-foreground mb-2">{item.color}</p>}
                  <p className="text-sm font-bold text-accent">{formatPKR(item.price)}</p>
                  <div className="flex items-center gap-2 mt-2">
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-7 w-7"
                      onClick={() => updateQuantity(item.id, item.quantity - 1)}
                    >
                      <Minus className="h-3 w-3" />
                    </Button>
                    <span className="w-8 text-center text-sm font-medium">{item.quantity}</span>
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-7 w-7"
                      onClick={() => updateQuantity(item.id, item.quantity + 1)}
                    >
                      <Plus className="h-3 w-3" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7 ml-auto text-muted-foreground hover:text-destructive"
                      onClick={() => removeItem(item.id)}
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </div>
              </div>
            ))
          )}
        </div>

        {/* Footer */}
        {items.length > 0 && (
          <div className="border-t p-5 space-y-4 bg-section-light">
            <div className="space-y-2">
              <div className="flex justify-between text-sm text-muted-foreground">
                <span>{totalItems} items</span>
                <span>{formatPKR(totalPrice)}</span>
              </div>
              <Separator />
              <div className="flex justify-between font-bold text-lg">
                <span>Estimated Total</span>
                <span className="text-accent">{formatPKR(totalPrice)}</span>
              </div>
              <p className="text-xs text-muted-foreground">Final pricing confirmed in your quotation</p>
            </div>
            <div className="space-y-2">
              <Button
                size="lg"
                className="w-full bg-accent hover:bg-hon-green-dark text-accent-foreground font-semibold"
                asChild
                onClick={() => setIsOpen(false)}
              >
                <Link to="/quotation">
                  <FileText className="h-4 w-4 mr-2" />
                  Request Quote ({totalItems})
                </Link>
              </Button>
              <Button
                variant="ghost"
                size="sm"
                className="w-full text-muted-foreground hover:text-destructive"
                onClick={clearQuote}
              >
                <Trash2 className="h-3.5 w-3.5 mr-1.5" />
                Clear Basket
              </Button>
            </div>
          </div>
        )}
      </div>
    </>
  );
};

export default QuoteBasket;
