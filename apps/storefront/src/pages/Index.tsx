import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { ChevronLeft, ChevronRight, Pause, Play, ArrowRight } from "lucide-react";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import ServicesStrip from "@/components/home/ServicesStrip";
import ProductCategoriesRow from "@/components/home/ProductCategoriesRow";
import LatestCollection from "@/components/home/LatestCollection";
import WorkspacePlanning from "@/components/home/WorkspacePlanning";
import OurServices from "@/components/home/OurServices";
import GoogleReviews from "@/components/home/GoogleReviews";
import ClientLogos from "@/components/home/ClientLogos";
import FAQSection from "@/components/home/FAQSection";

import slide1 from "@/assets/hero-slide-1.jpg";
import slide2 from "@/assets/hero-slide-2.jpg";
import slide3 from "@/assets/hero-slide-3.jpg";
import slide4 from "@/assets/hero-slide-4.jpg";
import slide5 from "@/assets/hero-slide-5.jpg";
import chairsImg from "@/assets/category-chairs.jpg";
import desksImg from "@/assets/category-executive.jpg";
import workstationsImg from "@/assets/category-workstations.jpg";
import tablesImg from "@/assets/category-tables.jpg";
import storageImg from "@/assets/category-storage.jpg";
import bedroomCatImg from "@/assets/category-bedroom.jpg";
import livingCatImg from "@/assets/category-living.jpg";
import diningCatImg from "@/assets/category-dining.jpg";
import proj1 from "@/assets/project-1.jpg";
import proj2 from "@/assets/project-2.jpg";
import proj3 from "@/assets/project-3.jpg";

const slides = [
  { image: slide1, title: "Design For Your Workspace", subtitle: "From concept to installation — design, manufacture, and deliver", cta: "Shop Now", ctaHref: "/shop?category=workstations" },
  { image: slide2, title: "Meeting Rooms That Inspire", subtitle: "Conference furniture engineered for collaboration and focus", cta: "Shop Now", ctaHref: "/shop?category=meeting-tables" },
  { image: slide3, title: "Executive Offices, Crafted", subtitle: "Premium executive desks and seating with timeless presence", cta: "Shop Now", ctaHref: "/shop?category=executive-tables" },
  { image: slide4, title: "Reception That Welcomes", subtitle: "First impressions built with bespoke reception solutions", cta: "Shop Now", ctaHref: "/shop?category=reception" },
  { image: slide5, title: "Manager Cabins, Refined", subtitle: "Functional, elegant private offices for leadership teams", cta: "Shop Now", ctaHref: "/shop?category=executive-tables" },
];

const officeCategories = [
  { label: "Ergonomic Chairs", image: chairsImg, href: "/shop?category=chairs" },
  { label: "Executive Desks", image: desksImg, href: "/shop?category=executive-tables" },
  { label: "Workstations", image: workstationsImg, href: "/shop?category=workstations" },
  { label: "Meeting Tables", image: tablesImg, href: "/shop?category=meeting-tables" },
  { label: "Office Storage", image: storageImg, href: "/shop?category=storage" },
];

const homeCategories = [
  { label: "Bedroom", href: "/shop?category=bedroom", description: "Beds, dressing tables, mirrors & more", image: bedroomCatImg },
  { label: "Living Room", href: "/shop?category=living", description: "Sofas, coffee tables, TV units", image: livingCatImg },
  { label: "Dining", href: "/shop?category=dining", description: "Complete dining sets & chairs", image: diningCatImg },
];

const Index = () => {
  const [current, setCurrent] = useState(0);
  const [playing, setPlaying] = useState(true);

  useEffect(() => {
    document.title = "WOODEX — Pakistan's Premium Office & Home Furniture Manufacturer";
  }, []);

  useEffect(() => {
    if (!playing) return;
    const timer = setInterval(() => setCurrent((c) => (c + 1) % slides.length), 5000);
    return () => clearInterval(timer);
  }, [playing]);

  const prev = () => setCurrent((c) => (c - 1 + slides.length) % slides.length);
  const next = () => setCurrent((c) => (c + 1) % slides.length);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      <main className="flex-1">
        {/* === HERO SLIDER === */}
        <section className="relative h-[70vh] min-h-[480px] overflow-hidden bg-primary">
          {slides.map((slide, i) => (
            <div key={i} className={`absolute inset-0 transition-opacity duration-1000 ${i === current ? "opacity-100" : "opacity-0"}`}>
              <img src={slide.image} alt={slide.title} className="w-full h-full object-cover" />
              <div className="absolute inset-0 bg-gradient-to-r from-primary/75 via-primary/40 to-transparent" />
            </div>
          ))}
          <div className="absolute inset-0 flex items-end pb-20">
            <div className="container mx-auto px-4">
              <div className="max-w-xl text-primary-foreground">
                <h1 className="text-4xl lg:text-5xl xl:text-6xl font-black mb-3 leading-tight">{slides[current].title}</h1>
                <p className="text-base lg:text-lg text-primary-foreground/85 mb-6 leading-relaxed">{slides[current].subtitle}</p>
                <Button size="lg" className="bg-accent hover:bg-hon-green-dark text-accent-foreground font-semibold px-8" asChild>
                  <Link to={slides[current].ctaHref}>{slides[current].cta}</Link>
                </Button>
              </div>
            </div>
          </div>
          <div className="absolute bottom-6 left-1/2 -translate-x-1/2 flex items-center gap-3">
            <button onClick={prev} className="w-8 h-8 rounded-full bg-primary-foreground/20 hover:bg-primary-foreground/40 flex items-center justify-center text-primary-foreground transition-colors"><ChevronLeft className="h-4 w-4" /></button>
            <button onClick={() => setPlaying(!playing)} className="w-8 h-8 rounded-full bg-primary-foreground/20 hover:bg-primary-foreground/40 flex items-center justify-center text-primary-foreground transition-colors">{playing ? <Pause className="h-3 w-3" /> : <Play className="h-3 w-3" />}</button>
            <button onClick={next} className="w-8 h-8 rounded-full bg-primary-foreground/20 hover:bg-primary-foreground/40 flex items-center justify-center text-primary-foreground transition-colors"><ChevronRight className="h-4 w-4" /></button>
            <div className="flex gap-1.5 ml-2">
              {slides.map((_, i) => (
                <button key={i} onClick={() => setCurrent(i)} className={`h-1.5 rounded-full transition-all ${i === current ? "w-6 bg-accent" : "w-1.5 bg-primary-foreground/40"}`} />
              ))}
            </div>
          </div>
        </section>

        {/* === TAGLINE === */}
        <section className="py-10 bg-background border-b">
          <div className="container mx-auto px-4 text-center">
            <p className="text-xl lg:text-2xl font-bold text-accent">Unmatched Capabilities. Fresh Products that Matter. Trusted Partnership.</p>
          </div>
        </section>

        {/* === SERVICES STRIP (replaces Markets) === */}
        <ServicesStrip />

        {/* === PRODUCT CATEGORIES ROW === */}
        <ProductCategoriesRow />

        {/* === OFFICE CATEGORIES === */}
        <section className="py-16 bg-background">
          <div className="container mx-auto px-4">
            <div className="flex items-end justify-between mb-10">
              <div>
                <h2 className="text-3xl lg:text-4xl font-bold mb-2">Office Furniture</h2>
                <p className="text-muted-foreground max-w-xl">Explore our comprehensive 2025 WOODEX Collection — furniture solutions that optimize your space.</p>
              </div>
              <Button variant="outline" className="hidden md:flex border-accent text-accent hover:bg-accent hover:text-accent-foreground" asChild>
                <Link to="/shop">Browse All</Link>
              </Button>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
              {officeCategories.map((cat) => (
                <Link key={cat.label} to={cat.href} className="group block">
                  <div className="aspect-square overflow-hidden rounded-sm bg-muted mb-3">
                    <img src={cat.image} alt={cat.label} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />
                  </div>
                  <h3 className="font-semibold text-sm group-hover:text-accent transition-colors flex items-center gap-1">
                    {cat.label} <ArrowRight className="h-3 w-3 opacity-0 group-hover:opacity-100 transition-opacity" />
                  </h3>
                </Link>
              ))}
            </div>
          </div>
        </section>

        {/* === HOME FURNITURE === */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-10">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl lg:text-4xl font-bold mb-3">Home Furniture</h2>
              <p className="text-muted-foreground max-w-xl mx-auto">Extend the WOODEX quality beyond the office — premium furniture for every room in your home.</p>
            </div>
            <div className="grid md:grid-cols-3 gap-6">
              {homeCategories.map((cat) => (
                <Link key={cat.label} to={cat.href} className="group border border-border bg-background rounded-sm hover:border-accent hover:shadow-lg transition-all overflow-hidden">
                  <div className="aspect-video overflow-hidden">
                    <img src={cat.image} alt={cat.label} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />
                  </div>
                  <div className="p-6 text-center">
                    <h3 className="font-bold text-2xl mb-2 group-hover:text-accent transition-colors">{cat.label}</h3>
                    <p className="text-muted-foreground text-sm mb-3">{cat.description}</p>
                    <span className="text-accent text-sm font-semibold flex items-center justify-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">Shop Now <ArrowRight className="h-3.5 w-3.5" /></span>
                  </div>
                </Link>
              ))}
            </div>
          </div>
        </section>

        {/* === LATEST COLLECTION === */}
        <LatestCollection />

        {/* === WORKSPACE PLANNING (slider) === */}
        <WorkspacePlanning />

        {/* === OUR SERVICES === */}
        <OurServices />

        {/* === MAKE YOUR SPACE WORK === */}
        <section className="py-0 bg-section-mid">
          <div className="grid lg:grid-cols-2 min-h-[400px]">
            <div className="relative overflow-hidden">
              <img src={proj1} alt="Office project" className="w-full h-full object-cover min-h-[300px]" />
            </div>
            <div className="flex items-center p-10 lg:p-16">
              <div>
                <div className="w-12 h-1 bg-accent mb-6" />
                <h2 className="text-3xl lg:text-4xl font-bold mb-4 leading-tight">Make Your Space Work</h2>
                <p className="text-muted-foreground leading-relaxed mb-6">It's more than just an attitude. It's a commitment to our customers. At WOODEX, we know a thoughtfully designed workspace sets the stage for better work.</p>
                <Button className="bg-accent hover:bg-hon-green-dark text-accent-foreground" asChild>
                  <Link to="/about">Learn More</Link>
                </Button>
              </div>
            </div>
          </div>
        </section>

        {/* === LATEST PROJECTS === */}
        <section className="py-16 bg-background border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-10">
              <h2 className="text-3xl font-bold mb-2 uppercase tracking-wide">Latest Projects</h2>
              <p className="text-accent text-sm uppercase tracking-widest">Transforming Spaces with Design that Blends Creativity, Comfort, and Function</p>
            </div>
            <div className="grid md:grid-cols-3 gap-6">
              {[proj1, proj2, proj3].map((img, i) => (
                <Link key={i} to="/projects" className="group overflow-hidden rounded-sm">
                  <div className="aspect-[4/3] overflow-hidden">
                    <img src={img} alt={`Project ${i + 1}`} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />
                  </div>
                </Link>
              ))}
            </div>
            <div className="text-center mt-8">
              <Button variant="outline" className="border-accent text-accent hover:bg-accent hover:text-accent-foreground" asChild>
                <Link to="/projects">View All Projects <ArrowRight className="h-4 w-4 ml-2" /></Link>
              </Button>
            </div>
          </div>
        </section>

        {/* === GOOGLE REVIEWS (slider) === */}
        <GoogleReviews />

        {/* === CLIENT LOGOS (slider) === */}
        <ClientLogos />

        {/* === STATS === */}
        <section className="py-16 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4">
            <div className="text-center mb-10">
              <p className="text-accent font-semibold text-sm uppercase tracking-widest mb-2">By the Numbers</p>
              <h2 className="text-3xl font-bold">Pakistan's Trusted Furniture Partner</h2>
            </div>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-8 text-center">
              {[
                { number: "20+", label: "Years Experience" },
                { number: "500+", label: "Happy Clients" },
                { number: "50+", label: "Cities Served" },
                { number: "98%", label: "Satisfaction Rate" },
              ].map((stat) => (
                <div key={stat.label}>
                  <p className="text-4xl lg:text-5xl font-black text-accent mb-2">{stat.number}</p>
                  <p className="text-primary-foreground/70 text-sm font-medium">{stat.label}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* === FAQs === */}
        <FAQSection />

        {/* === CTA === */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl lg:text-4xl font-bold mb-4">Ready to Transform Your Workspace?</h2>
            <p className="text-muted-foreground mb-8 max-w-xl mx-auto">Get in touch with our experts for a free consultation and discover how WOODEX can elevate your office environment.</p>
            <div className="flex flex-wrap gap-4 justify-center">
              <Button size="lg" className="bg-accent hover:bg-hon-green-dark text-accent-foreground px-8" asChild>
                <Link to="/quotation">Request E-Quote</Link>
              </Button>
              <Button size="lg" variant="outline" className="border-accent text-accent hover:bg-accent hover:text-accent-foreground px-8" asChild>
                <Link to="/virtual-showroom">Virtual Showroom</Link>
              </Button>
            </div>
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default Index;
