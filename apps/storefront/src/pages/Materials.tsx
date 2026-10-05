import { useState, useEffect } from "react";
import materialsHero from "@/assets/materials-hero.jpg";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { Button } from "@/components/ui/button";
import { Link } from "react-router-dom";

const woodFinishes = [
  { name: "Dark Walnut", hex: "#3e2723", description: "Rich, deep brown with natural grain" },
  { name: "Natural Oak", hex: "#c4a35a", description: "Warm golden tone, light wood feel" },
  { name: "Espresso", hex: "#3c2415", description: "Very dark brown, near-black elegance" },
  { name: "White Oak", hex: "#d4c5a9", description: "Light, Scandinavian-inspired finish" },
  { name: "Sheesham", hex: "#7a4f2d", description: "Pakistani teak, warm reddish brown" },
  { name: "Beech", hex: "#c9a87c", description: "Light honey tone, smooth grain" },
];

const fabricColors = [
  { name: "Charcoal Black", hex: "#1a1a1a" },
  { name: "Slate Gray", hex: "#4a5568" },
  { name: "Navy Blue", hex: "#1e3a5f" },
  { name: "Burgundy", hex: "#722f37" },
  { name: "Dove Gray", hex: "#9ca3af" },
  { name: "Forest Green", hex: "#15803d" },
  { name: "Cream", hex: "#f5f0e0" },
  { name: "Royal Blue", hex: "#2563eb" },
  { name: "Beige", hex: "#d4b896" },
  { name: "Teal", hex: "#0d9488" },
  { name: "Cognac Brown", hex: "#8b4513" },
  { name: "Off-White", hex: "#f8f6f0" },
];

const metalFinishes = [
  { name: "Chrome Silver", hex: "#c0c0c0", description: "Bright, reflective, modern" },
  { name: "Matte Black", hex: "#1a1a1a", description: "Contemporary, sleek, industrial" },
  { name: "Brushed Nickel", hex: "#8a8a8a", description: "Subtle sheen, durable" },
  { name: "Antique Brass", hex: "#b5a642", description: "Warm, vintage, sophisticated" },
  { name: "Powder White", hex: "#f0f0f0", description: "Clean, minimalist, bright" },
];

const materials = [
  {
    id: "solid-wood",
    name: "Solid Hardwood",
    description: "Premium Pakistani and imported hardwoods including sheesham, walnut, and teak. Exceptional durability with natural beauty that improves with age.",
    properties: ["Natural grain variation", "Highly durable", "Refinishable", "Eco-certified options"],
  },
  {
    id: "engineered-wood",
    name: "Engineered Wood",
    description: "High-quality MDF and particle board with veneer finishes. Dimensionally stable, resistant to warping, and more consistent than solid wood.",
    properties: ["Uniform appearance", "Cost-effective", "Moisture resistant", "Wide color range"],
  },
  {
    id: "steel-frame",
    name: "Steel Frame",
    description: "Heavy-duty powder-coated steel frames used in workstations and storage. Built for commercial durability with a clean modern aesthetic.",
    properties: ["Extremely durable", "Powder-coated finish", "Recyclable", "Custom colors available"],
  },
  {
    id: "upholstery",
    name: "Upholstery Fabrics",
    description: "Premium seating fabrics including mesh, fabric, and full-grain leather options. All rated for commercial use with minimum 100,000 Martindale rub cycles.",
    properties: ["Commercial grade", "Stain resistant", "50+ color options", "Antimicrobial available"],
  },
];

const Materials = () => {
  const [selectedWood, setSelectedWood] = useState(woodFinishes[0]);
  const [selectedFabric, setSelectedFabric] = useState(fabricColors[0]);

  useEffect(() => {
    document.title = "Materials & Colors — WOODEX Pakistan";
  }, []);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      <main className="flex-1">
        {/* Hero */}
        <section className="relative bg-primary text-primary-foreground py-20 overflow-hidden">
          <img src={materialsHero} alt="Materials & finishes" className="absolute inset-0 w-full h-full object-cover opacity-30" />
          <div className="absolute inset-0 bg-gradient-to-r from-primary via-primary/85 to-primary/40" />
          <div className="container mx-auto px-4 relative">
            <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">Finishes & Fabrics</p>
            <h1 className="text-4xl lg:text-5xl font-black mb-3">Materials & Colors</h1>
            <p className="text-primary-foreground/75 max-w-xl">
              Every WOODEX piece is available in a range of premium finishes and fabric options. 
              Explore our materials and customize your furniture to match your space.
            </p>
          </div>
        </section>

        {/* Wood Finishes */}
        <section className="py-16">
          <div className="container mx-auto px-4">
            <div className="w-12 h-1 bg-accent mb-5" />
            <h2 className="text-3xl font-bold mb-2">Wood Finishes</h2>
            <p className="text-muted-foreground mb-10">Choose from 6 premium wood finishes for tables, desks, and storage</p>
            <div className="grid lg:grid-cols-2 gap-10 items-start">
              {/* Preview */}
              <div className="sticky top-28">
                <div
                  className="aspect-square rounded-sm border border-border flex items-center justify-center relative overflow-hidden"
                  style={{ backgroundColor: selectedWood.hex + "22" }}
                >
                  <div className="text-center">
                    <div className="w-24 h-24 rounded-sm mx-auto mb-4 shadow-xl border border-border" style={{ backgroundColor: selectedWood.hex }} />
                    <h3 className="font-bold text-xl">{selectedWood.name}</h3>
                    <p className="text-muted-foreground text-sm mt-1">{selectedWood.description}</p>
                  </div>
                </div>
              </div>
              {/* Swatches */}
              <div>
                <div className="grid grid-cols-2 gap-4">
                  {woodFinishes.map((finish) => (
                    <button
                      key={finish.name}
                      onClick={() => setSelectedWood(finish)}
                      className={`p-4 border rounded-sm text-left transition-all hover:shadow-md ${selectedWood.name === finish.name ? "border-accent shadow-md" : "border-border"}`}
                    >
                      <div className="w-12 h-8 rounded mb-3 border border-border" style={{ backgroundColor: finish.hex }} />
                      <p className="font-semibold text-sm">{finish.name}</p>
                      <p className="text-xs text-muted-foreground mt-0.5">{finish.description}</p>
                    </button>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* Fabric Colors */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="w-12 h-1 bg-accent mb-5" />
            <h2 className="text-3xl font-bold mb-2">Fabric & Upholstery Colors</h2>
            <p className="text-muted-foreground mb-10">12 standard colors for office chairs, sofas, and seating</p>
            <div className="grid lg:grid-cols-2 gap-10 items-start">
              <div className="sticky top-28">
                <div
                  className="aspect-square rounded-sm border border-border flex items-center justify-center"
                  style={{ backgroundColor: selectedFabric.hex + "33" }}
                >
                  <div className="text-center">
                    <div className="w-24 h-24 rounded-full mx-auto mb-4 shadow-xl border-4 border-background" style={{ backgroundColor: selectedFabric.hex }} />
                    <h3 className="font-bold text-xl">{selectedFabric.name}</h3>
                  </div>
                </div>
              </div>
              <div className="flex flex-wrap gap-3">
                {fabricColors.map((color) => (
                  <button
                    key={color.name}
                    onClick={() => setSelectedFabric(color)}
                    title={color.name}
                    className={`w-12 h-12 rounded-full border-4 transition-all hover:scale-110 ${selectedFabric.name === color.name ? "border-accent scale-110" : "border-background shadow-md"}`}
                    style={{ backgroundColor: color.hex }}
                  />
                ))}
              </div>
            </div>
          </div>
        </section>

        {/* Metal Finishes */}
        <section className="py-16 border-t">
          <div className="container mx-auto px-4">
            <div className="w-12 h-1 bg-accent mb-5" />
            <h2 className="text-3xl font-bold mb-2">Metal Frame Finishes</h2>
            <p className="text-muted-foreground mb-10">Available on workstations, chair bases, and storage frames</p>
            <div className="grid sm:grid-cols-2 lg:grid-cols-5 gap-4">
              {metalFinishes.map((metal) => (
                <div key={metal.name} className="p-5 border border-border rounded-sm hover:border-accent transition-colors text-center">
                  <div className="w-16 h-16 rounded-full mx-auto mb-3 border-4 border-background shadow-lg" style={{ backgroundColor: metal.hex }} />
                  <h3 className="font-semibold text-sm mb-1">{metal.name}</h3>
                  <p className="text-xs text-muted-foreground">{metal.description}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Material Specs */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-12">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl font-bold mb-3">Our Material Standards</h2>
              <p className="text-muted-foreground max-w-xl mx-auto">Every material is rigorously tested to meet commercial durability standards</p>
            </div>
            <div className="grid md:grid-cols-2 gap-6">
              {materials.map((m) => (
                <div key={m.id} className="p-6 bg-background border border-border rounded-sm hover:border-accent transition-colors">
                  <div className="w-8 h-1 bg-accent mb-4" />
                  <h3 className="font-bold text-lg mb-2">{m.name}</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed mb-4">{m.description}</p>
                  <div className="flex flex-wrap gap-2">
                    {m.properties.map((p) => (
                      <span key={p} className="text-xs bg-hon-green-pale text-accent px-2.5 py-1 rounded-full font-medium">{p}</span>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* CTA */}
        <section className="py-14 bg-accent text-accent-foreground">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl font-bold mb-3">Need a Custom Color?</h2>
            <p className="opacity-85 mb-7 max-w-md mx-auto">
              We offer custom fabric and finish matching for enterprise orders. Contact us with your brand guidelines.
            </p>
            <Button size="lg" className="bg-primary hover:bg-primary/90 text-primary-foreground px-8" asChild>
              <Link to="/quotation">Request Custom Finish</Link>
            </Button>
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default Materials;
