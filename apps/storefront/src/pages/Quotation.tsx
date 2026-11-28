import LeadChallenge from "@/components/LeadChallenge";
import { redeemLeadHandoff } from "@/lib/leadCapture";
import { basketDestination, getLeadAttribution, logManualWhatsAppHandoff, submitLead, submitQuoteRequest, useRuntimeRevision } from "@/lib/runtime";
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
import { formatPKR, products, optionPrice, categoryLabel } from "@/data/products";
import type { Product, ProductVariant } from "@/data/products";
import { keyOf } from "@/contexts/lineKey";
import type { QuoteItem } from "@/contexts/QuoteContext";
import { brand } from "@/config/brand";
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
  /* Whether what was received was a basket that the admin keeps. The confirmation says "Pending staff review"
     for it, and cannot ask the basket: the basket is emptied once it has been received. */
  const [basketWithTheTeam, setBasketWithTheTeam] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState("");
  const [productSearch, setProductSearch] = useState("");
  const [showProductSearch, setShowProductSearch] = useState(false);
  const catalogRevision = useRuntimeRevision();
  /* Where this basket goes (lib/runtime.ts). "legacy" is this shop's own API; the other two are the Laravel
     admin, which keeps a basket for its team to review and does not log a WhatsApp click against it. Read on
     every render: the catalogue, and with it the destination, arrives after the page has mounted. */
  const destination = basketDestination();
  const toLaravel = destination !== "legacy";
  const toAdmin = destination === "admin";
  const { items, removeItem, updateQuantity, estimateText, totalItems, onRequestCount, issueCount, addItem, clearQuote } = useQuote();
  const printRef = useRef<HTMLDivElement>(null);

  // Client info state
  const [clientInfo, setClientInfo] = useState<ClientInfo & { contactConsent: boolean }>({ name: "", location: "", contactNumber: "", whatsapp: "", contactConsent: false });
  const [showClientDialog, setShowClientDialog] = useState(false);
  const [pendingAction, setPendingAction] = useState<"print" | "email" | "whatsapp" | null>(null);
  const [manualHandoff, setManualHandoff] = useState<{ leadId: number; ref: string; interest: string } | null>(null);
  const [manualHandoffUrl, setManualHandoffUrl] = useState("");
  const basketRequestKey = useRef(crypto.randomUUID());
  const [submissionReference, setSubmissionReference] = useState("");
  const [versionedHandoff, setVersionedHandoff] = useState<string | null>(null);
  const [handoffBusy, setHandoffBusy] = useState(false);

  /* One row per thing that can be quoted: a product, or — for a product sold
     in options — each of its options. A product that is switched off is left
     out; the API would refuse a request that names it. */
  const searchResults = useMemo(() => {
    const q = productSearch.trim().toLowerCase();
    if (!q) return [];
    const rows: { product: Product; variant: ProductVariant | null }[] = [];
    for (const p of products) {
      if (!p.inStock) continue;
      const hit = [p.name, p.shortDescription, p.sku ?? ""].join(" ").toLowerCase().includes(q);
      const options = p.variants ?? [];
      if (options.length === 0) {
        if (hit) rows.push({ product: p, variant: null });
      } else {
        for (const v of options) {
          if (hit || [v.label, v.sku].join(" ").toLowerCase().includes(q)) rows.push({ product: p, variant: v });
        }
      }
      if (rows.length >= 8) break;
    }
    return rows.slice(0, 8);
  }, [productSearch, catalogRevision]);

  /* What a line is called and priced as, wherever it is written out. */
  const lineTitle = (item: QuoteItem) => item.name + (item.variant ? ` — ${item.variant}` : "");
  const lineAmount = (item: QuoteItem) => (item.priceOnRequest ? "price on request" : formatPKR(item.price * item.quantity));
  /* What the API needs to identify a line. It looks up the price itself. */
  const lineRequest = (item: QuoteItem) => ({
    productId: item.id, name: item.name, qty: item.quantity ?? 1, material: item.color,
    ...(item.variantId != null ? { variantId: item.variantId } : {}),
    /* The Laravel admin knows an option by its code, not by a number of this shop (lib/quoteCapture.ts). */
    ...(toAdmin && item.variantId != null && item.sku ? { sku: item.sku } : {}),
  });
  const blockedByIssues = () => {
    if (issueCount === 0) return false;
    toast.error(`${issueCount === 1 ? "One item" : `${issueCount} items`} in your basket can no longer be quoted. Remove ${issueCount === 1 ? "it" : "them"} to continue.`);
    return true;
  };

  useEffect(() => {
    document.title = "Get E-Quotation — WOODEX Pakistan | Free Quote Request";
  }, []);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    const name = String(fd.get("name") || "").trim();
    const company = String(fd.get("company") || "").trim();
    const email = String(fd.get("email") || "").trim();
    const phone = String(fd.get("phone") || "").trim();
    const projectType = String(fd.get("projectType") || "General quotation request").trim();
    const contactConsent = fd.get("contactConsent") === "on";
    const preferredChannel = String(fd.get("preferredChannel") || "email");
    const contact = [email, phone].filter(Boolean).join(" · ");
    const attribution = getLeadAttribution();
    if (blockedByIssues()) return;
    setSubmitting(true);
    setSubmitError("");
    try {
      const result = items.length > 0
        ? await submitQuoteRequest({
            requestKey: basketRequestKey.current,
            turnstile_token: String(fd.get("turnstile_token") || ""),
            customer: name,
            company,
            email,
            phone,
            contact,
            // `productId` (and the option) is what the server prices from; nothing the page shows is trusted.
            items: items.map(lineRequest),
            source: "storefront",
            leadSource: "E-Quotation Form",
            captureLead: true,
            interest: projectType,
            contactConsent,
            preferredChannel,
            note: projectType,
            ...attribution,
          })
        : await submitLead({
            name,
            company,
            email,
            phone,
            contact,
            interest: projectType,
            source: "E-Quotation Form",
            contactConsent,
            preferredChannel,
            note: "Quote request submitted without a product basket.",
            turnstile_token: String(fd.get("turnstile_token") || ""),
            ...attribution,
          });
      if (!result) throw new Error("The request could not be saved. Please try again.");
      setVersionedHandoff(preferredChannel === "whatsapp" ? result.handoffToken ?? null : null);
      if (preferredChannel === "whatsapp" && result.handoffUnavailable) {
        toast.info(`Enquiry ${result.ref} saved. WhatsApp handoff is currently unavailable; our team will follow up.`);
      }
      const leadId = Number(result.leadId ?? result.id);
      if (preferredChannel === "whatsapp" && Number.isSafeInteger(leadId) && leadId > 0) {
        setManualHandoff({ leadId, ref: String(result.ref ?? ""), interest: projectType });
        setManualHandoffUrl("");
      } else {
        setManualHandoff(null);
        setManualHandoffUrl("");
      }
      setSubmissionReference(String(result.ref ?? ""));
      /* The admin has received the basket, so it is emptied, as a cart is after its order (pages/Checkout.tsx).
         Until 28 November it stayed: the header went on counting products that were already with the team, and
         the same basket could be sent a second time from the next page (defect D-086). A basket filled after
         this one is a new request and gets a key of its own. A basket sent to this shop's own database is left
         as it was: there the builder above this form still prints it and sends it by WhatsApp. */
      if (items.length > 0 && toLaravel) {
        setBasketWithTheTeam(true);
        clearQuote();
        basketRequestKey.current = crypto.randomUUID();
      }
      setSubmitted(true);
    } catch (error) {
      const message = error instanceof Error ? error.message : "The request could not be saved. Please try again.";
      setSubmitError(message);
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const isClientInfoValid = clientInfo.name.trim() && clientInfo.location.trim() && clientInfo.contactNumber.trim() && clientInfo.whatsapp.trim();

  const buildManualWhatsAppHref = (ref: string, interest: string) => {
    const message = encodeURIComponent(`Hello Woodex, I submitted quote request${ref ? ` ${ref}` : ""}${interest ? ` about ${interest}` : ""}. Please follow up with me.`);
    return `https://wa.me/${brand.whatsapp}?text=${message}`;
  };

  const openLoggedWhatsApp = async (handoff: { leadId: number; ref: string; interest: string }) => {
    const popup = window.open("about:blank", "_blank");
    setHandoffBusy(true);
    try {
      const logged = await logManualWhatsAppHandoff(handoff.leadId);
      if (!logged?.logged || logged.delivery !== "manual_handoff_not_sent")
        throw new Error("The handoff could not be logged. Please retry before opening WhatsApp.");
      const url = buildManualWhatsAppHref(handoff.ref, handoff.interest);
      setManualHandoffUrl(url);
      if (popup) { popup.opener = null; popup.location.href = url; }
      toast.success("Handoff logged. Review and send the message yourself in WhatsApp; Woodex did not send it.");
    } catch (error) {
      popup?.close();
      toast.error(error instanceof Error ? error.message : "The handoff could not be logged.");
    } finally { setHandoffBusy(false); }
  };

  const openVersionedWhatsApp = async () => {
    if (!versionedHandoff) return;
    const popup = window.open("about:blank", "_blank");
    if (popup) popup.opener = null;
    setHandoffBusy(true);
    try {
      const url = await redeemLeadHandoff(versionedHandoff);
      setManualHandoffUrl(url);
      if (popup) popup.location.href = url;
      toast.success("Handoff logged. Review and send the message yourself in WhatsApp; Woodex did not send it.");
    } catch (error) {
      popup?.close();
      toast.error(error instanceof Error ? error.message : "The handoff could not be logged.");
    } finally { setHandoffBusy(false); }
  };

  const createAndOpenBuilderHandoff = async () => {
    if (toLaravel) {
      toast.info("Use the basket request form below to save your enquiry. Builder WhatsApp is not available on the new API yet.");
      return;
    }
    if (!clientInfo.contactConsent) {
      toast.error("Please confirm permission for follow-up before opening WhatsApp.");
      return;
    }
    const popup = window.open("about:blank", "_blank");
    setHandoffBusy(true);
    try {
      let handoff = manualHandoff;
      if (!handoff) {
        const interest = `Quotation request: ${items.map((item) => item.name).slice(0, 6).join(", ")}`;
        const result = await submitQuoteRequest({
          customer: clientInfo.name, contact: [clientInfo.contactNumber, clientInfo.whatsapp].filter(Boolean).join(" · "),
          phone: clientInfo.whatsapp, siteAddress: clientInfo.location, source: "storefront", leadSource: "E-Quotation Builder",
          captureLead: true, interest, note: interest, preferredChannel: "whatsapp", contactConsent: true,
          ...getLeadAttribution(),
          /* `productId` is mandatory: the server re-prices every anonymous line from
             the catalog and zeroes any item it cannot identify. Omitting it (as this
             path used to) saved a quotation with a PKR 0 total. */
          items: items.map(lineRequest),
        });
        const leadId = Number(result?.leadId);
        if (!result || !Number.isSafeInteger(leadId) || leadId < 1) throw new Error("The quotation request could not be saved.");
        handoff = { leadId, ref: String(result.ref ?? ""), interest };
        setManualHandoff(handoff);
      }
      const logged = await logManualWhatsAppHandoff(handoff.leadId);
      if (!logged?.logged || logged.delivery !== "manual_handoff_not_sent")
        throw new Error("The request was saved but its WhatsApp handoff could not be logged. Retry the handoff.");
      const url = buildManualWhatsAppHref(handoff.ref, handoff.interest);
      setManualHandoffUrl(url);
      if (popup) { popup.opener = null; popup.location.href = url; }
      toast.success("Handoff logged. Review and send the message yourself in WhatsApp; Woodex did not send it.");
    } catch (error) {
      popup?.close();
      toast.error(error instanceof Error ? error.message : "Could not prepare the WhatsApp handoff.");
    } finally { setHandoffBusy(false); }
  };

  const requestAction = (action: "print" | "email" | "whatsapp") => {
    if (items.length === 0) {
      toast.error("Please add at least one product to your quote basket first.");
      return;
    }
    if (blockedByIssues()) return;
    if (!isClientInfoValid || (action === "whatsapp" && !clientInfo.contactConsent)) {
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
      const itemsList = items.map((item, i) => `${i + 1}. ${lineTitle(item)}${item.sku ? ` [${item.sku}]` : ""} x${item.quantity} — ${lineAmount(item)}`).join("\n");
      const body = encodeURIComponent(
        `Quotation Request\n\nClient: ${clientInfo.name}\nLocation: ${clientInfo.location}\nContact: ${clientInfo.contactNumber}\nWhatsApp: ${clientInfo.whatsapp}\n\nProducts:\n${itemsList}\n\nEstimated Total: ${estimateText}\nFinal payment terms will be included in formal quotation.`
      );
      window.open(`${brand.emailLink}?subject=${subject}&body=${body}`, "_blank");
      toast.success("Email draft opened. Review and send it yourself; Woodex did not send it automatically.");
    } else if (action === "whatsapp") {
      void createAndOpenBuilderHandoff();
    }
  };

  const handleClientDialogSubmit = () => {
    if (!isClientInfoValid) {
      toast.error("Please fill in all client details.");
      return;
    }
    if (pendingAction === "whatsapp" && !clientInfo.contactConsent) {
      toast.error("Please confirm permission for Woodex to follow up using WhatsApp.");
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
                  aria-label="Search products by name"
                  placeholder="Search products by name... (e.g. BALLMER, ergonomic chair)"
                  value={productSearch}
                  onChange={(e) => { setProductSearch(e.target.value); setShowProductSearch(true); }}
                  onFocus={() => setShowProductSearch(true)}
                  className="pl-10"
                />
                {showProductSearch && searchResults.length > 0 && (
                  <div className="absolute z-20 top-full left-0 right-0 mt-1 bg-background border border-border rounded-sm shadow-lg max-h-80 overflow-y-auto">
                    {searchResults.map(({ product: p, variant }) => {
                      const price = optionPrice(p, variant);
                      return (
                        <button
                          key={p.id + "|" + (variant?.id ?? "")}
                          type="button"
                          className="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-section-light transition-colors border-b border-border last:border-b-0"
                          onClick={() => {
                            addItem({
                              id: p.id, name: p.name, category: p.category, image: p.images[0],
                              price: price ?? 0, priceOnRequest: price === null,
                              variantId: variant?.id ?? null, variant: variant?.label, sku: variant?.sku || p.sku || null,
                            });
                            setProductSearch("");
                            setShowProductSearch(false);
                          }}
                        >
                          {p.images[0]
                            ? <img src={p.images[0]} alt="" className="w-10 h-10 object-cover rounded-sm flex-shrink-0" />
                            : <span className="w-10 h-10 rounded-sm bg-muted flex-shrink-0" aria-hidden="true" />}
                          <div className="flex-1 min-w-0">
                            <p className="text-sm font-semibold truncate">{p.name}{variant ? ` — ${variant.label}` : ""}</p>
                            <p className="text-xs text-muted-foreground">{categoryLabel(p.subcategory || p.category)}</p>
                          </div>
                          <span className="text-sm font-bold text-accent flex-shrink-0">{price === null ? "On request" : formatPKR(price)}</span>
                        </button>
                      );
                    })}
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
                    Print / Save PDF
                  </Button>
                  {/* Not offered where the basket goes to the Laravel admin: that click cannot be logged there, and
                      a button that only answers "not available" is no button. The form below records WhatsApp as
                      the way the visitor wants to be answered. */}
                  {!toLaravel && (
                    <Button variant="outline" size="sm" disabled={handoffBusy} className="border-green-600 text-green-700 hover:bg-green-600 hover:text-white gap-2" onClick={() => requestAction("whatsapp")}>
                      <MessageCircle className="h-4 w-4" />
                      Log &amp; open WhatsApp
                    </Button>
                  )}
                  <Button variant="outline" size="sm" className="border-blue-600 text-blue-700 hover:bg-blue-600 hover:text-white gap-2" onClick={() => requestAction("email")}>
                    <Send className="h-4 w-4" />
                    Draft Email
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
                  <div key={keyOf(item)} className="grid grid-cols-12 gap-4 px-5 py-4 items-center border-b border-border last:border-b-0">
                    <div className="col-span-12 sm:col-span-5">
                      <Link to={`/shop/${item.id}`} className="font-semibold text-sm hover:text-accent transition-colors">{lineTitle(item)}</Link>
                      <p className="text-xs text-muted-foreground">{categoryLabel(item.category)}{item.sku ? ` · ${item.sku}` : ""}</p>
                      {item.issue && <p className="text-xs text-destructive mt-0.5">{item.issue}</p>}
                    </div>
                    <div className="col-span-4 sm:col-span-2 text-center text-sm text-muted-foreground">{item.color || "—"}</div>
                    <div className="col-span-4 sm:col-span-2 flex items-center justify-center gap-2">
                      <button type="button" aria-label={`One fewer ${item.name}`} onClick={() => updateQuantity(keyOf(item), item.quantity - 1)} className="w-7 h-7 border border-border rounded-sm flex items-center justify-center hover:border-accent transition-colors">
                        <Minus className="h-3 w-3" />
                      </button>
                      <span className="w-8 text-center text-sm font-medium">{item.quantity}</span>
                      <button type="button" aria-label={`One more ${item.name}`} onClick={() => updateQuantity(keyOf(item), item.quantity + 1)} className="w-7 h-7 border border-border rounded-sm flex items-center justify-center hover:border-accent transition-colors">
                        <Plus className="h-3 w-3" />
                      </button>
                    </div>
                    <div className="col-span-3 sm:col-span-2 text-right font-semibold text-sm">{item.issue ? "—" : item.priceOnRequest ? "On request" : formatPKR(item.price * item.quantity)}</div>
                    <div className="col-span-1 text-right">
                      <button type="button" aria-label={`Remove ${item.name}`} onClick={() => removeItem(keyOf(item))} className="text-muted-foreground hover:text-destructive transition-colors">
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                ))}
                <div className="px-5 py-4 bg-section-light border-t">
                  <div className="flex items-center justify-between mb-1">
                    <span className="font-bold">Estimated Total</span>
                    <span className="text-xl font-black text-accent">{estimateText}</span>
                  </div>
                  {onRequestCount > 0 && (
                    <p className="text-xs text-muted-foreground">
                      We will send the price of the {onRequestCount === 1 ? "item" : `${onRequestCount} items`} priced on request in the quotation.
                    </p>
                  )}
                  <p className="mt-2 text-xs text-muted-foreground">Final payment terms will be included in formal quotation.</p>
                </div>
              </div>
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
                      {submissionReference && <p className="mb-2 break-all text-sm font-semibold">Reference: {submissionReference}</p>}
                      {basketWithTheTeam && <p className="mb-2 text-sm">Pending staff review. This is not an issued quotation, order or stock reservation.</p>}
                      <p className="text-muted-foreground max-w-sm mx-auto">
                        Thank you! Our team will review your requirements and follow up through your selected contact method.
                      </p>
                      {(manualHandoff || versionedHandoff) && (
                        <div className="mx-auto mt-5 max-w-sm space-y-2">
                          <p className="text-xs text-muted-foreground">WhatsApp is a manual handoff. This click is logged against your enquiry; Woodex does not send or receive a message automatically.{versionedHandoff ? " The secure link expires after 15 minutes." : ""}</p>
                          <Button type="button" disabled={handoffBusy} onClick={() => { if (versionedHandoff) void openVersionedWhatsApp(); else if (manualHandoff) void openLoggedWhatsApp(manualHandoff); }} className="bg-accent hover:bg-hon-green-dark text-accent-foreground">
                            <MessageCircle className="mr-2 h-4 w-4" /> {handoffBusy ? "Logging handoff…" : "Log & open WhatsApp"}
                          </Button>
                          {manualHandoffUrl && <a href={manualHandoffUrl} target="_blank" rel="noopener noreferrer" className="block text-sm font-semibold text-accent underline">Open WhatsApp manually</a>}
                        </div>
                      )}
                    </div>
                  ) : (
                    <form onSubmit={handleSubmit} className="space-y-5">
                      {items.length > 0 && (
                        <div className="p-4 bg-hon-green-pale rounded-sm border border-accent/30">
                          <p className="text-sm font-semibold text-accent">
                            ✓ {totalItems} product{totalItems !== 1 ? "s" : ""} from your quote basket will be included (estimate: {estimateText})
                          </p>
                        </div>
                      )}
                      <div className="grid md:grid-cols-2 gap-5">
                        <div className="space-y-1.5">
                          <Label htmlFor="name">Full Name *</Label>
                          <Input id="name" name="name" autoComplete="name" placeholder="John Doe" required />
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="company">Company Name *</Label>
                          <Input id="company" name="company" autoComplete="organization" placeholder="Company Inc." required />
                        </div>
                      </div>
                      <div className="grid md:grid-cols-2 gap-5">
                        <div className="space-y-1.5">
                          <Label htmlFor="email">Email Address *</Label>
                          <Input id="email" name="email" type="email" autoComplete="email" placeholder="john@company.com" required />
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="phone">Phone Number *</Label>
                          <Input id="phone" name="phone" type="tel" autoComplete="tel" placeholder="+92 3XX XXXXXXX" required />
                        </div>
                      </div>
                      <div className="grid md:grid-cols-2 gap-5">
                        <div className="space-y-1.5">
                          <Label htmlFor="project-type">Project Type *</Label>
                          <Input id="project-type" name="projectType" placeholder="e.g., New office setup, Office renovation" required />
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="preferred-channel">Preferred contact method *</Label>
                          <select id="preferred-channel" name="preferredChannel" defaultValue="email" className="h-10 w-full rounded-sm border border-input bg-background px-3 py-2 text-sm">
                            <option value="email">Email</option>
                            <option value="phone">Phone call</option>
                            <option value="whatsapp">WhatsApp</option>
                          </select>
                        </div>
                      </div>
                      <label className="flex items-start gap-2.5 text-sm text-muted-foreground">
                        <input name="contactConsent" type="checkbox" required className="mt-1 accent-[hsl(var(--accent))]" />
                        <span>I agree that WOODEX may contact me about this quote request using my selected method.</span>
                      </label>
                      <LeadChallenge mode={items.length > 0 ? (toLaravel ? "v1" : "legacy") : import.meta.env.VITE_LEAD_API_MODE} />
                      {submitError && <p role="alert" className="text-sm text-destructive">{submitError}</p>}
                      <Button type="submit" size="lg" disabled={submitting} className="bg-primary hover:bg-primary/90 text-primary-foreground font-bold px-8 gap-2 uppercase tracking-wider text-xs">
                        <ArrowRight className="h-4 w-4" /> {submitting ? "Submitting…" : "Submit Quote Request"}
                      </Button>
                      <p className="text-xs text-muted-foreground">
                        We'll use your details only to respond to this project enquiry. Selecting WhatsApp records your preference but does not send a WhatsApp message automatically.
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
            <DialogDescription>{pendingAction === "whatsapp" ? "Enter details and consent to log a manual WhatsApp handoff. Woodex will not send a message automatically." : "Please enter client details to include on the quotation."}</DialogDescription>
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
              <Input id="client-contact" type="tel" placeholder="+92 3XX XXXXXXX" value={clientInfo.contactNumber} onChange={(e) => setClientInfo(prev => ({ ...prev, contactNumber: e.target.value }))} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="client-whatsapp">WhatsApp Number *</Label>
              <Input id="client-whatsapp" type="tel" placeholder={brand.phone} value={clientInfo.whatsapp} onChange={(e) => setClientInfo(prev => ({ ...prev, whatsapp: e.target.value }))} />
            </div>
            {pendingAction === "whatsapp" && (
              <label className="flex items-start gap-2.5 text-sm text-muted-foreground">
                <input type="checkbox" checked={clientInfo.contactConsent} onChange={(e) => setClientInfo((prev) => ({ ...prev, contactConsent: e.target.checked }))} className="mt-1 accent-[hsl(var(--accent))]" />
                <span>I agree that Woodex may follow up about this quotation using WhatsApp. I will review and send the opened message myself.</span>
              </label>
            )}
            <Button onClick={handleClientDialogSubmit} className="w-full bg-accent hover:bg-hon-green-dark text-accent-foreground font-semibold">
              Continue
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Printable Invoice — hidden on screen, shown on print */}
      {/* Lines that can no longer be quoted are not printed: the estimate
          would list something the shop does not offer. */}
      <PrintableInvoice
        ref={printRef}
        items={items.filter((item) => !item.issue)}
        totalItems={items.filter((item) => !item.issue).reduce((sum, item) => sum + item.quantity, 0)}
        quoteNumber={manualHandoff?.ref || undefined}
        clientInfo={clientInfo}
      />

      <Footer />
    </div>
  );
};

export default Quotation;
