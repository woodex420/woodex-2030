import { submitQuoteRequest } from "@/lib/runtime";
import { useState, useRef, useMemo } from "react";
import { Link } from "react-router-dom";
import { CheckCircle, Clock, Shield, Users, Headphones, Trash2, Minus, Plus, ShoppingBag, Search, Download, Send, MessageCircle, FileText, ArrowRight, SlidersHorizontal } from "lucide-react";
// Card removed — new layout no longer uses it
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { useQuote } from "@/contexts/QuoteContext";
import { formatPKR, products } from "@/data/products";
import { useEffect } from "react";
import PrintableInvoice, { ClientInfo } from "@/components/quotation/PrintableInvoice";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/components/ui/dialog";
import { toast } from "sonner";

const benefits = [
  { icon: Clock, title: "24-Hour Response", description: "Receive detailed quotes within one business day" },
  { icon: Shield, title: "Transparent Pricing", description: "Clear breakdown with no hidden fees" },
  { icon: Users, title: "No Obligation", description: "Free quotes with zero pressure to commit" },
  { icon: Headphones, title: "Expert Advice", description: "Personalized recommendations for your needs" },
];


const Quotation = () => {
  const [submitted, setSubmitted] = useState(false);
  const [productSearch, setProductSearch] = useState("");
  const [showProductSearch, setShowProductSearch] = useState(false);
  const { items, removeItem, updateQuantity, totalPrice, totalItems, addItem } = useQuote();
  const printRef = useRef<HTMLDivElement>(null);

  // Client info state
  const [clientInfo, setClientInfo] = useState<ClientInfo>({ name: "", location: "", contactNumber: "", whatsapp: "" });
  const [showClientDialog, setShowClientDialog] = useState(false);
  const [pendingAction, setPendingAction] = useState<"print" | "email" | "whatsapp" | null>(null);

  const searchResults = useMemo(() => {
    if (!productSearch.trim()) return [];
    const q = productSearch.toLowerCase();
    return products.filter(p => p.name.toLowerCase().includes(q) || p.shortDescription.toLowerCase().includes(q)).slice(0, 8);
  }, [productSearch]);

  useEffect(() => {
    document.title = "Get E-Quotation — WOODEX Pakistan | Free Quote Request";
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    // → shared backend: lands in the dashboard quotation pipeline as a Draft
    await submitQuoteRequest({
      customer: clientInfo.name,
      contact: clientInfo.whatsapp || clientInfo.contactNumber,
      items: items.map((i) => ({ name: i.name, price: i.price, qty: i.quantity ?? 1, material: i.color })),
      source: "storefront",
      note: `Location: ${clientInfo.location}`,
    });
    setSubmitted(true);
    setTimeout(() => setSubmitted(false), 5000);
  };

  const isClientInfoValid = clientInfo.name.trim() && clientInfo.location.trim() && clientInfo.contactNumber.trim() && clientInfo.whatsapp.trim();

  const requestAction = (action: "print" | "email" | "whatsapp") => {
    if (items.length === 0) {
      toast.error("Please add at least one product to your quote basket first.");
      return;
    }
    if (!isClientInfoValid) {
      setPendingAction(action);
      setShowClientDialog(true);
    } else {
      executeAction(action);
    }
  };

  const executeAction = (action: "print" | "email" | "whatsapp") => {
    if (action === "print") {
      window.print();
    } else if (action === "email") {
      const subject = encodeURIComponent(`WOODEX Quotation Request — ${clientInfo.name}`);
      const itemsList = items.map((item, i) => `${i + 1}. ${item.name} x${item.quantity} — ${formatPKR(item.price * item.quantity)}`).join("%0A");
      const body = encodeURIComponent(
        `Quotation Request\n\nClient: ${clientInfo.name}\nLocation: ${clientInfo.location}\nContact: ${clientInfo.contactNumber}\nWhatsApp: ${clientInfo.whatsapp}\n\nProducts:\n${decodeURIComponent(itemsList)}\n\nEstimated Total: ${formatPKR(totalPrice)}\nAdvance (75%): ${formatPKR(Math.round(totalPrice * 0.75))}\nBalance Due: ${formatPKR(Math.round(totalPrice * 0.25))}\n\nPlease confirm this quotation.`
      );
      window.open(`mailto:info@woodex.pk?subject=${subject}&body=${body}`, "_blank");
      toast.success("Email client opened with quotation details!");
    } else if (action === "whatsapp") {
      const itemsList = items.map((item, i) => `${i + 1}. ${item.name} x${item.quantity} — ${formatPKR(item.price * item.quantity)}`).join("\n");
      const message = encodeURIComponent(
        `*WOODEX Quotation Request*\n\n*Client:* ${clientInfo.name}\n*Location:* ${clientInfo.location}\n*Contact:* ${clientInfo.contactNumber}\n\n*Products:*\n${itemsList}\n\n*Estimated Total:* ${formatPKR(totalPrice)}\n*Advance (75%):* ${formatPKR(Math.round(totalPrice * 0.75))}\n*Balance Due:* ${formatPKR(Math.round(totalPrice * 0.25))}\n\nPlease confirm this quotation.`
      );
      window.open(`https://wa.me/923224000768?text=${message}`, "_blank");
      toast.success("WhatsApp opened with quotation details!");
    }
  };

  const handleClientDialogSubmit = () => {
    if (!isClientInfoValid) {
      toast.error("Please fill in all client details.");
      return;
    }
    setShowClientDialog(false);
    if (pendingAction) {
      executeAction(pendingAction);
      setPendingAction(null);
    }
  };

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* Hero */}
        <section className="bg-primary text-primary-foreground py-14">
          <div className="container mx-auto px-4">
            <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">Free Service</p>
            <h1 className="text-4xl lg:text-5xl font-black mb-3">Get an E-Quotation</h1>
            <p className="text-primary-foreground/75 max-w-xl">
              Fast, transparent pricing for all your office furniture needs. Fill out the form and 
              receive a detailed quote within 24 hours.
            </p>
          </div>
        </section>

        {/* Benefits Bar */}
        <section className="bg-accent py-6">
          <div className="container mx-auto px-4">
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
              {benefits.map((b) => (
                <div key={b.title} className="flex items-center gap-3 text-accent-foreground">
                  <b.icon className="h-5 w-5 flex-shrink-0" />
                  <div>
                    <p className="font-semibold text-sm">{b.title}</p>
                    <p className="text-xs opacity-80">{b.description}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Product Search & Add */}
        <section className="py-6 bg-background border-b">
          <div className="container mx-auto px-4">
            <div className="max-w-2xl">
              <div className="flex items-center gap-3 mb-3">
                <Search className="h-5 w-5 text-accent" />
                <h2 className="font-bold">Add Products to Quote</h2>
              </div>
              <div className="relative">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Search products by name... (e.g. BALLMER, ergonomic chair)"
                  value={productSearch}
                  onChange={(e) => { setProductSearch(e.target.value); setShowProductSearch(true); }}
                  onFocus={() => setShowProductSearch(true)}
                  className="pl-10"
                />
                {showProductSearch && searchResults.length > 0 && (
                  <div className="absolute z-20 top-full left-0 right-0 mt-1 bg-background border border-border rounded-sm shadow-lg max-h-80 overflow-y-auto">
                    {searchResults.map((p) => (
                      <button
                        key={p.id}
                        className="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-section-light transition-colors border-b border-border last:border-b-0"
                        onClick={() => {
                          addItem({ id: p.id, name: p.name, category: p.category, price: p.price, image: p.images[0] });
                          setProductSearch("");
                          setShowProductSearch(false);
                        }}
                      >
                        <img src={p.images[0]} alt={p.name} className="w-10 h-10 object-cover rounded-sm flex-shrink-0" />
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-semibold truncate">{p.name}</p>
                          <p className="text-xs text-muted-foreground">{p.subcategory || p.category}</p>
                        </div>
                        <span className="text-sm font-bold text-accent flex-shrink-0">{formatPKR(p.price)}</span>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
        </section>

        {/* Client Info Bar (shown when client info is filled) */}
        {isClientInfoValid && items.length > 0 && (
          <section className="py-3 bg-accent/10 border-b">
            <div className="container mx-auto px-4 flex items-center justify-between flex-wrap gap-3">
              <div className="flex items-center gap-4 text-sm">
                <span className="font-semibold">👤 {clientInfo.name}</span>
                <span className="text-muted-foreground">📍 {clientInfo.location}</span>
                <span className="text-muted-foreground">📞 {clientInfo.contactNumber}</span>
              </div>
              <Button variant="ghost" size="sm" onClick={() => { setPendingAction(null); setShowClientDialog(true); }}>
                Edit Client Info
              </Button>
            </div>
          </section>
        )}

        {/* Quote Basket Items */}
        {items.length > 0 && (
          <section className="py-8 bg-section-light border-b">
            <div className="container mx-auto px-4">
              <div className="flex items-center justify-between mb-6 flex-wrap gap-3">
                <div className="flex items-center gap-3">
                  <ShoppingBag className="h-5 w-5 text-accent" />
                  <h2 className="text-xl font-bold">Your Quote Basket ({totalItems} items)</h2>
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                  <Button variant="outline" size="sm" className="border-accent text-accent hover:bg-accent hover:text-accent-foreground gap-2" onClick={() => requestAction("print")}>
                    <Download className="h-4 w-4" />
                    Download PDF
                  </Button>
                  <Button variant="outline" size="sm" className="border-green-600 text-green-700 hover:bg-green-600 hover:text-white gap-2" onClick={() => requestAction("whatsapp")}>
                    <MessageCircle className="h-4 w-4" />
                    Send WhatsApp
                  </Button>
                  <Button variant="outline" size="sm" className="border-blue-600 text-blue-700 hover:bg-blue-600 hover:text-white gap-2" onClick={() => requestAction("email")}>
                    <Send className="h-4 w-4" />
                    Send Email
                  </Button>
                </div>
              </div>

              <div className="bg-background border border-border rounded-sm overflow-hidden">
                <div className="hidden sm:grid grid-cols-12 gap-4 px-5 py-3 bg-section-light text-xs font-bold uppercase tracking-wider text-muted-foreground border-b">
                  <div className="col-span-5">Product</div>
                  <div className="col-span-2 text-center">Color</div>
                  <div className="col-span-2 text-center">Qty</div>
                  <div className="col-span-2 text-right">Price</div>
                  <div className="col-span-1"></div>
                </div>
                {items.map((item) => (
                  <div key={item.id} className="grid grid-cols-12 gap-4 px-5 py-4 items-center border-b border-border last:border-b-0">
                    <div className="col-span-12 sm:col-span-5">
                      <Link to={`/shop/${item.id}`} className="font-semibold text-sm hover:text-accent transition-colors">{item.name}</Link>
                      <p className="text-xs text-muted-foreground">{item.category}</p>
                    </div>
                    <div className="col-span-4 sm:col-span-2 text-center text-sm text-muted-foreground">{item.color || "—"}</div>
                    <div className="col-span-4 sm:col-span-2 flex items-center justify-center gap-2">
                      <button onClick={() => updateQuantity(item.id, item.quantity - 1)} className="w-7 h-7 border border-border rounded-sm flex items-center justify-center hover:border-accent transition-colors">
                        <Minus className="h-3 w-3" />
                      </button>
                      <span className="w-8 text-center text-sm font-medium">{item.quantity}</span>
                      <button onClick={() => updateQuantity(item.id, item.quantity + 1)} className="w-7 h-7 border border-border rounded-sm flex items-center justify-center hover:border-accent transition-colors">
                        <Plus className="h-3 w-3" />
                      </button>
                    </div>
                    <div className="col-span-3 sm:col-span-2 text-right font-semibold text-sm">{formatPKR(item.price * item.quantity)}</div>
                    <div className="col-span-1 text-right">
                      <button onClick={() => removeItem(item.id)} className="text-muted-foreground hover:text-destructive transition-colors">
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                ))}
                <div className="px-5 py-4 bg-section-light border-t">
                  <div className="flex items-center justify-between mb-1">
                    <span className="font-bold">Estimated Total</span>
                    <span className="text-xl font-black text-accent">{formatPKR(totalPrice)}</span>
                  </div>
                  <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>Advance Payment (75%)</span>
                    <span className="font-semibold text-foreground">{formatPKR(Math.round(totalPrice * 0.75))}</span>
                  </div>
                  <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>Balance Due (25%)</span>
                    <span className="font-semibold text-foreground">{formatPKR(Math.round(totalPrice * 0.25))}</span>
                  </div>
                </div>
              </div>
              <p className="text-xs text-muted-foreground mt-3">* Final pricing may vary based on customization, delivery location, and quantity discounts.</p>
            </div>
          </section>
        )}

        {/* ============ 3-STEP PROCESS FLOW ============ */}
        <section className="py-16 bg-background border-b">
          <div className="container mx-auto px-4">
            <div className="max-w-4xl mx-auto">
              <div className="flex items-center justify-center gap-4 sm:gap-8 flex-wrap">
                {[
                  { icon: ShoppingBag, label: "Add to Cart" },
                  { icon: FileText, label: "Review & Submit", badge: "PKR" },
                  { icon: Download, label: "Download" },
                ].map((step, i, arr) => (
                  <div key={step.label} className="flex items-center gap-4 sm:gap-8">
                    <div className="flex flex-col items-center text-center">
                      <div className="relative w-24 h-24 border-2 border-border rounded-sm flex items-center justify-center bg-section-light hover:border-accent transition-colors group">
                        <step.icon className="h-10 w-10 text-foreground group-hover:text-accent transition-colors" strokeWidth={1.5} />
                        {step.badge && (
                          <span className="absolute bottom-2 right-2 text-[8px] font-black text-accent bg-background border border-accent px-1 rounded-sm">
                            {step.badge}
                          </span>
                        )}
                      </div>
                      <p className="mt-3 text-xs font-bold uppercase tracking-[0.15em] text-foreground">{step.label}</p>
                    </div>
                    {i < arr.length - 1 && (
                      <ArrowRight className="h-6 w-6 text-muted-foreground flex-shrink-0" strokeWidth={2} />
                    )}
                  </div>
                ))}
              </div>

              <p className="text-center text-sm text-muted-foreground mt-10 max-w-2xl mx-auto leading-relaxed">
                If you are having difficulties or require product customization, you can always{" "}
                <Link to="/contact" className="text-accent font-semibold hover:underline">Contact Us</Link>{" "}
                and our consultant will be in touch and assist you with customized office furniture quotations.
              </p>

              <div className="text-center mt-7">
                <Button
                  size="lg"
                  className="bg-primary hover:bg-primary/90 text-primary-foreground font-bold px-8 gap-2 uppercase tracking-wider text-xs"
                  onClick={() => {
                    document.getElementById("quote-form")?.scrollIntoView({ behavior: "smooth" });
                  }}
                >
                  <ArrowRight className="h-4 w-4" /> Create a Quote!
                </Button>
              </div>
            </div>
          </div>
        </section>

        {/* ============ 3 FEATURE CARDS ============ */}
        <section className="py-14 bg-section-light border-b">
          <div className="container mx-auto px-4">
            <div className="grid md:grid-cols-3 gap-5 max-w-5xl mx-auto">
              {[
                { icon: ShoppingBag, title: "Browse Products", desc: "Explore our complete catalog of office furniture and select products that match your requirements." },
                { icon: SlidersHorizontal, title: "Customize Options", desc: "Configure colors, materials, and dimensions for each product to match your exact specifications." },
                { icon: Download, title: "Get Your Quote", desc: "Receive a detailed quotation with all specifications and pricing in PKR. Download as PDF instantly." },
              ].map((card) => (
                <div key={card.title} className="bg-background p-7 rounded-sm border border-border hover:border-accent hover:shadow-lg transition-all">
                  <card.icon className="h-7 w-7 text-accent mb-4" strokeWidth={1.75} />
                  <h3 className="font-bold text-base mb-2">{card.title}</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">{card.desc}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* ============ READY TO START — FORM + BENEFITS ============ */}
        <section id="quote-form" className="py-16 bg-background">
          <div className="container mx-auto px-4">
            <div className="max-w-6xl mx-auto">
              <h2 className="text-3xl lg:text-4xl font-bold mb-10">Ready to Start Your Project?</h2>

              <div className="grid lg:grid-cols-5 gap-12">
                {/* Form — 3 cols */}
                <div className="lg:col-span-3">
                  {submitted ? (
                    <div className="text-center py-16 border border-border rounded-sm bg-section-light">
                      <div className="w-20 h-20 rounded-full bg-hon-green-pale flex items-center justify-center mx-auto mb-5">
                        <CheckCircle className="h-10 w-10 text-accent" />
                      </div>
                      <h3 className="text-2xl font-bold mb-2">Quote Request Submitted!</h3>
                      <p className="text-muted-foreground max-w-sm mx-auto">
                        Thank you! Our team will review your requirements and get back to you within 24 business hours.
                      </p>
                    </div>
                  ) : (
                    <form onSubmit={handleSubmit} className="space-y-5">
                      {items.length > 0 && (
                        <div className="p-4 bg-hon-green-pale rounded-sm border border-accent/30">
                          <p className="text-sm font-semibold text-accent">
                            ✓ {totalItems} product{totalItems !== 1 ? "s" : ""} from your quote basket will be included ({formatPKR(totalPrice)} estimated)
                          </p>
                        </div>
                      )}
                      <div className="grid md:grid-cols-2 gap-5">
                        <div className="space-y-1.5">
                          <Label htmlFor="name">Full Name *</Label>
                          <Input id="name" placeholder="John Doe" required />
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="company">Company Name *</Label>
                          <Input id="company" placeholder="Company Inc." required />
                        </div>
                      </div>
                      <div className="grid md:grid-cols-2 gap-5">
                        <div className="space-y-1.5">
                          <Label htmlFor="email">Email Address *</Label>
                          <Input id="email" type="email" placeholder="john@company.com" required />
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="phone">Phone Number *</Label>
                          <Input id="phone" type="tel" placeholder="+92 300 1234567" required />
                        </div>
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor="project-type">Project Type *</Label>
                        <Input id="project-type" placeholder="e.g., New office setup, Office renovation" required />
                      </div>
                      <Button type="submit" size="lg" className="bg-primary hover:bg-primary/90 text-primary-foreground font-bold px-8 gap-2 uppercase tracking-wider text-xs">
                        <ArrowRight className="h-4 w-4" /> Download Quote!
                      </Button>
                      <p className="text-xs text-muted-foreground">
                        By submitting this form, you agree to our privacy policy. We'll only use your information to provide the requested quotation.
                      </p>
                    </form>
                  )}
                </div>

                {/* Benefits — 2 cols */}
                <div className="lg:col-span-2 space-y-7 lg:pl-6 lg:border-l lg:border-border">
                  {benefits.map((b) => (
                    <div key={b.title} className="flex gap-4 items-start">
                      <ArrowRight className="h-5 w-5 text-accent flex-shrink-0 mt-1" strokeWidth={2.5} />
                      <div>
                        <h4 className="font-bold text-base mb-1">{b.title}</h4>
                        <p className="text-sm text-muted-foreground leading-relaxed">{b.description}</p>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </section>
      </main>

      {/* Client Info Dialog */}
      <Dialog open={showClientDialog} onOpenChange={setShowClientDialog}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Client Information Required</DialogTitle>
            <DialogDescription>Please enter client details to include on the quotation.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4 pt-2">
            <div className="space-y-1.5">
              <Label htmlFor="client-name">Client / Company Name *</Label>
              <Input id="client-name" placeholder="Muhammad Ali / XYZ Corp" value={clientInfo.name} onChange={(e) => setClientInfo(prev => ({ ...prev, name: e.target.value }))} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="client-location">Location / City *</Label>
              <Input id="client-location" placeholder="Lahore, Pakistan" value={clientInfo.location} onChange={(e) => setClientInfo(prev => ({ ...prev, location: e.target.value }))} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="client-contact">Contact Number *</Label>
              <Input id="client-contact" type="tel" placeholder="+92 300 1234567" value={clientInfo.contactNumber} onChange={(e) => setClientInfo(prev => ({ ...prev, contactNumber: e.target.value }))} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="client-whatsapp">WhatsApp Number *</Label>
              <Input id="client-whatsapp" type="tel" placeholder="+92 322 4000768" value={clientInfo.whatsapp} onChange={(e) => setClientInfo(prev => ({ ...prev, whatsapp: e.target.value }))} />
            </div>
            <Button onClick={handleClientDialogSubmit} className="w-full bg-accent hover:bg-hon-green-dark text-accent-foreground font-semibold">
              Continue
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Printable Invoice — hidden on screen, shown on print */}
      <PrintableInvoice
        ref={printRef}
        items={items}
        totalPrice={totalPrice}
        totalItems={totalItems}
        clientInfo={clientInfo}
      />

      <Footer />
    </div>
  );
};

export default Quotation;
