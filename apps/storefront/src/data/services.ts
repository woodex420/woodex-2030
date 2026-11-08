import customDesignImg from "@/assets/service-custom-design.jpg";
import b2bImg from "@/assets/service-b2b-solutions.jpg";
import customMfgImg from "@/assets/service-custom-manufacturing.jpg";
import deliveryImg from "@/assets/service-delivery.jpg";
import spacePlanningImg from "@/assets/service-space-planning.jpg";
import afterSalesImg from "@/assets/service-after-sales.jpg";
import projectMgmtImg from "@/assets/service-project-management.jpg";

export interface ServiceData {
  slug: string;
  number: string;
  title: string;
  shortTitle: string;
  tagline: string;
  hero: string;
  intro: string;
  metaTitle: string;
  metaDescription: string;
  overview: { heading: string; body: string };
  process: { step: string; title: string; description: string }[];
  benefits: { title: string; description: string }[];
  inclusions: string[];
  faqs: { q: string; a: string }[];
  caseStudy: { client: string; sector: string; result: string };
  related: string[];
}

export const services: ServiceData[] = [
  {
    slug: "custom-design",
    number: "01",
    title: "Custom Office Furniture Design",
    shortTitle: "Custom Design",
    tagline: "Bespoke executive desks, reception counters and workstations — engineered around how your team actually works.",
    hero: customDesignImg,
    intro: "Every workspace is unique. WOODEX's in-house design studio translates your brand, dimensions and workflow into precision-engineered office furniture — from a single sculptural executive desk to a 500-seat floor of brand-aligned workstations, built right here in Lahore.",
    metaTitle: "Custom Office Furniture Design Pakistan — Bespoke Desks & Workstations | WOODEX",
    metaDescription: "Bespoke custom office furniture design in Lahore, Karachi & Islamabad. Executive desks, reception counters, workstations & boardroom tables tailored to your brand and floor plate. Free 3D renders.",
    overview: {
      heading: "Furniture engineered around your business",
      body: "Off-the-shelf furniture forces your team to adapt to a generic product. Custom-designed furniture does the opposite — it adapts to your team. We start by studying how your people actually work: meeting cadence, storage needs, cable routing, ergonomic posture, even the way light moves across your floor through the day. That field data becomes scaled CAD drawings, real material samples and photoreal 3D renders, all approved before a single board is cut. Every WOODEX bespoke piece is then built in our 45,000 sq ft Lahore facility by craftsmen averaging 15+ years on the floor — and shipped with a 5-year structural warranty.",
    },
    process: [
      { step: "01", title: "Brief & Site Visit", description: "Free on-site consultation with a senior designer in Lahore, Karachi or Islamabad." },
      { step: "02", title: "Concept & 3D Render", description: "Scaled CAD drawings, photoreal 3D renders and material / finish options for sign-off." },
      { step: "03", title: "Material & Prototype Approval", description: "Physical swatches and a working prototype before bulk production starts." },
      { step: "04", title: "Precision Production", description: "CNC-cut, edge-banded and assembled in our Lahore facility under two-stage QC." },
      { step: "05", title: "Delivery & Install", description: "White-glove installation by uniformed in-house crews, with on-site walk-through." },
      { step: "06", title: "Warranty & Care", description: "5-year structural warranty plus optional annual maintenance for fleets." },
    ],
    benefits: [
      { title: "Brand-Aligned", description: "Pantone-matched laminates, logo inlays and signature finishes that reinforce your identity." },
      { title: "Space-Optimised", description: "Custom dimensions that squeeze every usable inch out of your floor plate." },
      { title: "Ergonomic by Default", description: "BIFMA and ISO-compliant heights, clearances and posture support built in." },
      { title: "Built to Last", description: "Imported hardware, hardwood frames and a 5-year structural warranty." },
    ],
    inclusions: [
      "Free on-site design consultation",
      "Unlimited revisions before production sign-off",
      "Scaled 2D CAD floor plans",
      "Photoreal 3D visualisation",
      "Physical material & finish sample kit",
      "Dedicated project designer",
      "Pantone / RAL brand colour matching",
      "5-year structural warranty",
    ],
    faqs: [
      { q: "How long does a custom design project take in Pakistan?", a: "Typical end-to-end lead time is 4–6 weeks from approved design to installation. Single executive pieces can ship in 2–3 weeks; full-floor fit-outs of 200+ workstations run 6–9 weeks." },
      { q: "What is the minimum order for custom work?", a: "There is no minimum — we accept single bespoke executive desks for CEO offices as well as multi-city corporate rollouts of 1,000+ pieces." },
      { q: "Can you match my office colour palette and brand?", a: "Yes — we colour-match laminates, fabrics, leathers and powder-coated metalwork against any Pantone, RAL or NCS reference, and we can inlay logos in brass, wood or laser-etched acrylic." },
      { q: "Do you provide 3D renders before production?", a: "Yes. Every custom project ships with photoreal 3D renders so you (and your board) can sign off on look, scale and finish before manufacturing begins." },
      { q: "Is the custom design service available outside Lahore?", a: "Yes — site visits, design and installation are available across Pakistan, with dedicated teams in Lahore, Karachi, Islamabad, Rawalpindi, Faisalabad and Multan." },
      { q: "How much does custom office furniture cost vs ready-made?", a: "Custom typically runs 15–30% above comparable ready-made furniture, but delivers 2–3x the lifespan and is sized exactly to your floor — which usually saves money overall." },
    ],
    caseStudy: {
      client: "Habib Bank Limited — Karachi HQ",
      sector: "Banking & Finance",
      result: "120 custom-designed workstations and 14 brand-aligned executive cabins delivered in 5 weeks, with zero on-site rework.",
    },
    related: ["custom-manufacturing", "space-planning-design", "project-management"],
  },
  {
    slug: "b2b-office-solutions",
    number: "02",
    title: "B2B Office Furniture Solutions",
    shortTitle: "B2B Office Solutions",
    tagline: "End-to-end office furniture for banks, hospitals, tech parks and government — at scale, on schedule.",
    hero: b2bImg,
    intro: "WOODEX supplies enterprise clients across Pakistan with complete office furniture programmes — from single floors to multi-city rollouts — backed by dedicated account management and volume pricing.",
    metaTitle: "B2B Office Furniture Solutions Pakistan | Bulk Supply WOODEX",
    metaDescription: "Enterprise office furniture for Pakistani banks, hospitals, IT parks & government. Volume pricing, project management & nationwide installation.",
    overview: {
      heading: "Trusted by Pakistan's leading enterprises",
      body: "We partner with corporate procurement teams to standardise furniture across multiple branches, manage phased rollouts and stay aligned with budget cycles. Net-30 terms available for verified businesses, with a dedicated KAM for every account.",
    },
    process: [
      { step: "01", title: "Needs Assessment", description: "Branch audit, headcount mapping and standardisation review." },
      { step: "02", title: "Master Spec & Pricing", description: "Locked specifications with tiered volume pricing." },
      { step: "03", title: "Pilot Rollout", description: "First-site installation as a working reference." },
      { step: "04", title: "Phased Deployment", description: "Synchronised delivery across cities and branches." },
      { step: "05", title: "Account Management", description: "Quarterly reviews, spares and replenishment." },
    ],
    benefits: [
      { title: "Volume Pricing", description: "Up to 22% off retail on orders over 50 workstations." },
      { title: "Net-30 Terms", description: "Flexible payment terms for verified corporate accounts." },
      { title: "Single Vendor", description: "One contract covering supply, install and after-sales nationwide." },
      { title: "GST-Compliant Invoicing", description: "FBR-registered invoices for full corporate compliance." },
    ],
    inclusions: [
      "Dedicated Key Account Manager",
      "Volume discount structure",
      "Nationwide installation",
      "Multi-branch standardisation",
      "Quarterly account reviews",
      "Priority replacement & spares",
    ],
    faqs: [
      { q: "Do you serve government tenders?", a: "Yes — WOODEX is registered with PPRA and regularly supplies federal and provincial departments." },
      { q: "What's your largest project?", a: "A 1,400-seat tech park fit-out in Islamabad delivered across 9 weeks." },
      { q: "Can you match existing furniture?", a: "Yes — we reverse-engineer and produce matching pieces for legacy office programmes." },
    ],
    caseStudy: {
      client: "Allied Bank Limited",
      sector: "Banking",
      result: "8 branches across Punjab refurnished on a single PO, completed in 11 weeks.",
    },
    related: ["project-management", "custom-manufacturing", "delivery-installation"],
  },
  {
    slug: "custom-manufacturing",
    number: "03",
    title: "Custom Manufacturing",
    shortTitle: "Custom Manufacturing",
    tagline: "In-house production at our Lahore facility — control, quality and speed under one roof.",
    hero: customMfgImg,
    intro: "Our 45,000 sq ft manufacturing facility in Sundar Industrial Estate, Lahore combines German CNC precision with master craftsmanship to produce furniture engineered for Pakistani workplaces.",
    metaTitle: "Custom Office Furniture Manufacturing Lahore | WOODEX Pakistan",
    metaDescription: "WOODEX manufactures premium office furniture in Lahore. CNC precision, imported materials, 5-year warranty. ISO-certified facility.",
    overview: {
      heading: "From raw material to finished installation",
      body: "Vertical integration means no middlemen, no quality gaps, no surprises on lead time. We import boards, laminates and hardware directly from Germany, Turkey and Malaysia and process them on calibrated CNC routers, edge banders and powder-coat lines.",
    },
    process: [
      { step: "01", title: "Material Selection", description: "Imported MDF, particle board, laminate and hardware." },
      { step: "02", title: "CNC Cutting", description: "Sub-millimetre precision on every component." },
      { step: "03", title: "Edge & Finish", description: "PUR edge banding and powder-coat metalwork." },
      { step: "04", title: "Assembly & QC", description: "Two-stage quality inspection before dispatch." },
      { step: "05", title: "Pack & Dispatch", description: "Protective packaging for nationwide shipping." },
    ],
    benefits: [
      { title: "Vertical Integration", description: "Single supplier from design to installation." },
      { title: "Faster Lead Times", description: "Standard orders ship in 2–3 weeks." },
      { title: "Consistent Quality", description: "Two-stage QC on every batch." },
      { title: "Imported Materials", description: "German hardware, Turkish laminates, Malaysian board." },
    ],
    inclusions: [
      "CNC-precision cutting",
      "Imported raw materials",
      "Two-stage quality control",
      "Protective packaging",
      "ISO 9001 process documentation",
      "Material traceability",
    ],
    faqs: [
      { q: "Can I tour the facility?", a: "Yes — corporate clients are welcome to schedule a facility visit before placing large orders." },
      { q: "What's your monthly capacity?", a: "Up to 800 workstations and 2,000 chairs per month at full utilisation." },
      { q: "Do you do export?", a: "We currently ship to UAE, Saudi Arabia and Bahrain for select corporate clients." },
    ],
    caseStudy: {
      client: "Systems Limited",
      sector: "Information Technology",
      result: "320 workstations manufactured and delivered in 18 working days.",
    },
    related: ["custom-design", "delivery-installation", "after-sales-support"],
  },
  {
    slug: "delivery-installation",
    number: "04",
    title: "Delivery & Installation",
    shortTitle: "Delivery & Installation",
    tagline: "Professional logistics and on-site assembly across every major city in Pakistan.",
    hero: deliveryImg,
    intro: "WOODEX operates its own fleet and in-house installation crews — no third-party movers, no damaged goods, no missed deadlines.",
    metaTitle: "Office Furniture Delivery & Installation Pakistan | WOODEX",
    metaDescription: "Nationwide office furniture delivery & professional installation across Pakistan. In-house fleet, trained crews, zero damage guarantee.",
    overview: {
      heading: "Owned fleet, trained crews, zero damage",
      body: "From a single executive desk to a 500-piece corporate rollout, every WOODEX delivery is handled by our uniformed in-house team using padded transport and standardised assembly procedures.",
    },
    process: [
      { step: "01", title: "Dispatch Schedule", description: "Confirmed delivery window 48 hours in advance." },
      { step: "02", title: "Protected Transport", description: "Padded shipping in WOODEX-owned vehicles." },
      { step: "03", title: "On-Site Survey", description: "Lift access, floor protection and route planning." },
      { step: "04", title: "Assembly", description: "Certified installers using factory torque specs." },
      { step: "05", title: "Walk-Through & Cleanup", description: "Client sign-off and full debris removal." },
    ],
    benefits: [
      { title: "Nationwide Reach", description: "Lahore, Karachi, Islamabad, Rawalpindi, Faisalabad and Multan." },
      { title: "Zero Damage Guarantee", description: "Any transit damage replaced free of charge." },
      { title: "After-Hours Service", description: "Evening and weekend installs for live offices." },
      { title: "Floor & Wall Protection", description: "Padding and corner guards on every site." },
    ],
    inclusions: [
      "In-house fleet & crew",
      "Floor & wall protection",
      "Professional assembly",
      "Debris removal",
      "Warranty activation on-site",
      "After-hours options",
    ],
    faqs: [
      { q: "Do you deliver outside major cities?", a: "Yes — we deliver to all 4 provinces. A nominal logistics fee applies beyond our service hubs." },
      { q: "How long does installation take?", a: "A standard 50-workstation floor is installed and ready in 2 working days." },
      { q: "Can you install after office hours?", a: "Yes — we routinely install overnight and on weekends for banks and live offices." },
    ],
    caseStudy: {
      client: "Shifa International Hospital",
      sector: "Healthcare",
      result: "Phased installation across 4 wings completed without disrupting patient services.",
    },
    related: ["project-management", "after-sales-support", "custom-manufacturing"],
  },
  {
    slug: "space-planning-design",
    number: "05",
    title: "Space Planning & Design",
    shortTitle: "Space Planning & Design",
    tagline: "Workplace strategy, CAD floor plans and 3D renders — before you commit to a single piece of furniture.",
    hero: spacePlanningImg,
    intro: "Our space planners study your team density, collaboration patterns and growth plans, then deliver scaled CAD layouts and photoreal 3D renders so you can see — and adjust — your office before it's built.",
    metaTitle: "Office Space Planning & Interior Design Pakistan | WOODEX",
    metaDescription: "Professional office space planning, CAD layouts & 3D renders for Pakistani businesses. Ergonomic, scalable workplace design by WOODEX.",
    overview: {
      heading: "Design first, build once",
      body: "Most office fit-outs fail not in installation but in planning. WOODEX space planners use real data — headcount projections, BIFMA clearances, fire-egress rules — to produce layouts that flex with your business for the next 5 years.",
    },
    process: [
      { step: "01", title: "Discovery", description: "Headcount, workflow and culture audit." },
      { step: "02", title: "Test-Fit", description: "Multiple layout options on your actual floor plate." },
      { step: "03", title: "3D Visualisation", description: "Photoreal renders of approved layouts." },
      { step: "04", title: "Specification", description: "Bill of materials, furniture schedule and budget." },
      { step: "05", title: "Implementation Support", description: "Site supervision during fit-out." },
    ],
    benefits: [
      { title: "Data-Driven Layouts", description: "Density and clearance based on actual usage patterns." },
      { title: "Photoreal Renders", description: "See your office before construction begins." },
      { title: "Budget Clarity", description: "Costed BOM at design sign-off — no surprises." },
      { title: "Future-Proof", description: "Layouts designed to flex with 5-year growth." },
    ],
    inclusions: [
      "On-site space audit",
      "2D & 3D CAD layouts",
      "Photoreal 3D renders",
      "Furniture specification (BOM)",
      "Ergonomic compliance review",
      "Phasing plan",
    ],
    faqs: [
      { q: "Is space planning free?", a: "Initial consultation and concept layout are complimentary for projects above 25 workstations." },
      { q: "Do you work with our architect?", a: "Yes — we routinely collaborate with architects and PMC firms across Pakistan." },
      { q: "What software do you use?", a: "AutoCAD, SketchUp and 3ds Max with V-Ray for photoreal rendering." },
    ],
    caseStudy: {
      client: "TechVentures Islamabad",
      sector: "Technology",
      result: "200-seat office designed and rendered in 8 days, fit-out completed in 6 weeks.",
    },
    related: ["custom-design", "project-management", "b2b-office-solutions"],
  },
  {
    slug: "after-sales-support",
    number: "06",
    title: "After-Sales Support",
    shortTitle: "After-Sales Support",
    tagline: "5-year warranty, on-call repair teams and spare parts always in stock.",
    hero: afterSalesImg,
    intro: "When you buy WOODEX, you're buying into a long-term partnership. Our service desk responds within 24 hours and our technicians carry common spares for same-day fixes.",
    metaTitle: "Office Furniture After-Sales Support Pakistan | WOODEX Warranty",
    metaDescription: "5-year warranty, 24-hour response and on-site repair for WOODEX office furniture across Pakistan. Annual maintenance plans available.",
    overview: {
      heading: "Service that outlasts the sale",
      body: "Office furniture is a 7–10 year investment. WOODEX backs every product with a 5-year structural warranty, transparent spare parts pricing and optional annual maintenance contracts for corporate clients.",
    },
    process: [
      { step: "01", title: "Raise a Ticket", description: "Call, WhatsApp or email — 24-hour acknowledgement." },
      { step: "02", title: "Remote Diagnosis", description: "Photo / video triage to dispatch the right technician." },
      { step: "03", title: "On-Site Visit", description: "Field engineer with spares within 48 hours." },
      { step: "04", title: "Repair or Replace", description: "Fix in place or full unit replacement under warranty." },
      { step: "05", title: "Follow-Up", description: "Quality check call 7 days after resolution." },
    ],
    benefits: [
      { title: "5-Year Warranty", description: "Structural coverage on frames, mechanisms and welds." },
      { title: "24-Hour Response", description: "Service desk acknowledges every ticket within a day." },
      { title: "Spare Parts in Stock", description: "Common components always available, no import wait." },
      { title: "AMC Plans", description: "Annual maintenance contracts for fleets of 100+ pieces." },
    ],
    inclusions: [
      "5-year structural warranty",
      "Dedicated service helpline",
      "On-site repair across Pakistan",
      "Transparent spares pricing",
      "Optional AMC contracts",
      "Free annual inspection (AMC)",
    ],
    faqs: [
      { q: "What does the warranty cover?", a: "Structural frames, welds, mechanisms and manufacturing defects under normal commercial use." },
      { q: "How fast is on-site response?", a: "Within 48 hours in major cities; 72 hours elsewhere in Pakistan." },
      { q: "Is the AMC worth it?", a: "For offices with 100+ pieces, AMC pays for itself in extended life and zero downtime." },
    ],
    caseStudy: {
      client: "Engro Corporation",
      sector: "Industrial",
      result: "3-year AMC across 4 sites — zero unresolved tickets, 98% same-week closure.",
    },
    related: ["delivery-installation", "custom-manufacturing", "b2b-office-solutions"],
  },
  {
    slug: "project-management",
    number: "07",
    title: "Project Management",
    shortTitle: "Project Management",
    tagline: "One dedicated PM, one budget tracker, one timeline — from contract to keys.",
    hero: projectMgmtImg,
    intro: "Every WOODEX project of 25+ workstations gets a dedicated certified project manager who owns the timeline, budget, communication and quality from day one.",
    metaTitle: "Office Furniture Project Management Pakistan | WOODEX",
    metaDescription: "Certified project managers for office furniture rollouts across Pakistan. Timeline, budget & quality ownership from concept to handover.",
    overview: {
      heading: "A single throat to choke",
      body: "Multi-vendor furniture projects fail because nobody owns the schedule. WOODEX appoints one PMP-style project lead who runs weekly status calls, maintains a live Gantt chart and escalates risks before they become delays.",
    },
    process: [
      { step: "01", title: "Kickoff", description: "Charter, scope and stakeholder mapping." },
      { step: "02", title: "Detailed Plan", description: "Gantt chart, milestones and dependency mapping." },
      { step: "03", title: "Weekly Status", description: "Live dashboard and weekly stakeholder call." },
      { step: "04", title: "Risk Management", description: "Early flagging with mitigation options." },
      { step: "05", title: "Handover & Closeout", description: "Snag list resolved, warranty activated, lessons captured." },
    ],
    benefits: [
      { title: "Single Owner", description: "One PM accountable for the entire programme." },
      { title: "Live Dashboard", description: "Online tracker visible to your team 24/7." },
      { title: "Budget Discipline", description: "Variance reported weekly, never quarterly." },
      { title: "Risk Transparency", description: "Issues raised early, not after they bite." },
    ],
    inclusions: [
      "Dedicated certified PM",
      "Weekly status reports",
      "Live online Gantt tracker",
      "Risk & issue register",
      "Budget variance reporting",
      "Formal handover & lessons learned",
    ],
    faqs: [
      { q: "Is PM included or extra?", a: "Included free for projects above 50 workstations; optional add-on below that." },
      { q: "Can the PM coordinate with our contractors?", a: "Yes — we routinely interface with civil, MEP and IT contractors on live sites." },
      { q: "What if the project slips?", a: "We share the schedule risk: late delivery on our scope triggers contractual penalties." },
    ],
    caseStudy: {
      client: "State Bank of Pakistan",
      sector: "Government / Banking",
      result: "Multi-floor regional office rollout delivered 4 days ahead of contractual milestone.",
    },
    related: ["b2b-office-solutions", "space-planning-design", "after-sales-support"],
  },
];

export const getService = (slug: string) => services.find((s) => s.slug === slug);
export const getRelated = (slugs: string[]) => services.filter((s) => slugs.includes(s.slug));
