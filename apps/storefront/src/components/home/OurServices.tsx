import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { CheckCircle } from "lucide-react";
import servicesImg from "@/assets/services-office.jpg";

const serviceList = [
  "3D Rendered Floor Plan",
  "Custom Office Furniture",
  "2D Spatial Planning",
  "Project Management",
  "Interior Designing",
  "Turnkey Solutions",
];

const OurServices = () => (
  <section className="py-16 bg-section-light border-t">
    <div className="container mx-auto px-4">
      <div className="text-center mb-10">
        <h2 className="text-3xl lg:text-4xl font-bold mb-2">Our Services</h2>
        <p className="text-accent font-semibold text-sm uppercase tracking-widest">How Can We Help?</p>
      </div>
      <div className="grid lg:grid-cols-2 gap-12 items-center">
        <div>
          <p className="text-muted-foreground leading-relaxed mb-8">
            From smart interiors to full-scale fitouts, our services cover everything you need
            to build a standout commercial space. Discover how our high-quality furniture
            can transform your space into a haven of comfort and happiness.
          </p>
          <div className="space-y-4 mb-8">
            {serviceList.map((s) => (
              <div key={s} className="flex items-center gap-3">
                <CheckCircle className="h-5 w-5 text-accent flex-shrink-0" />
                <span className="font-semibold text-sm">{s}</span>
              </div>
            ))}
          </div>
          <Button variant="outline" className="border-accent text-accent hover:bg-accent hover:text-accent-foreground" asChild>
            <Link to="/services">View All</Link>
          </Button>
        </div>
        <div className="relative">
          <img src={servicesImg} alt="Our services" className="w-full rounded-sm shadow-lg" />
          <div className="absolute -bottom-4 -left-4 bg-accent text-accent-foreground rounded-sm px-6 py-4 shadow-lg">
            <p className="text-3xl font-black">20+</p>
            <p className="text-xs font-semibold">Years of Experience</p>
          </div>
        </div>
      </div>
    </div>
  </section>
);

export default OurServices;
