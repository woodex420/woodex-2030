import { useEffect } from "react";
import { Link } from "react-router-dom";
import { Play, Monitor, RotateCcw, Palette, Maximize, Users, Box } from "lucide-react";
import { Button } from "@/components/ui/button";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import RoomConfigurator from "@/components/configurator/RoomConfigurator";
import showroomImg from "@/assets/virtual-showroom-hero.jpg";

const features = [
  { icon: Monitor, title: "Real-Time 3D Preview", description: "Photorealistic rendering of every product from any angle, in your actual room dimensions." },
  { icon: RotateCcw, title: "360° Product View", description: "Spin, zoom, and inspect every detail of our furniture before you commit." },
  { icon: Palette, title: "Color & Material Configurator", description: "Try different fabric options, wood finishes, and color combinations instantly." },
  { icon: Maximize, title: "Space Planning Tool", description: "Design your office layout with accurate dimensions and real-time space validation." },
  { icon: Users, title: "Collaborative Design", description: "Share your virtual layout with colleagues and stakeholders for instant feedback." },
  { icon: Box, title: "Save & Export", description: "Save your configurations and export professional presentation layouts and specs." },
];

const VirtualShowroom = () => {
  useEffect(() => {
    document.title = "Virtual Showroom — WOODEX Pakistan | 3D Room Configurator";
  }, []);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* Hero */}
        <section className="bg-primary text-primary-foreground py-16">
          <div className="container mx-auto px-4">
            <div className="grid lg:grid-cols-2 gap-10 items-center">
              <div>
                <p className="text-accent text-xs font-bold uppercase tracking-widest mb-3">Experience It First</p>
                <h1 className="text-4xl lg:text-6xl font-black mb-4 leading-tight">
                  Virtual Showroom
                </h1>
                <p className="text-primary-foreground/75 mb-7 text-lg leading-relaxed">
                  Experience our furniture in immersive 3D. Click on any piece in the room to change its material in real time — wood, metal, fabric, or laminate.
                </p>
                <div className="flex flex-wrap gap-4">
                  <Button
                    size="lg"
                    className="bg-accent hover:bg-accent/90 text-accent-foreground px-8"
                    onClick={() => document.getElementById("configurator-section")?.scrollIntoView({ behavior: "smooth" })}
                  >
                    Launch Configurator
                  </Button>
                  <Button
                    size="lg"
                    variant="outline"
                    className="border-primary-foreground/40 text-primary-foreground hover:bg-primary-foreground/10"
                  >
                    <Play className="mr-2 h-5 w-5" />
                    Watch Demo
                  </Button>
                </div>
              </div>
              <div className="rounded-sm overflow-hidden shadow-2xl">
                <img src={showroomImg} alt="Virtual Showroom" className="w-full h-72 lg:h-80 object-cover" />
              </div>
            </div>
          </div>
        </section>

        {/* Interactive Room Configurator */}
        <section id="configurator-section" className="py-16 bg-background">
          <div className="container mx-auto px-4">
            <div className="text-center mb-8">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl font-bold mb-2">Interactive Room Configurator</h2>
              <p className="text-muted-foreground max-w-xl mx-auto">
                Choose a room type, click on furniture hotspots, and swap materials in real time.
                Filter by wood, metal, fabric, or brightness level.
              </p>
            </div>

            <RoomConfigurator />
          </div>
        </section>

        {/* Features Grid */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-12">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl font-bold mb-3">Virtual Showroom Features</h2>
              <p className="text-muted-foreground">Experience furniture shopping like never before</p>
            </div>
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
              {features.map((f) => (
                <div key={f.title} className="flex gap-4 p-5 bg-background rounded-sm border border-border hover:border-accent transition-colors">
                  <div className="w-10 h-10 rounded-sm bg-muted flex items-center justify-center flex-shrink-0">
                    <f.icon className="h-5 w-5 text-accent" />
                  </div>
                  <div>
                    <h3 className="font-bold mb-1">{f.title}</h3>
                    <p className="text-sm text-muted-foreground leading-relaxed">{f.description}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* CTA */}
        <section className="py-14 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl font-bold mb-3">Ready to Design Your Dream Office?</h2>
            <p className="text-primary-foreground/75 mb-7 max-w-md mx-auto">
              Start with our virtual showroom and get a tailored quote based on your custom design.
            </p>
            <div className="flex flex-wrap gap-4 justify-center">
              <Button className="bg-accent hover:bg-accent/90 text-accent-foreground px-8" asChild>
                <Link to="/quotation">Get Custom Quote</Link>
              </Button>
              <Button variant="outline" className="border-primary-foreground/30 text-primary-foreground hover:bg-primary-foreground/10" asChild>
                <Link to="/shop">Browse Products</Link>
              </Button>
            </div>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
};

export default VirtualShowroom;
