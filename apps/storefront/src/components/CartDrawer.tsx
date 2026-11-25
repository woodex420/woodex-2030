import { useState } from "react";
import { Link } from "react-router-dom";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { Button } from "@/components/ui/button";
import { ShoppingCart, Minus, Plus, Trash2, X } from "lucide-react";
import { useCart, MAX_ORDER_QTY } from "@/contexts/CartContext";
import { formatPKR } from "@/data/products";
import { keyOf } from "@/contexts/lineKey";
import { ProductImage } from "@/components/shop/ProductImage";

const CartDrawer = () => {
  const { items, removeItem, updateQuantity, totalItems, totalPrice, issueCount, isOpen, setIsOpen } = useCart();

  return (
    <Sheet open={isOpen} onOpenChange={setIsOpen}>
      <SheetTrigger asChild>
        <button className="relative p-2 hover:bg-muted rounded-sm transition-colors" aria-label={totalItems > 0 ? `Shopping cart, ${totalItems} ${totalItems === 1 ? "item" : "items"}` : "Shopping cart"}>
          <ShoppingCart className="h-5 w-5" />
          {totalItems > 0 && (
            <span className="absolute -top-1 -right-1 w-5 h-5 bg-accent text-accent-foreground text-[10px] font-bold rounded-full flex items-center justify-center">
              {totalItems}
            </span>
          )}
        </button>
      </SheetTrigger>
      <SheetContent className="w-full sm:max-w-md flex flex-col">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <ShoppingCart className="h-5 w-5 text-accent" />
            Shopping Cart ({totalItems})
          </SheetTitle>
        </SheetHeader>

        {items.length === 0 ? (
          <div className="flex-1 flex flex-col items-center justify-center text-center py-12">
            <ShoppingCart className="h-12 w-12 text-muted-foreground/40 mb-4" />
            <p className="font-semibold mb-1">Your cart is empty</p>
            <p className="text-sm text-muted-foreground mb-6">Browse our products and add items to your cart</p>
            <Button className="bg-accent hover:bg-accent/90 text-accent-foreground" asChild onClick={() => setIsOpen(false)}>
              <Link to="/shop">Shop Now</Link>
            </Button>
          </div>
        ) : (
          <>
            <div className="flex-1 overflow-y-auto space-y-3 py-4">
              {items.map((item) => (
                <div key={keyOf(item)} className="flex gap-3 p-3 border border-border rounded-sm">
                  <div className="w-16 h-16 rounded-sm overflow-hidden flex-shrink-0 bg-muted">
                    <ProductImage src={item.image} name={item.name} className="w-full h-full object-cover" initialClassName="text-xl" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <Link
                      to={`/shop/${item.id}`}
                      onClick={() => setIsOpen(false)}
                      className="font-semibold text-sm hover:text-accent transition-colors line-clamp-1"
                    >
                      {item.name}
                    </Link>
                    {(item.variant || item.color) && <p className="text-xs text-muted-foreground">{[item.variant, item.color].filter(Boolean).join(" · ")}</p>}
                    {item.issue
                      ? <p className="text-xs text-destructive mt-1">{item.issue}</p>
                      : <p className="text-sm font-bold text-accent mt-1">{formatPKR(item.price)}</p>}
                    <div className="flex items-center justify-between mt-2">
                      <div className="flex items-center border border-border rounded-sm">
                        <button
                          onClick={() => updateQuantity(keyOf(item), item.quantity - 1)}
                          aria-label={`One fewer ${item.name}`}
                          className="p-1 hover:bg-muted transition-colors"
                        >
                          <Minus className="h-3 w-3" />
                        </button>
                        <span className="w-8 text-center text-xs font-medium">{item.quantity}</span>
                        <button
                          onClick={() => updateQuantity(keyOf(item), item.quantity + 1)}
                          disabled={item.quantity >= MAX_ORDER_QTY}
                          aria-label={`One more ${item.name}`}
                          className="p-1 hover:bg-muted transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                          <Plus className="h-3 w-3" />
                        </button>
                      </div>
                      <button onClick={() => removeItem(keyOf(item))} aria-label={`Remove ${item.name}`} className="p-1 text-muted-foreground hover:text-destructive transition-colors">
                        <Trash2 className="h-3.5 w-3.5" />
                      </button>
                    </div>
                    {item.quantity >= MAX_ORDER_QTY && (
                      <p className="mt-1 text-xs text-muted-foreground">
                        {MAX_ORDER_QTY} is the most one order can take. For more, add it to a quote.
                      </p>
                    )}
                  </div>
                </div>
              ))}
            </div>

            <div className="border-t border-border pt-4 space-y-3">
              <div className="flex justify-between items-center">
                <span className="font-semibold">Subtotal</span>
                <span className="font-bold text-lg">{formatPKR(totalPrice)}</span>
              </div>
              {issueCount > 0
                ? <p role="alert" className="text-xs text-destructive">{issueCount === 1 ? "One item" : `${issueCount} items`} above cannot be ordered and {issueCount === 1 ? "is" : "are"} not in the subtotal.</p>
                : <p className="text-xs text-muted-foreground">Shipping calculated at checkout</p>}
              <Button className="w-full bg-accent hover:bg-accent/90 text-accent-foreground font-semibold" asChild onClick={() => setIsOpen(false)}>
                <Link to="/checkout">Proceed to Checkout</Link>
              </Button>
              <Button variant="outline" className="w-full" onClick={() => setIsOpen(false)} asChild>
                <Link to="/shop">Continue Shopping</Link>
              </Button>
            </div>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
};

export default CartDrawer;
