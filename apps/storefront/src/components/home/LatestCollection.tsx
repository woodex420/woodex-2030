import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { ArrowRight } from "lucide-react";
import { products, categoryLabel } from "@/data/products";
import type { Product } from "@/data/products";
import { useCatalogStatus } from "@/lib/runtime";
import { ProductImage } from "@/components/shop/ProductImage";

/**
 * Four products from the catalogue, on the home page.
 *
 * This section used to show four products typed in here — names that were in
 * no catalogue, with links to /product/…, an address the site does not have,
 * so every one of them opened "Page not found". It now shows products the shop
 * actually lists, and links to their pages.
 *
 * Which four: the ones the owner has marked New, then the ones marked Best
 * Seller, then the most recently changed. Marking a product in the dashboard
 * is how the owner chooses what stands here.
 */
const pick = (list: Product[]): Product[] => {
  const rank = (p: Product) => (p.isNew ? 0 : p.isBestSeller ? 1 : 2);
  return list
    .filter((p) => p.inStock && p.images.length > 0)
    .sort((a, b) => rank(a) - rank(b) || (b.updatedAt ?? "").localeCompare(a.updatedAt ?? "") || a.name.localeCompare(b.name))
    .slice(0, 4);
};

const LatestCollection = () => {
  const status = useCatalogStatus();
  const shown = status === "ready" ? pick(products) : [];
  /* Nothing to show is not an error on the home page: the section is left
     out. While the catalogue is on its way the four tiles keep their place, so
     the page does not jump when they arrive. */
  if (status !== "loading" && shown.length === 0) return null;

  return (
    <section className="py-16 bg-background">
      <div className="container mx-auto px-4">
        <div className="flex items-end justify-between mb-10">
          <div>
            <h2 className="text-3xl lg:text-4xl font-bold mb-2">Latest Collection</h2>
            <p className="text-muted-foreground">Handpicked premium furniture for the modern workspace</p>
          </div>
          <Button variant="outline" className="hidden md:flex border-accent text-accent hover:bg-accent hover:text-accent-foreground" asChild>
            <Link to="/shop">View All <ArrowRight className="h-4 w-4 ml-2" /></Link>
          </Button>
        </div>
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-6" aria-busy={status === "loading"}>
          {status === "loading"
            ? Array.from({ length: 4 }).map((_, index) => (
                <div key={index} className="border border-border rounded-sm overflow-hidden bg-background" aria-hidden="true">
                  <div className="aspect-square bg-muted animate-pulse" />
                  <div className="p-4 space-y-2">
                    <div className="h-3 w-1/2 mx-auto bg-muted rounded animate-pulse" />
                    <div className="h-4 w-3/4 mx-auto bg-muted rounded animate-pulse" />
                  </div>
                </div>
              ))
            : shown.map((p) => (
                <Link key={p.id} to={`/shop/${p.id}`} className="group border border-border rounded-sm overflow-hidden bg-background hover:border-accent hover:shadow-lg transition-all">
                  <div className="aspect-square bg-muted overflow-hidden">
                    <ProductImage src={p.images[0]} name={p.name} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />
                  </div>
                  <div className="p-4 text-center">
                    <p className="text-xs text-muted-foreground mb-1">{categoryLabel(p.subcategory || p.category)}</p>
                    <h3 className="font-bold text-sm group-hover:text-accent transition-colors">{p.name}</h3>
                  </div>
                </Link>
              ))}
        </div>
      </div>
    </section>
  );
};

export default LatestCollection;
