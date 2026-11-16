import { useEffect, useState } from "react";
import { useParams, Link } from "react-router-dom";
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
  ShoppingBag,
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
import { getProductById, products, categoryLabel, formatPKR, optionPrice, fromPrice } from "@/data/products";
import type { ProductColor } from "@/data/products";
import { useQuote } from "@/contexts/QuoteContext";
import { useCart } from "@/contexts/CartContext";
import { useCatalogStatus } from "@/lib/runtime";
import { brand, title } from "@/config/brand";
import { CatalogUnavailable } from "@/components/shop/CatalogNotice";
import { toast } from "sonner";

const ProductDetail = () => {
  const { productId } = useParams<{ productId: string }>();
  /* The catalogue arrives from the API after the page has mounted, so the
     product is looked up on every render and nothing about it is frozen into
     state on the first one. */
  const status = useCatalogStatus();
  const product = getProductById(productId || "");

  const [pickedColor, setPickedColor] = useState<ProductColor | undefined>(undefined);
  const [pickedVariantId, setPickedVariantId] = useState<number | null>(null);
  const [quantity, setQuantity] = useState(1);
  const [addedToQuote, setAddedToQuote] = useState(false);
  const [addedToCart, setAddedToCart] = useState(false);
  const { addItem } = useQuote();
  const { addItem: addToCart } = useCart();

  /* Another product, another choice: a "related product" link reuses this page. */
  useEffect(() => {
    setPickedColor(undefined);
    setPickedVariantId(null);
    setQuantity(1);
  }, [productId]);

  const options = product?.variants ?? [];
  /* One option is no choice; several must be chosen by the customer. */
  const variant = options.find((option) => option.id === pickedVariantId) ?? (options.length === 1 ? options[0] : null);
  const needsChoice = options.length > 0 && !variant;
  const selectedColor = (pickedColor && product?.colors.find((color) => color.name === pickedColor.name)) || product?.colors[0];
  /* null means "price on request": it can be quoted and cannot be ordered. */
  const unitPrice = product ? optionPrice(product, variant) : null;

  const lineOf = () => ({
    id: product!.id,
    name: product!.name,
    category: product!.category,
    quantity,
    color: selectedColor?.name,
    image: product!.images[0],
    variantId: variant?.id ?? null,
    variant: variant?.label,
    sku: variant?.sku || product!.sku || null,
  });

  const handleAddToQuote = () => {
    if (!product || needsChoice) return;
    addItem({ ...lineOf(), price: unitPrice ?? 0, priceOnRequest: unitPrice === null });
    setAddedToQuote(true);
    setTimeout(() => setAddedToQuote(false), 2500);
  };

  const handleAddToCart = () => {
    if (!product || needsChoice || unitPrice === null) return;
    addToCart({ ...lineOf(), price: unitPrice });
    setAddedToCart(true);
    setTimeout(() => setAddedToCart(false), 2500);
  };

  /* What the browser tab, a shared link and a search engine read about this
     product. Until Furniture 2030 Phase 2 the build wrote this into a static
     page for each of 60 demo products; the catalogue now comes from the
     database, so it is written here, when the product arrives.

     The facts are gathered into one string so the effect runs when one of them
     changes and not on every refresh of the catalogue. */
  const lowest = product ? fromPrice(product) : null;
  const seoFacts = product
    ? JSON.stringify({
        id: product.id, name: product.name, sku: product.sku ?? null, image: product.images[0] ?? null,
        category: categoryLabel(product.subcategory || product.category),
        text: (product.shortDescription || product.description || "").trim().slice(0, 300),
        price: lowest,
        availability: !product.inStock ? "OutOfStock" : product.trackStock === false ? "MadeToOrder" : "InStock",
      })
    : "";
  useEffect(() => {
    const SCRIPT_ID = "product-jsonld";
    const ROBOTS_ID = "product-robots";
    /* Everything written to <head> here is taken back when the visitor moves
       on, so the next page does not inherit this product's address or text. */
    const before = {
      title: document.title,
      description: document.querySelector('meta[name="description"]')?.getAttribute("content") ?? null,
      canonical: document.querySelector('link[rel="canonical"]')?.getAttribute("href") ?? null,
    };
    const restore = () => {
      document.getElementById(SCRIPT_ID)?.remove();
      document.getElementById(ROBOTS_ID)?.remove();
      document.title = before.title;
      if (before.description !== null) document.querySelector('meta[name="description"]')?.setAttribute("content", before.description);
      const link = document.querySelector('link[rel="canonical"]');
      if (before.canonical !== null) link?.setAttribute("href", before.canonical);
      else link?.remove();
    };
    if (!seoFacts) {
      if (status !== "ready") return;
      /* An address with no product behind it still answers 200 — it is one
         page for every address. Ask search engines not to keep it. */
      document.title = title("Product not found");
      const robots = document.createElement("meta");
      robots.id = ROBOTS_ID; robots.name = "robots"; robots.content = "noindex";
      document.head.appendChild(robots);
      return restore;
    }
    const facts = JSON.parse(seoFacts) as { id: string; name: string; sku: string | null; image: string | null; category: string; text: string; price: number | null; availability: string };
    const url = `${window.location.origin}/shop/${facts.id}`;
    const description = facts.text || `${facts.name} — ${facts.category} by ${brand.name}.`;
    document.title = title(`${facts.name} — ${facts.category}`);
    document.querySelector('meta[name="description"]')?.setAttribute("content", description);
    let canonical = document.querySelector('link[rel="canonical"]');
    if (!canonical) {
      canonical = document.createElement("link");
      canonical.setAttribute("rel", "canonical");
      document.head.appendChild(canonical);
    }
    canonical.setAttribute("href", url);
    const script = document.createElement("script");
    script.id = SCRIPT_ID;
    script.type = "application/ld+json";
    script.text = JSON.stringify({
      "@context": "https://schema.org", "@type": "Product",
      name: facts.name, url, category: facts.category, description,
      brand: { "@type": "Brand", name: brand.name },
      ...(facts.sku ? { sku: facts.sku } : {}),
      ...(facts.image ? { image: new URL(facts.image, window.location.origin).href } : {}),
      /* An offer states a price. A product priced on request has none to
         state, so it carries no offer — not an offer of 0. */
      ...(facts.price !== null
        ? { offers: { "@type": "Offer", url, priceCurrency: brand.currency, price: facts.price, availability: `https://schema.org/${facts.availability}` } }
        : {}),
    });
    document.head.appendChild(script);
    return restore;
  }, [seoFacts, status]);

  /* The address of this page, handed to the phone's share sheet where there is
     one and copied otherwise. */
  const handleShare = async () => {
    if (!product) return;
    const url = `${window.location.origin}/shop/${product.id}`;
    try {
      if (typeof navigator.share === "function") {
        await navigator.share({ title: product.name, url });
        return;
      }
      await navigator.clipboard.writeText(url);
      toast.success("Link copied.");
    } catch (error) {
      /* Closing the share sheet is not a failure. */
      if (error instanceof DOMException && error.name === "AbortError") return;
      toast.error("The link could not be copied. Copy it from the address bar.");
    }
  };

  /* Not "Product Not Found" while the catalogue is still on its way, and not
     when it could not be read: neither says anything about this product. */
  if (!product && status !== "ready") {
    return (
      <div className="min-h-screen flex flex-col">
        <Header />
        <main className="flex-1 container mx-auto px-4 py-12">
          {status === "loading" ? (
            <div role="status" aria-live="polite" aria-busy="true" className="grid lg:grid-cols-2 gap-12">
              <span className="sr-only">Loading the product…</span>
              <div className="aspect-square bg-muted rounded-sm animate-pulse" aria-hidden="true" />
              <div className="space-y-4" aria-hidden="true">
                <div className="h-9 w-3/4 bg-muted rounded animate-pulse" />
                <div className="h-8 w-1/3 bg-muted rounded animate-pulse" />
                <div className="h-4 w-full bg-muted rounded animate-pulse" />
                <div className="h-4 w-5/6 bg-muted rounded animate-pulse" />
                <div className="h-12 w-1/2 bg-muted rounded animate-pulse" />
              </div>
            </div>
          ) : (
            <CatalogUnavailable />
          )}
        </main>
        <Footer />
      </div>
    );
  }

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

  const categoryName = categoryLabel(product.category);

  /* A "was" price belongs to the product's own price, not to an option's. */
  const discount = unitPrice !== null && options.length === 0 && product.originalPrice && product.originalPrice > unitPrice
    ? Math.round(((product.originalPrice - unitPrice) / product.originalPrice) * 100)
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
              <ImageGallery key={product.id} images={product.images} productName={product.name} />
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
                  <Badge variant="destructive">Not available</Badge>
                )}
                {product.inStock && product.trackStock === false && (
                  <Badge variant="outline">Made to order</Badge>
                )}
              </div>

              {/* Title. No rating: the shop has no review system to produce one. */}
              <div>
                <h1 className="text-3xl lg:text-4xl font-bold mb-3">{product.name}</h1>
                {(variant?.sku || product.sku) && (
                  <p className="text-sm text-muted-foreground">SKU <span className="font-mono text-foreground">{variant?.sku || product.sku}</span></p>
                )}
              </div>

              {/* Price */}
              {needsChoice ? (
                <p className="text-lg text-muted-foreground">Choose an option to see its price.</p>
              ) : unitPrice === null ? (
                <div>
                  <p className="text-2xl font-bold">Price on request</p>
                  <p className="text-sm text-muted-foreground mt-1">Add it to your quote and we will send you the price.</p>
                </div>
              ) : (
                <div className="flex items-baseline gap-3">
                  <span className="text-4xl font-bold">{formatPKR(unitPrice)}</span>
                  {discount > 0 && product.originalPrice && (
                    <>
                      <span className="text-xl text-muted-foreground line-through">
                        {formatPKR(product.originalPrice)}
                      </span>
                      <Badge variant="destructive">Save {discount}%</Badge>
                    </>
                  )}
                </div>
              )}

              {/* Short Description */}
              {product.shortDescription && <p className="text-muted-foreground text-lg">{product.shortDescription}</p>}

              <Separator />

              {/* Options: a size, a finish, a material — each with its own price */}
              {options.length > 0 && (
                <fieldset className="space-y-3">
                  <legend className="text-sm font-medium">Option{variant ? <span className="font-normal text-muted-foreground">: {variant.label}</span> : null}</legend>
                  <div className="flex flex-wrap gap-2">
                    {options.map((option) => {
                      const chosen = variant?.id === option.id;
                      return (
                        <label
                          key={option.id}
                          className={`cursor-pointer rounded-md border px-3 py-2 text-sm transition-colors focus-within:ring-2 focus-within:ring-accent ${
                            chosen ? "border-accent bg-accent/10 font-semibold" : "border-border hover:border-accent"
                          }`}
                        >
                          <input
                            type="radio"
                            name="product-option"
                            className="sr-only"
                            checked={chosen}
                            onChange={() => setPickedVariantId(option.id)}
                          />
                          <span className="block">{option.label}</span>
                          <span className="block text-xs text-muted-foreground">
                            {option.priceOnRequest ? "Price on request" : formatPKR(option.price)}
                          </span>
                        </label>
                      );
                    })}
                  </div>
                </fieldset>
              )}

              {/* Color Selection */}
              {selectedColor && (
                <ColorSwatches
                  colors={product.colors}
                  selectedColor={selectedColor}
                  onColorChange={setPickedColor}
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
                  {unitPrice !== null && !needsChoice && (
                    <span className="text-muted-foreground">
                      Total: <span className="font-semibold text-foreground">
                        {formatPKR(unitPrice * quantity)}
                      </span>
                    </span>
                  )}
                </div>
              </div>

              {/* Action Buttons */}
              <div className="flex flex-col sm:flex-row gap-3">
                {/* A product with no price cannot be ordered, so it has no cart
                    button: the quote button is the way to buy it. */}
                {(unitPrice !== null || needsChoice) && (
                  <Button
                    size="lg"
                    className={`flex-1 ${addedToCart ? "bg-hon-green-dark" : "bg-accent hover:bg-hon-green-dark"} text-accent-foreground`}
                    disabled={!product.inStock || needsChoice}
                    onClick={handleAddToCart}
                  >
                    {addedToCart ? (
                      <><CheckCircle className="h-5 w-5 mr-2" />Added to Cart!</>
                    ) : (
                      <><ShoppingBag className="h-5 w-5 mr-2" />{!product.inStock ? "Not available" : needsChoice ? "Choose an option" : "Add to Cart"}</>
                    )}
                  </Button>
                )}
                <Button
                  size="lg"
                  variant={unitPrice === null && !needsChoice ? "default" : "outline"}
                  className={unitPrice === null && !needsChoice ? "flex-1 bg-accent hover:bg-hon-green-dark text-accent-foreground" : "border-accent text-accent hover:bg-accent hover:text-accent-foreground"}
                  disabled={!product.inStock || needsChoice}
                  onClick={handleAddToQuote}
                >
                  {addedToQuote ? (
                    <><CheckCircle className="h-5 w-5 mr-2" />Added to Quote!</>
                  ) : (
                    <>Add to Quote</>
                  )}
                </Button>
                {/* A "Wishlist" button stood here and did nothing; the shop has no
                    wishlist. Share works: it hands over the link to this page. */}
                <Button size="icon" variant="outline" aria-label={`Share ${product.name}`} title="Share" onClick={() => void handleShare()}>
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
                    {/* The same rule checkout applies — see brand.delivery. */}
                    <p className="font-medium text-sm">Delivery across Pakistan</p>
                    <p className="text-xs text-muted-foreground">Free on orders of {formatPKR(brand.delivery.freeFrom)} or more</p>
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
                    <p className="text-xs text-muted-foreground">Confirm written coverage</p>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <div className="shrink-0 w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center">
                    <Package className="h-5 w-5 text-accent" />
                  </div>
                  <div>
                    <p className="font-medium text-sm">Installation options</p>
                    <p className="text-xs text-muted-foreground">Confirm assembly terms</p>
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
