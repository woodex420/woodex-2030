import { useEffect } from "react";
import { Link } from "react-router-dom";
import { Building2, Users, TrendingUp, HeadphonesIcon, CheckCircle2, XCircle, ArrowRight, FileText } from "lucide-react";
import { Button } from "@/components/ui/button";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import b2bHero from "@/assets/b2b-hero.jpg";

const benefits = [
  { icon: Building2, title: "Corporate Solutions", description: "Large-scale office furnishing projects with dedicated project management from concept to completion." },
  { icon: Users, title: "Dealer Program", description: "Become an authorized WOODEX dealer and access exclusive trade pricing, marketing support, and training." },
  { icon: TrendingUp, title: "Volume Pricing", description: "Competitive rates that improve with scale. Significant savings on orders of 50+ units." },
  { icon: HeadphonesIcon, title: "Dedicated Support", description: "Your personal account manager provides priority service, fast quotes, and proactive communication." },
];

const industries = [
  { name: "Corporate Business", description: "C-suite offices, open-plan floors, boardrooms, and reception areas for leading companies.", count: "200+ projects" },
  { name: "Education", description: "Libraries, classrooms, faculty offices, and student collaboration spaces for schools and universities.", count: "80+ projects" },
  { name: "Healthcare", description: "Durable, easy-clean furniture for clinics, hospitals, and medical facilities.", count: "60+ projects" },
  { name: "Government", description: "Compliant, functional furniture for government offices, courts, and public institutions.", count: "40+ projects" },
  { name: "Hospitality", description: "Premium lobby, business center, and restaurant furniture for hotels and hospitality venues.", count: "50+ projects" },
];

const comparisonFeatures = [
  { feature: "Dedicated Account Manager", starter: "Basic", business: "Senior", enterprise: "Director-Level" },
  { feature: "Volume Discount", starter: "5%", business: "12%", enterprise: "Custom" },
  { feature: "Free Space Planning", starter: false, business: true, enterprise: true },
  { feature: "Free 3D Visualization", starter: false, business: false, enterprise: true },
  { feature: "Priority Manufacturing", starter: false, business: true, enterprise: true },
  { feature: "Expedited Production", starter: false, business: false, enterprise: true },
  { feature: "Extended Warranty", starter: "1 Year", business: "3 Years", enterprise: "5 Years" },
  { feature: "Support Channel", starter: "Email", business: "Phone & Email", enterprise: "24/7 Priority" },
  { feature: "Custom Credit Terms", starter: false, business: false, enterprise: true },
  { feature: "Custom Branding / Logo", starter: false, business: true, enterprise: true },
  { feature: "Dedicated Design Service", starter: false, business: false, enterprise: true },
  { feature: "Annual Maintenance Plan", starter: false, business: "Optional", enterprise: "Included" },
];

const tiers = [
  { name: "Starter", range: "PKR 500K – 2M", description: "Ideal for small businesses and startups furnishing their first office." },
  { name: "Business", range: "PKR 2M – 10M", description: "For growing companies needing professional workspace solutions.", popular: true },
  { name: "Enterprise", range: "PKR 10M+", description: "Full-service partnership for large-scale corporate projects." },
];

const B2B = () => {
  useEffect(() => {
    document.title = "B2B Partner Program — WOODEX Pakistan | Volume Pricing & Corporate Solutions";
  }, []);

  const renderCell = (value: boolean | string) => {
    if (value === true) return <CheckCircle2 className="h-4 w-4 text-accent mx-auto" />;
    if (value === false) return <XCircle className="h-4 w-4 text-muted-foreground/40 mx-auto" />;
    return <span className="text-sm font-medium">{value}</span>;
  };

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* Hero */}
        <section className="relative min-h-[400px] overflow-hidden bg-primary flex items-center">
          <img src={b2bHero} alt="B2B Solutions" className="absolute inset-0 w-full h-full object-cover opacity-30" />
          <div className="container mx-auto px-4 relative z-10 py-16">
            <p className="text-accent text-xs font-bold uppercase tracking-widest mb-3">Business Solutions</p>
            <h1 className="text-4xl lg:text-6xl font-black text-primary-foreground mb-4 max-w-2xl leading-tight">
              B2B Partner Program
            </h1>
            <p className="text-primary-foreground/75 max-w-xl mb-8 text-lg">
              Partner with WOODEX for enterprise furniture solutions. Volume pricing, dedicated support,
              and flexible terms for businesses of all sizes.
            </p>
            <div className="flex flex-wrap gap-4">
              <Button size="lg" className="bg-accent hover:bg-accent/90 text-accent-foreground px-8" asChild>
                <Link to="/quotation">Request B2B Catalog</Link>
              </Button>
              <Button size="lg" variant="outline" className="border-primary-foreground/40 text-primary-foreground hover:bg-primary-foreground/10" asChild>
                <Link to="/contact">Talk to Sales</Link>
              </Button>
            </div>
          </div>
        </section>

        {/* Benefits */}
        <section className="py-16 bg-background">
          <div className="container mx-auto px-4">
            <div className="text-center mb-12">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl font-bold mb-3">Why Partner with WOODEX?</h2>
              <p className="text-muted-foreground">Exclusive benefits designed for business customers</p>
            </div>
            <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
              {benefits.map((item) => (
                <div key={item.title} className="group p-6 border border-border rounded-sm hover:border-accent hover:shadow-md transition-all text-center">
                  <div className="w-14 h-14 rounded-full bg-muted flex items-center justify-center mx-auto mb-4 group-hover:bg-accent transition-colors">
                    <item.icon className="h-7 w-7 text-accent group-hover:text-accent-foreground transition-colors" />
                  </div>
                  <h3 className="font-bold text-lg mb-2">{item.title}</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">{item.description}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Industries */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-12">
              <h2 className="text-3xl font-bold mb-3">Industries We Serve</h2>
              <p className="text-muted-foreground">Specialized solutions for every sector</p>
            </div>
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
              {industries.map((ind) => (
                <div key={ind.name} className="p-6 bg-background border border-border rounded-sm hover:border-accent transition-colors group">
                  <div className="flex items-start justify-between mb-3">
                    <h3 className="font-bold text-lg group-hover:text-accent transition-colors">{ind.name}</h3>
                    <span className="text-xs text-accent font-semibold bg-muted px-2 py-1 rounded">{ind.count}</span>
                  </div>
                  <p className="text-sm text-muted-foreground leading-relaxed">{ind.description}</p>
                  <Button variant="ghost" size="sm" className="mt-3 px-0 text-accent hover:text-accent/80 hover:bg-transparent">
                    Learn More <ArrowRight className="h-3.5 w-3.5 ml-1" />
                  </Button>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Pricing Tiers Header */}
        <section className="py-16 bg-background border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-10">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl font-bold mb-3">B2B Partnership Tiers</h2>
              <p className="text-muted-foreground">Choose the tier that fits your business — transparent benefits at every level</p>
            </div>

            {/* Tier Cards */}
            <div className="grid md:grid-cols-3 gap-6 max-w-4xl mx-auto mb-12">
              {tiers.map((tier) => (
                <div
                  key={tier.name}
                  className={`rounded-sm p-7 border-2 transition-all text-center ${
                    tier.popular
                      ? "border-accent shadow-lg scale-[1.02]"
                      : "border-border hover:border-accent"
                  }`}
                >
                  {tier.popular && (
                    <div className="text-xs font-bold uppercase tracking-wider text-accent mb-3">Most Popular</div>
                  )}
                  <h3 className="font-black text-2xl mb-1">{tier.name}</h3>
                  <p className="text-accent font-bold text-lg mb-2">{tier.range}</p>
                  <p className="text-sm text-muted-foreground mb-5">{tier.description}</p>
                  <Button
                    className={`w-full ${tier.popular ? "bg-accent hover:bg-accent/90 text-accent-foreground" : ""}`}
                    variant={tier.popular ? "default" : "outline"}
                    asChild
                  >
                    <Link to="/contact">Get Started</Link>
                  </Button>
                </div>
              ))}
            </div>

            {/* Feature Comparison Table */}
            <div className="max-w-5xl mx-auto">
              <h3 className="text-xl font-bold mb-6 text-center">Feature Comparison</h3>
              <div className="border border-border rounded-sm overflow-hidden">
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="bg-primary text-primary-foreground">
                        <th className="text-left px-5 py-3 font-semibold">Feature</th>
                        <th className="text-center px-5 py-3 font-semibold w-32">Starter</th>
                        <th className="text-center px-5 py-3 font-semibold w-32 bg-accent text-accent-foreground">Business</th>
                        <th className="text-center px-5 py-3 font-semibold w-32">Enterprise</th>
                      </tr>
                    </thead>
                    <tbody>
                      {comparisonFeatures.map((row, i) => (
                        <tr key={row.feature} className={i % 2 === 0 ? "bg-background" : "bg-section-light"}>
                          <td className="px-5 py-3 font-medium text-foreground">{row.feature}</td>
                          <td className="px-5 py-3 text-center">{renderCell(row.starter)}</td>
                          <td className="px-5 py-3 text-center bg-accent/5">{renderCell(row.business)}</td>
                          <td className="px-5 py-3 text-center">{renderCell(row.enterprise)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* CTA */}
        <section className="py-14 bg-accent text-accent-foreground">
          <div className="container mx-auto px-4">
            <div className="flex flex-col lg:flex-row items-center justify-between gap-6">
              <div>
                <h2 className="text-2xl lg:text-3xl font-bold mb-2">Ready to Start Your Partnership?</h2>
                <p className="opacity-85">Join 200+ companies that trust WOODEX for their office furniture needs.</p>
              </div>
              <div className="flex gap-3 flex-shrink-0">
                <Button size="lg" className="bg-primary hover:bg-primary/90 text-primary-foreground" asChild>
                  <Link to="/quotation"><FileText className="h-4 w-4 mr-2" />Request Catalog</Link>
                </Button>
                <Button size="lg" variant="outline" className="border-primary-foreground/40 text-primary-foreground hover:bg-primary-foreground/10" asChild>
                  <Link to="/contact">Contact Sales</Link>
                </Button>
              </div>
            </div>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
};

export default B2B;
