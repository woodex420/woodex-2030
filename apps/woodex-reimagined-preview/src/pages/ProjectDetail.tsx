import { useState } from "react";
import { useParams, Link } from "react-router-dom";
import { ArrowLeft, MapPin, Calendar, Ruler, Users, ChevronLeft, ChevronRight, Star, CheckCircle2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import proj1 from "@/assets/project-1.jpg";
import proj2 from "@/assets/project-2.jpg";
import proj3 from "@/assets/project-3.jpg";
import proj4 from "@/assets/project-4.jpg";
import proj5 from "@/assets/project-5.jpg";
import proj6 from "@/assets/project-6.jpg";
import projSalon from "@/assets/project-hair-salon.jpg";

const projectsData = [
  {
    id: "1",
    title: "DHA Corporate Tower",
    client: "DHA Developers",
    category: "Corporate",
    image: proj1,
    beforeImage: proj2,
    location: "Lahore",
    sqft: "12,000 sq ft",
    year: "2024",
    employees: "250+",
    duration: "8 weeks",
    description: "Complete C-suite office fit-out with custom executive furniture, meeting rooms, and collaborative spaces for one of Lahore's most prestigious corporate towers.",
    challenge: "The client needed a complete furniture solution for all 6 floors within a tight 8-week timeline without disrupting ongoing business operations.",
    solution: "WOODEX deployed a phased delivery approach, furnishing one floor at a time while production continued for subsequent floors. Custom executive furniture was crafted to match the building's architectural language.",
    furnitureUsed: ["Executive desks — 15 units", "ErgoMax chairs — 250 units", "Board conference tables — 3", "Workstations — 120 units", "Sofas & lounge furniture — 8 sets"],
    testimonial: { text: "WOODEX delivered beyond our expectations. The quality is exceptional and the team handled our project with complete professionalism.", author: "Mr. Khalid Mahmood", role: "Director Operations, DHA Developers" },
    rating: 5,
  },
  {
    id: "2",
    title: "TechHub Karachi",
    client: "TechHub Pakistan",
    category: "Tech",
    image: proj2,
    beforeImage: proj1,
    location: "Karachi",
    sqft: "8,500 sq ft",
    year: "2024",
    employees: "150+",
    duration: "6 weeks",
    description: "Modern open-plan tech office with agile workstations, standing desks, and vibrant breakout areas designed to foster innovation and collaboration.",
    challenge: "Creating a dynamic, modern office that would attract and retain top tech talent while maximizing space utilization in a busy Karachi commercial district.",
    solution: "WOODEX designed a flexible layout using the Infinity Series workstations combined with height-adjustable desks, creating adaptable spaces that teams can reconfigure as projects evolve.",
    furnitureUsed: ["Infinity workstations — 80 units", "Height-adjustable desks — 40", "Meeting pods — 8", "Lounge furniture — 6 sets", "Cafe tables & stools — 20 units"],
    testimonial: { text: "Our team loves the new office. The furniture is exactly what we needed — flexible, modern, and built to last.", author: "Sara Malik", role: "CEO, TechHub Pakistan" },
    rating: 5,
  },
  {
    id: "3",
    title: "Shaukat Khanum Clinic",
    client: "SKMT Foundation",
    category: "Healthcare",
    image: proj3,
    beforeImage: proj4,
    location: "Islamabad",
    sqft: "4,200 sq ft",
    year: "2023",
    employees: "80+",
    duration: "4 weeks",
    description: "Healthcare-grade furniture for waiting areas, consultation rooms, and staff offices, meeting strict hygiene and durability requirements.",
    challenge: "Healthcare environments demand furniture that is durable, easy to sanitize, and comfortable for long waiting periods.",
    solution: "WOODEX specified antimicrobial upholstery fabrics, stainless steel accents, and seamless surfaces across all patient-facing areas while maintaining a calming, professional aesthetic.",
    furnitureUsed: ["Waiting benches — 20 units", "Consultation chairs — 40", "Doctor desks — 15", "Nurse stations — 4", "Staff seating — 60 units"],
    testimonial: { text: "The furniture meets all our healthcare standards while creating a welcoming environment for our patients.", author: "Dr. Amina Raza", role: "Operations Manager, SKMT" },
    rating: 5,
  },
  {
    id: "4",
    title: "LUMS Library Expansion",
    client: "LUMS University",
    category: "Education",
    image: proj4,
    beforeImage: proj3,
    location: "Lahore",
    sqft: "6,800 sq ft",
    year: "2023",
    employees: "3,000 students",
    duration: "5 weeks",
    description: "Academic furniture including collaborative study tables, individual reading pods, and faculty offices designed for focused learning.",
    challenge: "Balancing collaborative study spaces with individual focus areas while accommodating thousands of daily student users.",
    solution: "A hybrid layout combining group study tables with individual acoustic pods, all ergonomically designed for extended study sessions.",
    furnitureUsed: ["Study tables — 60 units", "Library chairs — 200", "Individual pods — 30", "Faculty desks — 20", "Bookshelves — 40 units"],
    testimonial: { text: "Students love the new library. The furniture perfectly balances aesthetics with functionality.", author: "Prof. Ali Hassan", role: "Dean of Libraries, LUMS" },
    rating: 5,
  },
  {
    id: "5",
    title: "FBR Regional Office",
    client: "Federal Board of Revenue",
    category: "Government",
    image: proj5,
    beforeImage: proj6,
    location: "Islamabad",
    sqft: "15,000 sq ft",
    year: "2023",
    employees: "400+",
    duration: "10 weeks",
    description: "Government-standard office furniture for multiple floors of the regional headquarters, compliant with federal procurement standards.",
    challenge: "Meeting strict government procurement standards while delivering a modern, functional workspace within budget constraints.",
    solution: "WOODEX provided detailed technical specifications compliant with government standards, sourcing certified materials and providing full documentation for compliance audits.",
    furnitureUsed: ["Staff desks — 300 units", "Executive furniture — 30 sets", "Conference tables — 6", "File storage units — 150", "Visitor chairs — 200"],
    testimonial: { text: "WOODEX met all government specifications and delivered on time. Excellent quality and professional service.", author: "Mr. Farrukh Ahmed", role: "Director Administration, FBR" },
    rating: 4,
  },
  {
    id: "6",
    title: "PC Hotel Business Center",
    client: "Pearl Continental",
    category: "Hospitality",
    image: proj6,
    beforeImage: proj5,
    location: "Lahore",
    sqft: "3,500 sq ft",
    year: "2024",
    employees: "N/A",
    duration: "3 weeks",
    description: "Luxury lounge and business center furniture for a 5-star hospitality environment requiring premium aesthetics and durability.",
    challenge: "5-star hospitality demands the highest level of aesthetic refinement combined with commercial durability for constant guest use.",
    solution: "Custom Woodex Series furniture in bespoke finishes matching the hotel's interior design language, with commercial-grade upholstery rated for 100,000 rubs.",
    furnitureUsed: ["Lounge sofas — 8 sets", "Business center desks — 12", "Premium chairs — 40", "Coffee tables — 10", "Reception furniture — 2 sets"],
    testimonial: { text: "The furniture perfectly complements our 5-star brand. Guests frequently compliment the business center setup.", author: "Mr. Hassan Nawaz", role: "General Manager, PC Hotel Lahore" },
    rating: 5,
  },
  {
    id: "7",
    title: "Gents Grooming Lounge — DHA Lahore",
    client: "Private Boutique Salon",
    category: "Hospitality",
    image: projSalon,
    beforeImage: proj6,
    location: "DHA Phase 5, Lahore",
    sqft: "3,500 sq ft",
    year: "2025",
    employees: "12 stylists",
    duration: "7 weeks",
    description:
      "End-to-end concept-to-execution interior and furniture solution for a premium men's hair salon and grooming lounge in DHA Phase 5, Lahore. WOODEX delivered architectural design, custom joinery, lighting and furnishings across a 3,500 sq ft open-plan space — including a 6-station barber row, two private grooming alcoves, a beard-bar, retail display and a guest waiting lounge.",
    challenge:
      "The client wanted a luxury London-meets-Lahore barbershop experience inside a raw 3,500 sq ft commercial unit — no existing interior, no joinery, no MEP. The brief: a moody, masculine, Instagram-worthy space that would also pass commercial water, drainage and ventilation standards for shampoo stations and could open to paying customers within 8 weeks.",
    solution:
      "WOODEX took the project from concept to keys. Our designers produced 2D layouts and photoreal 3D renders within 10 days, then ran a turnkey fit-out: walnut wall panelling, polished concrete flooring, brass-framed wall mirrors, Carrara marble grooming counters, deep forest-green feature wall, vintage Edison pendants, and custom-built walnut barber consoles with integrated tool storage. Bespoke leather-and-brass barber chairs were sourced and installed alongside three private grooming alcoves wrapped in walnut veneer.",
    furnitureUsed: [
      "Custom walnut barber consoles — 6 units",
      "Brass-framed wall mirrors — 6 units",
      "Premium leather barber chairs — 6 units",
      "Private grooming alcoves — 3 (walnut-clad)",
      "Carrara marble grooming counters — 18 lm",
      "Retail display joinery — 1 wall",
      "Guest lounge sofa & coffee tables — 1 set",
      "Beard-bar with integrated lighting — 1",
    ],
    testimonial: {
      text: "WOODEX delivered exactly the moody, premium grooming lounge we'd been chasing on Pinterest for two years — and they did it in seven weeks. Every joint, every brass fitting, every light is exactly where it should be.",
      author: "Mr. Hamza Sheikh",
      role: "Founder, Gents Grooming Lounge DHA",
    },
    rating: 5,
  },
];

const ProjectDetail = () => {
  const { projectId } = useParams<{ projectId: string }>();
  const project = projectsData.find((p) => p.id === projectId);
  const [showBefore, setShowBefore] = useState(false);
  const [galleryIdx, setGalleryIdx] = useState(0);

  const gallery = project ? [project.image, project.beforeImage, proj1, proj2] : [];

  if (!project) {
    return (
      <div className="min-h-screen flex flex-col">
        <Header />
        <main className="flex-1 flex items-center justify-center">
          <div className="text-center">
            <h1 className="text-2xl font-bold mb-4">Project Not Found</h1>
            <Button asChild><Link to="/projects">View All Projects</Link></Button>
          </div>
        </main>
        <Footer />
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      <main className="flex-1">
        {/* Hero */}
        <section className="relative h-[55vh] min-h-[380px] overflow-hidden bg-primary">
          <img src={project.image} alt={project.title} className="absolute inset-0 w-full h-full object-cover opacity-40" />
          <div className="absolute inset-0 bg-gradient-to-t from-primary via-primary/50 to-transparent" />
          <div className="absolute inset-0 flex items-end pb-10">
            <div className="container mx-auto px-4">
              <div className="flex items-center gap-2 text-sm mb-3">
                <Link to="/projects" className="text-accent hover:underline flex items-center gap-1">
                  <ArrowLeft className="h-3.5 w-3.5" /> Portfolio
                </Link>
              </div>
              <Badge className="bg-accent text-accent-foreground mb-3">{project.category}</Badge>
              <h1 className="text-3xl lg:text-5xl font-black text-primary-foreground mb-2">{project.title}</h1>
              <p className="text-accent font-semibold">{project.client}</p>
            </div>
          </div>
        </section>

        {/* Stats Bar */}
        <section className="bg-accent text-accent-foreground py-5">
          <div className="container mx-auto px-4">
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
              {[
                { icon: MapPin, label: "Location", value: project.location },
                { icon: Ruler, label: "Project Size", value: project.sqft },
                { icon: Users, label: "Staff", value: project.employees },
                { icon: Calendar, label: "Completion", value: `${project.duration} — ${project.year}` },
              ].map((stat) => (
                <div key={stat.label} className="flex items-center gap-2.5">
                  <stat.icon className="h-4 w-4 flex-shrink-0 opacity-80" />
                  <div>
                    <p className="text-xs opacity-75">{stat.label}</p>
                    <p className="font-semibold">{stat.value}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        <section className="py-16">
          <div className="container mx-auto px-4">
            <div className="grid lg:grid-cols-3 gap-10">
              {/* Main Content */}
              <div className="lg:col-span-2 space-y-10">
                {/* Gallery */}
                <div>
                  <div className="relative aspect-video rounded-sm overflow-hidden bg-muted mb-3">
                    <img src={gallery[galleryIdx]} alt={project.title} className="w-full h-full object-cover" />
                    <button
                      onClick={() => setGalleryIdx((i) => (i - 1 + gallery.length) % gallery.length)}
                      className="absolute left-3 top-1/2 -translate-y-1/2 w-9 h-9 bg-primary/70 hover:bg-primary rounded-full flex items-center justify-center text-white"
                    >
                      <ChevronLeft className="h-5 w-5" />
                    </button>
                    <button
                      onClick={() => setGalleryIdx((i) => (i + 1) % gallery.length)}
                      className="absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 bg-primary/70 hover:bg-primary rounded-full flex items-center justify-center text-white"
                    >
                      <ChevronRight className="h-5 w-5" />
                    </button>
                    <div className="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-1.5">
                      {gallery.map((_, i) => (
                        <button key={i} onClick={() => setGalleryIdx(i)} className={`h-1.5 rounded-full transition-all ${i === galleryIdx ? "w-6 bg-accent" : "w-1.5 bg-white/50"}`} />
                      ))}
                    </div>
                  </div>
                  <div className="flex gap-2">
                    {gallery.slice(0, 4).map((img, i) => (
                      <button key={i} onClick={() => setGalleryIdx(i)} className={`flex-1 aspect-video rounded-sm overflow-hidden border-2 transition-colors ${galleryIdx === i ? "border-accent" : "border-border"}`}>
                        <img src={img} alt="" className="w-full h-full object-cover" />
                      </button>
                    ))}
                  </div>
                </div>

                {/* Before / After */}
                <div>
                  <h2 className="text-2xl font-bold mb-4">Before & After</h2>
                  <div className="relative aspect-video rounded-sm overflow-hidden bg-muted">
                    <img src={showBefore ? project.beforeImage : project.image} alt={showBefore ? "Before" : "After"} className="w-full h-full object-cover transition-opacity duration-500" />
                    <div className="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2">
                      <button onClick={() => setShowBefore(true)} className={`px-5 py-2 rounded-full text-sm font-semibold transition-all ${showBefore ? "bg-accent text-accent-foreground" : "bg-primary/60 text-white hover:bg-primary"}`}>
                        Before
                      </button>
                      <button onClick={() => setShowBefore(false)} className={`px-5 py-2 rounded-full text-sm font-semibold transition-all ${!showBefore ? "bg-accent text-accent-foreground" : "bg-primary/60 text-white hover:bg-primary"}`}>
                        After
                      </button>
                    </div>
                    <div className="absolute top-4 left-4">
                      <Badge className={showBefore ? "bg-primary text-primary-foreground" : "bg-accent text-accent-foreground"}>
                        {showBefore ? "Before Renovation" : "After WOODEX"}
                      </Badge>
                    </div>
                  </div>
                </div>

                {/* Description */}
                <div>
                  <div className="w-12 h-1 bg-accent mb-4" />
                  <h2 className="text-2xl font-bold mb-4">Project Overview</h2>
                  <p className="text-muted-foreground leading-relaxed mb-6">{project.description}</p>
                  <h3 className="font-bold text-lg mb-2">The Challenge</h3>
                  <p className="text-muted-foreground leading-relaxed mb-6">{project.challenge}</p>
                  <h3 className="font-bold text-lg mb-2">Our Solution</h3>
                  <p className="text-muted-foreground leading-relaxed">{project.solution}</p>
                </div>
              </div>

              {/* Sidebar */}
              <div className="space-y-6">
                {/* Furniture Used */}
                <div className="p-6 border border-border rounded-sm">
                  <div className="w-8 h-1 bg-accent mb-4" />
                  <h3 className="font-bold mb-4">Furniture Used</h3>
                  <ul className="space-y-2.5">
                    {project.furnitureUsed.map((item) => (
                      <li key={item} className="flex items-start gap-2.5 text-sm text-muted-foreground">
                        <CheckCircle2 className="h-4 w-4 text-accent flex-shrink-0 mt-0.5" />
                        {item}
                      </li>
                    ))}
                  </ul>
                </div>

                {/* Testimonial */}
                <div className="p-6 bg-section-light border border-border rounded-sm">
                  <div className="flex gap-0.5 mb-3">
                    {Array(project.rating).fill(0).map((_, i) => (
                      <Star key={i} className="h-4 w-4 fill-amber-400 text-amber-400" />
                    ))}
                  </div>
                  <p className="text-sm text-muted-foreground italic mb-4">"{project.testimonial.text}"</p>
                  <p className="font-bold text-sm">{project.testimonial.author}</p>
                  <p className="text-xs text-muted-foreground">{project.testimonial.role}</p>
                </div>

                {/* CTA */}
                <div className="p-6 bg-accent text-accent-foreground rounded-sm">
                  <h3 className="font-bold text-lg mb-2">Want a Similar Project?</h3>
                  <p className="text-sm opacity-85 mb-4">Tell us about your space and we'll create a tailored solution.</p>
                  <Button className="w-full bg-primary hover:bg-primary/90 text-primary-foreground" asChild>
                    <Link to="/quotation">Request a Quote</Link>
                  </Button>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* Back to Projects */}
        <section className="py-10 border-t bg-section-light">
          <div className="container mx-auto px-4 text-center">
            <Button variant="outline" className="border-accent text-accent hover:bg-accent hover:text-accent-foreground" asChild>
              <Link to="/projects"><ArrowLeft className="h-4 w-4 mr-2" />Back to All Projects</Link>
            </Button>
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default ProjectDetail;
