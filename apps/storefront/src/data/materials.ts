import { img } from "@/assets/img";
// Material texture imports

export type MaterialCategory = "wood" | "laminate" | "metal" | "fabric";
export type BrightnessLevel = "light" | "medium" | "dark" | "all";

export interface Material {
  id: string;
  name: string;
  category: MaterialCategory;
  brightness: BrightnessLevel;
  image: string;
  /** Applicable furniture parts */
  applicableTo: ("worktop" | "frame" | "seating" | "panel" | "storage")[];
}

export const materialCategories: { id: MaterialCategory; label: string }[] = [
  { id: "wood", label: "Wood Finishes" },
  { id: "laminate", label: "Solid & Laminate" },
  { id: "metal", label: "Metal Colors" },
  { id: "fabric", label: "Fabric & Leather" },
];

export const materials: Material[] = [
  // Wood Finishes
  { id: "natural-oak", name: "Natural Oak", category: "wood", brightness: "light", image: img("natural-oak.jpg"), applicableTo: ["worktop", "panel", "storage"] },
  { id: "maple", name: "Maple", category: "wood", brightness: "light", image: img("maple.jpg"), applicableTo: ["worktop", "panel", "storage"] },
  { id: "teak", name: "Teak", category: "wood", brightness: "medium", image: img("teak.jpg"), applicableTo: ["worktop", "panel", "storage"] },
  { id: "cherry", name: "Cherry Wood", category: "wood", brightness: "medium", image: img("cherry-wood.jpg"), applicableTo: ["worktop", "panel", "storage"] },
  { id: "dark-walnut", name: "Dark Walnut", category: "wood", brightness: "dark", image: img("dark-walnut.jpg"), applicableTo: ["worktop", "panel", "storage"] },
  { id: "mahogany", name: "Mahogany", category: "wood", brightness: "dark", image: img("mahogany.jpg"), applicableTo: ["worktop", "panel", "storage"] },
  { id: "thermo-oak", name: "Thermo Oak", category: "wood", brightness: "dark", image: img("thermo-oak.jpg"), applicableTo: ["worktop", "panel", "storage"] },

  // Solid Colors & Laminates
  { id: "white-laminate", name: "White Laminate", category: "laminate", brightness: "light", image: img("white-laminate.jpg"), applicableTo: ["worktop", "panel", "storage"] },
  { id: "light-concrete", name: "Light Concrete", category: "laminate", brightness: "light", image: img("light-concrete.jpg"), applicableTo: ["worktop", "panel"] },

  // Metal Colors
  { id: "matte-black", name: "Matte Black", category: "metal", brightness: "dark", image: img("matte-black.jpg"), applicableTo: ["frame"] },
  { id: "brushed-chrome", name: "Brushed Chrome", category: "metal", brightness: "light", image: img("brushed-chrome.jpg"), applicableTo: ["frame"] },
  { id: "brushed-gold", name: "Brushed Gold", category: "metal", brightness: "medium", image: img("brushed-gold.jpg"), applicableTo: ["frame"] },

  // Fabric & Leather
  { id: "black-leather", name: "Black Leather", category: "fabric", brightness: "dark", image: img("black-leather.jpg"), applicableTo: ["seating"] },
  { id: "tan-leather", name: "Tan Leather", category: "fabric", brightness: "medium", image: img("tan-leather.jpg"), applicableTo: ["seating"] },
  { id: "navy-fabric", name: "Navy Fabric", category: "fabric", brightness: "dark", image: img("navy-fabric.jpg"), applicableTo: ["seating"] },
  { id: "grey-mesh", name: "Grey Mesh", category: "fabric", brightness: "medium", image: img("grey-mesh.jpg"), applicableTo: ["seating"] },
];
