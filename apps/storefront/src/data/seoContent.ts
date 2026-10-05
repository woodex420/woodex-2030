// ─── SEO + AIO CONTENT LIBRARY ──────────────────────────────────────────
// Rich, AI-search-optimized content for every category and series page.
// Used by Shop.tsx, Series.tsx, SeriesDetail.tsx to render structured
// long-form copy + FAQs + JSON-LD schema for Google & AI assistants.

export interface SeoSection {
  h2: string;
  body: string;
  bullets?: string[];
}

export interface SeoFaq {
  q: string;
  a: string;
}

export interface SeoBlock {
  title: string;            // <title> tag
  metaDescription: string;  // <meta description> (≤160 chars)
  h1?: string;              // optional override for page H1
  intro: string;            // 2–3 sentence lead paragraph (AIO-friendly direct answer)
  keywords: string[];       // primary + secondary keywords for context
  sections: SeoSection[];   // H2 long-form sections
  faqs: SeoFaq[];           // FAQPage JSON-LD source
}

// Helper to build FAQPage JSON-LD
export const buildFaqJsonLd = (faqs: SeoFaq[]) => ({
  "@context": "https://schema.org",
  "@type": "FAQPage",
  mainEntity: faqs.map((f) => ({
    "@type": "Question",
    name: f.q,
    acceptedAnswer: { "@type": "Answer", text: f.a },
  })),
});

// Helper to build CollectionPage JSON-LD
export const buildCollectionJsonLd = (name: string, description: string, url: string) => ({
  "@context": "https://schema.org",
  "@type": "CollectionPage",
  name,
  description,
  url,
  isPartOf: { "@type": "WebSite", name: "WOODEX", url: "https://woodex-reimagined.lovable.app" },
});

// Shared FAQs reused across furniture categories
const sharedFaqs: SeoFaq[] = [
  { q: "Does WOODEX deliver across Pakistan?", a: "Yes. WOODEX delivers nationwide from our Lahore manufacturing facility — including Karachi, Islamabad, Rawalpindi, Faisalabad, Multan, Peshawar, Quetta, Sialkot, Gujranwala, and surrounding regions. Major-city delivery and installation are included free." },
  { q: "Are prices in PKR and inclusive of GST?", a: "All prices are listed in Pakistani Rupees (PKR). For B2B orders we issue GST invoices on request, and bulk pricing is available through our E-Quotation system." },
  { q: "Can I order in bulk for my office or project?", a: "Absolutely. WOODEX specialises in B2B fit-outs from 5 to 5,000+ workstations. Use the E-Quotation basket to build a project list, then our team responds with a tailored proposal within 24 hours." },
  { q: "What payment methods do you accept?", a: "B2C orders accept Cash on Delivery (COD) and bank transfer. B2B projects run on a 75% advance / 25% on installation model with cheque, bank transfer, or pay order." },
];

// ─── CATEGORY SEO CONTENT ───────────────────────────────────────────────
export const categorySeo: Record<string, SeoBlock> = {
  all: {
    title: "Shop Office & Home Furniture in Pakistan — WOODEX",
    metaDescription: "Buy premium office and home furniture in Pakistan. Executive desks, ergonomic chairs, workstations, beds, sofas & more. Made in Lahore, delivered nationwide.",
    intro: "WOODEX is Pakistan's premium furniture manufacturer, producing executive desks, ergonomic office chairs, modular workstations, bedroom sets, sofas, and dining furniture from our Lahore facility. Every product is built to commercial-grade standards, priced in PKR, and delivered nationwide with free assembly in major cities.",
    keywords: ["furniture Pakistan", "office furniture Lahore", "home furniture Karachi", "buy furniture online Pakistan"],
    sections: [
      { h2: "Why choose WOODEX furniture in Pakistan?", body: "We combine traditional Pakistani craftsmanship with modern manufacturing — CNC-cut frames, kiln-dried hardwoods, BIFMA-grade hardware, and powder-coated steel. Whether you are furnishing a single home office or a 200-seat corporate floor, WOODEX gives you a single accountable partner from design to installation.", bullets: ["Made in Pakistan — no import duties, no shipping delays", "Direct-from-factory pricing in PKR", "Free delivery & installation in Lahore, Karachi, Islamabad", "3–10 year warranty depending on series"] },
      { h2: "Office furniture for every workspace", body: "Browse executive tables, manager desks, staff workstations, ergonomic chairs, meeting tables, reception counters, cubicle systems, and office storage. Every office category is engineered for 8+ hour daily use and backed by our commercial warranty." },
      { h2: "Home furniture that lasts generations", body: "Our home collection covers complete bedroom sets, living room sofas, coffee and console tables, TV units, and dining sets — all built from solid hardwood and premium veneer with finishes designed for Pakistan's climate." },
      { h2: "Built for B2B projects and individual buyers", body: "Use our E-Quotation system for offices, hospitals, schools, hotels, and co-working spaces. Individual shoppers can checkout instantly via Cash on Delivery or bank transfer. No minimum order quantity." },
    ],
    faqs: [
      ...sharedFaqs,
      { q: "How long does manufacturing take?", a: "Standard catalogue items ship in 7–10 working days. Custom or bulk B2B orders typically take 3–5 weeks depending on quantity and finish." },
    ],
  },

  // ─── OFFICE PARENT CATEGORIES ─────────────────────────────────────────
  "office-tables": {
    title: "Office Tables in Pakistan — Executive, Manager & Staff Desks | WOODEX",
    metaDescription: "Shop premium office tables in Pakistan: executive desks, manager tables, staff workstations, meeting tables & reception counters. Made in Lahore, delivered nationwide.",
    intro: "WOODEX office tables are engineered for Pakistani offices that demand durability, cable management, and a commanding aesthetic. Choose from executive desks, manager tables, staff workstations, meeting tables, and reception counters — all available in walnut, oak, espresso, and white finishes.",
    keywords: ["office table Pakistan", "executive desk Lahore", "office desk price PKR"],
    sections: [
      { h2: "Types of office tables we manufacture", body: "Our office table line covers every hierarchy and use-case in a modern workspace.", bullets: ["Executive Tables — 1.8m–2.4m, premium veneer, side returns", "Manager Tables — 1.6m–1.8m with pedestals", "Staff Tables — 1.2m–1.5m space-efficient desks", "Meeting Tables — 6 to 20-seater boardroom", "Reception Tables — branded front-desk counters"] },
      { h2: "Materials & build quality", body: "All WOODEX tables use 25–36mm engineered or solid wood tops with edge-banded finishes, powder-coated steel frames, and German-style cam-lock joinery. Optional grommets, wire trays, and pedestal locks are factory-fitted." },
      { h2: "Customisation for B2B office projects", body: "Sizes, finishes, branding inlays, and accessory packages are fully customisable for orders of 10+ units. Lead time for custom office tables is 3–4 weeks." },
    ],
    faqs: [
      { q: "What is the standard size of an executive office table?", a: "WOODEX executive tables range from 1.8m × 0.9m up to 2.4m × 1.1m with optional side returns. Custom dimensions are available for B2B orders." },
      { q: "Do office tables include cable management?", a: "Yes — all WOODEX office tables ship with grommet holes and an under-desk wire tray as standard. Pop-up power modules can be added." },
      ...sharedFaqs.slice(0, 2),
    ],
  },
  "executive-tables": {
    title: "Executive Office Tables in Pakistan — Premium CEO Desks | WOODEX",
    metaDescription: "Premium executive office tables in Pakistan. Solid wood, veneer & leather-top CEO desks with side returns. Direct from WOODEX Lahore factory.",
    intro: "Executive tables from WOODEX are designed for C-suite offices, directors, and senior management who want a desk that commands the room. Available in 1.8m to 2.4m widths with optional side returns, leather inlays, and integrated pedestals.",
    keywords: ["executive table Pakistan", "CEO desk Lahore", "boss table price"],
    sections: [
      { h2: "What makes an executive table 'executive'?", body: "Width over 1.8m, premium materials (veneer, leather, or solid hardwood), a side return for visitor seating or extra surface, and integrated storage. WOODEX executive desks meet all four criteria as standard." },
      { h2: "Finishes available", body: "Dark walnut, natural oak, espresso, high-gloss white, and full-grain leather tops. Custom RAL colours available on bulk orders." },
      { h2: "Best-selling executive table series", body: "The Woodex Series flagship line uses solid hardwood; the Infinity Series offers modular executive layouts with matching credenzas and bookshelves." },
    ],
    faqs: [
      { q: "What is the price of an executive table in Pakistan?", a: "WOODEX executive tables start at approximately PKR 95,000 for the Ek Series and go up to PKR 450,000 for the flagship Woodex Series. Use our E-Quotation tool for an exact, current quote." },
      { q: "Can I add my company logo to the desk?", a: "Yes. Laser-etched or brass-inlay logos are available on executive tables for an additional charge, typically delivered in 4 weeks." },
      ...sharedFaqs.slice(0, 2),
    ],
  },
  "manager-tables": {
    title: "Manager Office Tables in Pakistan — Mid-Level Desks | WOODEX",
    metaDescription: "Manager office tables built for Pakistani mid-management. 1.6m–1.8m desks with pedestals, cable management & 5-year warranty. Made in Lahore.",
    intro: "WOODEX manager tables strike the balance between executive presence and floor-space efficiency. Ideal for department heads, team leads, and senior associates in offices across Lahore, Karachi, and Islamabad.",
    keywords: ["manager table Pakistan", "office desk manager", "mid level desk"],
    sections: [
      { h2: "Designed for department heads", body: "1.6m–1.8m widths fit comfortably in glass-partitioned cabins while still offering an L-shape return option for additional surface area." },
      { h2: "Built-in storage", body: "Every manager table ships with a fixed or mobile 3-drawer pedestal with central key-locking. Hanging file rails fit standard A4 folders." },
    ],
    faqs: [
      { q: "What's the difference between a manager and executive table?", a: "Manager tables are typically 1.6m–1.8m wide with a single pedestal, while executive tables are 1.8m–2.4m with side returns and premium materials like leather or solid hardwood." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  "staff-tables": {
    title: "Staff Office Tables in Pakistan — Workstation Desks | WOODEX",
    metaDescription: "Affordable staff office tables for Pakistani workplaces. 1.2m–1.5m desks with cable trays. Bulk pricing for offices, schools & call centres.",
    intro: "Staff tables from WOODEX are space-efficient, durable, and built for high-volume corporate roll-outs. Standard sizes from 1.2m × 0.6m to 1.5m × 0.75m with optional screen dividers and shared pedestals.",
    keywords: ["staff table Pakistan", "office workstation desk", "employee desk bulk"],
    sections: [
      { h2: "Bulk pricing for offices", body: "Orders of 20+ staff tables qualify for project pricing — typically 15–25% below catalogue rates. Mix-and-match colours within a single order are supported." },
      { h2: "Linear vs cluster layouts", body: "Choose linear bench-style layouts for open offices or 4-pod cluster configurations for team collaboration. Our space-planning team provides a free CAD layout on B2B enquiries." },
    ],
    faqs: [
      { q: "What is the standard staff desk size in Pakistan?", a: "The most common WOODEX staff desk is 1.2m × 0.6m, which fits standard office layouts with 1.5m aisle spacing." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  "meeting-tables": {
    title: "Meeting & Conference Tables in Pakistan | WOODEX",
    metaDescription: "Boardroom & conference meeting tables in Pakistan. 6 to 20-seater designs with cable boxes & AV cutouts. Made in Lahore by WOODEX.",
    intro: "WOODEX meeting tables are built for Pakistani boardrooms, conference rooms, and huddle spaces. Available in rectangular, boat-shaped, and round formats from 6 to 20 seaters, with integrated cable boxes for power, HDMI, and network connections.",
    keywords: ["meeting table Pakistan", "conference table Lahore", "boardroom table"],
    sections: [
      { h2: "Choose by seating capacity", body: "Pick the right table for your room.", bullets: ["6-seater — 1.8m × 0.9m huddle rooms", "8-seater — 2.4m × 1.1m team meeting", "12-seater — 3.6m × 1.4m boardroom", "16-seater — 4.8m × 1.5m executive boardroom", "20-seater — modular segments"] },
      { h2: "AV-ready cable management", body: "Optional flip-top cable boxes hide HDMI, power, USB-C, and network ports. Compatible with Logitech Rally, Poly Studio, and Microsoft Teams Room hardware." },
    ],
    faqs: [
      { q: "How much space do I need per person at a meeting table?", a: "Allow 60–70cm of table edge per person for comfortable seating, plus 1m clearance behind each chair for circulation." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  "reception-tables": {
    title: "Reception Desks & Counters in Pakistan | WOODEX",
    metaDescription: "Branded reception desks & counters for offices, hotels & clinics in Pakistan. LED backlighting & logo inlays available. Made in Lahore.",
    intro: "Reception tables are the first impression of your brand. WOODEX manufactures branded reception counters with LED backlighting, illuminated logos, and integrated cable management for offices, hotels, hospitals, and showrooms across Pakistan.",
    keywords: ["reception desk Pakistan", "reception counter Lahore", "front desk furniture"],
    sections: [
      { h2: "Standard vs branded reception desks", body: "Standard reception counters ship in 7–10 days; branded versions with custom logos, lighting, and corporate colours take 4 weeks." },
      { h2: "Compliance & accessibility", body: "Lower transaction tops at 750mm height are available for wheelchair accessibility in compliance with international ADA-style guidelines." },
    ],
    faqs: [
      { q: "Can you build a reception desk with our company logo?", a: "Yes — laser-cut acrylic logos with LED back-lighting, brass inlays, and printed vinyl branding are all available options." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  chairs: {
    title: "Ergonomic Office Chairs in Pakistan — Mesh & Executive | WOODEX",
    metaDescription: "BIFMA-certified ergonomic office chairs in Pakistan. Mesh, executive leather & task chairs with lumbar support. Made in Lahore, delivered nationwide.",
    intro: "Long workdays demand chairs that protect your back. WOODEX ergonomic office chairs are BIFMA-tested for 8+ hour daily use, with adjustable lumbar support, breathable mesh backs, and synchronised tilt mechanisms — at a fraction of imported brand prices.",
    keywords: ["office chair Pakistan", "ergonomic chair Lahore", "mesh chair price"],
    sections: [
      { h2: "Types of office chairs", body: "WOODEX manufactures the full chair pyramid.", bullets: ["Executive Leather — high-back, full-grain leather, for directors", "Mid-back Mesh — breathable, adjustable, for staff", "Task Chairs — compact, swivel, for call centres", "Visitor Chairs — cantilever or 4-leg, for waiting areas", "Drafting Stools — height-adjustable for counters"] },
      { h2: "Ergonomic features explained", body: "Look for synchro-tilt (back and seat move together), adjustable lumbar (vertical and depth), 4D armrests, and seat depth slide. WOODEX premium chairs include all four." },
      { h2: "Warranty & after-sales", body: "All office chairs carry a 3-year mechanism warranty. Gas-lifts and castors are stocked spares and replaced on-site in major cities within 48 hours." },
    ],
    faqs: [
      { q: "Are WOODEX office chairs BIFMA certified?", a: "Yes — our premium and executive ranges are tested to BIFMA X5.1 standards for cyclic durability, stability, and weight capacity (up to 150kg)." },
      { q: "What is the price of a good ergonomic chair in Pakistan?", a: "Quality ergonomic chairs in Pakistan typically range from PKR 18,000 (entry mesh task) to PKR 85,000 (executive leather). WOODEX offers BIFMA-tested chairs across the full range." },
      ...sharedFaqs.slice(0, 2),
    ],
  },
  workstations: {
    title: "Office Workstations in Pakistan — Modular Desk Systems | WOODEX",
    metaDescription: "Modular office workstations for Pakistani open offices. 2, 4, 6 & 8-seater cluster layouts with screens. Made in Lahore by WOODEX.",
    intro: "Open-plan offices need workstations that flex with the team. WOODEX modular workstations connect end-to-end, side-to-side, and into 4-pod or 6-pod clusters — with optional 1100mm fabric screens for visual privacy and acoustic dampening.",
    keywords: ["office workstation Pakistan", "modular desk system", "open office furniture"],
    sections: [
      { h2: "Workstation layouts we support", body: "Linear bench, L-shape pair, 4-pod cluster, 6-pod cluster, call-centre row, and back-to-back configurations." },
      { h2: "Screens & accessories", body: "Fabric-wrapped acoustic screens (35mm), glass dividers, monitor arms, CPU holders, and personal locker pedestals are all factory-fitted." },
    ],
    faqs: [
      { q: "How much space does each workstation need?", a: "WOODEX recommends 4.5–6 m² per workstation including aisle space, depending on chosen desk size and screen height." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  "cubicle-workstations": {
    title: "Cubicle Workstations in Pakistan — Privacy Pods | WOODEX",
    metaDescription: "High-panel cubicle workstations for focused work. Acoustic privacy pods in 2, 4 & 6-seater clusters. Made in Lahore by WOODEX.",
    intro: "When focus matters more than collaboration, cubicle workstations deliver. WOODEX cubicle systems use 1200–1500mm acoustic panels to create private, sound-dampened pods ideal for call centres, finance teams, and software developers.",
    keywords: ["cubicle workstation Pakistan", "office cubicle Lahore", "privacy pod"],
    sections: [
      { h2: "Acoustic performance", body: "Our 50mm acoustic panels achieve NRC 0.75, reducing surrounding noise by up to 12 dB versus an open desk — a meaningful improvement for concentration." },
      { h2: "Cubicle Series collection", body: "The dedicated Cubicle Series (22 SKUs) covers everything from a single 1.2m pod to a 12-pod block, all with matching pedestal storage." },
    ],
    faqs: [
      { q: "What is the standard cubicle size in Pakistan?", a: "Common cubicle sizes are 1.2m × 1.2m (compact), 1.5m × 1.5m (standard), and 1.8m × 1.8m (premium). All include panel walls on at least two sides." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  "office-sofas": {
    title: "Office Sofas & Reception Seating in Pakistan | WOODEX",
    metaDescription: "Premium office sofas, lounge seating & reception couches in Pakistan. Leather & fabric upholstery, modular designs. Made in Lahore.",
    intro: "First impressions happen in the lounge. WOODEX office sofas are built for waiting areas, executive lounges, and informal meeting zones with commercial-grade leather and fabric upholstery rated for 50,000+ Martindale rubs.",
    keywords: ["office sofa Pakistan", "reception sofa Lahore", "lounge seating"],
    sections: [
      { h2: "Single, two-seater, three-seater & modular", body: "Mix and match seat counts to fit your reception. Modular benches connect into L or U shapes for larger lounges." },
      { h2: "Upholstery options", body: "PU leather (budget), bonded leather (mid), full-grain leather (premium), and stain-resistant commercial fabric in 40+ colourways." },
    ],
    faqs: [
      { q: "Which is better for an office sofa — leather or fabric?", a: "Leather is easier to wipe clean and lasts 8–10 years in commercial use. Fabric is more comfortable in hot climates and offers more colour options. Both come with a 5-year frame warranty from WOODEX." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  storage: {
    title: "Office Storage & Filing Cabinets in Pakistan | WOODEX",
    metaDescription: "Office storage solutions in Pakistan: filing cabinets, bookshelves, lockers, credenzas. Lockable drawers & adjustable shelves. Made in Lahore.",
    intro: "Organised offices run better. WOODEX office storage covers 2-, 3-, and 4-drawer filing cabinets, swing-door cupboards, open bookshelves, mobile pedestals, and employee lockers — all lockable and made from 18mm engineered board.",
    keywords: ["office storage Pakistan", "filing cabinet Lahore", "office cupboard"],
    sections: [
      { h2: "Filing cabinets", body: "2, 3, and 4-drawer vertical cabinets accept A4 hanging files. Lateral cabinets hold A4 + Foolscap and offer higher capacity per square metre." },
      { h2: "Bookshelves & credenzas", body: "Open and half-glazed bookshelves up to 2.1m tall. Credenzas match all executive table finishes for a coordinated cabin look." },
    ],
    faqs: [
      { q: "Are the locks secure on WOODEX filing cabinets?", a: "Yes — we use 5-pin disc locks with anti-pick protection. Spare keys ship with every unit and master-key systems are available for B2B orders." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  acoustic: {
    title: "Acoustic Office Furniture in Pakistan — Pods & Panels | WOODEX",
    metaDescription: "Acoustic office furniture: phone booths, meeting pods, acoustic screens & ceiling baffles. Reduce noise in Pakistani offices. Made in Lahore.",
    intro: "Open offices are loud. WOODEX acoustic furniture — phone booths, 2-4 person meeting pods, free-standing screens, and ceiling baffles — reduces ambient noise by up to 30 dB inside the pod and improves overall workplace acoustics.",
    keywords: ["acoustic pod Pakistan", "phone booth office", "soundproof office furniture"],
    sections: [
      { h2: "Phone booths & focus pods", body: "Single-person booths with ventilation, LED lighting, and a power outlet — perfect for video calls in noisy offices." },
      { h2: "Meeting pods", body: "2-, 4-, and 6-person enclosed meeting pods with glass fronts and acoustic walls. Optional video conferencing screen mount." },
    ],
    faqs: [
      { q: "Do acoustic pods need to be wired into the building?", a: "No — WOODEX phone booths and meeting pods come with built-in ventilation and LED lighting. They only need to be plugged into a standard wall socket." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  collaborative: {
    title: "Collaborative Office Furniture in Pakistan | WOODEX",
    metaDescription: "Collaborative furniture for Pakistani offices: huddle tables, brainstorm booths, modular seating, mobile whiteboards. Made in Lahore.",
    intro: "Teams that collaborate need furniture that gets out of the way. WOODEX collaborative furniture includes huddle tables, soft-seating booths, mobile whiteboards, and stackable training chairs — built for offices that rearrange weekly.",
    keywords: ["collaborative furniture Pakistan", "huddle table", "training room chairs"],
    sections: [
      { h2: "Huddle & brainstorm zones", body: "Round huddle tables for 4–6 people, soft booths with built-in power, and writable surfaces for sticky-note workshops." },
      { h2: "Training rooms", body: "Folding training tables and stackable chairs that store in 1/10th their deployed footprint." },
    ],
    faqs: [
      { q: "What is the minimum order for training room furniture?", a: "There is no minimum — we supply single huddle tables to corporate buyers and 200+ chair training-hall fit-outs alike." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  cafe: { title: "Cafe & Cafeteria Furniture in Pakistan | WOODEX", metaDescription: "Cafe tables, bar stools & cafeteria chairs for restaurants, offices & co-working in Pakistan. Made in Lahore by WOODEX.", intro: "Cafeteria and cafe furniture from WOODEX is built for high-turnover environments — restaurants, office canteens, hotels, and co-working spaces. Sturdy steel frames, easy-clean tops, and stackable designs.", keywords: ["cafe furniture Pakistan", "cafeteria table", "bar stool"], sections: [{ h2: "Tables, chairs & stools", body: "Bistro tables (round 600–800mm), bar tables (1100mm high), cafeteria 4-seaters, and stackable cafe chairs with metal or wood seats." }], faqs: sharedFaqs.slice(0, 3) },
  public: { title: "Public Sitting & Lobby Benches in Pakistan | WOODEX", metaDescription: "Public seating, lobby benches & airport-style seating for institutions in Pakistan. Heavy-duty, stain-resistant. Made in Lahore.", intro: "Public seating must survive abuse. WOODEX public sitting benches use 2mm steel frames, anti-vandal fasteners, and stain-resistant upholstery — supplied to hospitals, banks, airports, and government offices across Pakistan.", keywords: ["public seating Pakistan", "lobby bench", "waiting area chairs"], sections: [{ h2: "Where it's used", body: "Hospital waiting rooms, bank branches, airport gates, government counters, university lobbies." }], faqs: sharedFaqs.slice(0, 3) },
  "home-office": { title: "Home Office Furniture in Pakistan | WOODEX", metaDescription: "Home office desks, study tables & WFH chairs for Pakistani homes. Compact designs for apartments. Delivered nationwide.", intro: "Working from home requires furniture that fits a bedroom corner or guest room. WOODEX home office furniture is compact, attractive, and ergonomic — purpose-built for the post-pandemic Pakistani WFH setup.", keywords: ["home office Pakistan", "WFH desk", "study table"], sections: [{ h2: "Compact desks", body: "1.0m–1.4m desks with side drawers and cable management — fit in 2m of wall space." }, { h2: "Home-friendly chairs", body: "Quieter castors, smaller footprints, and finishes that suit residential interiors." }], faqs: sharedFaqs.slice(0, 3) },

  // ─── HOME PARENT CATEGORIES ───────────────────────────────────────────
  bedroom: {
    title: "Bedroom Furniture in Pakistan — Bed Sets, Dressers | WOODEX",
    metaDescription: "Premium bedroom furniture in Pakistan: king & queen beds, dressing tables, bedside tables, mirrors, wardrobes. Solid wood. Made in Lahore.",
    intro: "Your bedroom is your sanctuary. WOODEX bedroom furniture — king and queen-size bed sets, dressing tables, bedside cabinets, full-length mirrors, and storage benches — is built from kiln-dried solid hardwood and finished to withstand Pakistan's humidity.",
    keywords: ["bedroom furniture Pakistan", "bed set Lahore", "dressing table"],
    sections: [
      { h2: "Complete bedroom sets", body: "Save 15–20% with a coordinated set: bed + 2 bedside tables + dressing table + mirror. Available in walnut, oak, and white finishes." },
      { h2: "Bed sizes available", body: "Single (3ft), Three-Quarter (3.5ft), Queen (5ft), King (6ft), Super King (6.5ft). Custom hydraulic-lift storage beds are available on request." },
      { h2: "Mattresses", body: "We partner with leading Pakistani mattress brands for spring, foam, and pocket-spring options delivered together with your bed set." },
    ],
    faqs: [
      { q: "Do bed sets include the mattress?", a: "No — mattresses are sold separately so you can choose the firmness and brand you prefer. We can deliver both together if ordered at the same time." },
      { q: "How is the bedroom furniture delivered and assembled?", a: "WOODEX delivers in flat-pack or knock-down form to protect during transit, then our team assembles in your bedroom free of charge in major cities." },
      ...sharedFaqs.slice(0, 2),
    ],
  },
  living: {
    title: "Living Room Furniture in Pakistan — Sofas, TV Units | WOODEX",
    metaDescription: "Living room furniture in Pakistan: sofas, coffee tables, console tables, TV units. Modern & traditional designs. Made in Lahore.",
    intro: "The living room is where Pakistani families and guests gather. WOODEX living room furniture covers everything from 3+2+1 sofa sets to coffee tables, console tables, TV units, and side tables — coordinated in matching finishes for a cohesive look.",
    keywords: ["living room furniture Pakistan", "sofa set Lahore", "TV unit"],
    sections: [
      { h2: "Sofa sets & modular sofas", body: "Traditional 3+2+1 sets, L-shape sectionals, and modular configurations. Upholstery in fabric, leatherette, and full-grain leather." },
      { h2: "Tables for every corner", body: "Centre tables, side tables, nesting coffee tables, and consoles for the foyer or behind the sofa." },
      { h2: "TV units & entertainment", body: "Wall-mounted floating TV units and floor-standing entertainment centres for TVs up to 85 inches." },
    ],
    faqs: [
      { q: "What is the standard sofa set in Pakistan?", a: "The classic Pakistani sofa set is 3+2+1 (one three-seater, one two-seater, and one single chair). WOODEX also offers modern L-shape and modular sectionals." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
  dining: {
    title: "Dining Furniture in Pakistan — Dining Tables & Chairs | WOODEX",
    metaDescription: "Dining tables & chairs in Pakistan. 4, 6 & 8-seater sets in solid wood with marble or wood tops. Made in Lahore by WOODEX.",
    intro: "Family dinners, dinner parties, Eid lunches. WOODEX dining furniture is built for daily use and special occasions — 4-, 6-, and 8-seater dining sets with matching chairs in solid sheesham, oak, and walnut.",
    keywords: ["dining table Pakistan", "dining set Lahore", "6 seater dining"],
    sections: [
      { h2: "Dining set sizes", body: "Pick based on family size.", bullets: ["4-seater — 1.2m × 0.8m for couples and small families", "6-seater — 1.6m × 0.9m most popular Pakistani size", "8-seater — 2.0m × 1.0m for joint families and entertaining", "10–12 seater — extendable designs available"] },
      { h2: "Table tops: wood, marble, glass", body: "Solid wood for traditional homes, Pakistani marble for premium statement pieces, tempered glass for modern interiors." },
    ],
    faqs: [
      { q: "Which wood is best for a dining table in Pakistan?", a: "Sheesham (Indian rosewood) is the most popular for its strength and grain. Walnut and oak veneers are common for modern designs. All WOODEX dining tables use kiln-dried wood to prevent warping." },
      ...sharedFaqs.slice(0, 3),
    ],
  },
};

// Subcategory aliases — short SEO blocks for finer subcategories that
// reuse parent content with a tighter intro.
const subcategoryAliases: Record<string, Partial<SeoBlock> & { parent: string }> = {
  "bed-sets": { parent: "bedroom", title: "Bed Sets in Pakistan — Complete Bedroom | WOODEX", metaDescription: "Complete bed sets in Pakistan: bed + bedside tables + dressing table. King & queen sizes in solid wood. Made in Lahore." },
  "bedside-tables": { parent: "bedroom", title: "Bedside Tables in Pakistan — Night Stands | WOODEX", metaDescription: "Bedside tables & night stands in Pakistan. 2-drawer & open-shelf designs. Matches all WOODEX bed sets." },
  "dressing-tables": { parent: "bedroom", title: "Dressing Tables in Pakistan with Mirror | WOODEX", metaDescription: "Dressing tables with LED mirrors in Pakistan. Drawers, stool, jewellery storage. Made in Lahore by WOODEX." },
  mirrors: { parent: "bedroom", title: "Mirrors for Bedrooms & Hallways in Pakistan | WOODEX", metaDescription: "Full-length, dressing & decorative mirrors in Pakistan. Wood-framed & frameless designs. Delivered nationwide." },
  "bench-settee": { parent: "bedroom", title: "Bedroom Benches & Settees in Pakistan | WOODEX", metaDescription: "Upholstered bedroom benches and settees for the foot of the bed. Made in Lahore by WOODEX." },
  "home-sofa": { parent: "living", title: "Home Sofas in Pakistan — 3+2+1 & L-Shape | WOODEX", metaDescription: "Premium home sofas in Pakistan. Fabric & leather 3+2+1 sets, L-shape sectionals. Made in Lahore." },
  "center-side-tables": { parent: "living", title: "Centre & Side Tables in Pakistan | WOODEX", metaDescription: "Centre tables and side tables for living rooms in Pakistan. Wood, marble & glass tops. Made in Lahore." },
  "coffee-tables": { parent: "living", title: "Coffee Tables in Pakistan — Modern & Classic | WOODEX", metaDescription: "Coffee tables in Pakistan. Solid wood, marble & glass designs. Round, square & nested options." },
  console: { parent: "living", title: "Console Tables in Pakistan for Hallways | WOODEX", metaDescription: "Console tables for foyers and behind-the-sofa placement. Slim profile, drawer storage. Made in Lahore." },
  "tv-units": { parent: "living", title: "TV Units & Entertainment Centres in Pakistan | WOODEX", metaDescription: "TV units for 55–85 inch screens. Wall-mounted & floor-standing entertainment centres. Made in Lahore." },
  "dining-sets": { parent: "dining", title: "Dining Sets in Pakistan — 4, 6 & 8 Seater | WOODEX", metaDescription: "Complete dining sets in Pakistan: table + chairs. Sheesham, walnut & oak. 4–10 seater. Made in Lahore." },
  "dining-chairs": { parent: "dining", title: "Dining Chairs in Pakistan — Upholstered & Wood | WOODEX", metaDescription: "Dining chairs in Pakistan: upholstered, solid wood, modern & classic. Sold individually or as sets." },
  "dining-tables": { parent: "dining", title: "Dining Tables in Pakistan — Wood, Marble & Glass | WOODEX", metaDescription: "Dining tables in Pakistan. 4–12 seater, extendable options. Solid wood, marble & glass tops." },
  "office-storage": { parent: "storage" },
  "cafe-furniture": { parent: "cafe" },
  "public-sitting": { parent: "public" },
};

// Resolve subcategory by falling back to parent block
export const getCategorySeo = (id: string): SeoBlock => {
  if (categorySeo[id]) return categorySeo[id];
  const alias = subcategoryAliases[id];
  if (alias) {
    const parent = categorySeo[alias.parent] || categorySeo.all;
    return { ...parent, ...alias, sections: parent.sections, faqs: parent.faqs } as SeoBlock;
  }
  return categorySeo.all;
};

// ─── SERIES SEO CONTENT ─────────────────────────────────────────────────
export const seriesSeo: Record<string, SeoBlock> = {
  "ek-series": {
    title: "Ek Series — Affordable Office Furniture in Pakistan | WOODEX",
    metaDescription: "Ek Series by WOODEX: budget-friendly office furniture for startups, schools & growing businesses in Pakistan. 3-year warranty. Made in Lahore.",
    intro: "The Ek Series is WOODEX's entry-tier collection — engineered wood construction, powder-coated steel frames, and standard ergonomics at the most accessible price point in our line-up. Built for startups, educational institutions, and growing SMEs across Pakistan.",
    keywords: ["affordable office furniture Pakistan", "budget office desk", "startup furniture"],
    sections: [
      { h2: "Who the Ek Series is for", body: "Bootstrapped startups, schools and colleges, co-working operators, NGOs, and any business that needs reliable furniture without the premium-tier price tag." },
      { h2: "Materials & construction", body: "25mm engineered melamine board with PVC edge-banding, powder-coated 1.2mm steel legs, and standard nylon castors. Components are flat-packed for cost-efficient delivery." },
      { h2: "Warranty & value", body: "3-year structural warranty covers frames, joints, and hardware. Catalogue items typically ship within 7 working days from our Lahore facility." },
    ],
    faqs: [
      { q: "Is the Ek Series suitable for daily 8-hour office use?", a: "Yes — Ek Series furniture is built to commercial standards for normal office use. For 24/7 operations like call centres, we recommend the Infinity or Cubicle Series for enhanced durability." },
      { q: "What is the cheapest desk in the Ek Series?", a: "Ek Series staff tables start at approximately PKR 18,500. Bulk pricing kicks in at 10 units. Use the E-Quotation tool for current pricing." },
      ...sharedFaqs.slice(0, 2),
    ],
  },
  "infinity-series": {
    title: "Infinity Series — Modular Office Furniture in Pakistan | WOODEX",
    metaDescription: "Infinity Series: fully modular office furniture that grows with your team. Expandable desks, screens & storage. 5-year warranty. Made in Lahore.",
    intro: "The Infinity Series is built for offices that evolve. Every desk, screen, and storage unit connects to every other — letting you start with 4 workstations today and expand to 40 next year without replacing what you've already bought.",
    keywords: ["modular office furniture Pakistan", "expandable workstation", "scalable office furniture"],
    sections: [
      { h2: "True modularity", body: "Shared legs between adjacent desks, beam-connected screens, and pedestals that dock under any desk in the line. Mix linear, cluster, and bench layouts within one connected system." },
      { h2: "Integrated cable management", body: "Steel cable trays run continuously along the spine of every Infinity desk row. Pop-up power modules and grommets are standard." },
      { h2: "5-year warranty", body: "Frames, mechanisms, and connectors carry a 5-year warranty. The Infinity Series is our most-specified collection for corporate fit-outs of 50+ seats." },
    ],
    faqs: [
      { q: "Can I expand my Infinity Series setup later?", a: "Yes — that's the core promise. All Infinity components made today are backwards-compatible with components from the last 3 years, and we commit to availability for 5+ years from purchase date." },
      { q: "Is the Infinity Series suitable for call centres?", a: "Yes. Infinity Series is our most popular choice for call-centre fit-outs because of its dense linear layouts, integrated power, and modular acoustic screens." },
      ...sharedFaqs.slice(0, 2),
    ],
  },
  "woodex-series": {
    title: "Woodex Series — Premium Executive Furniture Pakistan | WOODEX",
    metaDescription: "Woodex Series: flagship premium furniture in Pakistan. Solid hardwood, leather, handcrafted detail. 10-year warranty. Made in Lahore.",
    intro: "The Woodex Series is our flagship — the pinnacle of Pakistani craftsmanship. Solid hardwood frames, premium full-grain leather, hand-applied finishes, and a 10-year warranty for executives, directors, and clients who refuse to compromise.",
    keywords: ["premium executive furniture Pakistan", "luxury office desk", "boss table Lahore"],
    sections: [
      { h2: "Materials reserved for our flagship", body: "Solid sheesham and walnut, full-grain Italian-style leather inlays, brass and chrome hardware, hand-rubbed lacquer finishes. No engineered board, no laminate." },
      { h2: "Handcrafted detail", body: "Every Woodex Series piece is hand-finished by our senior craftsmen in Lahore. Production time per executive desk is 4–6 weeks; we do not rush." },
      { h2: "10-year warranty", body: "Our longest warranty covers structure, joinery, and finish. Many Woodex Series pieces from 2010 are still in active service today." },
    ],
    faqs: [
      { q: "How much does a Woodex Series executive desk cost in Pakistan?", a: "Woodex Series flagship executive desks start at PKR 285,000 and can reach PKR 650,000+ for fully custom configurations with leather inlays and matching credenzas." },
      { q: "Can I customise the Woodex Series for my office?", a: "Yes — dimensions, wood species, leather colour, and brass detailing are all customisable. Lead time for fully custom Woodex Series pieces is 6–8 weeks." },
      ...sharedFaqs.slice(0, 2),
    ],
  },
  "cubicle-series": {
    title: "Cubicle Series — Privacy Workstations in Pakistan | WOODEX",
    metaDescription: "Cubicle Series: acoustic privacy workstations for focused work in Pakistani offices. NRC 0.75 panels. 5-year warranty. Made in Lahore.",
    intro: "Open offices kill focus. The Cubicle Series brings back the productive privacy of dedicated workstations — with acoustic panels, personal storage, and height-adjustable options for finance teams, developers, and call-centre agents.",
    keywords: ["cubicle workstation Pakistan", "private office desk", "acoustic cubicle"],
    sections: [
      { h2: "Acoustic-first design", body: "50mm fabric-wrapped panels achieve NRC 0.75 — meaning 75% of incoming sound is absorbed rather than reflected. Conversation across cubicles drops below 50 dB." },
      { h2: "Personal storage integration", body: "Every cubicle ships with a mobile 3-drawer pedestal that docks under the desk. Optional overhead cabinets add lockable storage above the panel line." },
      { h2: "Height-adjustable options", body: "Add electric sit-stand bases (650–1280mm range) to any Cubicle Series workstation. A premium-tier productivity upgrade." },
    ],
    faqs: [
      { q: "How much noise do Cubicle Series panels block?", a: "Our acoustic panels reduce perceived noise by approximately 12 dB compared to an open desk — a noticeable improvement equivalent to roughly halving the loudness." },
      { q: "Can Cubicle Series desks be configured for left-handed users?", a: "Yes — pedestals and L-returns can be mounted on either side. Just specify when ordering." },
      ...sharedFaqs.slice(0, 2),
    ],
  },
  "nova-series": {
    title: "Nova Series — Minimalist Office Furniture in Pakistan | WOODEX",
    metaDescription: "Nova Series: Scandinavian-inspired minimalist office furniture. Slim steel frames, hidden hardware, push-to-open drawers. Made in Lahore.",
    intro: "The Nova Series brings Scandinavian minimalism to Pakistani workspaces. Slim steel profiles, hidden hardware, push-to-open drawers, and a restrained palette of oak, white, and charcoal — for design-conscious offices and creative agencies.",
    keywords: ["minimalist office furniture Pakistan", "scandinavian desk", "modern office furniture"],
    sections: [
      { h2: "Design philosophy", body: "Less is more. Nova hides cable management inside slim 1.5mm steel legs, replaces drawer handles with push-to-open hardware, and uses thin 20mm tops with chamfered edges." },
      { h2: "Best for creative & tech offices", body: "Architecture studios, design agencies, software companies, and modern start-ups specify Nova for its photogenic restraint and Instagram-ready aesthetics." },
      { h2: "3-year warranty", body: "Despite its slim profile, Nova is engineered for 8-hour daily use and backed by a 3-year structural warranty." },
    ],
    faqs: [
      { q: "Is the Nova Series strong enough despite its slim design?", a: "Yes — slim does not mean weak. Nova uses 1.5mm cold-rolled steel (vs 1.2mm in lower-tier furniture) and tops are reinforced with internal aluminium ribs. Load rating is 80kg per desk." },
      { q: "What finishes are available in the Nova Series?", a: "Natural oak, matte white, and matte charcoal — the three colours that define the Scandinavian aesthetic. Custom RAL colours available on bulk orders." },
      ...sharedFaqs.slice(0, 2),
    ],
  },
};

export const getSeriesSeo = (id: string): SeoBlock | undefined => seriesSeo[id];
