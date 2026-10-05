import blogErgonomic from "@/assets/blog-ergonomic.jpg";
import blogHybrid from "@/assets/blog-hybrid-workspace.jpg";
import blogSustainable from "@/assets/blog-sustainable.jpg";
import blogColors from "@/assets/blog-office-colors.jpg";
import blogCaseStudy from "@/assets/blog-case-study.jpg";
import blogStandingDesk from "@/assets/blog-standing-desk.jpg";

export interface BlogPost {
  id: string;
  title: string;
  excerpt: string;
  image: string;
  category: string;
  author: string;
  date: string;
  readTime: string;
  featured?: boolean;
  content: { heading?: string; body: string }[];
  tags: string[];
}

export const blogPosts: BlogPost[] = [
  {
    id: "ergonomic-office-guide-2025",
    title: "The Complete Guide to Ergonomic Office Furniture in 2025",
    excerpt:
      "Discover how the right office chair and desk setup can reduce back pain, boost productivity, and improve employee wellbeing. Expert tips from WOODEX's certified ergonomists.",
    image: blogErgonomic,
    category: "Ergonomics",
    author: "Dr. Farah Khan",
    date: "Feb 15, 2025",
    readTime: "8 min read",
    featured: true,
    tags: ["Ergonomics", "Office Chairs", "Productivity", "Health"],
    content: [
      {
        body: "Pakistani professionals spend an average of 9 hours a day at their desks. Yet most offices in Lahore, Karachi, and Islamabad are still equipped with furniture that hasn't evolved in decades — leading to chronic back pain, reduced focus, and rising healthcare costs for employers.",
      },
      {
        heading: "Why Ergonomics Matters in 2025",
        body: "Ergonomic furniture isn't a luxury — it's a productivity multiplier. Studies from the Cornell University Ergonomics Lab show that proper seating and desk height can increase output by up to 17.5% and reduce sick days by nearly a third.",
      },
      {
        heading: "The 5 Pillars of an Ergonomic Workstation",
        body: "1. Adjustable lumbar support that follows the natural S-curve of the spine.\n2. Seat depth and height that keep knees at a 90° angle.\n3. Monitor positioning at eye level, roughly an arm's length away.\n4. Keyboard and mouse placement that keeps wrists neutral.\n5. Adequate task lighting to reduce eye strain in Pakistan's variable daylight conditions.",
      },
      {
        heading: "WOODEX's Ergonomic Range",
        body: "Our Infinity and Nova series chairs are designed in consultation with certified ergonomists and tested for the Pakistani climate. Every chair ships with a 5-year structural warranty and free ergonomic assessment at our Lahore, Karachi, and Islamabad showrooms.",
      },
      {
        heading: "Final Thoughts",
        body: "Investing in ergonomic furniture pays for itself within 12–18 months through reduced absenteeism and higher engagement. Book a free consultation with our team to audit your workspace.",
      },
    ],
  },
  {
    id: "hybrid-workspace-design",
    title: "Designing Hybrid Workspaces: Furniture That Adapts",
    excerpt:
      "How Pakistani companies are rethinking office layouts for hybrid work. Modular furniture, hot-desking solutions, and collaborative zones explained.",
    image: blogHybrid,
    category: "Workspace Design",
    author: "Ahmed Raza",
    date: "Feb 8, 2025",
    readTime: "6 min read",
    tags: ["Hybrid Work", "Modular", "Workspace"],
    content: [
      { body: "Hybrid work is no longer a trend — it's the default operating model for Pakistan's leading firms. The challenge: building offices that flex between solo focus, team collaboration, and visiting remote staff." },
      { heading: "Modular by Default", body: "Modular workstations let teams reconfigure layouts in hours instead of weeks. WOODEX's Cubicle series supports tool-free reassembly and integrated power management." },
      { heading: "Hot-Desking Done Right", body: "Successful hot-desking requires lockable storage, easy-clean surfaces, and a booking system. We help clients pair our furniture with simple SaaS booking tools." },
      { heading: "Collaborative Zones", body: "Soft seating, writable surfaces, and acoustic panels turn corridors into productive collaboration pockets." },
    ],
  },
  {
    id: "sustainable-furniture-pakistan",
    title: "Sustainable Furniture Manufacturing in Pakistan",
    excerpt:
      "WOODEX's commitment to eco-friendly production — from FSC-certified wood sourcing to zero-waste manufacturing practices in our Lahore facility.",
    image: blogSustainable,
    category: "Sustainability",
    author: "Sara Malik",
    date: "Jan 28, 2025",
    readTime: "5 min read",
    tags: ["Sustainability", "FSC", "Manufacturing"],
    content: [
      { body: "Sustainability is more than a marketing badge. At WOODEX, it's a manufacturing standard built into every step of our production line." },
      { heading: "FSC-Certified Sourcing", body: "All hardwood used in our Ek and Woodex series is sourced from FSC-certified forests, ensuring responsible reforestation." },
      { heading: "Zero-Waste Workshop", body: "Sawdust is repurposed into briquettes; offcuts become accessories. Our Lahore facility diverts 94% of waste from landfill." },
      { heading: "Low-VOC Finishes", body: "We use water-based finishes that meet European EN 71-3 safety standards — safer for installers and end users alike." },
    ],
  },
  {
    id: "office-color-psychology",
    title: "How Office Colors Affect Productivity: A Research-Backed Guide",
    excerpt:
      "Blue for focus, green for creativity, and warm tones for collaboration — the science behind choosing the right furniture finishes for your workspace.",
    image: blogColors,
    category: "Interior Design",
    author: "Hina Javed",
    date: "Jan 20, 2025",
    readTime: "7 min read",
    tags: ["Color", "Design", "Psychology"],
    content: [
      { body: "Color drives mood, attention, and even decision-making. The right palette can quietly transform how a team performs." },
      { heading: "Blue: The Focus Color", body: "Deep blues lower heart rate and support analytical work — ideal for finance and engineering floors." },
      { heading: "Green: The Creative Color", body: "Forest green (our signature accent) balances calm and energy, perfect for design studios and brainstorm rooms." },
      { heading: "Warm Neutrals", body: "Walnut, terracotta, and soft beige humanize meeting rooms and reception areas." },
    ],
  },
  {
    id: "corporate-case-study-allied-bank",
    title: "Case Study: Furnishing 8 Allied Bank Branches Across Punjab",
    excerpt:
      "How WOODEX delivered 200+ workstations, executive suites, and customer service counters across 8 branches in just 6 weeks — on time and on budget.",
    image: blogCaseStudy,
    category: "Case Study",
    author: "Bilal Ahmed",
    date: "Jan 12, 2025",
    readTime: "10 min read",
    tags: ["Case Study", "Banking", "B2B"],
    content: [
      { body: "When Allied Bank needed to refresh 8 branches across Punjab within a single quarter, they turned to WOODEX for end-to-end execution." },
      { heading: "The Brief", body: "200+ workstations, 16 executive suites, and 24 customer service counters — all matching the bank's new brand standards." },
      { heading: "Our Approach", body: "Centralized manufacturing in Lahore, staggered logistics, and on-site installation crews per city allowed parallel delivery." },
      { heading: "Results", body: "Delivered 4 days ahead of schedule, 0 safety incidents, and a 12% saving versus the original budget estimate." },
    ],
  },
  {
    id: "standing-desk-benefits",
    title: "Standing Desks in Pakistan: Are They Worth the Investment?",
    excerpt:
      "A comprehensive analysis of sit-stand desks for Pakistani offices — health benefits, ROI calculations, and the best models for different budgets.",
    image: blogStandingDesk,
    category: "Ergonomics",
    author: "Dr. Farah Khan",
    date: "Jan 5, 2025",
    readTime: "6 min read",
    tags: ["Standing Desk", "Ergonomics", "Health"],
    content: [
      { body: "Sit-stand desks are gaining ground in Pakistani offices. Here's an honest look at whether they're worth the premium." },
      { heading: "Proven Health Benefits", body: "Alternating posture every 30–60 minutes reduces lower-back load and improves circulation." },
      { heading: "ROI in 14 Months", body: "Our enterprise clients report measurable productivity gains that offset the hardware cost in just over a year." },
      { heading: "Choosing the Right Model", body: "Electric height adjustment is worth the extra cost — manual cranks rarely get used after the first month." },
    ],
  },
];

export const getBlogPost = (id: string) => blogPosts.find((p) => p.id === id);
