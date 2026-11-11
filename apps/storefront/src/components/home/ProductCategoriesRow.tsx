import { nativeCatalogue } from "@/lib/nativeCategories";
import { useRuntimeRevision } from "@/lib/runtime";
import { Link } from "react-router-dom";
import { img } from "@/assets/img";

const categories = [
  { label: "Reception Desk", image: img("product-reception-counter.jpg"), href: "/shop?category=reception-tables" },
  { label: "Cubicle Workstation", image: img("product-cubicle.jpg"), href: "/shop?category=cubicle-workstations" },
  { label: "Office Storage", image: img("product-storage.jpg"), href: "/shop?category=storage" },
  { label: "Office Chair", image: img("product-office-chair.jpg"), href: "/shop?category=chairs" },
  { label: "Staff Tables", image: img("product-staff-desk.jpg"), href: "/shop?category=staff-tables" },
  { label: "Office Sofas", image: img("product-office-sofa.jpg"), href: "/shop?category=office-sofas" },
  { label: "Executive Table", image: img("product-executive-desk.jpg"), href: "/shop?category=executive-tables" },
  { label: "Meeting Table", image: img("product-meeting-table.jpg"), href: "/shop?category=meeting-tables" },
];

const ProductCategoriesRow = () => {
  useRuntimeRevision();
  const native = nativeCatalogue();
  const rows = native ? native.categories.map(c => ({ label: c.name, image: c.page?.content.hero_url || '', href: `/shop?category=${c.slug}` })) : categories;
  return (
  <section className="py-14 bg-section-light">
    <div className="container mx-auto px-4">
      <div className="flex gap-6 overflow-x-auto pb-4 scrollbar-hide">
        {rows.map((cat) => (
          <Link key={cat.label} to={cat.href} className="group flex-shrink-0 text-center w-[140px]">
            <div className="w-[120px] h-[120px] mx-auto mb-3 rounded-full overflow-hidden bg-muted border-2 border-transparent group-hover:border-accent transition-colors">
              {cat.image ? <img src={cat.image} alt={cat.label} className="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500" /> : <span className="grid h-full place-items-center text-2xl font-bold">{cat.label.slice(0, 2)}</span>}
            </div>
            <p className="text-xs font-semibold text-foreground group-hover:text-accent transition-colors">{cat.label}</p>
          </Link>
        ))}
      </div>
    </div>
  </section>
);
};

export default ProductCategoriesRow;
