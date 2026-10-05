import { useState } from "react";
import { Link } from "react-router-dom";
import { Phone, Mail, MapPin, Clock, Facebook, Twitter, Linkedin, Instagram, Youtube, ArrowRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

const Footer = () => {
  const [email, setEmail] = useState("");
  const [subscribed, setSubscribed] = useState(false);

  const handleSubscribe = (e: React.FormEvent) => {
    e.preventDefault();
    if (email.trim()) {
      setSubscribed(true);
      setEmail("");
      setTimeout(() => setSubscribed(false), 3000);
    }
  };

  return (
    <footer className="bg-primary text-primary-foreground mt-auto">
      {/* Main Footer */}
      <div className="container mx-auto px-4 py-14">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-8">
          {/* Col 1: Brand */}
          <div className="lg:col-span-1">
            <div className="mb-4">
              <span className="text-2xl font-black tracking-tight">WOODEX</span>
              <p className="text-xs text-primary-foreground/50 font-normal mt-0.5">Make your space work®</p>
            </div>
            <p className="text-sm text-primary-foreground/70 leading-relaxed mb-6">
              Pakistan's premium office furniture manufacturer delivering exceptional design-to-delivery
              solutions for modern workspaces.
            </p>
            <div className="space-y-2.5 text-sm text-primary-foreground/70">
              <div className="flex items-center gap-2.5">
                <Phone className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <span>+92 300 1234567</span>
              </div>
              <div className="flex items-center gap-2.5">
                <Mail className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <span>info@woodex.pk</span>
              </div>
              <div className="flex items-center gap-2.5">
                <MapPin className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <span>Lahore, Pakistan</span>
              </div>
            </div>
            <div className="flex items-center gap-3 mt-6">
              {[Facebook, Twitter, Linkedin, Instagram, Youtube].map((Icon, i) => (
                <button
                  key={i}
                  className="w-8 h-8 rounded-full border border-primary-foreground/20 flex items-center justify-center hover:border-accent hover:text-accent transition-colors"
                >
                  <Icon className="h-3.5 w-3.5" />
                </button>
              ))}
            </div>
          </div>

          {/* Col 2: Quick Links */}
          <div>
            <h4 className="font-semibold text-sm mb-4 text-primary-foreground uppercase tracking-wider">Quick Links</h4>
            <ul className="space-y-2.5 text-sm">
              {[
                { label: "About Us", href: "/about" },
                { label: "Portfolio", href: "/projects" },
                { label: "Blog", href: "/blog" },
                { label: "Careers", href: "/contact" },
                { label: "Contact", href: "/contact" },
                { label: "Showrooms", href: "/showrooms" },
                { label: "Series", href: "/series" },
                { label: "B2B / Markets", href: "/b2b" },
              ].map((link) => (
                <li key={link.label}>
                  <Link to={link.href} className="text-primary-foreground/70 hover:text-accent transition-colors">
                    {link.label}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          {/* Col 3: Shop */}
          <div>
            <h4 className="font-semibold text-sm mb-4 text-primary-foreground uppercase tracking-wider">Shop</h4>
            <ul className="space-y-2.5 text-sm">
              {[
                { label: "Executive Tables", href: "/shop?category=executive-tables" },
                { label: "Manager Tables", href: "/shop?category=manager-tables" },
                { label: "Staff Tables", href: "/shop?category=staff-tables" },
                { label: "Meeting Tables", href: "/shop?category=meeting-tables" },
                { label: "Office Chairs", href: "/shop?category=chairs" },
                { label: "Workstations", href: "/shop?category=workstations" },
                { label: "Cubicle Systems", href: "/shop?category=cubicle-workstations" },
                { label: "Office Sofas", href: "/shop?category=office-sofas" },
                { label: "Office Storage", href: "/shop?category=storage" },
              ].map((link) => (
                <li key={link.label}>
                  <Link to={link.href} className="text-primary-foreground/70 hover:text-accent transition-colors">
                    {link.label}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          {/* Col 4: Learn More */}
          <div>
            <h4 className="font-semibold text-sm mb-4 text-primary-foreground uppercase tracking-wider">Learn More</h4>
            <ul className="space-y-2.5 text-sm">
              {[
                { label: "Awards", href: "/about" },
                { label: "Ideas & Inspiration", href: "/projects" },
                { label: "Terms of Use", href: "/contact" },
                { label: "Resources", href: "/services" },
                { label: "Support", href: "/contact" },
                { label: "Warranty", href: "/warranty" },
                { label: "Distributors", href: "/b2b" },
                { label: "FAQ", href: "/warranty" },
              ].map((link) => (
                <li key={link.label}>
                  <Link to={link.href} className="text-primary-foreground/70 hover:text-accent transition-colors">
                    {link.label}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          {/* Col 5: Contact & Newsletter */}
          <div>
            <h4 className="font-semibold text-sm mb-4 text-primary-foreground uppercase tracking-wider">Contact</h4>
            <div className="space-y-3 text-sm text-primary-foreground/70 mb-6">
              <div className="flex items-start gap-2.5">
                <MapPin className="h-3.5 w-3.5 text-accent flex-shrink-0 mt-0.5" />
                <span>123 Gulberg III, Main Boulevard,<br />Lahore, Pakistan</span>
              </div>
              <div className="flex items-center gap-2.5">
                <Phone className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <span>+92 42 111 WOODEX</span>
              </div>
              <div className="flex items-center gap-2.5">
                <Mail className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <span>sales@woodex.pk</span>
              </div>
              <div className="flex items-center gap-2.5">
                <Clock className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <span>Mon–Sat: 9am–7pm</span>
              </div>
            </div>

            {/* Newsletter */}
            <div>
              <p className="text-xs font-semibold text-primary-foreground uppercase tracking-wider mb-3">Newsletter</p>
              {subscribed ? (
                <p className="text-sm text-accent font-semibold">✓ Subscribed! Thank you.</p>
              ) : (
                <form onSubmit={handleSubscribe} className="flex gap-2">
                  <Input
                    type="email"
                    placeholder="Your email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                    className="bg-primary-foreground/10 border-primary-foreground/20 text-primary-foreground placeholder:text-primary-foreground/40 text-sm h-9"
                  />
                  <Button type="submit" size="sm" className="bg-accent hover:bg-accent/80 text-accent-foreground px-3 h-9 flex-shrink-0">
                    <ArrowRight className="h-4 w-4" />
                  </Button>
                </form>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Bottom Bar */}
      <div className="border-t border-primary-foreground/10">
        <div className="container mx-auto px-4 py-4">
          <div className="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-primary-foreground/50">
            <p>&copy; {new Date().getFullYear()} WOODEX. All rights reserved.</p>
            <div className="flex items-center gap-4">
              <Link to="/contact" className="hover:text-accent transition-colors">Privacy Policy</Link>
              <Link to="/contact" className="hover:text-accent transition-colors">Terms of Service</Link>
              <Link to="/contact" className="hover:text-accent transition-colors">Sitemap</Link>
            </div>
          </div>
        </div>
      </div>
    </footer>
  );
};

export default Footer;
