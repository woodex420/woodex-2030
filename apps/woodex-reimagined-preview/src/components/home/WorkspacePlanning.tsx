import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { CheckSquare, Sun, GitFork, Maximize2, Monitor, ArrowRight, ChevronLeft, ChevronRight } from "lucide-react";
import sketchImg from "@/assets/workspace-planning-sketch.jpg";
import proj1 from "@/assets/project-1.jpg";
import proj2 from "@/assets/project-2.jpg";

const slides = [
  {
    image: sketchImg,
    title: "We Help You Plan your workspace.",
    description: "Workspace Design can be a challenge for brands to represent their identity through their office environment. Modern trendy office designs use unique ways to represent their brand identity, fulfilling their brand's goals through Workspace layouts and styles.",
    features: [
      { icon: CheckSquare, label: "Floor Planning" },
      { icon: Sun, label: "3D Rendered Floor Plan" },
      { icon: GitFork, label: "Furniture Planning" },
      { icon: Maximize2, label: "Project Management" },
      { icon: Monitor, label: "Customize Furniture" },
      { icon: Monitor, label: "Turnkey Solutions" },
    ],
  },
  {
    image: proj1,
    title: "Transform Your Office Space",
    description: "From concept to completion, our design team works closely with you to create workspaces that inspire productivity and reflect your company culture.",
    features: [
      { icon: CheckSquare, label: "Space Optimization" },
      { icon: Sun, label: "Lighting Design" },
      { icon: GitFork, label: "Ergonomic Layout" },
      { icon: Maximize2, label: "Brand Integration" },
      { icon: Monitor, label: "Modular Systems" },
      { icon: Monitor, label: "Acoustic Planning" },
    ],
  },
  {
    image: proj2,
    title: "End-to-End Project Delivery",
    description: "We handle every aspect — from initial consultation and 3D rendering to manufacturing, delivery, and professional installation.",
    features: [
      { icon: CheckSquare, label: "Site Assessment" },
      { icon: Sun, label: "3D Visualization" },
      { icon: GitFork, label: "Custom Manufacturing" },
      { icon: Maximize2, label: "Quality Assurance" },
      { icon: Monitor, label: "On-Site Install" },
      { icon: Monitor, label: "After-Sales Support" },
    ],
  },
];

const WorkspacePlanning = () => {
  const [current, setCurrent] = useState(0);

  useEffect(() => {
    const timer = setInterval(() => setCurrent((c) => (c + 1) % slides.length), 6000);
    return () => clearInterval(timer);
  }, []);

  const slide = slides[current];

  return (
    <section className="py-16 bg-background border-t">
      <div className="container mx-auto px-4">
        <div className="grid lg:grid-cols-2 gap-12 items-center">
          <div>
            <h2 className="text-3xl lg:text-4xl font-black mb-4 leading-tight">{slide.title}</h2>
            <p className="text-muted-foreground leading-relaxed mb-8">{slide.description}</p>
            <div className="grid grid-cols-2 gap-4 mb-8">
              {slide.features.map((f) => (
                <div key={f.label} className="flex items-center gap-3">
                  <f.icon className="h-5 w-5 text-accent flex-shrink-0" />
                  <span className="font-semibold text-sm">{f.label}</span>
                </div>
              ))}
            </div>
            <div className="flex items-center gap-4">
              <Button className="bg-accent hover:bg-hon-green-dark text-accent-foreground" asChild>
                <Link to="/services">Explore Services <ArrowRight className="h-4 w-4 ml-2" /></Link>
              </Button>
              <div className="flex gap-2">
                <button onClick={() => setCurrent((c) => (c - 1 + slides.length) % slides.length)} className="w-8 h-8 rounded-full border border-border hover:border-accent flex items-center justify-center transition-colors">
                  <ChevronLeft className="h-4 w-4" />
                </button>
                <button onClick={() => setCurrent((c) => (c + 1) % slides.length)} className="w-8 h-8 rounded-full border border-border hover:border-accent flex items-center justify-center transition-colors">
                  <ChevronRight className="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>
          <div className="relative">
            <img src={slide.image} alt={slide.title} className="w-full rounded-sm shadow-lg transition-opacity duration-500" />
            <div className="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-1.5">
              {slides.map((_, i) => (
                <button key={i} onClick={() => setCurrent(i)} className={`h-1.5 rounded-full transition-all ${i === current ? "w-6 bg-accent" : "w-1.5 bg-foreground/30"}`} />
              ))}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};

export default WorkspacePlanning;
