import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { retryCatalog } from "@/lib/runtime";

/**
 * What a page shows while the catalogue is on its way, and when it could not
 * be read.
 *
 * The storefront has no compiled list of products to fall back on — on
 * purpose: a fallback list is how sample products with sample prices used to
 * be shown to customers as if they were for sale. So there are exactly two
 * honest things to show before the real catalogue is on screen, and these are
 * them.
 */
export const CatalogLoading = ({ cards = 6 }: { cards?: number }) => (
  <div role="status" aria-live="polite" aria-busy="true">
    <span className="sr-only">Loading the catalogue…</span>
    <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5" aria-hidden="true">
      {Array.from({ length: cards }).map((_, index) => (
        <div key={index} className="border border-border rounded-sm overflow-hidden">
          <div className="aspect-square bg-muted animate-pulse" />
          <div className="p-4 space-y-2">
            <div className="h-4 w-3/4 bg-muted rounded animate-pulse" />
            <div className="h-3 w-1/2 bg-muted rounded animate-pulse" />
            <div className="h-5 w-1/3 bg-muted rounded animate-pulse" />
          </div>
        </div>
      ))}
    </div>
  </div>
);

export const CatalogUnavailable = () => (
  <div role="alert" className="text-center py-16 px-6 border border-dashed border-border rounded-sm">
    <p className="text-xl font-bold mb-2">The catalogue is unavailable right now</p>
    <p className="text-muted-foreground mb-6 max-w-md mx-auto">
      We could not load our products. Nothing is wrong on your side. Please try again in a moment, or send us your
      requirement and we will reply with a quotation.
    </p>
    <div className="flex flex-wrap justify-center gap-3">
      <Button onClick={() => void retryCatalog()} className="bg-accent hover:bg-accent/90 text-accent-foreground">
        Try again
      </Button>
      <Button variant="outline" asChild>
        <Link to="/contact">Contact us</Link>
      </Button>
    </div>
  </div>
);
