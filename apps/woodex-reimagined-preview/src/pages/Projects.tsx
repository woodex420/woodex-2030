import { useState, useEffect } from "react";
import projectsHero from "@/assets/projects-hero.jpg";
import { Link } from "react-router-dom";
import { ArrowRight, Filter } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import proj1 from "@/assets/project-1.jpg";
import proj2 from "@/assets/project-2.jpg";
import proj3 from "@/assets/project-3.jpg";
import proj4 from "@/assets/project-4.jpg";
import proj5 from "@/assets/project-5.jpg";
import proj6 from "@/assets/project-6.jpg";
import projSalon from "@/assets/project-hair-salon.jpg";

const projects = [
  { id: 7, title: "Gents Grooming Lounge — DHA Lahore", client: "Private Boutique Salon", category: "Hospitality", image: projSalon, location: "DHA Phase 5, Lahore", sqft: "3,500 sq ft", description: "Concept-to-execution turnkey fit-out of a premium men's hair salon — walnut joinery, brass-framed mirrors, deep forest green feature wall, custom barber stations and private grooming alcoves." },
  { id: 1, title: "DHA Corporate Tower", client: "DHA Developers", category: "Corporate", image: proj1, location: "Lahore", sqft: "12,000 sq ft", description: "Complete C-suite office fit-out with custom executive furniture, meeting rooms, and collaborative spaces." },
  { id: 2, title: "TechHub Karachi", client: "TechHub Pakistan", category: "Tech", image: proj2, location: "Karachi", sqft: "8,500 sq ft", description: "Modern open-plan tech office with agile workstations, standing desks, and vibrant breakout areas." },
  { id: 3, title: "Shaukat Khanum Clinic", client: "SKMT Foundation", category: "Healthcare", image: proj3, location: "Islamabad", sqft: "4,200 sq ft", description: "Healthcare-grade furniture for waiting areas, consultation rooms, and staff offices." },
  { id: 4, title: "LUMS Library Expansion", client: "LUMS University", category: "Education", image: proj4, location: "Lahore", sqft: "6,800 sq ft", description: "Academic furniture including collaborative study tables, individual pods, and faculty offices." },
  { id: 5, title: "FBR Regional Office", client: "Federal Board of Revenue", category: "Government", image: proj5, location: "Islamabad", sqft: "15,000 sq ft", description: "Government-standard office furniture for multiple floors of the regional headquarters." },
  { id: 6, title: "PC Hotel Business Center", client: "Pearl Continental", category: "Hospitality", image: proj6, location: "Lahore", sqft: "3,500 sq ft", description: "Luxury lounge and business center furniture for a 5-star hospitality environment." },
];

const categories = ["All", "Corporate", "Tech", "Healthcare", "Education", "Government", "Hospitality"];

const Projects = () => {
  const [activeCategory, setActiveCategory] = useState("All");

  useEffect(() => {
    document.title = "Our Projects — WOODEX Pakistan | Portfolio & Case Studies";
  }, []);

  const filtered = activeCategory === "All" ? projects : projects.filter(p => p.category === activeCategory);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* Hero */}
        <section className="relative bg-primary text-primary-foreground py-20 overflow-hidden">
          <img src={projectsHero} alt="Our completed office projects" className="absolute inset-0 w-full h-full object-cover opacity-30" />
          <div className="absolute inset-0 bg-gradient-to-r from-primary via-primary/85 to-primary/40" />
          <div className="container mx-auto px-4 relative">
            <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">Portfolio</p>
            <h1 className="text-4xl lg:text-5xl font-black mb-3">Our Projects</h1>
            <p className="text-primary-foreground/75 max-w-xl">
              See how we've transformed workspaces for leading companies across Pakistan and the region.
            </p>
          </div>
        </section>

        {/* Filter Bar */}
        <section className="border-b bg-background py-4 sticky top-16 z-20">
          <div className="container mx-auto px-4">
            <div className="flex items-center gap-2 overflow-x-auto pb-1">
              <Filter className="h-4 w-4 text-muted-foreground flex-shrink-0 mr-1" />
              {categories.map((cat) => (
                <button
                  key={cat}
                  onClick={() => setActiveCategory(cat)}
                  className={`whitespace-nowrap px-4 py-1.5 rounded-full text-sm font-medium border transition-all ${
                    activeCategory === cat
                      ? "bg-accent text-accent-foreground border-accent"
                      : "border-border text-muted-foreground hover:border-accent hover:text-accent bg-background"
                  }`}
                >
                  {cat}
                </button>
              ))}
            </div>
          </div>
        </section>

        {/* Projects Grid */}
        <section className="py-12">
          <div className="container mx-auto px-4">
            <p className="text-sm text-muted-foreground mb-6">{filtered.length} project{filtered.length !== 1 ? "s" : ""} found</p>
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
              {filtered.map((project) => (
                <div key={project.id} className="group border border-border rounded-sm overflow-hidden hover:border-accent hover:shadow-lg transition-all">
                  <div className="relative aspect-video overflow-hidden bg-muted">
                    <img
                      src={project.image}
                      alt={project.title}
                      className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                    />
                    <div className="absolute top-3 left-3">
                      <Badge className="bg-accent text-accent-foreground text-xs">{project.category}</Badge>
                    </div>
                  </div>
                  <div className="p-5">
                    <div className="flex items-start justify-between mb-2">
                      <h3 className="font-bold text-lg group-hover:text-accent transition-colors">{project.title}</h3>
                    </div>
                    <p className="text-sm text-accent font-medium mb-1">{project.client}</p>
                    <div className="flex gap-3 text-xs text-muted-foreground mb-3">
                      <span>{project.location}</span>
                      <span>•</span>
                      <span>{project.sqft}</span>
                    </div>
                    <p className="text-sm text-muted-foreground leading-relaxed">{project.description}</p>
                    <Button variant="ghost" size="sm" className="mt-3 px-0 text-accent hover:text-hon-green-dark hover:bg-transparent" asChild>
                      <Link to={`/projects/${project.id}`}>View Case Study <ArrowRight className="h-3.5 w-3.5 ml-1" /></Link>
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* CTA */}
        <section className="py-14 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl font-bold mb-3">Start Your Project with WOODEX</h2>
            <p className="text-primary-foreground/75 mb-7 max-w-md mx-auto">
              Let our team help you create an inspiring workspace tailored to your needs and budget.
            </p>
            <Button className="bg-accent hover:bg-hon-green-dark text-accent-foreground px-8" asChild>
              <Link to="/quotation">Request a Quote</Link>
            </Button>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
};

export default Projects;
