import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Card, CardContent } from "@/components/ui/card";
import { Check, FileText, Settings, Shield, Truck } from "lucide-react";
import { Product, formatPKR } from "@/data/products";
import { brand } from "@/config/brand";

interface SpecificationTabsProps {
  product: Product;
}

const SpecificationTabs = ({ product }: SpecificationTabsProps) => {
  /* A tab is offered only when the product has something to put in it. The
     catalogue taken from the old shop has names, prices and photographs and,
     for most products, no description or specification yet; an empty tab
     would be a page that promises text and shows a blank card. */
  const hasDescription = product.description.trim().length > 0;
  const hasSpecifications = product.specifications.length > 0;
  const hasFeatures = product.features.length > 0;
  const first = hasDescription ? "description" : hasSpecifications ? "specifications" : hasFeatures ? "features" : "shipping";
  return (
    <Tabs key={product.id + first} defaultValue={first} className="w-full">
      <TabsList className="w-full justify-start h-auto flex-wrap gap-1 bg-muted/50 p-1">
        {hasDescription && (
          <TabsTrigger value="description" className="flex items-center gap-2">
            <FileText className="h-4 w-4" />
            Description
          </TabsTrigger>
        )}
        {hasSpecifications && (
          <TabsTrigger value="specifications" className="flex items-center gap-2">
            <Settings className="h-4 w-4" />
            Specifications
          </TabsTrigger>
        )}
        {hasFeatures && (
          <TabsTrigger value="features" className="flex items-center gap-2">
            <Check className="h-4 w-4" />
            Features
          </TabsTrigger>
        )}
        <TabsTrigger value="shipping" className="flex items-center gap-2">
          <Truck className="h-4 w-4" />
          Shipping
        </TabsTrigger>
        <TabsTrigger value="warranty" className="flex items-center gap-2">
          <Shield className="h-4 w-4" />
          Warranty
        </TabsTrigger>
      </TabsList>

      <TabsContent value="description" className="mt-6">
        <Card>
          <CardContent className="pt-6">
            <p className="text-muted-foreground leading-relaxed">
              {product.description}
            </p>
          </CardContent>
        </Card>
      </TabsContent>

      <TabsContent value="specifications" className="mt-6">
        <Card>
          <CardContent className="pt-6">
            <div className="grid gap-0">
              {product.specifications.map((spec, index) => (
                <div
                  key={index}
                  className={cn(
                    "grid grid-cols-2 py-3",
                    index !== product.specifications.length - 1 && "border-b"
                  )}
                >
                  <span className="font-medium">{spec.label}</span>
                  <span className="text-muted-foreground">{spec.value}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </TabsContent>

      <TabsContent value="features" className="mt-6">
        <Card>
          <CardContent className="pt-6">
            <div className="grid sm:grid-cols-2 gap-3">
              {product.features.map((feature, index) => (
                <div key={index} className="flex items-center gap-3">
                  <div className="shrink-0 w-6 h-6 rounded-full bg-accent/10 flex items-center justify-center">
                    <Check className="h-4 w-4 text-accent" />
                  </div>
                  <span>{feature}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </TabsContent>

      <TabsContent value="shipping" className="mt-6">
        <Card>
          <CardContent className="pt-6 space-y-4">
            <div className="flex items-start gap-4">
              <div className="shrink-0 w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center">
                <Truck className="h-5 w-5 text-accent" />
              </div>
              <div>
                {/* The rule checkout applies (brand.delivery). This text used to
                    promise free delivery "within UAE for orders above AED 2,000",
                    and free assembly for every item — a promise nobody confirmed. */}
                <h4 className="font-medium mb-1">Delivery across Pakistan</h4>
                {/* What the product's own editor wrote about its delivery and installation, where the product
                    carries it (a product published in the admin does); the shop's general rule otherwise. */}
                {product.shipping?.trim() ? (
                  <p className="whitespace-pre-line text-sm text-muted-foreground">{product.shipping}</p>
                ) : (
                  <p className="text-sm text-muted-foreground">
                    Delivery is {formatPKR(brand.delivery.fee)} per order and free on orders of {formatPKR(brand.delivery.freeFrom)} or more.
                    Our team confirms the delivery date and the installation with you after your order.
                  </p>
                )}
              </div>
            </div>
          </CardContent>
        </Card>
      </TabsContent>
      <TabsContent value="warranty" className="mt-6">
        <Card><CardContent className="pt-6 space-y-4"><h4 className="font-medium">{product.specifications.find(s => s.label === "Warranty")?.value || "Warranty terms"}</h4>
          <p className="whitespace-pre-line text-sm text-muted-foreground">{product.warranty || "Confirm this product's written warranty coverage, exclusions and service process with WOODEX. A source warranty label does not establish coverage for normal wear or every component."}</p>
        </CardContent></Card>
      </TabsContent>
    </Tabs>
  );
};

// Helper function for conditional classes
function cn(...classes: (string | boolean | undefined)[]) {
  return classes.filter(Boolean).join(' ');
}

export default SpecificationTabs;
