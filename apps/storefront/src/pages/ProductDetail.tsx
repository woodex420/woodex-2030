import { useState } from "react";
import { useParams, Link, useNavigate } from "react-router-dom";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import ImageGallery from "@/components/product/ImageGallery";
import ColorSwatches from "@/components/product/ColorSwatches";
import SpecificationTabs from "@/components/product/SpecificationTabs";
import ProductCard from "@/components/shop/ProductCard";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Separator } from "@/components/ui/separator";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@/components/ui/breadcrumb";
import {
  Star,
  ShoppingBag,
  Heart,
  Share2,
  Truck,
  Shield,
  Package,
  Phone,
  Minus,
  Plus,
  ChevronLeft,
  CheckCircle,
} from "lucide-react";
import { getProductById, products, categories, formatPKR } from "@/data/products";
import { useQuote } from "@/contexts/QuoteContext";
import { useCart } from "@/contexts/CartContext";

const ProductDetail = () => {
  const { productId } = useParams<{ productId: string }>();
  const navigate = useNavigate();
  const product = getProductById(productId || "");
  
  const [selectedColor, setSelectedColor] = useState(product?.colors[0]);
  const [quantity, setQuantity] = useState(1);
  const [addedToQuote, setAddedToQuote] = useState(false);
  const [addedToCart, setAddedToCart] = useState(false);
  const { addItem } = useQuote();
  const { addItem: addToCart } = useCart();

  const handleAddToQuote = () => {
    if (!product) return;
    addItem({
      id: product.id,
      name: product.name,
      category: product.category,
      price: product.price,
      quantity,
      color: selectedColor?.name,
      image: product.images[0],
    });
    setAddedToQuote(true);
    setTimeout(() => setAddedToQuote(false), 2500);
  };

  const handleAddToCart = () => {
    if (!product) return;
    addToCart({
      id: product.id,
      name: product.name,
      category: product.category,
      price: product.price,
      quantity,
      color: selectedColor?.name,
      image: product.images[0],
    });
    setAddedToCart(true);
    setTimeout(() => setAddedToCart(false), 2500);
  };

  if (!product) {
    return (
      <div className="min-h-screen flex flex-col">
        <Header />
        <main className="flex-1 flex items-center justify-center">
          <div className="text-center">
            <h1 className="text-2xl font-bold mb-4">Product Not Found</h1>
            <p className="text-muted-foreground mb-6">
              The product you're looking for doesn't exist or has been removed.
            </p>
            <Button asChild>
              <Link to="/shop">
                <ChevronLeft className="h-4 w-4 mr-2" />
                Back to Shop
              </Link>
            </Button>
          </div>
        </main>
        <Footer />
      </div>
    );
  }

  const categoryName = categories.find(c => c.id === product.category)?.name || product.category;
  
  const discount = product.originalPrice
    ? Math.round(((product.originalPrice - product.price) / product.originalPrice) * 100)
    : 0;

  // Get related products (same category, excluding current)
  const relatedProducts = products
    .filter((p) => p.category === product.category && p.id !== product.id)
    .slice(0, 4);

  const handleQuantityChange = (delta: number) => {
    setQuantity((prev) => Math.max(1, prev + delta));
  };

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      
      <main className="flex-1">
        {/* Breadcrumb */}
        <div className="container mx-auto px-4 py-4">
          <Breadcrumb>
            <BreadcrumbList>
              <BreadcrumbItem>
                <BreadcrumbLink asChild>
                  <Link to="/">Home</Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem>
                <BreadcrumbLink asChild>
                  <Link to="/shop">Shop</Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem>
                <BreadcrumbLink asChild>
                  <Link to={`/shop?category=${product.category}`}>{categoryName}</Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem>
                <BreadcrumbPage>{product.name}</BreadcrumbPage>
              </BreadcrumbItem>
            </BreadcrumbList>
          </Breadcrumb>
        </div>

        {/* Product Section */}
        <section className="container mx-auto px-4 py-8">
          <div className="grid lg:grid-cols-2 gap-12">
            {/* Image Gallery */}
            <div>
              <ImageGallery images={product.images} productName={product.name} />
            </div>

            {/* Product Info */}
            <div className="space-y-6">
              {/* Badges */}
              <div className="flex flex-wrap gap-2">
                {product.isNew && (
                  <Badge className="bg-accent text-accent-foreground">New Arrival</Badge>
                )}
                {product.isBestSeller && (
                  <Badge variant="secondary">Best Seller</Badge>
                )}
                {!product.inStock && (
                  <Badge variant="destructive">Out of Stock</Badge>
                )}
              </div>

              {/* Title & Rating */}
              <div>
                <h1 className="text-3xl lg:text-4xl font-bold mb-3">{product.name}</h1>
                <div className="flex items-center gap-4">
                  <div className="flex items-center gap-1">
                    {[...Array(5)].map((_, i) => (
                      <Star
                        key={i}
                        className={`h-5 w-5 ${
                          i < Math.floor(product.rating)
                            ? "fill-amber-400 text-amber-400"
                            : "fill-muted text-muted"
                        }`}
                      />
                    ))}
                    <span className="ml-2 font-medium">{product.rating}</span>
                  </div>
                  <span className="text-muted-foreground">({product.reviews} reviews)</span>
                </div>
              </div>

              {/* Price */}
              <div className="flex items-baseline gap-3">
                <span className="text-4xl font-bold">{formatPKR(product.price)}</span>
                {product.originalPrice && (
                  <>
                    <span className="text-xl text-muted-foreground line-through">
                      {formatPKR(product.originalPrice)}
                    </span>
                    <Badge variant="destructive">Save {discount}%</Badge>
                  </>
                )}
              </div>

              {/* Short Description */}
              <p className="text-muted-foreground text-lg">{product.shortDescription}</p>

              <Separator />

              {/* Color Selection */}
              {selectedColor && (
                <ColorSwatches
                  colors={product.colors}
                  selectedColor={selectedColor}
                  onColorChange={setSelectedColor}
                />
              )}

              {/* Quantity */}
              <div className="space-y-3">
                <span className="text-sm font-medium">Quantity</span>
                <div className="flex items-center gap-4">
                  <div className="flex items-center border rounded-md">
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={() => handleQuantityChange(-1)}
                      disabled={quantity <= 1}
                    >
                      <Minus className="h-4 w-4" />
                    </Button>
                    <span className="w-12 text-center font-medium">{quantity}</span>
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={() => handleQuantityChange(1)}
                    >
                      <Plus className="h-4 w-4" />
                    </Button>
                  </div>
                  <span className="text-muted-foreground">
                    Total: <span className="font-semibold text-foreground">
                      {formatPKR(product.price * quantity)}
                    </span>
                  </span>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="flex flex-col sm:flex-row gap-3">
                <Button
                  size="lg"
                  className={`flex-1 ${addedToCart ? "bg-hon-green-dark" : "bg-accent hover:bg-hon-green-dark"} text-accent-foreground`}
                  disabled={!product.inStock}
                  onClick={handleAddToCart}
                >
                  {addedToCart ? (
                    <><CheckCircle className="h-5 w-5 mr-2" />Added to Cart!</>
                  ) : (
                    <><ShoppingBag className="h-5 w-5 mr-2" />{product.inStock ? "Add to Cart" : "Out of Stock"}</>
                  )}
                </Button>
                <Button
                  size="lg"
                  variant="outline"
                  className="border-accent text-accent hover:bg-accent hover:text-accent-foreground"
                  disabled={!product.inStock}
                  onClick={handleAddToQuote}
                >
                  {addedToQuote ? (
                    <><CheckCircle className="h-5 w-5 mr-2" />Added to Quote!</>
                  ) : (
                    <>Add to Quote</>
                  )}
                </Button>
                <Button size="lg" variant="outline">
                  <Heart className="h-5 w-5 mr-2" />
                  Wishlist
                </Button>
                <Button size="icon" variant="outline">
                  <Share2 className="h-5 w-5" />
                </Button>
              </div>

              <Separator />

              {/* Quick Info */}
              <div className="grid grid-cols-2 gap-4">
                <div className="flex items-center gap-3">
                  <div className="shrink-0 w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center">
                    <Truck className="h-5 w-5 text-accent" />
                  </div>
                  <div>
                    <p className="font-medium text-sm">Free Delivery</p>
                    <p className="text-xs text-muted-foreground">Nationwide across Pakistan</p>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <div className="shrink-0 w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center">
                    <Shield className="h-5 w-5 text-accent" />
                  </div>
                  <div>
                    <p className="font-medium text-sm">
                      {product.specifications.find(s => s.label === "Warranty")?.value || "Warranty"}
                    </p>
                    <p className="text-xs text-muted-foreground">Manufacturer warranty</p>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <div className="shrink-0 w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center">
                    <Package className="h-5 w-5 text-accent" />
                  </div>
                  <div>
                    <p className="font-medium text-sm">Free Assembly</p>
                    <p className="text-xs text-muted-foreground">Professional installation</p>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <div className="shrink-0 w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center">
                    <Phone className="h-5 w-5 text-accent" />
                  </div>
                  <div>
                    <p className="font-medium text-sm">Expert Support</p>
                    <p className="text-xs text-muted-foreground">24/7 assistance</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* Specification Tabs */}
        <section className="container mx-auto px-4 py-12">
          <SpecificationTabs product={product} />
        </section>

        {/* Related Products */}
        {relatedProducts.length > 0 && (
          <section className="container mx-auto px-4 py-12">
            <h2 className="text-2xl font-bold mb-8">Related Products</h2>
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
              {relatedProducts.map((relatedProduct) => (
                <ProductCard key={relatedProduct.id} product={relatedProduct} />
              ))}
            </div>
          </section>
        )}

        {/* CTA Section */}
        <section className="bg-muted/50 py-16">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-2xl font-bold mb-4">Need Help Choosing?</h2>
            <p className="text-muted-foreground mb-6 max-w-xl mx-auto">
              Our experts are here to help you find the perfect furniture for your workspace. 
              Get personalized recommendations and bulk pricing.
            </p>
            <div className="flex flex-wrap justify-center gap-4">
              <Button size="lg" asChild>
                <Link to="/quotation">Request Quote</Link>
              </Button>
              <Button size="lg" variant="outline" asChild>
                <Link to="/contact">Contact Us</Link>
              </Button>
            </div>
          </div>
        </section>
      </main>
      
      <Footer />
    </div>
  );
};

export default ProductDetail;
