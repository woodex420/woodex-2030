import { type Material } from "@/data/materials";

interface HotspotMarkerProps {
  x: number; // percentage
  y: number; // percentage
  label: string;
  isActive: boolean;
  selectedMaterial?: Material | null;
  onClick: () => void;
}

const HotspotMarker = ({ x, y, label, isActive, selectedMaterial, onClick }: HotspotMarkerProps) => {
  return (
    <button
      onClick={onClick}
      className="absolute group z-20"
      style={{ left: `${x}%`, top: `${y}%`, transform: "translate(-50%, -50%)" }}
      title={label}
    >
      {/* Pulse ring */}
      <span className={`absolute inset-0 rounded-full animate-ping ${isActive ? "bg-accent/40" : "bg-background/30"}`} style={{ animationDuration: "2s" }} />

      {/* Outer ring */}
      <span
        className={`relative flex items-center justify-center w-8 h-8 rounded-full border-2 transition-all ${
          isActive
            ? "border-accent bg-accent/20 scale-110"
            : "border-background/80 bg-background/30 hover:border-accent hover:bg-accent/10"
        }`}
      >
        {/* Inner dot / material preview */}
        {selectedMaterial ? (
          <span className="w-4 h-4 rounded-full overflow-hidden border border-border">
            <img src={selectedMaterial.image} alt="" className="w-full h-full object-cover" />
          </span>
        ) : (
          <span className={`w-3 h-3 rounded-full ${isActive ? "bg-accent" : "bg-background/80"}`} />
        )}
      </span>

      {/* Label tooltip */}
      <span className="absolute top-full left-1/2 -translate-x-1/2 mt-1 px-2 py-0.5 bg-primary text-primary-foreground text-[10px] font-medium rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
        {label}
      </span>
    </button>
  );
};

export default HotspotMarker;
