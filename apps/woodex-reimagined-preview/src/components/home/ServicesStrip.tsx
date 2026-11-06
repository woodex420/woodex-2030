import { Link } from "react-router-dom";
import { Compass, PenTool, FileText, Factory, Truck } from "lucide-react";

const services = [
  { icon: Compass, label: "Space Planning", description: "Consultation for space management, project management & personalized planning.", href: "/services" },
  { icon: PenTool, label: "Custom Design", description: "Furniture tailored to your exact specifications and workflow needs.", href: "/services" },
  { icon: FileText, label: "E-Quoting", description: "Transparent pricing with detailed electronic quotations for every project.", href: "/quotation" },
  { icon: Factory, label: "Factory Direct", description: "Premium quality furniture at manufacturer prices — no middleman.", href: "/about" },
  { icon: Truck, label: "Delivery & Installation", description: "Professional setup ensuring your furniture is ready to use.", href: "/services" },
];

const ServicesStrip = () => (
  <section className="py-14 bg-background border-b">
    <div className="container mx-auto px-4">
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6">
        {services.map((s) => (
          <Link key={s.label} to={s.href} className="group text-center">
            <div className="w-14 h-14 mx-auto mb-4 rounded-full bg-accent/10 flex items-center justify-center group-hover:bg-accent transition-colors">
              <s.icon className="h-6 w-6 text-accent group-hover:text-accent-foreground transition-colors" />
            </div>
            <h3 className="font-bold text-sm mb-1 group-hover:text-accent transition-colors">{s.label}</h3>
            <p className="text-xs text-muted-foreground leading-relaxed">{s.description}</p>
          </Link>
        ))}
      </div>
    </div>
  </section>
);

export default ServicesStrip;
