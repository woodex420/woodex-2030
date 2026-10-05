import { useEffect } from "react";
import { Link } from "react-router-dom";
import { ArrowRight, Star, MessageCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { services } from "@/data/services";
import heroImg from "@/assets/services-main-hero.jpg";

const WA_PHONE = "923001234567";

// "Make Your Space Work" — curated 6-item showcase mapped to underlying service pages
const spaceWorkItems = [
  { num: "01", label: "3D Rendered Floor Plan", slug: "space-planning-design" },
  { num: "02", label: "Custom Office Furniture", slug: "custom-design" },
  { num: "03", label: "2D Spatial Planning", slug: "space-planning-design" },
  { num: "04", label: "Project Management", slug: "project-management" },
  { num: "05", label: "Interior Designing", slug: "custom-design" },
  { num: "06", label: "Turnkey Solutions", slug: "b2b-office-solutions" },
];

const testimonials = [
  { name: "Bilal Ahmed", company: "Allied Bank Limited", text: "WOODEX furnished our 8 new branches across Punjab. Their project management was exceptional — on time, on budget.", rating: 5 },
  { name: "Dr. Ayesha Siddiqui", company: "Shifa International Hospital", text: "From patient waiting areas to executive offices, WOODEX delivered quality furniture that meets healthcare standards.", rating: 5 },
  { name: "Hassan Raza", company: "TechVentures Islamabad", text: "Our 200-seat tech office was designed and furnished in just 6 weeks. The ergonomic chairs are a game-changer.", rating: 5 },
];

const Services = () => {
  useEffect(() => {
    document.title = "Office Furniture Services Pakistan — Design, Manufacture, Install | WOODEX";
    const meta = document.querySelector('meta[name="description"]') || (() => {
      const m = document.createElement("meta");
      m.setAttribute("name", "description");
      document.head.appendChild(m);
      return m;
    })();
    meta.setAttribute("content", "End-to-end office furniture services in Pakistan: custom design, B2B supply, manufacturing, delivery, space planning, after-sales support & project management.");
  }, []);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* Hero */}
        <section className="relative h-72 overflow-hidden bg-primary">
          <img src={heroImg} alt="WOODEX office furniture services" className="w-full h-full object-cover opacity-30" width={1536} height={1024} />
          <div className="absolute inset-0 flex items-center">
            <div className="container mx-auto px-4">
              <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">What We Offer</p>
              <h1 className="text-4xl lg:text-6xl font-black text-primary-foreground mb-3">Our Services</h1>
              <p className="text-primary-foreground/75 max-w-lg">
                Comprehensive office furniture solutions from design to delivery — end-to-end services
                ensuring your workspace meets your exact needs.
              </p>
            </div>
          </div>
        </section>

        {/* Make Your Space Work — numbered clickable list + large image */}
        <section className="py-20">
          <div className="container mx-auto px-4">
            <div className="text-center mb-14">
              <h2 className="text-3xl lg:text-5xl font-black mb-3">Make Your Space Work</h2>
              <div className="w-16 h-1 bg-accent mx-auto" />
            </div>

            <div className="grid lg:grid-cols-2 gap-12 lg:gap-20 items-center max-w-6xl mx-auto">
              {/* Left: numbered list */}
              <div>
                <p className="text-muted-foreground leading-relaxed mb-8">
                  From smart interiors to full-scale fitouts, our services cover everything you
                  need to build a standout commercial space. Discover how our high-quality
                  furniture can transform your space into a haven of comfort and productivity.
                </p>

                <ul className="divide-y border-y">
                  {spaceWorkItems.map((s) => (
                    <li key={s.num}>
                      <Link
                        to={`/services/${s.slug}`}
                        className="group flex items-center gap-4 py-4 hover:pl-2 transition-all"
                      >
                        <span className="w-8 h-8 rounded-full bg-accent text-accent-foreground text-[11px] font-black flex items-center justify-center flex-shrink-0">
                          {s.num}
                        </span>
                        <span className="font-bold text-base flex-1 group-hover:text-accent transition-colors">
                          {s.label}
                        </span>
                        <ArrowRight className="h-4 w-4 text-muted-foreground group-hover:text-accent group-hover:translate-x-1 transition-all" />
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>

              {/* Right: large image */}
              <div className="relative">
                <img
                  src={heroImg}
                  alt="WOODEX corporate office building Pakistan"
                  className="w-full h-auto rounded-sm shadow-xl"
                  width={1536}
                  height={1024}
                  loading="lazy"
                />
              </div>
            </div>
          </div>
        </section>

        {/* Service Cards Grid */}
        <section className="py-20 bg-section-light border-t border-b">
          <div className="container mx-auto px-4">
            <div className="max-w-2xl mx-auto text-center mb-12">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl lg:text-4xl font-bold mb-3">Explore Every Service</h2>
              <p className="text-muted-foreground">Click any service to see process, pricing approach and recent case studies.</p>
            </div>
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
              {services.map((s) => (
                <Link
                  key={s.slug}
                  to={`/services/${s.slug}`}
                  className="group border border-border rounded-sm overflow-hidden bg-background hover:border-accent hover:shadow-lg transition-all"
                >
                  <div className="aspect-video overflow-hidden">
                    <img
                      src={s.hero}
                      alt={s.title}
                      loading="lazy"
                      width={1536}
                      height={1024}
                      className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                    />
                  </div>
                  <div className="p-7">
                    <span className="text-xs font-black text-accent">{s.number}</span>
                    <h3 className="font-bold text-xl mt-2 mb-3 group-hover:text-accent transition-colors">{s.shortTitle}</h3>
                    <p className="text-muted-foreground text-sm leading-relaxed mb-5">{s.tagline}</p>
                    <span className="inline-flex items-center gap-2 text-sm font-bold text-accent">
                      Learn more <ArrowRight className="h-3.5 w-3.5 group-hover:translate-x-1 transition-transform" />
                    </span>
                  </div>
                </Link>
              ))}
            </div>
          </div>
        </section>

        {/* Testimonials */}
        <section className="py-20 bg-background">
          <div className="container mx-auto px-4">
            <div className="text-center mb-12">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl font-bold mb-3">What Our Clients Say</h2>
              <p className="text-muted-foreground">Trusted by leading organisations across Pakistan</p>
            </div>
            <div className="grid md:grid-cols-3 gap-6 max-w-5xl mx-auto">
              {testimonials.map((t) => (
                <div key={t.name} className="p-6 border rounded-sm hover:border-accent transition-colors">
                  <div className="flex gap-0.5 mb-4">
                    {Array(t.rating).fill(0).map((_, i) => (
                      <Star key={i} className="h-4 w-4 fill-amber-400 text-amber-400" />
                    ))}
                  </div>
                  <p className="text-sm text-muted-foreground italic mb-4 leading-relaxed">"{t.text}"</p>
                  <div>
                    <p className="font-bold text-sm">{t.name}</p>
                    <p className="text-xs text-muted-foreground">{t.company}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* CTA */}
        <section className="py-16 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl font-bold mb-3">Ready to Get Started?</h2>
            <p className="text-primary-foreground/75 mb-7 max-w-md mx-auto">
              Contact our team today for a free consultation and discover how WOODEX can transform your workspace.
            </p>
            <div className="flex flex-wrap gap-4 justify-center">
              <Button className="bg-accent hover:bg-accent/90 text-accent-foreground px-8" asChild>
                <Link to="/quotation">Request a Quote</Link>
              </Button>
              <Button variant="outline" className="border-primary-foreground/30 text-primary-foreground hover:bg-primary-foreground/10" asChild>
                <a href={`https://wa.me/${WA_PHONE}?text=Hi%20WOODEX%2C%20I%27m%20interested%20in%20your%20services`} target="_blank" rel="noopener noreferrer">
                  <MessageCircle className="mr-2 h-4 w-4" /> WhatsApp Us
                </a>
              </Button>
            </div>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
};

export default Services;
