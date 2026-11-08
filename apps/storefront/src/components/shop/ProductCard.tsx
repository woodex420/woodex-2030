import { Link } from "react-router-dom";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Star, Eye, ShoppingBag, ShoppingCart } from "lucide-react";
import { Product, formatPKR } from "@/data/products";
import { useQuote } from "@/contexts/QuoteContext";
import { useCart } from "@/contexts/CartContext";

interface ProductCardProps {
  product: Product;
}

const ProductCard = ({ product }: ProductCardProps) => {
  const { addItem: addToQuote } = useQuote();
  const { addItem: addToCart } = useCart();
  const discount = product.originalPrice
    ? Math.round(((product.originalPrice - product.price) / product.originalPrice) * 100)
    : 0;

  const handleAddToQuote = (e: React.MouseEvent) => {
    e.preventDefault();
    addToQuote({
      id: product.id,
      name: product.name,
      category: product.category,
      price: product.price,
      image: product.images[0],
    });
  };

  const handleAddToCart = (e: React.MouseEvent) => {
    e.preventDefault();
    addToCart({
      id: product.id,
      name: product.name,
      category: product.category,
      price: product.price,
      image: product.images[0],
    });
  };

  return (
    <Card className="group overflow-hidden hover:shadow-xl transition-all duration-300 border-border hover:border-accent">
      <div className="relative aspect-square overflow-hidden bg-section-light">
        <img
          src={product.images[0]}
          alt={product.name}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
        />

        {/* Badges */}
        <div className="absolute top-3 left-3 flex flex-col gap-1.5">
          {product.isNew && <Badge className="bg-accent text-accent-foreground text-xs">New</Badge>}
          {product.isBestSeller && <Badge variant="secondary" className="text-xs">Best Seller</Badge>}
          {discount > 0 && <Badge variant="destructive" className="text-xs">-{discount}%</Badge>}
          {!product.inStock && <Badge variant="outline" className="bg-background text-xs">Out of Stock</Badge>}
        </div>

        {/* Hover Overlay */}
        <div className="absolute inset-0 bg-primary/50 opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-center justify-center gap-3">
          <Button size="icon" variant="secondary" className="rounded-full shadow-lg" asChild>
            <Link to={`/shop/${product.id}`}>
              <Eye className="h-5 w-5" />
            </Link>
          </Button>
          <Button
            size="icon"
            className="rounded-full shadow-lg bg-accent hover:bg-hon-green-dark text-accent-foreground"
            disabled={!product.inStock}
            onClick={handleAddToCart}
            title="Add to Cart"
          >
            <ShoppingCart className="h-5 w-5" />
          </Button>
          <Button
            size="icon"
            variant="secondary"
            className="rounded-full shadow-lg"
            disabled={!product.inStock}
            onClick={handleAddToQuote}
            title="Add to Quote"
          >
            <ShoppingBag className="h-5 w-5" />
          </Button>
        </div>

        {/* Color Swatches */}
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
      </div>

      <CardContent className="p-4">
        <div className="flex items-center gap-1 mb-1.5">
          <Star className="h-3.5 w-3.5 fill-amber-400 text-amber-400" />
          <span className="text-xs font-semibold">{product.rating}</span>
          <span className="text-xs text-muted-foreground">({product.reviews})</span>
          {product.series && (
            <span className="ml-auto text-xs text-muted-foreground capitalize">{product.series.replace("-", " ")}</span>
          )}
        </div>

        <Link to={`/shop/${product.id}`}>
          <h3 className="font-semibold text-sm mb-1 hover:text-accent transition-colors line-clamp-2 leading-tight">
            {product.name}
          </h3>
        </Link>

        <p className="text-xs text-muted-foreground mb-3 line-clamp-1">{product.shortDescription}</p>

        <div className="flex items-center justify-between gap-2">
          <div>
            <p className="text-base font-bold text-foreground">{formatPKR(product.price)}</p>
            {product.originalPrice && (
              <p className="text-xs text-muted-foreground line-through">{formatPKR(product.originalPrice)}</p>
            )}
          </div>
          <Button
            size="sm"
            className="bg-accent hover:bg-hon-green-dark text-accent-foreground text-xs px-3"
            disabled={!product.inStock}
            onClick={handleAddToCart}
          >
            <ShoppingCart className="h-3 w-3 mr-1" />
            Add
          </Button>
        </div>
      </CardContent>
    </Card>
  );
};

export default ProductCard;
