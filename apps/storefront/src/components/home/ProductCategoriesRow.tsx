import { Link } from "react-router-dom";
import receptionImg from "@/assets/product-reception-counter.jpg";
import cubicleImg from "@/assets/product-cubicle.jpg";
import storageImg from "@/assets/product-storage.jpg";
import chairImg from "@/assets/product-office-chair.jpg";
import staffImg from "@/assets/product-staff-desk.jpg";
import sofaImg from "@/assets/product-office-sofa.jpg";
import execImg from "@/assets/product-executive-desk.jpg";
import meetingImg from "@/assets/product-meeting-table.jpg";

const categories = [
  { label: "Reception Desk", image: receptionImg, href: "/shop?category=reception-tables" },
  { label: "Cubicle Workstation", image: cubicleImg, href: "/shop?category=cubicle-workstations" },
  { label: "Office Storage", image: storageImg, href: "/shop?category=storage" },
  { label: "Office Chair", image: chairImg, href: "/shop?category=chairs" },
  { label: "Staff Tables", image: staffImg, href: "/shop?category=staff-tables" },
  { label: "Office Sofas", image: sofaImg, href: "/shop?category=office-sofas" },
  { label: "Executive Table", image: execImg, href: "/shop?category=executive-tables" },
  { label: "Meeting Table", image: meetingImg, href: "/shop?category=meeting-tables" },
];

const ProductCategoriesRow = () => (
  <section className="py-14 bg-section-light">
    <div className="container mx-auto px-4">
      <div className="flex gap-6 overflow-x-auto pb-4 scrollbar-hide">
        {categories.map((cat) => (
          <Link key={cat.label} to={cat.href} className="group flex-shrink-0 text-center w-[140px]">
            <div className="w-[120px] h-[120px] mx-auto mb-3 rounded-full overflow-hidden bg-muted border-2 border-transparent group-hover:border-accent transition-colors">
              <img src={cat.image} alt={cat.label} className="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500" />
            </div>
            <p className="text-xs font-semibold text-foreground group-hover:text-accent transition-colors">{cat.label}</p>
          </Link>
        ))}
      </div>
    </div>
  </section>
);

export default ProductCategoriesRow;
