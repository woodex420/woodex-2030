import { submitLead } from "@/lib/runtime";
import { useState, useEffect } from "react";
import { Mail, Phone, MapPin, Clock, Send, MessageCircle, ChevronDown } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import contactHero from "@/assets/contact-hero.jpg";

const showrooms = [
  { city: "Lahore (HQ)", address: "123 Gulberg III, Main Boulevard, Lahore", phone: "+92 42 111 WOODEX", hours: "Mon–Sat: 9am–7pm" },
  { city: "Karachi", address: "456 Clifton Block 5, Karachi", phone: "+92 21 111 WOODEX", hours: "Mon–Sat: 9am–7pm" },
  { city: "Islamabad", address: "789 Blue Area, F-7, Islamabad", phone: "+92 51 111 WOODEX", hours: "Mon–Sat: 10am–6pm" },
];

const faqs = [
  { q: "How quickly can you deliver?", a: "Standard delivery takes 7-14 business days within major cities. Custom orders may take 3-4 weeks depending on complexity." },
  { q: "Do you offer installation services?", a: "Yes, free professional assembly and installation is included for all orders within Lahore, Karachi, and Islamabad. Other cities available at nominal charges." },
  { q: "Can I visit the factory?", a: "Absolutely! We welcome factory visits by appointment. Contact us to schedule a tour of our manufacturing facility in Lahore." },
  { q: "What payment methods do you accept?", a: "We accept bank transfers, cheques, and cash on delivery for orders within major cities. Corporate clients can set up credit accounts." },
];

const Contact = () => {
  const [submitted, setSubmitted] = useState(false);
  const [openFaq, setOpenFaq] = useState<number | null>(null);

  useEffect(() => {
    document.title = "Contact Us — WOODEX Pakistan | Showrooms in Lahore, Karachi, Islamabad";
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const form = e.currentTarget as HTMLFormElement;
    const fd = new FormData(form);
    // → shared backend: becomes a live lead in the dashboard CRM kanban
    await submitLead({
      name: String(fd.get("name") || "Website visitor"),
      contact: [fd.get("email"), fd.get("phone")].filter(Boolean).join(" / "),
      interest: String(fd.get("subject") || "General inquiry"),
      source: "Contact Form",
      note: String(fd.get("message") || "").slice(0, 160),
    });
    setSubmitted(true);
    setTimeout(() => setSubmitted(false), 4000);
  };

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* Page Header with Hero Image */}
        <section className="relative h-72 overflow-hidden bg-primary">
          <img src={contactHero} alt="WOODEX Office Showroom" className="w-full h-full object-cover opacity-30" />
          <div className="absolute inset-0 flex items-center">
            <div className="container mx-auto px-4">
              <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">Contact Us</p>
              <h1 className="text-4xl lg:text-5xl font-black text-primary-foreground mb-3">Get in Touch</h1>
              <p className="text-primary-foreground/75 max-w-xl">
                Have questions about our products or services? We'd love to hear from you.
              </p>
            </div>
          </div>
        </section>

        {/* Contact Info + Form */}
        <section className="py-16">
          <div className="container mx-auto px-4">
            <div className="grid lg:grid-cols-3 gap-10">
              {/* Info Cards */}
              <div className="space-y-4">
                <h2 className="text-xl font-bold mb-6">Contact Information</h2>
                {[
                  { icon: Phone, title: "Call Us", lines: ["+92 300 1234567", "+92 42 111 WOODEX"] },
                  { icon: Mail, title: "Email Us", lines: ["info@woodex.pk", "sales@woodex.pk"] },
                  { icon: MapPin, title: "Head Office", lines: ["123 Gulberg III", "Lahore, Pakistan"] },
                  { icon: Clock, title: "Business Hours", lines: ["Mon–Sat: 9am–7pm", "Sun: Closed"] },
                ].map((item) => (
                  <div key={item.title} className="flex gap-4 p-4 border border-border rounded-sm hover:border-accent transition-colors">
                    <div className="w-10 h-10 rounded-sm bg-hon-green-pale flex items-center justify-center flex-shrink-0">
                      <item.icon className="h-5 w-5 text-accent" />
                    </div>
                    <div>
                      <p className="font-semibold text-sm mb-1">{item.title}</p>
                      {item.lines.map((line) => (
                        <p key={line} className="text-sm text-muted-foreground">{line}</p>
                      ))}
                    </div>
                  </div>
                ))}
              </div>

              {/* Form */}
              <div className="lg:col-span-2">
                <h2 className="text-xl font-bold mb-6">Send a Message</h2>
                <Card className="border shadow-sm">
                  <CardContent className="pt-6">
                    {submitted ? (
                      <div className="text-center py-12">
                        <div className="w-16 h-16 rounded-full bg-hon-green-pale flex items-center justify-center mx-auto mb-4">
                          <Send className="h-8 w-8 text-accent" />
                        </div>
                        <h3 className="text-xl font-bold mb-2">Message Sent!</h3>
                        <p className="text-muted-foreground">We'll get back to you within 24 hours.</p>
                      </div>
                    ) : (
                      <form onSubmit={handleSubmit} className="space-y-5">
                        <div className="grid md:grid-cols-2 gap-5">
                          <div className="space-y-1.5">
                            <Label htmlFor="name">Full Name *</Label>
                            <Input id="name" placeholder="Muhammad Ali" required />
                          </div>
                          <div className="space-y-1.5">
                            <Label htmlFor="email">Email *</Label>
                            <Input id="email" type="email" placeholder="your@email.com" required />
                          </div>
                        </div>
                        <div className="grid md:grid-cols-2 gap-5">
                          <div className="space-y-1.5">
                            <Label htmlFor="phone">Phone</Label>
                            <Input id="phone" type="tel" placeholder="+92 300 1234567" />
                          </div>
                          <div className="space-y-1.5">
                            <Label htmlFor="subject">Subject</Label>
                            <Input id="subject" placeholder="Product inquiry" />
                          </div>
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="message">Message *</Label>
                          <Textarea id="message" placeholder="Tell us about your project..." className="min-h-[140px]" required />
                        </div>
                        <Button type="submit" className="w-full bg-accent hover:bg-hon-green-dark text-accent-foreground font-semibold">
                          Send Message
                        </Button>
                      </form>
                    )}
                  </CardContent>
                </Card>
              </div>
            </div>
          </div>
        </section>

        {/* Google Maps */}
        <section className="border-t">
          <div className="w-full h-80 bg-section-mid">
            <iframe
              title="WOODEX Head Office Location — Gulberg III, Lahore"
              src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3401.456!2d74.3507!3d31.5204!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMzHCsDMxJzEzLjQiTiA3NMKwMjEnMDIuNSJF!5e0!3m2!1sen!2spk!4v1234567890"
              className="w-full h-full border-0"
              allowFullScreen
              loading="lazy"
              referrerPolicy="no-referrer-when-downgrade"
            />
          </div>
        </section>

        {/* Showrooms */}
        <section className="py-14 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="text-center mb-10">
              <div className="w-12 h-1 bg-accent mx-auto mb-4" />
              <h2 className="text-3xl font-bold mb-3">Visit Our Showrooms</h2>
              <p className="text-muted-foreground">Experience our furniture in person</p>
            </div>
            <div className="grid md:grid-cols-3 gap-6">
              {showrooms.map((s) => (
                <div key={s.city} className="p-6 bg-background border rounded-sm hover:border-accent transition-colors">
                  <div className="w-8 h-1 bg-accent mb-4" />
                  <h3 className="font-bold text-lg mb-3">{s.city}</h3>
                  <div className="space-y-2 text-sm text-muted-foreground">
                    <div className="flex items-start gap-2"><MapPin className="h-4 w-4 text-accent mt-0.5 flex-shrink-0" /><span>{s.address}</span></div>
                    <div className="flex items-center gap-2"><Phone className="h-4 w-4 text-accent flex-shrink-0" /><span>{s.phone}</span></div>
                    <div className="flex items-center gap-2"><Clock className="h-4 w-4 text-accent flex-shrink-0" /><span>{s.hours}</span></div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* FAQ */}
        <section className="py-14 bg-background border-t">
          <div className="container mx-auto px-4 max-w-3xl">
            <div className="text-center mb-10">
              <div className="w-12 h-1 bg-accent mx-auto mb-5" />
              <h2 className="text-2xl font-bold mb-2">Frequently Asked Questions</h2>
            </div>
            <div className="space-y-3">
              {faqs.map((faq, i) => (
                <div key={i} className="border border-border rounded-sm overflow-hidden">
                  <button
                    onClick={() => setOpenFaq(openFaq === i ? null : i)}
                    className="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-section-light transition-colors"
                  >
                    <span className="font-semibold text-sm">{faq.q}</span>
                    <ChevronDown className={`h-4 w-4 text-muted-foreground transition-transform flex-shrink-0 ml-4 ${openFaq === i ? "rotate-180" : ""}`} />
                  </button>
                  {openFaq === i && (
                    <div className="px-5 pb-4 text-sm text-muted-foreground leading-relaxed border-t border-border bg-section-light">
                      <div className="pt-3">{faq.a}</div>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>
        </section>
      </main>

      {/* WhatsApp Floating Button */}
      <a
        href="https://wa.me/923001234567?text=Hi%20WOODEX%2C%20I%27m%20interested%20in%20your%20furniture"
        target="_blank"
        rel="noopener noreferrer"
        className="fixed bottom-6 right-6 z-50 w-14 h-14 bg-[hsl(142,70%,45%)] hover:bg-[hsl(142,70%,38%)] text-white rounded-full flex items-center justify-center shadow-lg transition-colors"
        aria-label="Chat on WhatsApp"
      >
        <MessageCircle className="h-7 w-7" />
      </a>

      <Footer />
    </div>
  );
};

export default Contact;
