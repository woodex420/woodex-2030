/* ============================================================
   Woodex Agency OS — mock operational data (UI layer only).
   Business logic stays separate from presentation (§31).
   ============================================================ */

export type Tone =
  | "neutral"
  | "primary"
  | "success"
  | "warning"
  | "danger"
  | "info"
  | "muted";

/* ---------- Status system (§14) ---------- */
export type LeadStatus = "New" | "Qualified" | "Meeting" | "Quotation" | "Won" | "Lost";
export type QuoteStatus =
  | "Draft"
  | "Sent"
  | "Viewed"
  | "Negotiation"
  | "Revised"
  | "Approved"
  | "Rejected"
  | "Expired";
export type InvoiceStatus = "Draft" | "Issued" | "Partially Paid" | "Paid" | "Overdue" | "Cancelled";
export type OpStatus = "Pending" | "In Progress" | "On Track" | "On Hold" | "Delayed" | "Completed";

export const statusTone: Record<string, Tone> = {
  // Lead
  New: "info",
  Qualified: "primary",
  Meeting: "warning",
  Quotation: "info",
  Won: "success",
  Lost: "danger",
  // Quotation
  Draft: "neutral",
  Sent: "info",
  Viewed: "primary",
  Negotiation: "warning",
  Revised: "info",
  Approved: "success",
  Rejected: "danger",
  Expired: "muted",
  // Invoice
  Issued: "info",
  "Partially Paid": "warning",
  Paid: "success",
  Overdue: "danger",
  Cancelled: "muted",
  // Operations
  Pending: "neutral",
  "In Progress": "info",
  "On Track": "primary",
  "On Hold": "warning",
  Delayed: "danger",
  Completed: "success",
  // Priority
  High: "danger",
  Medium: "warning",
  Low: "neutral",
};

/* ---------- KPIs (§7.2) ---------- */
export type Kpi = {
  id: string;
  label: string;
  value: string;
  trend: number;
  comparison: string;
  icon: "users" | "clipboard" | "dollar" | "building" | "factory" | "invoice";
  tone: Tone;
};

export const kpis: Kpi[] = [
  { id: "leads", label: "Leads", value: "1,284", trend: 12.4, comparison: "vs. last 30 days", icon: "users", tone: "info" },
  { id: "quotes", label: "Active Quotations", value: "38", trend: 6.1, comparison: "vs. last 7 days", icon: "clipboard", tone: "primary" },
  { id: "revenue", label: "Sales Revenue", value: "$128,450", trend: 24, comparison: "vs. last 30 days", icon: "dollar", tone: "success" },
  { id: "projects", label: "Projects", value: "21", trend: 4.8, comparison: "vs. last 90 days", icon: "building", tone: "info" },
  { id: "production", label: "Production Orders", value: "46", trend: 8.3, comparison: "vs. last 30 days", icon: "factory", tone: "warning" },
  { id: "receivables", label: "Receivables", value: "$84,210", trend: -5.2, comparison: "vs. last 30 days", icon: "invoice", tone: "danger" },
];

/* ---------- Revenue chart (§8.1) ---------- */
export const revenueSeries = {
  labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
  revenue: [62, 74, 69, 88, 96, 91, 110, 104, 122, 128, 141, 158].map((n) => n * 1000),
  orders: [18, 22, 20, 27, 30, 28, 34, 33, 39, 41, 45, 52],
};

export const revenueSummary = {
  totalRevenue: 1143000,
  revenueChange: 24.0,
  totalOrders: 347,
  ordersChange: 12.6,
};

/* ---------- Quotation pipeline (§8.2) ---------- */
export const pipeline = [
  { stage: "Draft", count: 24, pct: 100 },
  { stage: "Sent", count: 18, pct: 75 },
  { stage: "Viewed", count: 14, pct: 58 },
  { stage: "Negotiation", count: 9, pct: 38 },
  { stage: "Approved", count: 6, pct: 25 },
  { stage: "Won", count: 4, pct: 17 },
];

/* ---------- Recent leads (§8.3) + CRM board ---------- */
export type Lead = {
  id: string;
  name: string;
  company?: string;
  interest: string;
  source: string;
  date: string;
  status: LeadStatus;
  priority: "High" | "Medium" | "Low";
  owner: string;
  value?: string;
};

export const recentLeads: Lead[] = [
  { id: "L-1042", name: "Amara Sheikh", interest: "Modular Kitchen", source: "Website Form", date: "Oct 05", status: "New", priority: "High", owner: "Sana R.", value: "$18,400" },
  { id: "L-1041", name: "Bilal Traders", interest: "Office Fit-out", source: "Referral", date: "Oct 04", status: "Qualified", priority: "High", owner: "Omar K.", value: "$64,000" },
  { id: "L-1039", name: "Hina Farooq", interest: "Wardrobe + Bedroom", source: "Instagram", date: "Oct 04", status: "Meeting", priority: "Medium", owner: "Sana R.", value: "$12,750" },
  { id: "L-1036", name: "Gulberg Residency", interest: "Furniture Lot (24 units)", source: "Cold Outreach", date: "Oct 03", status: "Quotation", priority: "High", owner: "Daniyal M.", value: "$96,500" },
  { id: "L-1033", name: "Zeeshan Ahmed", interest: "TV Unit & Panel", source: "Walk-in", date: "Oct 02", status: "Won", priority: "Medium", owner: "Omar K.", value: "$7,900" },
  { id: "L-1030", name: "Maple Cafe", interest: "Cafe Seating", source: "Website Form", date: "Oct 01", status: "Lost", priority: "Low", owner: "Sana R.", value: "$9,300" },
];

export const kanbanColumns: { key: LeadStatus; leads: Lead[] }[] = [
  {
    key: "New",
    leads: [
      { id: "L-1042", name: "Amara Sheikh", interest: "Modular Kitchen", source: "Website Form", date: "Oct 05", status: "New", priority: "High", owner: "SR", value: "$18,400" },
      { id: "L-1044", name: "Clifton Villa 9", interest: "Full Interior", source: "Referral", date: "Oct 05", status: "New", priority: "Medium", owner: "OK", value: "$142,000" },
      { id: "L-1045", name: "Tariq Hotels Ltd", interest: "Lobby Furniture", source: "Expo", date: "Oct 04", status: "New", priority: "High", owner: "DM", value: "$210,000" },
    ],
  },
  {
    key: "Qualified",
    leads: [
      { id: "L-1041", name: "Bilal Traders", interest: "Office Fit-out", source: "Referral", date: "Oct 04", status: "Qualified", priority: "High", owner: "OK", value: "$64,000" },
      { id: "L-1040", name: "Nadia Clock Tower", interest: "Bedroom Sets", source: "Instagram", date: "Oct 03", status: "Qualified", priority: "Low", owner: "SR", value: "$15,200" },
    ],
  },
  {
    key: "Meeting",
    leads: [
      { id: "L-1039", name: "Hina Farooq", interest: "Wardrobe + Bedroom", source: "Instagram", date: "Oct 02", status: "Meeting", priority: "Medium", owner: "SR", value: "$12,750" },
      { id: "L-1038", name: "DHA Phase 6 — Villa 12", interest: "Kitchen + Wardrobes", source: "Website", date: "Oct 02", status: "Meeting", priority: "High", owner: "DM", value: "$48,900" },
    ],
  },
  {
    key: "Quotation",
    leads: [
      { id: "L-1036", name: "Gulberg Residency", interest: "Furniture Lot (24)", source: "Cold Outreach", date: "Oct 01", status: "Quotation", priority: "High", owner: "DM", value: "$96,500" },
      { id: "L-1037", name: "Zen Dental Clinic", interest: "Reception + Cabinetry", source: "Referral", date: "Oct 01", status: "Quotation", priority: "Medium", owner: "OK", value: "$22,400" },
    ],
  },
  {
    key: "Won",
    leads: [
      { id: "L-1033", name: "Zeeshan Ahmed", interest: "TV Unit & Panel", source: "Walk-in", date: "Sep 29", status: "Won", priority: "Medium", owner: "OK", value: "$7,900" },
      { id: "L-1032", name: "Bahria Town — Block C", interest: "Complete Interior", source: "Instagram", date: "Sep 27", status: "Won", priority: "High", owner: "SR", value: "$118,000" },
    ],
  },
  {
    key: "Lost",
    leads: [
      { id: "L-1030", name: "Maple Cafe", interest: "Cafe Seating", source: "Website", date: "Sep 24", status: "Lost", priority: "Low", owner: "SR", value: "$9,300" },
    ],
  },
];

/* ---------- Production & delivery (§8.4) ---------- */
export const opsStatus = [
  { label: "Production Orders", total: 46, done: 32, delayed: 5, pct: 70, icon: "factory", status: "In Progress" as OpStatus },
  { label: "Quality Check", total: 28, done: 21, delayed: 2, pct: 75, icon: "qc", status: "On Track" as OpStatus },
  { label: "Dispatch", total: 19, done: 14, delayed: 3, pct: 74, icon: "dispatch", status: "Delayed" as OpStatus },
  { label: "Delivery", total: 17, done: 15, delayed: 1, pct: 88, icon: "delivery", status: "On Track" as OpStatus },
];

/* ---------- Activity feed (§8.5) ---------- */
export const activity = [
  { id: 1, type: "lead", title: "New lead captured", who: "Amara Sheikh", context: "Website form — Modular Kitchen", time: "12m ago" },
  { id: 2, type: "quote", title: "Quotation #Q-2291 approved", who: "Gulberg Residency", context: "Approved by Finance · $96,500", time: "1h ago" },
  { id: 3, type: "production", title: "Production order started", who: "PO-418 · TV panel wall", context: "Workshop floor 2 · Owner: Faisal", time: "3h ago" },
  { id: 4, type: "delivery", title: "Delivery completed & signed off", who: "Zeeshan Ahmed", context: "Site: DHA 5 · Customer sign-off ✓", time: "5h ago" },
  { id: 5, type: "project", title: "Project created", who: "Bahria Town — Block C", context: "Complete interior · 6 milestones", time: "Yesterday" },
  { id: 6, type: "approval", title: "Design proposal pending approval", who: "Clifton Villa 9", context: "Awaiting client review", time: "Yesterday" },
];

/* ---------- Catalog (§Screen 03) ---------- */
export type Product = {
  id: string;
  name: string;
  category: "Sofas" | "Beds" | "Wardrobes" | "Kitchens" | "Dining" | "Office";
  price: number;
  material: string;
  finish: string;
  badge?: "New" | "Bestseller" | "Custom";
  hue: number;
};

export const products: Product[] = [
  { id: "WX-SF-01", name: "Lahore 3-Seater Sofa", category: "Sofas", price: 128000, material: "Solid Sheesham", finish: "Matte Walnut", badge: "Bestseller", hue: 96 },
  { id: "WX-BD-04", name: "Serenity King Bed", category: "Beds", price: 165000, material: "MDF + Ply Core", finish: "Pearl White", badge: "New", hue: 40 },
  { id: "WX-WR-02", name: "Aurora Sliding Wardrobe", category: "Wardrobes", price: 240000, material: "HMR Board", finish: "Smoked Oak", hue: 150 },
  { id: "WX-KT-07", name: "Executive Modular Kitchen", category: "Kitchens", price: 850000, material: "Ply + Acrylic", finish: "Sage Green", badge: "Custom", hue: 120 },
  { id: "WX-DN-03", name: "Harvest 8-Seater Dining", category: "Dining", price: 195000, material: "Solid Walnut", finish: "Natural Oil", hue: 28 },
  { id: "WX-OF-05", name: "Focus Executive Desk", category: "Office", price: 88000, material: "Laminate + Steel", finish: "Charcoal Ash", hue: 205 },
  { id: "WX-SF-09", name: "Meadow Lounge Chair", category: "Sofas", price: 46000, material: "Beech Frame", finish: "Caramel Leather", badge: "New", hue: 30 },
  { id: "WX-WR-06", name: "Nordic Walk-in Closet", category: "Wardrobes", price: 410000, material: "Ply + HMR", finish: "Arctic Birch", badge: "Custom", hue: 175 },
];

/* ---------- Projects (§Screen 04) ---------- */
export type Project = {
  id: string;
  name: string;
  client: string;
  location: string;
  status: OpStatus;
  progress: number;
  budget: number;
  spent: number;
  due: string;
  lead: string;
};

export const projects: Project[] = [
  { id: "PRJ-207", name: "Clifton Villa 9 — Full Interior", client: "Residency Group", location: "Clifton, Karachi", status: "In Progress", progress: 42, budget: 14200000, spent: 5964000, due: "Nov 28, 2026", lead: "Omar K." },
  { id: "PRJ-205", name: "Gulberg Residency — Furniture Lot", client: "Gulberg Developers", location: "Gulberg, Lahore", status: "On Track", progress: 68, budget: 9650000, spent: 6100000, due: "Oct 30, 2026", lead: "Daniyal M." },
  { id: "PRJ-203", name: "Bahria Block C — Complete Interior", client: "Private", location: "Bahria Town, Lahore", status: "On Track", progress: 21, budget: 11800000, spent: 2478000, due: "Jan 15, 2027", lead: "Sana R." },
  { id: "PRJ-198", name: "Tariq Hotels — Lobby Furniture", client: "Tariq Hotels Ltd", location: "F-6, Islamabad", status: "Delayed", progress: 55, budget: 21000000, spent: 11550000, due: "Oct 20, 2026", lead: "Daniyal M." },
];

export const boq = [
  { item: "TV Panel Wall (6.2m)", material: "Ply + Walnut Veneer", qty: 1, unit: "685,000", total: "685,000", status: "In Progress" as OpStatus },
  { item: "False Ceiling — Living", material: "Gypsum + LED Cove", qty: 42, unit: "1,250", total: "52,500", status: "Completed" as OpStatus },
  { item: "Modular Kitchen", material: "Acrylic Shutters", qty: 1, unit: "1,240,000", total: "1,240,000", status: "Pending" as OpStatus },
  { item: "Master Wardrobe (Sliding)", material: "HMR + Mirror", qty: 1, unit: "390,000", total: "390,000", status: "In Progress" as OpStatus },
  { item: "Bedroom Panel + Bed", material: "MDF, Pearl White", qty: 1, unit: "455,000", total: "455,000", status: "On Hold" as OpStatus },
];

export const milestones = [
  { title: "Design proposal signed", date: "Aug 12", done: true },
  { title: "Site measurements & visit", date: "Aug 20", done: true },
  { title: "BOQ approval", date: "Sep 02", done: true },
  { title: "Production phase 1", date: "Sep 28", done: true },
  { title: "Civil + ceiling handover", date: "Oct 18", done: false },
  { title: "Installation phase 1", date: "Nov 06", done: false },
  { title: "Final handover & sign-off", date: "Nov 28", done: false },
];

export const projectFiles = [
  { name: "FloorPlan-v4.pdf", size: "3.2 MB", kind: "PDF", updated: "Oct 02" },
  { name: "Design-Proposal.pdf", size: "12.6 MB", kind: "PDF", updated: "Sep 30" },
  { name: "BOQ-CliftonV9.xlsx", size: "210 KB", kind: "XLS", updated: "Sep 28" },
  { name: "Site-Visit-Photos.zip", size: "84 MB", kind: "ZIP", updated: "Sep 21" },
];

/* ---------- Operations (§Screen 05) ---------- */
export type OpOrder = {
  id: string;
  customer: string;
  item: string;
  owner: string;
  due: string;
  status: OpStatus;
  exception?: string;
};

export const opStages: { key: string; label: string; orders: OpOrder[] }[] = [
  {
    key: "production",
    label: "Production",
    orders: [
      { id: "PO-418", customer: "Clifton Villa 9", item: "TV panel wall", owner: "Faisal", due: "Oct 12", status: "In Progress" },
      { id: "PO-419", customer: "Gulberg Residency", item: "24 dining sets", owner: "Usman", due: "Oct 15", status: "On Track" },
      { id: "PO-421", customer: "Zen Dental", item: "Reception counter", owner: "Faisal", due: "Oct 18", status: "Pending" },
      { id: "PO-415", customer: "Tariq Hotels", item: "Lobby lounge frames", owner: "Ahmed", due: "Oct 08", status: "Delayed", exception: "Hardware import stuck at customs" },
    ],
  },
  {
    key: "qc",
    label: "Quality Check",
    orders: [
      { id: "QC-112", customer: "Bahria Block C", item: "Bedroom panels", owner: "Hira", due: "Oct 07", status: "In Progress" },
      { id: "QC-113", customer: "Gulberg Residency", item: "Sofa batch #1 (8)", owner: "Hira", due: "Oct 08", status: "On Track" },
      { id: "QC-110", customer: "Zeeshan Ahmed", item: "TV unit finish", owner: "Bilal", due: "Oct 05", status: "Completed" },
    ],
  },
  {
    key: "dispatch",
    label: "Dispatch",
    orders: [
      { id: "DS-88", customer: "Zeeshan Ahmed", item: "TV unit + panel", owner: "Logistics", due: "Oct 06", status: "Pending" },
      { id: "DS-87", customer: "Bahria Block C", item: "Wardrobe batch", owner: "Logistics", due: "Oct 07", status: "On Hold", exception: "Site access not confirmed" },
    ],
  },
  {
    key: "delivery",
    label: "Delivery",
    orders: [
      { id: "DL-61", customer: "Maple Cafe", item: "Seating (re-delivery)", owner: "Tariq", due: "Oct 04", status: "Delayed", exception: "Customer rescheduled twice" },
      { id: "DL-60", customer: "Nadia Clock Tower", item: "Bedroom set", owner: "Tariq", due: "Oct 05", status: "In Progress" },
    ],
  },
  {
    key: "installation",
    label: "Installation",
    orders: [
      { id: "IN-31", customer: "Gulberg Residency", item: "Showroom install", owner: "Kamran", due: "Oct 09", status: "On Track" },
      { id: "IN-30", customer: "Zeeshan Ahmed", item: "Wall mounting", owner: "Kamran", due: "Oct 06", status: "Completed" },
    ],
  },
];

/* ---------- Analytics (§Screen 06) ---------- */
export const leadSources = [
  { label: "Website / SEO", value: 412, pct: 32, tone: "primary" as Tone },
  { label: "Instagram / Meta", value: 322, pct: 25, tone: "info" as Tone },
  { label: "Referrals", value: 258, pct: 20, tone: "success" as Tone },
  { label: "Walk-in / Showroom", value: 155, pct: 12, tone: "warning" as Tone },
  { label: "Cold Outreach", value: 129, pct: 11, tone: "muted" as Tone },
];

export const campaigns = [
  { name: "Kitchen Makeover — Oct", channel: "Meta Ads", spend: 320000, leads: 96, cpl: 3333, conv: 8.4, revenue: 1840000, status: "In Progress" as OpStatus },
  { name: "Woodex Interiors Brand", channel: "Google Search", spend: 210000, leads: 64, cpl: 3281, conv: 11.2, revenue: 1420000, status: "On Track" as OpStatus },
  { name: "Furniture Expo Booth", channel: "Offline", spend: 480000, leads: 58, cpl: 8276, conv: 6.9, revenue: 2260000, status: "Completed" as OpStatus },
  { name: "Retargeting — Quote Drop-off", channel: "Meta Ads", spend: 84000, leads: 22, cpl: 3818, conv: 13.6, revenue: 620000, status: "Delayed" as OpStatus },
];

export const conversionTrend = {
  labels: ["May", "Jun", "Jul", "Aug", "Sep", "Oct"],
  values: [6.2, 7.1, 6.8, 8.4, 9.0, 9.6],
};

/* ---------- Quotes ---------- */
export const quotes = [
  { id: "Q-2291", customer: "Gulberg Residency", stage: "Approved" as QuoteStatus, total: "$96,500", date: "Oct 04", items: 24 },
  { id: "Q-2294", customer: "Clifton Villa 9", stage: "Negotiation" as QuoteStatus, total: "$142,000", date: "Oct 04", items: 11 },
  { id: "Q-2290", customer: "Zen Dental Clinic", stage: "Viewed" as QuoteStatus, total: "$22,400", date: "Oct 03", items: 6 },
  { id: "Q-2288", customer: "Hina Farooq", stage: "Sent" as QuoteStatus, total: "$12,750", date: "Oct 02", items: 3 },
  { id: "Q-2285", customer: "Maple Cafe", stage: "Expired" as QuoteStatus, total: "$9,300", date: "Sep 26", items: 4 },
];

/* ---------- Notifications ---------- */
export const notifications = [
  { id: 1, tone: "success" as Tone, title: "Quotation Q-2291 approved", desc: "Gulberg Residency · $96,500", time: "12m ago" },
  { id: 2, tone: "warning" as Tone, title: "PO-415 delayed", desc: "Customs hold on imported hardware", time: "1h ago" },
  { id: 3, tone: "info" as Tone, title: "New lead from website", desc: "Amara Sheikh — Modular Kitchen", time: "3h ago" },
  { id: 4, tone: "danger" as Tone, title: "Invoice INV-0142 overdue", desc: "Tariq Hotels · $21,400 outstanding", time: "Yesterday" },
];
