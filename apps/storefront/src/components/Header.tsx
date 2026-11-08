import { useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { Search, FileText, Menu, X, ChevronDown, ShoppingBag, ShoppingCart, ArrowRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useQuote } from "@/contexts/QuoteContext";
import { useCart } from "@/contexts/CartContext";
import QuoteBasket from "@/components/QuoteBasket";
import CartDrawer from "@/components/CartDrawer";
import megaOfficeImg from "@/assets/hero-slide-1.jpg";
import megaHomeImg from "@/assets/hero-slide-3.jpg";

import { Moon, Sun } from "lucide-react";
import { setModeOverride, useSiteTheme } from "@/lib/theme";

/* P6 — site-level announcement (hidden on /p pages that ship their own via wx-pagebar) */
function SiteAnnounce() {
  const { theme } = useSiteTheme();
  if (!theme.announce?.text) return null;
  const inner = <span className="mx-auto block max-w-6xl truncate px-4 text-center text-[12px] font-bold tracking-wide">{theme.announce.text} {theme.announce.href && <span className="underline decoration-white/50 underline-offset-2">→</span>}</span>;
  const cls = "block w-full bg-accent py-1.5 text-white transition-opacity hover:opacity-90";
  return theme.announce.href
    ? <a id="wx-sitebar" href={theme.announce.href} className={cls}>{inner}</a>
    : <div id="wx-sitebar" className={cls}>{inner}</div>;
}

function ModeToggle() {
  const { resolved } = useSiteTheme();
  return (
    <button
      onClick={() => setModeOverride(resolved === "dark" ? "light" : "dark")}
      title={resolved === "dark" ? "Switch to light" : "Switch to dark"}
      className="inline-flex h-5 w-5 items-center justify-center rounded text-utility-text transition-colors hover:text-white"
      aria-label="Toggle dark mode"
    >
      {resolved === "dark" ? <Sun size={13} /> : <Moon size={13} />}
    </button>
  );
}

const megaMenuProducts = {
  office: {
    title: "Office Furniture",
    sections: [
      {
        heading: "Office Tables",
        links: [
          { label: "Executive Tables", href: "/shop?category=executive-tables" },
          { label: "Manager Tables", href: "/shop?category=manager-tables" },
          { label: "Staff Tables", href: "/shop?category=staff-tables" },
          { label: "Meeting Tables", href: "/shop?category=meeting-tables" },
          { label: "Reception Tables", href: "/shop?category=reception-tables" },
        ],
      },
      {
        heading: "Seating & More",
        links: [
          { label: "Office Chairs", href: "/shop?category=chairs" },
          { label: "Workstations", href: "/shop?category=workstations" },
          { label: "Cubicle Workstations", href: "/shop?category=cubicle-workstations" },
          { label: "Office Sofas", href: "/shop?category=office-sofas" },
          { label: "Office Storage", href: "/shop?category=storage" },
          { label: "Cafe Furniture", href: "/shop?category=cafe" },
          { label: "Public Sitting", href: "/shop?category=public" },
        ],
      },
    ],
  },
  home: {
    title: "Home Furniture",
    sections: [
      {
        heading: "Bedroom",
        links: [
          { label: "Bed Sets", href: "/shop?category=bed-sets" },
          { label: "Bedside Tables", href: "/shop?category=bedside-tables" },
          { label: "Dressing Tables", href: "/shop?category=dressing-tables" },
          { label: "Mirrors", href: "/shop?category=mirrors" },
          { label: "Bench & Settee", href: "/shop?category=bench-settee" },
        ],
      },
      {
        heading: "Living Room",
        links: [
          { label: "Home Sofa", href: "/shop?category=home-sofa" },
          { label: "Center & Side Tables", href: "/shop?category=center-side-tables" },
          { label: "Coffee Tables", href: "/shop?category=coffee-tables" },
          { label: "Console", href: "/shop?category=console" },
          { label: "TV Units", href: "/shop?category=tv-units" },
        ],
      },
      {
        heading: "Dining",
        links: [
          { label: "Dining Sets", href: "/shop?category=dining-sets" },
          { label: "Dining Chairs", href: "/shop?category=dining-chairs" },
          { label: "Dining Tables", href: "/shop?category=dining-tables" },
        ],
      },
    ],
  },
};

const navItems = [
  {
    label: "Products",
    href: "/shop",
    megaMenu: true,
  },
  {
    label: "Markets",
    href: "/b2b",
    dropdown: [
      { label: "Corporate Business", href: "/b2b" },
      { label: "Education", href: "/b2b" },
      { label: "Healthcare", href: "/b2b" },
      { label: "Government", href: "/b2b" },
      { label: "Hospitality", href: "/b2b" },
    ],
  },
  {
    label: "Series",
    href: "/series",
    dropdown: [
      { label: "Ek Series", href: "/series/ek-series" },
      { label: "Infinity Series", href: "/series/infinity-series" },
      { label: "Woodex Series", href: "/series/woodex-series" },
      { label: "Cubicle Series", href: "/series/cubicle-series" },
      { label: "Nova Series", href: "/series/nova-series" },
    ],
  },
  { label: "Projects", href: "/projects" },
  { label: "Services", href: "/services" },
  { label: "Blog", href: "/blog" },
  { label: "About", href: "/about" },
  { label: "Contact", href: "/contact" },
  { label: "Custom Design", href: "/custom-design" },
];

const Header = () => {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [mobileExpanded, setMobileExpanded] = useState<string | null>(null);
  const [activeDropdown, setActiveDropdown] = useState<string | null>(null);
  const location = useLocation();
  const { totalItems, isOpen, setIsOpen } = useQuote();
  const cart = useCart();

  const isActive = (href: string) =>
    location.pathname === href || location.pathname.startsWith(href + "/");

  return (
    <>
      <QuoteBasket />
      <header className="w-full sticky top-0 z-40 shadow-sm">
        <SiteAnnounce />
        {/* Utility Bar */}
        <div className="bg-utility-bar">
          <div className="container mx-auto px-4">
            <div className="flex items-center justify-between h-9 text-xs">
              <div className="flex items-center gap-5 text-utility-text">
                <Link to="/showrooms" className="hover:text-white transition-colors">Showrooms</Link>
                <span className="text-utility-text/30">|</span>
                <Link to="/materials" className="hover:text-white transition-colors">Material and Colors</Link>
                <span className="text-utility-text/30">|</span>
                <Link to="/warranty" className="hover:text-white transition-colors">Warranty</Link>
              </div>
              <div className="flex items-center gap-4 text-utility-text">
                <span className="hover:text-white cursor-pointer transition-colors">English</span>
                <span className="text-utility-text/30">|</span>
                <span className="hover:text-white cursor-pointer transition-colors">PKR</span>
                <span className="text-utility-text/30">|</span>
                <ModeToggle />
              </div>
            </div>
          </div>
        </div>

        {/* Main Navigation */}
        <div className="bg-background border-b">
          <div className="container mx-auto px-4">
            <div className="flex items-center justify-between h-16">
              {/* Logo */}
              <Link to="/" className="flex items-center gap-2 flex-shrink-0">
                <div className="flex items-center gap-1">
                  <span className="text-2xl font-black tracking-tight text-primary">WOODEX</span>
                  <span className="hidden sm:block text-xs text-muted-foreground font-normal ml-2 border-l pl-2 border-border leading-tight">
                    Make your<br />space work
                  </span>
                </div>
              </Link>

              {/* Desktop Nav */}
              <nav className="hidden xl:flex items-center gap-0.5">
                {navItems.map((item) => (
                  <div
                    key={item.label}
                    className="relative"
                    onMouseEnter={() => setActiveDropdown(item.label)}
                    onMouseLeave={() => setActiveDropdown(null)}
                  >
                    <Link
                      to={item.href}
                      className={`flex items-center gap-0.5 px-3 py-2 text-sm font-medium transition-colors rounded-sm ${
                        isActive(item.href) ? "text-accent" : "text-foreground hover:text-accent"
                      }`}
                    >
                      {item.label}
                      {(item.dropdown || item.megaMenu) && (
                        <ChevronDown className="h-3 w-3 ml-0.5 opacity-60" />
                      )}
                    </Link>

                    {/* Mega Menu — Products (wide, with featured imagery) */}
                    {item.megaMenu && activeDropdown === item.label && (
                      <div className="absolute top-full left-1/2 -translate-x-1/2 w-[980px] bg-background border shadow-2xl rounded-sm z-50 overflow-hidden">
                        <div className="grid grid-cols-2">
                          {/* OFFICE SEGMENT */}
                          <div className="p-7 bg-background">
                            <div className="flex items-center gap-2 mb-5">
                              <div className="w-8 h-0.5 bg-accent" />
                              <span className="text-[11px] font-bold uppercase tracking-[0.18em] text-accent">{megaMenuProducts.office.title}</span>
                            </div>
                            <Link to="/shop?segment=office" className="block group mb-5">
                              <div className="relative aspect-[16/8] overflow-hidden rounded-sm bg-muted">
                                <img src={megaOfficeImg} alt="Office Furniture" className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" />
                                <div className="absolute inset-0 bg-gradient-to-t from-primary/80 via-primary/20 to-transparent" />
                                <div className="absolute bottom-3 left-3 right-3 text-primary-foreground">
                                  <p className="text-[10px] uppercase tracking-widest text-accent font-bold mb-0.5">Featured Collection</p>
                                  <p className="text-sm font-bold leading-tight">Make Your Workspace Work</p>
                                </div>
                              </div>
                            </Link>
                            <div className="grid grid-cols-2 gap-x-5 gap-y-1">
                              {megaMenuProducts.office.sections.map((section) => (
                                <div key={section.heading}>
                                  <p className="text-[10px] font-bold text-muted-foreground uppercase tracking-[0.14em] mb-2 border-b border-border pb-1">{section.heading}</p>
                                  <ul className="space-y-0.5">
                                    {section.links.map((link) => (
                                      <li key={link.label}>
                                        <Link
                                          to={link.href}
                                          className="block text-[13px] text-foreground hover:text-accent hover:bg-hon-green-pale px-2 py-1 rounded-sm transition-colors"
                                        >
                                          {link.label}
                                        </Link>
                                      </li>
                                    ))}
                                  </ul>
                                </div>
                              ))}
                            </div>
                          </div>

                          {/* HOME SEGMENT */}
                          <div className="p-7 bg-section-light border-l border-border">
                            <div className="flex items-center gap-2 mb-5">
                              <div className="w-8 h-0.5 bg-accent" />
                              <span className="text-[11px] font-bold uppercase tracking-[0.18em] text-accent">{megaMenuProducts.home.title}</span>
                            </div>
                            <Link to="/shop?segment=home" className="block group mb-5">
                              <div className="relative aspect-[16/8] overflow-hidden rounded-sm bg-muted">
                                <img src={megaHomeImg} alt="Home Furniture" className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" />
                                <div className="absolute inset-0 bg-gradient-to-t from-primary/80 via-primary/20 to-transparent" />
                                <div className="absolute bottom-3 left-3 right-3 text-primary-foreground">
                                  <p className="text-[10px] uppercase tracking-widest text-accent font-bold mb-0.5">New Arrivals</p>
                                  <p className="text-sm font-bold leading-tight">WOODEX Home Collection</p>
                                </div>
                              </div>
                            </Link>
                            <div className="grid grid-cols-3 gap-x-4 gap-y-1">
                              {megaMenuProducts.home.sections.map((section) => (
                                <div key={section.heading}>
                                  <p className="text-[10px] font-bold text-muted-foreground uppercase tracking-[0.14em] mb-2 border-b border-border pb-1">{section.heading}</p>
                                  <ul className="space-y-0.5">
                                    {section.links.map((link) => (
                                      <li key={link.label}>
                                        <Link
                                          to={link.href}
                                          className="block text-[13px] text-foreground hover:text-accent hover:bg-hon-green-pale px-2 py-1 rounded-sm transition-colors"
                                        >
                                          {link.label}
                                        </Link>
                                      </li>
                                    ))}
                                  </ul>
                                </div>
                              ))}
                            </div>
                          </div>
                        </div>

                        {/* Footer bar */}
                        <div className="border-t border-border px-7 py-3 flex justify-between items-center bg-background">
                          <Link to="/shop" className="text-sm text-accent font-bold hover:underline flex items-center gap-1">
                            View All Products <ArrowRight className="h-3.5 w-3.5" />
                          </Link>
                          <div className="flex gap-5 text-xs">
                            <Link to="/series" className="text-muted-foreground hover:text-accent transition-colors font-medium">Series</Link>
                            <Link to="/materials" className="text-muted-foreground hover:text-accent transition-colors font-medium">Materials</Link>
                            <Link to="/virtual-showroom" className="text-muted-foreground hover:text-accent transition-colors font-medium">Virtual Showroom</Link>
                            <Link to="/quotation" className="text-accent font-bold hover:underline">Request E-Quote</Link>
                          </div>
                        </div>
                      </div>
                    )}

                    {/* Regular Dropdown */}
                    {item.dropdown && activeDropdown === item.label && (
                      <div className="absolute top-full left-0 w-52 bg-background border shadow-lg rounded-sm z-50 py-1">
                        {item.dropdown.map((sub) => (
                          <Link
                            key={sub.label}
                            to={sub.href}
                            className="block px-4 py-2.5 text-sm text-foreground hover:bg-hon-green-pale hover:text-accent transition-colors"
                          >
                            {sub.label}
                          </Link>
                        ))}
                      </div>
                    )}
                  </div>
                ))}
              </nav>

              {/* Actions */}
              <div className="flex items-center gap-2">
                <Button variant="ghost" size="icon" className="text-foreground hover:text-accent">
                  <Search className="h-4 w-4" />
                </Button>

                {/* Quote Basket Button */}
                <Button
                  variant="ghost"
                  size="icon"
                  className="relative text-foreground hover:text-accent"
                  onClick={() => setIsOpen(!isOpen)}
                >
                  <ShoppingBag className="h-4 w-4" />
                  {totalItems > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 w-4 h-4 bg-accent text-accent-foreground text-xs font-bold rounded-full flex items-center justify-center leading-none">
                      {totalItems > 9 ? "9+" : totalItems}
                    </span>
                  )}
                </Button>

                {/* Cart Button */}
                <CartDrawer />

                <Button
                  size="sm"
                  className="hidden sm:flex bg-accent text-accent-foreground hover:bg-hon-green-dark gap-1.5 text-xs font-semibold px-4"
                  asChild
                >
                  <Link to="/quotation">
                    <FileText className="h-3.5 w-3.5" />
                    E-Quotation
                  </Link>
                </Button>

                {/* Mobile hamburger */}
                <Button
                  variant="ghost"
                  size="icon"
                  className="xl:hidden"
                  onClick={() => setMobileOpen(!mobileOpen)}
                >
                  {mobileOpen ? (
                    <X className="h-5 w-5" />
                  ) : (
                    <div className="flex flex-col gap-1.5 w-5">
                      <span className="block h-0.5 w-5 bg-foreground rounded-full" />
                      <span className="block h-0.5 w-4 bg-foreground rounded-full" />
                      <span className="block h-0.5 w-5 bg-foreground rounded-full" />
                    </div>
                  )}
                </Button>
              </div>
            </div>
          </div>
        </div>

        {/* Mobile Menu */}
        {mobileOpen && (
          <div className="xl:hidden bg-background border-b shadow-lg max-h-[80vh] overflow-y-auto">
            <div className="container mx-auto px-4 py-4 space-y-1">
              {navItems.map((item) => (
                <div key={item.label}>
                  <div className="flex items-center justify-between">
                    <Link
                      to={item.href}
                      onClick={() => { setMobileOpen(false); setMobileExpanded(null); }}
                      className={`flex-1 block px-3 py-2.5 text-sm font-medium rounded-sm transition-colors ${
                        isActive(item.href) ? "text-accent bg-hon-green-pale" : "text-foreground hover:text-accent hover:bg-muted"
                      }`}
                    >
                      {item.label}
                    </Link>
                    {(item.dropdown || item.megaMenu) && (
                      <button
                        className="p-2 text-muted-foreground hover:text-accent"
                        onClick={() => setMobileExpanded(mobileExpanded === item.label ? null : item.label)}
                      >
                        <ChevronDown className={`h-4 w-4 transition-transform ${mobileExpanded === item.label ? "rotate-180" : ""}`} />
                      </button>
                    )}
                  </div>

                  {/* Mobile expanded sub-items */}
                  {mobileExpanded === item.label && item.dropdown && (
                    <div className="ml-4 border-l-2 border-accent/30 pl-4 mt-1 space-y-1">
                      {item.dropdown.map((sub) => (
                        <Link
                          key={sub.label}
                          to={sub.href}
                          onClick={() => { setMobileOpen(false); setMobileExpanded(null); }}
                          className="block px-2 py-2 text-sm text-muted-foreground hover:text-accent transition-colors"
                        >
                          {sub.label}
                        </Link>
                      ))}
                    </div>
                  )}

                  {mobileExpanded === item.label && item.megaMenu && (
                    <div className="ml-4 border-l-2 border-accent/30 pl-4 mt-1 space-y-3">
                      {[...megaMenuProducts.office.sections, ...megaMenuProducts.home.sections].map((section) => (
                        <div key={section.heading}>
                          <p className="text-xs font-bold text-accent uppercase tracking-widest mb-1">{section.heading}</p>
                          {section.links.map((link) => (
                            <Link
                              key={link.label}
                              to={link.href}
                              onClick={() => { setMobileOpen(false); setMobileExpanded(null); }}
                              className="block px-2 py-1.5 text-sm text-muted-foreground hover:text-accent transition-colors"
                            >
                              {link.label}
                            </Link>
                          ))}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              ))}

              <div className="pt-3 border-t space-y-2">
                <Button size="sm" className="w-full bg-accent text-accent-foreground hover:bg-hon-green-dark" asChild>
                  <Link to="/quotation" onClick={() => setMobileOpen(false)}>
                    <FileText className="h-4 w-4 mr-2" />
                    E-Quotation
                  </Link>
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  className="w-full border-accent text-accent"
                  onClick={() => { setMobileOpen(false); setIsOpen(true); }}
                >
                  <ShoppingBag className="h-4 w-4 mr-2" />
                  Quote Basket ({totalItems})
                </Button>
              </div>
            </div>
          </div>
        )}
      </header>
    </>
  );
};

export default Header;
