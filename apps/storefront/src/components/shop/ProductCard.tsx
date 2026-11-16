import { Link } from "react-router-dom";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Eye, ShoppingBag, ShoppingCart } from "lucide-react";
import { Product, formatPKR, priceText, fromPrice, hasOptions, availabilityText } from "@/data/products";
import { useQuote } from "@/contexts/QuoteContext";
import { useCart } from "@/contexts/CartContext";

interface ProductCardProps {
  product: Product;
}

/* A card can add a product straight to the cart only when there is nothing to
   choose and a price to charge. A product sold in options is opened instead —
   the option is chosen on its page — and one priced on request goes to the
   quote basket, never to the cart. No rating is shown: the shop has no review
   system, and the figures that used to be here were typed in by hand. */
const ProductCard = ({ product }: ProductCardProps) => {
  const { addItem: addToQuote } = useQuote();
  const { addItem: addToCart } = useCart();
  const price = fromPrice(product);
  const options = hasOptions(product);
  const canCart = product.inStock && !options && price !== null;
  const canQuote = product.inStock && !options;
  const discount = price !== null && !options && product.originalPrice && product.originalPrice > price
    ? Math.round(((product.originalPrice - price) / product.originalPrice) * 100)
    : 0;
  const line = { id: product.id, name: product.name, category: product.category, image: product.images[0], sku: product.sku ?? null };

  const handleAddToQuote = (e: React.MouseEvent) => {
    e.preventDefault();
    addToQuote({ ...line, price: price ?? 0, priceOnRequest: price === null });
  };

  const handleAddToCart = (e: React.MouseEvent) => {
    e.preventDefault();
    if (price === null) return;
    addToCart({ ...line, price });
  };

  return (
    <Card className="group overflow-hidden hover:shadow-xl transition-all duration-300 border-border hover:border-accent">
      <div className="relative aspect-square overflow-hidden bg-section-light">
        {product.images[0] ? (
          <img
            src={product.images[0]}
            alt={product.name}
            loading="lazy"
            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center text-4xl font-bold text-muted-foreground/40">{product.name[0]}</div>
        )}

        {/* Badges */}
        <div className="absolute top-3 left-3 flex flex-col gap-1.5">
          {product.isNew && <Badge className="bg-accent text-accent-foreground text-xs">New</Badge>}
          {product.isBestSeller && <Badge variant="secondary" className="text-xs">Best Seller</Badge>}
          {discount > 0 && <Badge variant="destructive" className="text-xs">-{discount}%</Badge>}
          {!product.inStock && <Badge variant="outline" className="bg-background text-xs">Not available</Badge>}
        </div>

        {/* Hover Overlay */}
        <div className="absolute inset-0 bg-primary/50 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity duration-300 flex items-center justify-center gap-3">
          <Button size="icon" variant="secondary" className="rounded-full shadow-lg" asChild>
            <Link to={`/shop/${product.id}`} aria-label={`View ${product.name}`}>
              <Eye className="h-5 w-5" />
            </Link>
          </Button>
          {canCart && (
            <Button
              size="icon"
              className="rounded-full shadow-lg bg-accent hover:bg-hon-green-dark text-accent-foreground"
              onClick={handleAddToCart}
              title="Add to Cart"
              aria-label={`Add ${product.name} to cart`}
            >
              <ShoppingCart className="h-5 w-5" />
            </Button>
          )}
          {canQuote && (
            <Button
              size="icon"
              variant="secondary"
              className="rounded-full shadow-lg"
              onClick={handleAddToQuote}
              title="Add to Quote"
              aria-label={`Add ${product.name} to quote`}
            >
              <ShoppingBag className="h-5 w-5" />
            </Button>
          )}
        </div>

        {/* Color Swatches */}
        {product.colors.length > 0 && (
          <div className="absolute bottom-3 left-3 right-3 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
            {product.colors.slice(0, 5).map((color, i) => (
              <div
                key={i}
                className="w-5 h-5 rounded-full border-2 border-background shadow-sm"
                style={{ backgroundColor: color.hex }}
                title={color.name}
              />
            ))}
            {product.colors.length > 5 && (
              <div className="w-5 h-5 rounded-full bg-background border-2 border-border flex items-center justify-center text-xs font-bold">
                +{product.colors.length - 5}
              </div>
            )}
          </div>
        )}
      </div>

      <CardContent className="p-4">
        <div className="flex items-center gap-1 mb-1.5">
          <span className="text-xs text-muted-foreground">{availabilityText(product)}</span>
          {product.series && (
            <span className="ml-auto text-xs text-muted-foreground capitalize">{product.series.replace("-", " ")}</span>
          )}
        </div>

        <Link to={`/shop/${product.id}`}>
          <h3 className="font-semibold text-sm mb-1 hover:text-accent transition-colors line-clamp-2 leading-tight">
            {product.name}
          </h3>
        </Link>

        {product.shortDescription && <p className="text-xs text-muted-foreground mb-3 line-clamp-1">{product.shortDescription}</p>}

        <div className="flex items-center justify-between gap-2">
          <div>
            <p className={price === null ? "text-sm font-semibold text-foreground" : "text-base font-bold text-foreground"}>{priceText(product)}</p>
            {discount > 0 && product.originalPrice && (
              <p className="text-xs text-muted-foreground line-through">{formatPKR(product.originalPrice)}</p>
            )}
          </div>
          {options ? (
            <Button size="sm" variant="outline" className="text-xs px-3" asChild>
              <Link to={`/shop/${product.id}`}>Options</Link>
            </Button>
          ) : price === null ? (
            <Button size="sm" variant="outline" className="text-xs px-3" disabled={!product.inStock} onClick={handleAddToQuote}>
              <ShoppingBag className="h-3 w-3 mr-1" />
              Quote
            </Button>
          ) : (
            <Button
              size="sm"
              className="bg-accent hover:bg-hon-green-dark text-accent-foreground text-xs px-3"
              disabled={!product.inStock}
              onClick={handleAddToCart}
            >
              <ShoppingCart className="h-3 w-3 mr-1" />
              Add
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  );
};

export default ProductCard;
