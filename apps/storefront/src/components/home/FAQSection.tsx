import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "@/components/ui/accordion";

const faqs = [
  { q: "What types of furniture does WOODEX manufacture?", a: "WOODEX manufactures a comprehensive range of office and home furniture including executive desks, workstations, ergonomic chairs, meeting tables, reception counters, office sofas, storage solutions, and complete home furniture collections for bedrooms, living rooms, and dining rooms." },
  { q: "Do you offer custom furniture design?", a: "Yes! We specialize in custom furniture manufacturing. Our in-house design team works with you to create furniture tailored to your exact specifications, brand identity, and workflow requirements." },
  { q: "What is the typical delivery timeline?", a: "Standard orders are delivered within 2–4 weeks. Custom furniture projects typically take 4–6 weeks depending on complexity. We also offer QuickShip options for select products with delivery within 7 business days." },
  { q: "Do you provide installation services?", a: "Absolutely. All WOODEX furniture comes with complimentary professional installation within Lahore. For other cities, our trained installation teams ensure your furniture is set up correctly and ready to use." },
  { q: "What warranty do you offer?", a: "We offer a comprehensive 5-year structural warranty on all WOODEX furniture, covering manufacturing defects in materials and workmanship. Extended warranty options are also available." },
  { q: "Can I visit your showroom?", a: "Yes, our showroom is located at 123 Gulberg III, Main Boulevard, Lahore. We're open Monday to Saturday, 9am to 7pm. You can also explore our Virtual Showroom online for an immersive experience." },
];

const FAQSection = () => (
  <section className="py-16 bg-background border-t">
    <div className="container mx-auto px-4 max-w-3xl">
      <div className="text-center mb-10">
        <div className="w-12 h-1 bg-accent mx-auto mb-5" />
        <h2 className="text-3xl font-bold mb-2">FAQs — WOODEX</h2>
        <p className="text-muted-foreground text-sm">Modern Office Furniture · Lahore, Pakistan</p>
      </div>
      <Accordion type="single" collapsible className="space-y-3">
        {faqs.map((faq, i) => (
          <AccordionItem key={i} value={`faq-${i}`} className="border border-border rounded-sm px-5">
            <AccordionTrigger className="text-sm font-semibold text-left hover:text-accent">{faq.q}</AccordionTrigger>
            <AccordionContent className="text-sm text-muted-foreground leading-relaxed">{faq.a}</AccordionContent>
          </AccordionItem>
        ))}
      </Accordion>
    </div>
  </section>
);

export default FAQSection;
