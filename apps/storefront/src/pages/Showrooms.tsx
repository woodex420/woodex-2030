import { useEffect } from "react";
import { brand } from "@/config/brand";
import { Link } from "react-router-dom";
import { MapPin, Phone, Clock, Navigation } from "lucide-react";
import { Button } from "@/components/ui/button";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { img } from "@/assets/img";

/* One showroom: the address in config/brand.ts. Four more stood here (Karachi, Islamabad, Rawalpindi,
   Faisalabad), each with a sample street and a number nobody can dial. A visitor could have set out for one of
   them. They are gone until the owner gives a real address for each (decision DL-048;
   scripts/test-storefront-facts.mjs names the samples and keeps them out). */
const showrooms = [
  {
    city: brand.address.city,
    address: `${brand.address.street}, ${brand.address.city}`,
    phone: brand.phone,
    hours: brand.hours,
    isHQ: true,
  },
];

const Showrooms = () => {
  useEffect(() => {
    document.title = "Showroom — WOODEX Pakistan";
  }, []);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      <main className="flex-1">
        {/* Hero */}
        <section className="relative h-64 overflow-hidden bg-primary">
          <img src={img("virtual-showroom-hero.jpg")} alt="WOODEX showroom" className="absolute inset-0 w-full h-full object-cover opacity-30" />
          <div className="absolute inset-0 flex items-center">
            <div className="container mx-auto px-4">
              <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">Experience In Person</p>
              <h1 className="text-4xl lg:text-5xl font-black text-primary-foreground">Visit Our Showroom</h1>
              <p className="text-primary-foreground/70 mt-3 max-w-xl">
                See and try our furniture in person at our showroom in {brand.address.city}.
              </p>
            </div>
          </div>
        </section>

        {/* Showrooms Grid */}
        <section className="py-16">
          <div className="container mx-auto px-4">
            <div className="mx-auto grid max-w-md gap-6">
              {showrooms.map((s) => (
                <div
                  key={s.city}
                  className={`p-6 border rounded-sm hover:border-accent transition-all hover:shadow-lg ${s.isHQ ? "border-accent bg-hon-green-pale" : "border-border bg-background"}`}
                >
                  {s.isHQ && (
                    <span className="text-xs font-bold uppercase tracking-widest text-accent mb-3 block">Showroom</span>
                  )}
                  <div className="w-10 h-1 bg-accent mb-4" />
                  <h2 className="font-bold text-xl mb-4">{s.city}</h2>
                  <div className="space-y-3 text-sm text-muted-foreground mb-5">
                    <div className="flex items-start gap-2.5">
                      <MapPin className="h-4 w-4 text-accent mt-0.5 flex-shrink-0" />
                      <span>{s.address}</span>
                    </div>
                    <div className="flex items-center gap-2.5">
                      <Phone className="h-4 w-4 text-accent flex-shrink-0" />
                      <a href={brand.phoneLink} className="hover:text-accent">{s.phone}</a>
                    </div>
                    <div className="flex items-center gap-2.5">
                      <Clock className="h-4 w-4 text-accent flex-shrink-0" />
                      <span>{s.hours}</span>
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      className="flex-1 bg-accent hover:bg-hon-green-dark text-accent-foreground"
                      onClick={() => window.open(brand.whatsappLink, "_blank")}
                    >
                      WhatsApp
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      className="flex-1 border-accent text-accent hover:bg-accent hover:text-accent-foreground"
                      onClick={() => window.open(`https://maps.google.com/?q=${brand.geo.lat},${brand.geo.lng}`, "_blank")}
                    >
                      <Navigation className="h-3.5 w-3.5 mr-1.5" />
                      Directions
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Map Placeholder */}
        <section className="py-0">
          <div className="bg-section-light h-72 flex items-center justify-center border-y border-border">
            <div className="text-center">
              <MapPin className="h-12 w-12 text-accent mx-auto mb-3" />
              <p className="font-bold text-lg mb-1">{brand.address.street}</p>
              <p className="text-muted-foreground text-sm">{brand.address.city}, Pakistan · {brand.hours}</p>
              <Button variant="outline" className="mt-4 border-accent text-accent hover:bg-accent hover:text-accent-foreground" asChild>
                <Link to="/virtual-showroom">Explore Virtual Showroom</Link>
              </Button>
            </div>
          </div>
        </section>

        {/* Virtual Option */}
        <section className="py-14 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl font-bold mb-3">Can't Visit In Person?</h2>
            <p className="text-primary-foreground/75 mb-7 max-w-md mx-auto">
              Experience our furniture collection through our immersive virtual showroom from the comfort of your office.
            </p>
            <Button className="bg-accent hover:bg-hon-green-dark text-accent-foreground px-8" asChild>
              <Link to="/virtual-showroom">Launch Virtual Showroom</Link>
            </Button>
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default Showrooms;
