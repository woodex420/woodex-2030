import { useState, useEffect } from "react";
import { Star, ChevronLeft, ChevronRight } from "lucide-react";

const reviews = [
  { name: "Kinza Nisar", time: "3 months ago", text: "Modern designs and durable materials. Highly recommend WOODEX for office furniture.", rating: 5 },
  { name: "Kamran Munir", time: "3 months ago", text: "Excellent service, top quality products and cooperative management.", rating: 5 },
  { name: "Mudassar Iqbal", time: "4 months ago", text: "I am blown away by the exceptional service and quality provided by them. I experienced exceptional craftsmanship.", rating: 5 },
  { name: "Sara Ahmed", time: "2 months ago", text: "Best furniture manufacturer in Lahore. The quality of wood and finishing is outstanding.", rating: 5 },
  { name: "Ali Hassan", time: "1 month ago", text: "Delivered on time and exactly as promised. Our new office looks amazing with WOODEX furniture.", rating: 5 },
];

const GoogleReviews = () => {
  const [startIdx, setStartIdx] = useState(0);
  const visibleCount = 3;

  useEffect(() => {
    const timer = setInterval(() => {
      setStartIdx((i) => (i + 1) % reviews.length);
    }, 5000);
    return () => clearInterval(timer);
  }, []);

  const getVisible = () => {
    const result = [];
    for (let i = 0; i < visibleCount; i++) {
      result.push(reviews[(startIdx + i) % reviews.length]);
    }
    return result;
  };

  return (
    <section className="py-16 bg-background border-t">
      <div className="container mx-auto px-4">
        <div className="text-center mb-10">
          <div className="w-12 h-1 bg-accent mx-auto mb-5" />
          <h2 className="text-3xl font-bold mb-3">What Our Clients Say</h2>
        </div>
        <div className="flex flex-col lg:flex-row items-center gap-10">
          {/* Google badge */}
          <div className="text-center lg:text-left flex-shrink-0">
            <p className="font-bold text-lg mb-1">WOODEX Office Furniture</p>
            <div className="flex items-center justify-center lg:justify-start gap-0.5 mb-1">
              {Array(5).fill(0).map((_, i) => <Star key={i} className="h-5 w-5 fill-amber-400 text-amber-400" />)}
            </div>
            <p className="text-muted-foreground text-sm">57 Google reviews</p>
            <button className="mt-3 border border-border rounded-sm px-4 py-2 text-sm font-semibold hover:border-accent transition-colors">
              Write a review
            </button>
          </div>

          {/* Review cards slider */}
          <div className="flex-1 relative">
            <div className="grid md:grid-cols-3 gap-4">
              {getVisible().map((r, idx) => (
                <div key={`${r.name}-${idx}`} className="bg-section-light rounded-sm p-5 transition-all">
                  <div className="flex items-center gap-3 mb-3">
                    <div className="w-9 h-9 rounded-full bg-accent/20 flex items-center justify-center text-accent font-bold text-sm">
                      {r.name[0]}
                    </div>
                    <div>
                      <p className="font-semibold text-sm">{r.name}</p>
                      <p className="text-xs text-muted-foreground">{r.time}</p>
                    </div>
                  </div>
                  <div className="flex gap-0.5 mb-2">
                    {Array(r.rating).fill(0).map((_, i) => <Star key={i} className="h-3.5 w-3.5 fill-amber-400 text-amber-400" />)}
                  </div>
                  <p className="text-sm text-muted-foreground leading-relaxed">{r.text}</p>
                </div>
              ))}
            </div>
            <div className="flex justify-center gap-2 mt-6">
              <button onClick={() => setStartIdx((i) => (i - 1 + reviews.length) % reviews.length)} className="w-8 h-8 rounded-full border border-border hover:border-accent flex items-center justify-center transition-colors">
                <ChevronLeft className="h-4 w-4" />
              </button>
              <button onClick={() => setStartIdx((i) => (i + 1) % reviews.length)} className="w-8 h-8 rounded-full border border-border hover:border-accent flex items-center justify-center transition-colors">
                <ChevronRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};

export default GoogleReviews;
