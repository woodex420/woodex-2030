import { useEffect } from "react";
import warrantyHero from "@/assets/warranty-hero.jpg";
import { Link } from "react-router-dom";
import { Shield, CheckCircle2, Phone, Mail, Clock } from "lucide-react";
import { Button } from "@/components/ui/button";
import Header from "@/components/Header";
import Footer from "@/components/Footer";

const warrantyTiers = [
  {
    name: "Standard Warranty",
    duration: "3 Years",
    applies: "Ek Series — Entry level products",
    coverage: [
      "Manufacturing defects in materials",
      "Structural frame integrity",
      "Drawer mechanism failures",
      "Surface delamination",
    ],
    excludes: ["Normal wear and tear", "Accidental damage", "Unauthorized modifications"],
  },
  {
    name: "Enhanced Warranty",
    duration: "5 Years",
    applies: "Infinity Series — Mid-range products",
    popular: true,
    coverage: [
      "All Standard Warranty coverage",
      "Upholstery defects (first 2 years)",
      "Pneumatic mechanism failures (chairs)",
      "Veneer defects & bubbling",
      "Hardware including hinges, rails",
    ],
    excludes: ["Normal wear and tear", "Sun fade on upholstery", "Water damage"],
  },
  {
    name: "Premium Warranty",
    duration: "10 Years",
    applies: "Woodex Series — Premium products",
    coverage: [
      "All Enhanced Warranty coverage",
      "Complete structural warranty",
      "Free annual maintenance check",
      "Priority repair service (48hr response)",
      "Free part replacement for life",
    ],
    excludes: ["Accidental damage", "Improper use damage"],
  },
];

const claimSteps = [
  { step: "01", title: "Contact Us", description: "Call +92 300 1234567 or email warranty@woodex.pk with your order number and issue description." },
  { step: "02", title: "Assessment", description: "Our team will assess your claim within 24 hours and may request photos or a site visit." },
  { step: "03", title: "Resolution", description: "Approved claims are resolved within 7 business days — repair, replacement, or part dispatch." },
  { step: "04", title: "Follow Up", description: "We follow up after resolution to ensure you're completely satisfied with the outcome." },
];

const faqs = [
  { q: "How do I register my WOODEX warranty?", a: "Your warranty is automatically registered upon delivery. Keep your invoice as proof of purchase. You can also email invoice details to warranty@woodex.pk for confirmation." },
  { q: "Is the warranty transferable?", a: "Woodex Series (10-year) warranties are transferable to new occupants of the same premises. Ek and Infinity Series warranties are non-transferable." },
  { q: "What proof do I need for a warranty claim?", a: "Original invoice, photos of the defect, and product serial number (found on a label under or behind the furniture)." },
  { q: "Do you offer on-site repair?", a: "Yes. For Woodex Series customers, on-site repair is the primary resolution method. For other series, we may require items to be returned to our facility." },
  { q: "What is not covered?", a: "Normal wear and tear, damage from misuse, water damage beyond minor spills, unauthorized modifications, and commercial use of residential products." },
];

const Warranty = () => {
  useEffect(() => {
    document.title = "Warranty Information — WOODEX Pakistan";
  }, []);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      <main className="flex-1">
        {/* Hero */}
        <section className="relative bg-primary text-primary-foreground py-20 overflow-hidden">
          <img src={warrantyHero} alt="Quality craftsmanship" className="absolute inset-0 w-full h-full object-cover opacity-30" />
          <div className="absolute inset-0 bg-gradient-to-r from-primary via-primary/85 to-primary/40" />
          <div className="container mx-auto px-4 relative">
            <div className="flex items-center gap-4 mb-4">
              <div className="w-14 h-14 rounded-full bg-accent flex items-center justify-center">
                <Shield className="h-7 w-7 text-accent-foreground" />
              </div>
              <div>
                <p className="text-accent text-xs font-bold uppercase tracking-widest">Peace of Mind</p>
                <h1 className="text-4xl lg:text-5xl font-black">Warranty Policy</h1>
              </div>
            </div>
            <p className="text-primary-foreground/75 max-w-xl mt-4">
              Every WOODEX product is backed by a comprehensive warranty. We stand behind our craftsmanship — 
              if something's wrong, we'll make it right.
            </p>
          </div>
        </section>

        {/* Warranty Tiers */}
        <section className="py-16">
          <div className="container mx-auto px-4">
            <div className="text-center mb-12">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl font-bold mb-3">Warranty Coverage by Series</h2>
              <p className="text-muted-foreground">Choose a series to see its warranty coverage</p>
            </div>
            <div className="grid md:grid-cols-3 gap-6">
              {warrantyTiers.map((tier) => (
                <div
                  key={tier.name}
                  className={`p-7 border-2 rounded-sm transition-all ${tier.popular ? "border-accent shadow-lg" : "border-border hover:border-accent"}`}
                >
                  {tier.popular && (
                    <span className="text-xs font-bold uppercase tracking-widest text-accent mb-3 block">Most Popular</span>
                  )}
                  <div className="flex items-end gap-2 mb-1">
                    <p className="text-4xl font-black text-accent">{tier.duration}</p>
                  </div>
                  <h3 className="font-bold text-lg mb-1">{tier.name}</h3>
                  <p className="text-xs text-muted-foreground mb-5">{tier.applies}</p>
                  <div className="space-y-2.5 mb-5">
                    <p className="text-xs font-bold uppercase tracking-wider text-foreground">Covered:</p>
                    {tier.coverage.map((item) => (
                      <div key={item} className="flex items-start gap-2 text-sm text-muted-foreground">
                        <CheckCircle2 className="h-4 w-4 text-accent flex-shrink-0 mt-0.5" />
                        {item}
                      </div>
                    ))}
                  </div>
                  <div className="border-t pt-4 space-y-1.5">
                    <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">Not Covered:</p>
                    {tier.excludes.map((item) => (
                      <div key={item} className="flex items-center gap-2 text-xs text-muted-foreground">
                        <div className="w-3 h-px bg-muted-foreground flex-shrink-0" />
                        {item}
                      </div>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Claim Process */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-12">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl font-bold mb-3">How to File a Warranty Claim</h2>
              <p className="text-muted-foreground">Simple, 4-step process — most claims resolved within 7 days</p>
            </div>
            <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
              {claimSteps.map((step, idx) => (
                <div key={step.step} className="text-center relative">
                  <div className="relative">
                    <div className="w-16 h-16 rounded-full bg-accent text-accent-foreground font-black text-xl flex items-center justify-center mx-auto mb-4">
                      {step.step}
                    </div>
                    {idx < claimSteps.length - 1 && (
                      <div className="hidden lg:block absolute top-8 left-[calc(50%+32px)] right-0 h-px bg-border" />
                    )}
                  </div>
                  <h3 className="font-bold text-lg mb-2">{step.title}</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">{step.description}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Contact for Warranty */}
        <section className="py-14 bg-background border-t">
          <div className="container mx-auto px-4">
            <div className="grid md:grid-cols-3 gap-6 max-w-3xl mx-auto">
              {[
                { icon: Phone, title: "Call Warranty Support", lines: ["+92 300 1234567", "Mon–Sat: 9am–6pm"] },
                { icon: Mail, title: "Email Warranty Team", lines: ["warranty@woodex.pk", "24hr response time"] },
                { icon: Clock, title: "Resolution Timeline", lines: ["Assessment: 24 hours", "Resolution: 7 business days"] },
              ].map((item) => (
                <div key={item.title} className="p-6 border border-border rounded-sm hover:border-accent transition-colors text-center">
                  <div className="w-12 h-12 bg-hon-green-pale rounded-full flex items-center justify-center mx-auto mb-4">
                    <item.icon className="h-6 w-6 text-accent" />
                  </div>
                  <h3 className="font-bold mb-2">{item.title}</h3>
                  {item.lines.map((l) => <p key={l} className="text-sm text-muted-foreground">{l}</p>)}
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* FAQ */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4 max-w-3xl">
            <div className="text-center mb-12">
              <h2 className="text-3xl font-bold mb-3">Warranty FAQs</h2>
            </div>
            <div className="space-y-4">
              {faqs.map((faq) => (
                <div key={faq.q} className="border border-border rounded-sm bg-background overflow-hidden">
                  <div className="p-5">
                    <h3 className="font-bold mb-2 text-foreground">{faq.q}</h3>
                    <p className="text-sm text-muted-foreground leading-relaxed">{faq.a}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* CTA */}
        <section className="py-14 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl font-bold mb-3">Have a Warranty Claim?</h2>
            <p className="text-primary-foreground/75 mb-7 max-w-md mx-auto">Our dedicated warranty team is here to help. Contact us and we'll resolve your issue quickly.</p>
            <div className="flex flex-wrap gap-4 justify-center">
              <Button className="bg-accent hover:bg-hon-green-dark text-accent-foreground px-8" asChild>
                <Link to="/contact">Contact Warranty Team</Link>
              </Button>
              <Button variant="outline" className="border-primary-foreground/40 text-primary-foreground hover:bg-primary-foreground/10 px-8" asChild>
                <Link to="/shop">Shop with Confidence</Link>
              </Button>
            </div>
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default Warranty;
