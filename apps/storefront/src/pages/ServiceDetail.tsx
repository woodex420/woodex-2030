import { useEffect, useState } from "react";
import { Link, useParams, Navigate } from "react-router-dom";
import { ArrowRight, ChevronRight, CheckCircle2, MessageCircle, Plus, Minus, Quote } from "lucide-react";
import { Button } from "@/components/ui/button";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { getService, getRelated, services } from "@/data/services";

const WA_PHONE = "923001234567";

const ServiceDetail = () => {
  const { slug } = useParams<{ slug: string }>();
  const service = slug ? getService(slug) : undefined;
  const [openFaq, setOpenFaq] = useState<number | null>(0);

  useEffect(() => {
    if (!service) return;
    document.title = service.metaTitle;
    const meta = document.querySelector('meta[name="description"]') || (() => {
      const m = document.createElement("meta");
      m.setAttribute("name", "description");
      document.head.appendChild(m);
      return m;
    })();
    meta.setAttribute("content", service.metaDescription);

    // JSON-LD
    const schemaId = "service-schema";
    document.getElementById(schemaId)?.remove();
    const ld = document.createElement("script");
    ld.id = schemaId;
    ld.type = "application/ld+json";
    ld.text = JSON.stringify({
      "@context": "https://schema.org",
      "@type": "Service",
      name: service.title,
      description: service.metaDescription,
      provider: { "@type": "Organization", name: "WOODEX", areaServed: "Pakistan" },
      serviceType: service.shortTitle,
    });
    document.head.appendChild(ld);
    window.scrollTo(0, 0);
  }, [service]);

  if (!service) return <Navigate to="/services" replace />;

  const related = getRelated(service.related);
  const waText = encodeURIComponent(`Hi WOODEX, I'm interested in your ${service.title} service.`);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* Hero */}
        <section className="relative h-[420px] overflow-hidden bg-primary">
          <img src={service.hero} alt={service.title} className="w-full h-full object-cover opacity-35" width={1536} height={1024} />
          <div className="absolute inset-0 bg-gradient-to-r from-primary via-primary/80 to-primary/30" />
          <div className="absolute inset-0 flex items-center">
            <div className="container mx-auto px-4">
              <nav aria-label="Breadcrumb" className="flex items-center gap-2 text-xs text-primary-foreground/70 mb-4">
                <Link to="/" className="hover:text-accent">Home</Link>
                <ChevronRight className="h-3 w-3" />
                <Link to="/services" className="hover:text-accent">Services</Link>
                <ChevronRight className="h-3 w-3" />
                <span className="text-primary-foreground">{service.shortTitle}</span>
              </nav>
              <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">Service {service.number}</p>
              <h1 className="text-4xl lg:text-6xl font-black text-primary-foreground mb-4 max-w-3xl">{service.title}</h1>
              <p className="text-primary-foreground/80 max-w-2xl text-base lg:text-lg">{service.tagline}</p>
            </div>
          </div>
        </section>

        {/* Overview */}
        <section className="py-20">
          <div className="container mx-auto px-4">
            <div className="grid lg:grid-cols-12 gap-12 max-w-6xl mx-auto">
              <div className="lg:col-span-7">
                <div className="w-12 h-1 bg-accent mb-5" />
                <h2 className="text-3xl lg:text-4xl font-bold mb-5 leading-tight">{service.overview.heading}</h2>
                <p className="text-muted-foreground leading-relaxed mb-4">{service.intro}</p>
                <p className="text-muted-foreground leading-relaxed">{service.overview.body}</p>
                <div className="flex flex-wrap gap-3 mt-8">
                  <Button className="bg-accent hover:bg-accent/90 text-accent-foreground" asChild>
                    <Link to="/quotation">Request a Quote <ArrowRight className="ml-2 h-4 w-4" /></Link>
                  </Button>
                  <Button variant="outline" asChild>
                    <a href={`https://wa.me/${WA_PHONE}?text=${waText}`} target="_blank" rel="noopener noreferrer">
                      <MessageCircle className="mr-2 h-4 w-4" /> WhatsApp Inquiry
                    </a>
                  </Button>
                </div>
              </div>
              <div className="lg:col-span-5">
                <div className="border-l-2 border-accent pl-6 py-2">
                  <p className="text-xs uppercase tracking-widest text-accent font-bold mb-3">What's Included</p>
                  <ul className="space-y-3">
                    {service.inclusions.map((i) => (
                      <li key={i} className="flex items-start gap-3 text-sm">
                        <CheckCircle2 className="h-4 w-4 text-accent flex-shrink-0 mt-0.5" />
                        <span>{i}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* Process */}
        <section className="py-20 bg-section-light border-t border-b">
          <div className="container mx-auto px-4">
            <div className="max-w-2xl mb-12">
              <div className="w-12 h-1 bg-accent mb-5" />
              <h2 className="text-3xl lg:text-4xl font-bold mb-3">Our Process</h2>
              <p className="text-muted-foreground">A proven, repeatable workflow refined over hundreds of Pakistani projects.</p>
            </div>
            <div className="grid md:grid-cols-2 lg:grid-cols-5 gap-6">
              {service.process.map((p, idx) => (
                <div key={p.step} className="relative">
                  <div className="bg-background border border-border p-6 rounded-sm h-full hover:border-accent transition-colors">
                    <span className="text-3xl font-black text-accent/30">{p.step}</span>
                    <h3 className="font-bold text-base mt-2 mb-2">{p.title}</h3>
                    <p className="text-xs text-muted-foreground leading-relaxed">{p.description}</p>
                  </div>
                  {idx < service.process.length - 1 && (
                    <ArrowRight className="hidden lg:block absolute top-1/2 -right-4 -translate-y-1/2 h-5 w-5 text-accent/50 z-10" />
                  )}
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Benefits */}
        <section className="py-20">
          <div className="container mx-auto px-4">
            <div className="max-w-2xl mx-auto text-center mb-12">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-3xl lg:text-4xl font-bold mb-3">Why Choose WOODEX</h2>
              <p className="text-muted-foreground">Built for Pakistani enterprises that can't afford to get it wrong.</p>
            </div>
            <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-6 max-w-6xl mx-auto">
              {service.benefits.map((b, i) => (
                <div key={b.title} className="p-6 border border-border rounded-sm hover:border-accent hover:shadow-md transition-all">
                  <span className="text-xs font-bold text-accent">0{i + 1}</span>
                  <h3 className="font-bold text-lg mt-2 mb-2">{b.title}</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">{b.description}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Case Study */}
        <section className="py-20 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4">
            <div className="max-w-4xl mx-auto">
              <Quote className="h-10 w-10 text-accent mb-6" />
              <p className="text-xs uppercase tracking-widest text-accent font-bold mb-3">Recent Project</p>
              <h3 className="text-2xl lg:text-3xl font-bold mb-3">{service.caseStudy.client}</h3>
              <p className="text-primary-foreground/70 mb-6">{service.caseStudy.sector}</p>
              <p className="text-lg lg:text-xl leading-relaxed border-l-2 border-accent pl-6">
                {service.caseStudy.result}
              </p>
              <Button variant="outline" className="mt-8 border-primary-foreground/30 text-primary-foreground hover:bg-primary-foreground/10" asChild>
                <Link to="/projects">View All Projects <ArrowRight className="ml-2 h-4 w-4" /></Link>
              </Button>
            </div>
          </div>
        </section>

        {/* FAQ */}
        <section className="py-20">
          <div className="container mx-auto px-4">
            <div className="max-w-3xl mx-auto">
              <div className="text-center mb-12">
                <div className="w-12 h-1 bg-accent mx-auto mb-5" />
                <h2 className="text-3xl lg:text-4xl font-bold mb-3">Frequently Asked Questions</h2>
              </div>
              <div className="space-y-3">
                {service.faqs.map((f, i) => (
                  <div key={i} className="border border-border rounded-sm overflow-hidden">
                    <button
                      onClick={() => setOpenFaq(openFaq === i ? null : i)}
                      className="w-full px-6 py-4 flex items-center justify-between text-left hover:bg-section-light transition-colors"
                    >
                      <span className="font-bold text-base pr-4">{f.q}</span>
                      {openFaq === i ? <Minus className="h-4 w-4 text-accent flex-shrink-0" /> : <Plus className="h-4 w-4 text-accent flex-shrink-0" />}
                    </button>
                    {openFaq === i && (
                      <div className="px-6 pb-5 text-sm text-muted-foreground leading-relaxed">{f.a}</div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          </div>
        </section>

        {/* Related Services */}
        <section className="py-20 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="max-w-2xl mb-10">
              <div className="w-12 h-1 bg-accent mb-5" />
              <h2 className="text-3xl font-bold mb-3">Related Services</h2>
              <p className="text-muted-foreground">Most clients pair this with the services below.</p>
            </div>
            <div className="grid md:grid-cols-3 gap-6">
              {related.map((r) => (
                <Link key={r.slug} to={`/services/${r.slug}`} className="group border border-border rounded-sm overflow-hidden bg-background hover:border-accent transition-all">
                  <div className="aspect-video overflow-hidden">
                    <img src={r.hero} alt={r.title} loading="lazy" width={1536} height={1024} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />
                  </div>
                  <div className="p-6">
                    <span className="text-xs font-bold text-accent">{r.number}</span>
                    <h3 className="font-bold text-lg mt-2 mb-2 group-hover:text-accent transition-colors">{r.shortTitle}</h3>
                    <p className="text-sm text-muted-foreground line-clamp-2">{r.tagline}</p>
                    <span className="inline-flex items-center gap-2 text-sm font-bold text-accent mt-4">
                      Learn more <ArrowRight className="h-3.5 w-3.5" />
                    </span>
                  </div>
                </Link>
              ))}
            </div>
          </div>
        </section>

        {/* CTA */}
        <section className="py-16 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl font-bold mb-3">Let's discuss your {service.shortTitle.toLowerCase()} project</h2>
            <p className="text-primary-foreground/75 mb-7 max-w-md mx-auto">
              Get a tailored proposal within 24 hours from our Lahore team.
            </p>
            <div className="flex flex-wrap gap-4 justify-center">
              <Button className="bg-accent hover:bg-accent/90 text-accent-foreground px-8" asChild>
                <Link to="/quotation">Request a Quote</Link>
              </Button>
              <Button variant="outline" className="border-primary-foreground/30 text-primary-foreground hover:bg-primary-foreground/10" asChild>
                <a href={`https://wa.me/${WA_PHONE}?text=${waText}`} target="_blank" rel="noopener noreferrer">
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

export default ServiceDetail;
