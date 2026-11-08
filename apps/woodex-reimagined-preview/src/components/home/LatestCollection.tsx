import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { ArrowRight } from "lucide-react";
import chairImg from "@/assets/product-office-chair.jpg";
import execDeskImg from "@/assets/product-executive-desk.jpg";
import workstationImg from "@/assets/product-workstation.jpg";
import sofaImg from "@/assets/product-office-sofa.jpg";

const products = [
  { name: "Black Pearl Executive Chair", category: "Executive Chairs", image: chairImg, slug: "executive-ergonomic-chair" },
  { name: "Monarch Executive Desk", category: "Executive Tables", image: execDeskImg, slug: "monarch-executive-desk" },
  { name: "Nova Pro Workstation", category: "Workstations", image: workstationImg, slug: "nova-pro-workstation" },
  { name: "Cambridge Office Sofa", category: "Office Sofas", image: sofaImg, slug: "cambridge-3-seater-sofa" },
];

const LatestCollection = () => (
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
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-6">
        {products.map((p) => (
          <Link key={p.slug} to={`/product/${p.slug}`} className="group border border-border rounded-sm overflow-hidden bg-background hover:border-accent hover:shadow-lg transition-all">
            <div className="aspect-square bg-muted overflow-hidden">
              <img src={p.image} alt={p.name} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />
            </div>
            <div className="p-4 text-center">
              <p className="text-xs text-muted-foreground mb-1">{p.category}</p>
              <h3 className="font-bold text-sm group-hover:text-accent transition-colors">{p.name}</h3>
            </div>
          </Link>
        ))}
      </div>
    </div>
  </section>
);

export default LatestCollection;
