import { Link } from "react-router-dom";
import { Phone, Mail, MapPin, Clock } from "lucide-react";
import { brand } from "@/config/brand";

const Footer = () => {
  return (
    <footer className="bg-primary text-primary-foreground mt-auto">
      {/* Main Footer */}
      <div className="container mx-auto px-4 py-14">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-8">
          {/* Col 1: Brand */}
          <div className="lg:col-span-1">
            <div className="mb-4">
              <span className="text-2xl font-black tracking-tight">{brand.name}</span>
              <p className="text-xs text-primary-foreground/50 font-normal mt-0.5">{brand.tagline}</p>
            </div>
            <p className="text-sm text-primary-foreground/70 leading-relaxed mb-6">
              {brand.description}
            </p>
            <div className="space-y-2.5 text-sm text-primary-foreground/70">
              <div className="flex items-center gap-2.5">
                <Phone className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <a href={brand.phoneLink} className="hover:text-accent transition-colors">{brand.phone}</a>
              </div>
              <div className="flex items-center gap-2.5">
                <Mail className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <a href={brand.emailLink} className="break-all hover:text-accent transition-colors">{brand.email}</a>
              </div>
              <div className="flex items-center gap-2.5">
                <MapPin className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <span>{brand.address.city}, Pakistan</span>
              </div>
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
                { label: "Track My Order", href: "/order-status" },
                { label: "Showroom", href: "/showrooms" },
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

          {/* Col 5: Contact */}
          <div>
            <h4 className="font-semibold text-sm mb-4 text-primary-foreground uppercase tracking-wider">Contact</h4>
            <div className="space-y-3 text-sm text-primary-foreground/70">
              <div className="flex items-start gap-2.5">
                <MapPin className="h-3.5 w-3.5 text-accent flex-shrink-0 mt-0.5" />
                <span>{brand.address.street},<br />{brand.address.city}, Pakistan</span>
              </div>
              <div className="flex items-center gap-2.5">
                <Phone className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <a href={brand.phoneLink} className="hover:text-accent transition-colors">{brand.phone}</a>
              </div>
              <div className="flex items-center gap-2.5">
                <Mail className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <a href={brand.emailLink} className="break-all hover:text-accent transition-colors">{brand.email}</a>
              </div>
              <div className="flex items-center gap-2.5">
                <Clock className="h-3.5 w-3.5 text-accent flex-shrink-0" />
                <span>{brand.hours}</span>
              </div>
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
