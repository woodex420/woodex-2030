import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import { Award, Target, Users, Globe, ArrowRight, ChevronDown, Leaf, Shield, Heart, Sparkles } from "lucide-react";
import { Button } from "@/components/ui/button";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import factoryImg from "@/assets/about-factory.jpg";
import aboutHero from "@/assets/about-hero.jpg";
import manufacturingImg from "@/assets/about-manufacturing.jpg";

const stats = [
  { icon: Award, number: "20+", label: "Years of Craft" },
  { icon: Users, number: "500+", label: "Corporate Clients" },
  { icon: Globe, number: "50+", label: "Cities Served" },
  { icon: Target, number: "98%", label: "Client Satisfaction" },
];

const values = [
  { icon: Shield, title: "Quality First", description: "Premium materials, rigorous QC, and a 10-year warranty on flagship pieces." },
  { icon: Sparkles, title: "Design-Led", description: "In-house designers shape every product around how people actually work." },
  { icon: Heart, title: "Client Partnership", description: "Free site visits, layout planning, and after-sales support that lasts." },
  { icon: Leaf, title: "Sustainable Build", description: "FSC-certified wood, low-VOC finishes, and responsible local sourcing." },
];

const timeline = [
  { year: "2004", title: "Founded in Lahore", description: "Started as a small workshop crafting bespoke executive desks." },
  { year: "2010", title: "First Factory", description: "Opened a 20,000 sq ft facility with CNC machinery and a dedicated QC team." },
  { year: "2015", title: "Nationwide Reach", description: "Showrooms in Karachi and Islamabad, serving 30+ cities." },
  { year: "2020", title: "500-Client Milestone", description: "Trusted by banks, hospitals, universities, and government offices." },
  { year: "2024", title: "Digital Platform", description: "Launched virtual showroom, E-Quotation, and online catalog." },
];

const certifications = [
  "ISO 9001:2015",
  "ISO 14001",
  "BIFMA Certified",
  "FSC Wood Sourcing",
  "Green Guard",
  "PSQCA",
];

const faqs = [
  { q: "Where is WOODEX based?", a: "Headquartered in Lahore with showrooms in Karachi and Islamabad. Our 50,000 sq ft manufacturing facility is in Lahore's industrial zone." },
  { q: "How long has WOODEX been in business?", a: "Founded in 2004 — over 20 years of experience in office and home furniture manufacturing in Pakistan." },
  { q: "Do you offer custom manufacturing?", a: "Yes. Custom builds to your exact dimensions, materials, colors, and finishes are a core part of what we do." },
  { q: "What warranty do you offer?", a: "3–10 year warranties depending on the series. Our flagship Woodex line ships with a 10-year comprehensive warranty." },
  { q: "Can you handle large corporate orders?", a: "Absolutely. We regularly furnish full office buildings, banks, and institutions — capacity of 5,000+ units per month." },
];

const About = () => {
  const [openFaq, setOpenFaq] = useState<number | null>(0);

  useEffect(() => {
    document.title = "About WOODEX — Pakistan's Leading Office Furniture Manufacturer";
  }, []);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* === HERO === */}
        <section className="relative h-[420px] lg:h-[520px] overflow-hidden bg-primary">
          <img src={aboutHero} alt="WOODEX modern office interior" className="absolute inset-0 w-full h-full object-cover opacity-45" />
          <div className="absolute inset-0 bg-gradient-to-r from-primary via-primary/80 to-primary/30" />
          <div className="absolute inset-0 flex items-center">
            <div className="container mx-auto px-4">
              <nav className="text-xs text-primary-foreground/60 mb-4 flex items-center gap-2">
                <Link to="/" className="hover:text-accent transition-colors">Home</Link>
                <span>/</span>
                <span className="text-accent">About</span>
              </nav>
              <p className="text-accent text-xs font-bold uppercase tracking-[0.25em] mb-3">Since 2004 · Lahore, Pakistan</p>
              <h1 className="text-4xl lg:text-6xl font-black text-primary-foreground mb-4 leading-[1.05] max-w-3xl">
                Furniture built for the way Pakistan works.
              </h1>
              <p className="text-primary-foreground/80 max-w-2xl text-base lg:text-lg leading-relaxed">
                Two decades of craftsmanship, in-house design, and end-to-end project delivery —
                from single executive desks to 500-seat corporate floors.
              </p>
            </div>
          </div>
        </section>

        {/* === STATS BAR === */}
        <section className="bg-accent py-8">
          <div className="container mx-auto px-4">
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-6 text-center text-accent-foreground">
              {stats.map((stat) => (
                <div key={stat.label}>
                  <p className="text-4xl lg:text-5xl font-black mb-1">{stat.number}</p>
                  <p className="text-sm font-medium opacity-85 uppercase tracking-wider">{stat.label}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* === STORY === */}
        <section className="py-20 bg-background">
          <div className="container mx-auto px-4">
            <div className="grid lg:grid-cols-2 gap-14 items-center">
              <div>
                <div className="w-12 h-1 bg-accent mb-6" />
                <p className="text-accent text-xs font-bold uppercase tracking-widest mb-3">Our Story</p>
                <h2 className="text-3xl lg:text-4xl font-bold mb-6 leading-tight">
                  From a small Lahore workshop to Pakistan's trusted furniture partner.
                </h2>
                <p className="text-muted-foreground leading-relaxed mb-4">
                  WOODEX began in 2004 with a simple belief — that thoughtfully designed workspaces
                  drive better work. That belief still guides every desk we build, every showroom we open,
                  and every client we serve.
                </p>
                <p className="text-muted-foreground leading-relaxed mb-8">
                  Today we manufacture office and home furniture in a 50,000 sq ft Lahore facility,
                  delivering turnkey workspace solutions to corporations, government bodies, hospitals,
                  and universities across the country.
                </p>
                <div className="flex flex-wrap gap-3">
                  <Button className="bg-accent hover:bg-hon-green-dark text-accent-foreground" asChild>
                    <Link to="/projects">View Our Projects <ArrowRight className="h-4 w-4 ml-2" /></Link>
                  </Button>
                  <Button variant="outline" className="border-accent text-accent hover:bg-accent hover:text-accent-foreground" asChild>
                    <Link to="/contact">Get in Touch</Link>
                  </Button>
                </div>
              </div>
              <div className="relative">
                <div className="rounded-sm overflow-hidden shadow-xl">
                  <img src={factoryImg} alt="WOODEX craftsmanship — hand finishing a walnut desk" className="w-full h-[460px] object-cover" loading="lazy" />
                </div>
                <div className="hidden lg:block absolute -bottom-6 -left-6 bg-accent text-accent-foreground px-6 py-4 rounded-sm shadow-lg">
                  <p className="text-3xl font-black leading-none">20+</p>
                  <p className="text-xs uppercase tracking-widest mt-1">Years of craft</p>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* === VALUES === */}
        <section className="py-20 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-12 max-w-2xl mx-auto">
              <div className="w-12 h-1 bg-accent mx-auto mb-6" />
              <p className="text-accent text-xs font-bold uppercase tracking-widest mb-3">What We Stand For</p>
              <h2 className="text-3xl lg:text-4xl font-bold mb-4">Four principles, every project.</h2>
              <p className="text-muted-foreground">The non-negotiables behind every piece of furniture that leaves our floor.</p>
            </div>
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
              {values.map((v) => (
                <div key={v.title} className="bg-background border border-border rounded-sm p-7 hover:border-accent hover:shadow-md transition-all">
                  <div className="w-12 h-12 rounded-sm bg-accent/10 flex items-center justify-center mb-5">
                    <v.icon className="h-6 w-6 text-accent" />
                  </div>
                  <h3 className="font-bold text-lg mb-2">{v.title}</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">{v.description}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* === MANUFACTURING === */}
        <section className="py-20 bg-background">
          <div className="container mx-auto px-4">
            <div className="grid lg:grid-cols-2 gap-14 items-center">
              <div className="rounded-sm overflow-hidden shadow-xl order-2 lg:order-1">
                <img src={manufacturingImg} alt="WOODEX manufacturing floor in Lahore" className="w-full h-[460px] object-cover" loading="lazy" />
              </div>
              <div className="order-1 lg:order-2">
                <div className="w-12 h-1 bg-accent mb-6" />
                <p className="text-accent text-xs font-bold uppercase tracking-widest mb-3">Manufacturing</p>
                <h2 className="text-3xl lg:text-4xl font-bold mb-6 leading-tight">
                  A 50,000 sq ft factory built for precision.
                </h2>
                <p className="text-muted-foreground leading-relaxed mb-6">
                  CNC routers, edge-banding lines, in-house upholstery, and a finishing booth that
                  delivers showroom-grade surfaces every time. Every piece is inspected before it ships.
                </p>
                <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                  {[
                    "ISO 9001:2015 certified",
                    "CNC & robotic lines",
                    "In-house upholstery",
                    "5,000+ units / month",
                    "Low-VOC finishes",
                    "Dedicated QC team",
                  ].map((item) => (
                    <div key={item} className="flex items-center gap-2 text-sm">
                      <div className="w-1.5 h-1.5 rounded-full bg-accent flex-shrink-0" />
                      <span className="text-foreground">{item}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* === TIMELINE === */}
        <section className="py-20 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-14">
              <div className="w-12 h-1 bg-accent mx-auto mb-6" />
              <p className="text-accent text-xs font-bold uppercase tracking-widest mb-3">Our Journey</p>
              <h2 className="text-3xl lg:text-4xl font-bold">Two decades. Five milestones.</h2>
            </div>
            <div className="max-w-3xl mx-auto relative">
              <div className="absolute left-6 top-0 bottom-0 w-0.5 bg-border" />
              <div className="space-y-10">
                {timeline.map((item) => (
                  <div key={item.year} className="relative flex gap-6 items-start">
                    <div className="relative z-10 w-12 h-12 rounded-full bg-accent text-accent-foreground flex items-center justify-center text-sm font-black flex-shrink-0">
                      {item.year.slice(2)}
                    </div>
                    <div className="pt-1.5">
                      <span className="text-xs font-bold text-accent uppercase tracking-widest">{item.year}</span>
                      <h3 className="font-bold text-lg mb-1">{item.title}</h3>
                      <p className="text-sm text-muted-foreground leading-relaxed">{item.description}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </section>

        {/* === CERTIFICATIONS === */}
        <section className="py-16 bg-background border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-10">
              <p className="text-accent text-xs font-bold uppercase tracking-widest mb-3">Certifications</p>
              <h2 className="text-2xl lg:text-3xl font-bold">Quality you can verify.</h2>
            </div>
            <div className="grid grid-cols-3 lg:grid-cols-6 gap-3 max-w-5xl mx-auto">
              {certifications.map((cert) => (
                <div key={cert} className="flex flex-col items-center text-center p-5 border border-border rounded-sm hover:border-accent hover:bg-section-light transition-all">
                  <Award className="h-7 w-7 text-accent mb-3" />
                  <p className="text-xs font-semibold leading-tight">{cert}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* === FAQ === */}
        <section className="py-20 bg-section-light border-t">
          <div className="container mx-auto px-4 max-w-3xl">
            <div className="text-center mb-10">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl font-bold">Frequently Asked Questions</h2>
            </div>
            <div className="space-y-3">
              {faqs.map((faq, i) => (
                <div key={i} className="border border-border rounded-sm overflow-hidden bg-background">
                  <button
                    onClick={() => setOpenFaq(openFaq === i ? null : i)}
                    className="w-full flex items-center justify-between px-6 py-5 text-left hover:bg-section-light transition-colors"
                  >
                    <span className="font-semibold text-sm pr-4">{faq.q}</span>
                    <ChevronDown className={`h-4 w-4 text-muted-foreground transition-transform flex-shrink-0 ${openFaq === i ? "rotate-180" : ""}`} />
                  </button>
                  {openFaq === i && (
                    <div className="px-6 pb-5 text-sm text-muted-foreground leading-relaxed">
                      {faq.a}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* === CTA === */}
        <section className="py-16 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4 text-center max-w-2xl">
            <h2 className="text-3xl lg:text-4xl font-bold mb-4">Partner with WOODEX</h2>
            <p className="text-primary-foreground/75 mb-8 leading-relaxed">
              Tell us about your space — we'll handle layout, manufacturing, and installation, end to end.
            </p>
            <div className="flex flex-wrap gap-4 justify-center">
              <Button size="lg" className="bg-accent hover:bg-hon-green-dark text-accent-foreground px-8" asChild>
                <Link to="/quotation">Request E-Quote</Link>
              </Button>
              <Button size="lg" variant="outline" className="border-primary-foreground/30 text-primary-foreground hover:bg-primary-foreground/10 px-8" asChild>
                <Link to="/contact">Contact Us</Link>
              </Button>
            </div>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
};

export default About;
