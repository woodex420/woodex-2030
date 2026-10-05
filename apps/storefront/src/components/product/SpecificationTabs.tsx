import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Card, CardContent } from "@/components/ui/card";
import { Check, FileText, Settings, Shield, Truck } from "lucide-react";
import { Product } from "@/data/products";

interface SpecificationTabsProps {
  product: Product;
}

const SpecificationTabs = ({ product }: SpecificationTabsProps) => {
  return (
    <Tabs defaultValue="description" className="w-full">
      <TabsList className="w-full justify-start h-auto flex-wrap gap-1 bg-muted/50 p-1">
        <TabsTrigger value="description" className="flex items-center gap-2">
          <FileText className="h-4 w-4" />
          Description
        </TabsTrigger>
        <TabsTrigger value="specifications" className="flex items-center gap-2">
          <Settings className="h-4 w-4" />
          Specifications
        </TabsTrigger>
        <TabsTrigger value="features" className="flex items-center gap-2">
          <Check className="h-4 w-4" />
          Features
        </TabsTrigger>
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
                <h4 className="font-medium mb-1">Free Delivery</h4>
                <p className="text-sm text-muted-foreground">
                  Free delivery within UAE for orders above AED 2,000. Standard delivery takes 5-7 business days.
                </p>
              </div>
            </div>
            <div className="flex items-start gap-4">
              <div className="shrink-0 w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center">
                <Settings className="h-5 w-5 text-accent" />
              </div>
              <div>
                <h4 className="font-medium mb-1">Professional Assembly</h4>
                <p className="text-sm text-muted-foreground">
                  Our team provides free professional assembly and installation for all furniture items.
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
      </TabsContent>

      <TabsContent value="warranty" className="mt-6">
        <Card>
          <CardContent className="pt-6 space-y-4">
            <div className="flex items-start gap-4">
              <div className="shrink-0 w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center">
                <Shield className="h-5 w-5 text-accent" />
              </div>
              <div>
                <h4 className="font-medium mb-1">
                  {product.specifications.find(s => s.label === "Warranty")?.value || "Standard Warranty"}
                </h4>
                <p className="text-sm text-muted-foreground">
                  This product comes with a comprehensive warranty covering manufacturing defects and normal wear. 
                  Our warranty includes free repair or replacement of defective parts.
                </p>
              </div>
            </div>
            <div className="bg-muted/50 p-4 rounded-lg">
              <h5 className="font-medium mb-2">Warranty Coverage Includes:</h5>
              <ul className="text-sm text-muted-foreground space-y-1">
                <li>• Manufacturing defects</li>
                <li>• Structural integrity issues</li>
                <li>• Mechanism failures (for chairs and adjustable desks)</li>
                <li>• Upholstery defects (within first year)</li>
              </ul>
            </div>
          </CardContent>
        </Card>
      </TabsContent>
    </Tabs>
  );
};

// Helper function for conditional classes
function cn(...classes: (string | boolean | undefined)[]) {
  return classes.filter(Boolean).join(' ');
}

export default SpecificationTabs;
