import { useEffect } from "react";
import { Link } from "react-router-dom";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { CheckCircle2, ArrowRight } from "lucide-react";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import chairImage from "@/assets/chair-product.jpg";
import deskImage from "@/assets/desk-product.jpg";
import workstationImage from "@/assets/workstation-product.jpg";
import proj1 from "@/assets/project-1.jpg";

const packages = [
  {
    id: 1,
    title: "Executive Office Package",
    image: deskImage,
    badge: "Save 10%",
    price: "PKR 450,000",
    originalPrice: "PKR 500,000",
    items: ["Executive L-Desk", "High-back Leather Chair", "2-Door Storage Cabinet", "Visitor Chairs (2)"],
    description: "Everything a C-suite office needs. Crafted for authority and comfort.",
    popular: false,
  },
  {
    id: 2,
    title: "Team Workspace Package",
    image: workstationImage,
    badge: "Best Value",
    price: "PKR 820,000",
    originalPrice: "PKR 900,000",
    items: ["4-Person Benching Workstation", "Task Chairs (4)", "Under-desk Pedestals (4)", "Cable Management System"],
    description: "Complete 4-person workstation solution ready to plug in and produce.",
    popular: true,
  },
  {
    id: 3,
    title: "Meeting Room Package",
    image: proj1,
    badge: "Save 8%",
    price: "PKR 320,000",
    originalPrice: "PKR 348,000",
    items: ["8-Seat Conference Table", "Meeting Chairs (8)", "Credenza", "Presentation Board"],
    description: "A complete boardroom setup that makes every meeting feel professional.",
    popular: false,
  },
  {
    id: 4,
    title: "Startup Bundle",
    image: workstationImage,
    badge: "Save 7%",
    price: "PKR 680,000",
    originalPrice: "PKR 733,000",
    items: ["6-Person Workstation", "Ergonomic Chairs (6)", "Mobile Storage Units (3)", "Reception Desk"],
    description: "Launch your office from day one with everything your team needs.",
    popular: false,
  },
  {
    id: 5,
    title: "Manager's Suite",
    image: deskImage,
    badge: "Save 12%",
    price: "PKR 580,000",
    originalPrice: "PKR 659,000",
    items: ["L-Shaped Executive Desk", "Executive Chair", "5-Shelf Bookcase", "Round Meeting Table (4)"],
    description: "A private office that projects leadership and supports focused work.",
    popular: false,
  },
  {
    id: 6,
    title: "Open Office Package",
    image: chairImage,
    badge: "Save 12%",
    price: "PKR 1,100,000",
    originalPrice: "PKR 1,250,000",
    items: ["8-Person Benching System", "Ergonomic Chairs (8)", "Storage Lockers (8)", "Breakout Lounge Seating"],
    description: "A full open-plan setup with flexible seating for modern collaborative teams.",
    popular: false,
  },
];

const RoomPackages = () => {
  useEffect(() => {
    document.title = "Room Packages — WOODEX Pakistan | Bundled Office Furniture Solutions";
  }, []);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* Hero */}
        <section className="bg-primary text-primary-foreground py-14">
          <div className="container mx-auto px-4">
            <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">Bundled Solutions</p>
            <h1 className="text-4xl lg:text-5xl font-black mb-3">Room Packages</h1>
            <p className="text-primary-foreground/75 max-w-xl">
              Complete office solutions with bundled savings. Each package includes coordinated furniture 
              pieces designed to work together seamlessly.
            </p>
          </div>
        </section>

        {/* Why Packages Bar */}
        <section className="bg-accent py-5">
          <div className="container mx-auto px-4">
            <div className="flex flex-wrap justify-center gap-8 text-accent-foreground text-sm font-medium">
              {["Coordinated Design", "Significant Savings", "Fast Delivery", "Easy Installation", "Full Warranty"].map((item) => (
                <div key={item} className="flex items-center gap-2">
                  <CheckCircle2 className="h-4 w-4" />
                  {item}
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Packages Grid */}
        <section className="py-16 bg-background">
          <div className="container mx-auto px-4">
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-7">
              {packages.map((pkg) => (
                <div
                  key={pkg.id}
                  className={`group border-2 rounded-sm overflow-hidden hover:shadow-xl transition-all ${
                    pkg.popular ? "border-accent" : "border-border hover:border-accent"
                  }`}
                >
                  {pkg.popular && (
                    <div className="bg-accent text-accent-foreground text-xs font-bold py-2 text-center uppercase tracking-wider">
                      ⭐ Most Popular Choice
                    </div>
                  )}
                  <div className="relative aspect-[4/3] overflow-hidden bg-muted">
                    <img
                      src={pkg.image}
                      alt={pkg.title}
                      className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                    />
                    <div className="absolute top-3 left-3">
                      <Badge className="bg-accent text-accent-foreground text-xs">{pkg.badge}</Badge>
                    </div>
                  </div>

                  <div className="p-6">
                    <h3 className="font-bold text-xl mb-2 group-hover:text-accent transition-colors">{pkg.title}</h3>
                    <p className="text-sm text-muted-foreground mb-4">{pkg.description}</p>

                    <div className="flex items-center gap-3 mb-5">
                      <span className="text-2xl font-black text-accent">{pkg.price}</span>
                      <span className="text-sm text-muted-foreground line-through">{pkg.originalPrice}</span>
                    </div>

                    <div className="space-y-2 mb-6 border-t pt-4">
                      <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">Includes:</p>
                      {pkg.items.map((item) => (
                        <div key={item} className="flex items-center gap-2 text-sm text-muted-foreground">
                          <div className="w-1.5 h-1.5 rounded-full bg-accent flex-shrink-0" />
                          {item}
                        </div>
                      ))}
                    </div>

                    <Button className="w-full bg-accent hover:bg-hon-green-dark text-accent-foreground" asChild>
                      <Link to="/quotation">
                        Get Quote <ArrowRight className="h-4 w-4 ml-2" />
                      </Link>
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Custom Package CTA */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="max-w-2xl mx-auto text-center">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl font-bold mb-3">Need a Custom Package?</h2>
              <p className="text-muted-foreground text-lg mb-7">
                Our team can create a tailored solution that perfectly matches your requirements and budget. 
                Just tell us what you need.
              </p>
              <div className="flex flex-wrap gap-4 justify-center">
                <Button size="lg" className="bg-accent hover:bg-hon-green-dark text-accent-foreground px-8" asChild>
                  <Link to="/quotation">Request Custom Quote</Link>
                </Button>
                <Button size="lg" variant="outline" className="border-accent text-accent hover:bg-accent hover:text-accent-foreground" asChild>
                  <Link to="/contact">Contact Sales</Link>
                </Button>
              </div>
            </div>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
};

export default RoomPackages;
