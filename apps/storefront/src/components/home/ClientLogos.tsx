import { useEffect, useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";

const clients = [
  "Etihad Town", "Haier", "MindBridge", "PACHEM Global", "PAF",
  "DHA Developers", "TechHub PK", "NLC", "SKMT", "HBL",
];

const ClientLogos = () => {
  const [offset, setOffset] = useState(0);

  useEffect(() => {
    const timer = setInterval(() => setOffset((o) => (o + 1) % clients.length), 3000);
    return () => clearInterval(timer);
  }, []);

  const getVisible = () => {
    const result = [];
    for (let i = 0; i < 5; i++) {
      result.push(clients[(offset + i) % clients.length]);
    }
    return result;
  };

  return (
    <section className="py-14 bg-section-light border-t">
      <div className="container mx-auto px-4">
        <div className="text-center mb-8">
          <h2 className="text-2xl lg:text-3xl font-bold mb-2">Our Prestigious Clients</h2>
          <p className="text-muted-foreground text-sm">Join 1,200+ satisfied clients across Pakistan who trust WOODEX for their furniture needs.</p>
        </div>
        <div className="relative flex items-center">
          <button onClick={() => setOffset((o) => (o - 1 + clients.length) % clients.length)} className="w-8 h-8 rounded-full border border-border hover:border-accent flex items-center justify-center transition-colors flex-shrink-0">
            <ChevronLeft className="h-4 w-4" />
          </button>
          <div className="flex-1 grid grid-cols-3 md:grid-cols-5 gap-6 px-4">
            {getVisible().map((client, i) => (
              <div key={`${client}-${i}`} className="flex items-center justify-center h-16 px-4 border border-border rounded-sm bg-background">
                <span className="font-bold text-sm text-muted-foreground tracking-wide">{client}</span>
              </div>
            ))}
          </div>
          <button onClick={() => setOffset((o) => (o + 1) % clients.length)} className="w-8 h-8 rounded-full border border-border hover:border-accent flex items-center justify-center transition-colors flex-shrink-0">
            <ChevronRight className="h-4 w-4" />
          </button>
        </div>
      </div>
    </section>
  );
};

export default ClientLogos;
