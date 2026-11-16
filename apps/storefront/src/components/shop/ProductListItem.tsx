import { Link } from "react-router-dom";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ShoppingBag, Eye, Check } from "lucide-react";
import { Product, formatPKR, priceText, fromPrice, hasOptions, availabilityText } from "@/data/products";
import { useQuote } from "@/contexts/QuoteContext";

interface ProductListItemProps {
  product: Product;
}

const ProductListItem = ({ product }: ProductListItemProps) => {
  const { addItem } = useQuote();
  const price = fromPrice(product);
  const options = hasOptions(product);
  const discount = price !== null && !options && product.originalPrice && product.originalPrice > price
    ? Math.round(((product.originalPrice - price) / product.originalPrice) * 100)
    : 0;

  return (
    <Card className="group overflow-hidden hover:shadow-lg transition-all duration-300">
      <div className="flex flex-col md:flex-row">
        {/* Image */}
        <div className="relative w-full md:w-72 aspect-square md:aspect-auto shrink-0 overflow-hidden bg-muted">
          {product.images[0] ? (
            <img
              src={product.images[0]}
              alt={product.name}
              loading="lazy"
              className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
            />
          ) : (
            <div className="w-full h-full min-h-40 flex items-center justify-center text-5xl font-bold text-muted-foreground/40" aria-hidden="true">{product.name[0]}</div>
          )}

          {/* Badges */}
          <div className="absolute top-3 left-3 flex flex-col gap-2">
            {product.isNew && (
              <Badge className="bg-accent text-accent-foreground">New</Badge>
            )}
            {product.isBestSeller && (
              <Badge variant="secondary">Best Seller</Badge>
            )}
            {discount > 0 && (
              <Badge variant="destructive">-{discount}%</Badge>
            )}
          </div>
        </div>

        {/* Content */}
        <CardContent className="flex-1 p-6">
          <div className="flex flex-col h-full">
            {/* Availability. No rating: the shop has no review system. */}
            <p className="text-sm text-muted-foreground mb-2">{availabilityText(product)}</p>

            {/* Title */}
            <Link to={`/shop/${product.id}`}>
              <h3 className="font-semibold text-xl mb-2 group-hover:text-accent transition-colors">
                {product.name}
              </h3>
            </Link>

            {/* Description, features and colours are shown when the product
                has them. A product taken from the old shop has a name, a
                price and a photograph; a "Colors:" label with nothing after
                it would be worse than no label. */}
            {product.shortDescription && (
              <p className="text-muted-foreground mb-4 line-clamp-2">
                {product.shortDescription}
              </p>
            )}

            {product.features.length > 0 && (
              <div className="flex flex-wrap gap-2 mb-4">
                {product.features.slice(0, 3).map((feature, index) => (
                  <div key={index} className="flex items-center gap-1 text-sm text-muted-foreground">
                    <Check className="h-3 w-3 text-accent" />
                    <span>{feature}</span>
                  </div>
                ))}
              </div>
            )}

            {product.colors.length > 0 && (
              <div className="flex items-center gap-2 mb-4">
                <span className="text-sm text-muted-foreground">Colors:</span>
                <div className="flex gap-1">
                  {product.colors.map((color, index) => (
                    <div
                      key={index}
                      className="w-5 h-5 rounded-full border-2 border-border"
                      style={{ backgroundColor: color.hex }}
                      title={color.name}
                    />
                  ))}
                </div>
              </div>
            )}

            {/* Price & Actions */}
            <div className="flex items-center justify-between mt-auto pt-4 border-t">
              <div className="flex items-center gap-2">
                <span className={price === null ? "text-lg font-semibold" : "text-2xl font-bold"}>{priceText(product)}</span>
                {discount > 0 && product.originalPrice && (
                  <span className="text-sm text-muted-foreground line-through">
                    {formatPKR(product.originalPrice)}
                  </span>
                )}
              </div>

              <div className="flex gap-2">
                <Button variant="outline" asChild>
                  <Link to={`/shop/${product.id}`}>
                    <Eye className="h-4 w-4 mr-2" />
                    {options ? "Choose options" : "View Details"}
                  </Link>
                </Button>
                {!options && (
                  <Button
                    disabled={!product.inStock}
                    onClick={() => addItem({
                      id: product.id,
                      name: product.name,
                      category: product.category,
                      price: price ?? 0,
                      priceOnRequest: price === null,
                      sku: product.sku ?? null,
                      image: product.images[0],
                    })}
                  >
                    <ShoppingBag className="h-4 w-4 mr-2" />
                    {product.inStock ? "Add to Quote" : "Not available"}
                  </Button>
                )}
              </div>
            </div>
          </div>
        </CardContent>
      </div>
    </Card>
  );
};

export default ProductListItem;
