import { useState } from "react";
import { X, Sun, SunMedium, Moon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { materials, materialCategories, type MaterialCategory, type BrightnessLevel, type Material } from "@/data/materials";

interface MaterialPanelProps {
  isOpen: boolean;
  onClose: () => void;
  hotspotLabel: string;
  applicablePart: Material["applicableTo"][number];
  selectedMaterialId: string | null;
  onSelectMaterial: (material: Material) => void;
}

const brightnessOptions: { id: BrightnessLevel; label: string; icon: typeof Sun }[] = [
  { id: "light", label: "Light", icon: Sun },
  { id: "medium", label: "Medium", icon: SunMedium },
  { id: "dark", label: "Dark", icon: Moon },
];

const MaterialPanel = ({
  isOpen,
  onClose,
  hotspotLabel,
  applicablePart,
  selectedMaterialId,
  onSelectMaterial,
}: MaterialPanelProps) => {
  const [activeCategory, setActiveCategory] = useState<MaterialCategory | "all">("all");
  const [brightness, setBrightness] = useState<BrightnessLevel>("all");

  const filtered = materials.filter((m) => {
    if (!m.applicableTo.includes(applicablePart)) return false;
    if (activeCategory !== "all" && m.category !== activeCategory) return false;
    if (brightness !== "all" && m.brightness !== brightness) return false;
    return true;
  });

  if (!isOpen) return null;

  return (
    <div className="absolute right-0 top-0 bottom-0 w-[340px] bg-background border-l border-border z-30 flex flex-col shadow-2xl animate-in slide-in-from-right-5 duration-300">
      {/* Header */}
      <div className="flex items-center justify-between p-4 border-b border-border">
        <div>
          <p className="text-xs font-bold uppercase tracking-widest text-accent">Product Selection</p>
          <p className="text-sm font-semibold mt-0.5">{hotspotLabel}</p>
        </div>
        <Button variant="ghost" size="icon" onClick={onClose} className="h-8 w-8">
          <X className="h-4 w-4" />
        </Button>
      </div>

      {/* Category Tabs */}
      <div className="flex gap-1 p-3 border-b border-border overflow-x-auto">
        <button
          onClick={() => setActiveCategory("all")}
          className={`px-3 py-1.5 text-xs font-medium rounded-sm whitespace-nowrap transition-colors ${
            activeCategory === "all" ? "bg-accent text-accent-foreground" : "bg-muted hover:bg-muted/80 text-muted-foreground"
          }`}
        >
          All
        </button>
        {materialCategories.map((cat) => (
          <button
            key={cat.id}
            onClick={() => setActiveCategory(cat.id)}
            className={`px-3 py-1.5 text-xs font-medium rounded-sm whitespace-nowrap transition-colors ${
              activeCategory === cat.id ? "bg-accent text-accent-foreground" : "bg-muted hover:bg-muted/80 text-muted-foreground"
            }`}
          >
            {cat.label}
          </button>
        ))}
      </div>

      {/* Brightness Toggle */}
      <div className="flex items-center gap-2 px-4 py-2.5 border-b border-border">
        <span className="text-xs text-muted-foreground mr-auto">Brightness</span>
        {brightnessOptions.map((opt) => (
          <button
            key={opt.id}
            onClick={() => setBrightness(brightness === opt.id ? "all" : opt.id)}
            className={`p-1.5 rounded-sm transition-colors ${
              brightness === opt.id ? "bg-accent text-accent-foreground" : "hover:bg-muted text-muted-foreground"
            }`}
            title={opt.label}
          >
            <opt.icon className="h-4 w-4" />
          </button>
        ))}
      </div>

      {/* Material Grid */}
      <div className="flex-1 overflow-y-auto p-3">
        {filtered.length === 0 ? (
          <p className="text-sm text-muted-foreground text-center py-8">No materials match this filter</p>
        ) : (
          <div className="grid grid-cols-3 gap-2">
            {filtered.map((mat) => (
              <button
                key={mat.id}
                onClick={() => onSelectMaterial(mat)}
                className={`group flex flex-col items-center gap-1.5 p-1.5 rounded-sm transition-all ${
                  selectedMaterialId === mat.id
                    ? "ring-2 ring-accent bg-accent/10"
                    : "hover:bg-muted"
                }`}
              >
                <div className="w-full aspect-square rounded-sm overflow-hidden border border-border group-hover:border-accent transition-colors">
                  <img src={mat.image} alt={mat.name} className="w-full h-full object-cover" />
                </div>
                <span className="text-[10px] text-muted-foreground text-center leading-tight line-clamp-2">
                  {mat.name}
                </span>
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Selected Summary */}
      {selectedMaterialId && (
        <div className="p-3 border-t border-border bg-muted/50">
          <p className="text-xs text-muted-foreground">
            Selected: <span className="font-medium text-foreground">{materials.find(m => m.id === selectedMaterialId)?.name}</span>
          </p>
        </div>
      )}
    </div>
  );
};

export default MaterialPanel;
