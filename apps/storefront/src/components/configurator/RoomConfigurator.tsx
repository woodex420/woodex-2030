import { useState, useCallback } from "react";
import { Save, Download, Share2, RotateCcw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import HotspotMarker from "./HotspotMarker";
import MaterialPanel from "./MaterialPanel";
import { materials, type Material } from "@/data/materials";

import roomOpen from "@/assets/configurator-room-open.jpg";
import roomExec from "@/assets/configurator-room-executive.jpg";
import roomConf from "@/assets/configurator-room-conference.jpg";

type FurniturePart = Material["applicableTo"][number];

interface Hotspot {
  id: string;
  x: number;
  y: number;
  label: string;
  part: FurniturePart;
}

interface RoomConfig {
  id: string;
  label: string;
  image: string;
  hotspots: Hotspot[];
}

const rooms: RoomConfig[] = [
  {
    id: "open-plan",
    label: "Open Plan Office",
    image: roomOpen,
    hotspots: [
      { id: "desk-1", x: 28, y: 55, label: "Worktop Surface", part: "worktop" },
      { id: "desk-2", x: 62, y: 52, label: "Desk Panel", part: "panel" },
      { id: "chair-1", x: 18, y: 62, label: "Chair Upholstery", part: "seating" },
      { id: "chair-2", x: 72, y: 60, label: "Chair Mesh", part: "seating" },
      { id: "frame-1", x: 38, y: 72, label: "Desk Frame", part: "frame" },
      { id: "storage-1", x: 85, y: 50, label: "Storage Cabinet", part: "storage" },
    ],
  },
  {
    id: "executive",
    label: "Executive Office",
    image: roomExec,
    hotspots: [
      { id: "exec-desk", x: 50, y: 55, label: "Executive Desk", part: "worktop" },
      { id: "exec-chair", x: 30, y: 58, label: "Executive Chair", part: "seating" },
      { id: "exec-shelf", x: 22, y: 35, label: "Bookshelf", part: "storage" },
      { id: "exec-frame", x: 50, y: 72, label: "Desk Base", part: "frame" },
      { id: "exec-panel", x: 75, y: 45, label: "Wall Panel", part: "panel" },
    ],
  },
  {
    id: "conference",
    label: "Conference Room",
    image: roomConf,
    hotspots: [
      { id: "conf-table", x: 50, y: 50, label: "Meeting Table", part: "worktop" },
      { id: "conf-chair-l", x: 25, y: 55, label: "Chair Left", part: "seating" },
      { id: "conf-chair-r", x: 75, y: 55, label: "Chair Right", part: "seating" },
      { id: "conf-frame", x: 50, y: 70, label: "Table Base", part: "frame" },
    ],
  },
];

const RoomConfigurator = () => {
  const [activeRoom, setActiveRoom] = useState(0);
  const [activeHotspot, setActiveHotspot] = useState<string | null>(null);
  const [selections, setSelections] = useState<Record<string, Material>>({});

  const room = rooms[activeRoom];
  const currentHotspot = room.hotspots.find((h) => h.id === activeHotspot);

  const handleSelectMaterial = useCallback(
    (material: Material) => {
      if (!activeHotspot) return;
      setSelections((prev) => ({ ...prev, [activeHotspot]: material }));
      toast.success(`Applied ${material.name} to ${currentHotspot?.label}`);
    },
    [activeHotspot, currentHotspot]
  );

  const handleReset = () => {
    setSelections({});
    setActiveHotspot(null);
    toast.info("Configuration reset");
  };

  const handleSave = () => {
    const config = {
      room: room.label,
      materials: Object.entries(selections).map(([hotspotId, mat]) => ({
        piece: room.hotspots.find((h) => h.id === hotspotId)?.label,
        material: mat.name,
        category: mat.category,
      })),
      savedAt: new Date().toISOString(),
    };
    localStorage.setItem("woodex-configurator-save", JSON.stringify(config));
    toast.success("Configuration saved!");
  };

  const handleShare = () => {
    const summary = Object.entries(selections)
      .map(([id, mat]) => `${room.hotspots.find((h) => h.id === id)?.label}: ${mat.name}`)
      .join("\n");
    const text = `WOODEX Virtual Showroom — ${room.label}\n\n${summary || "No materials selected yet"}`;
    if (navigator.share) {
      navigator.share({ title: "WOODEX Configuration", text });
    } else {
      navigator.clipboard.writeText(text);
      toast.success("Configuration copied to clipboard!");
    }
  };

  return (
    <div className="space-y-4">
      {/* Room Type Tabs */}
      <div className="flex gap-2 overflow-x-auto pb-1">
        {rooms.map((r, i) => (
          <button
            key={r.id}
            onClick={() => {
              setActiveRoom(i);
              setActiveHotspot(null);
            }}
            className={`px-4 py-2 text-sm font-medium rounded-sm whitespace-nowrap transition-colors ${
              activeRoom === i ? "bg-accent text-accent-foreground" : "bg-muted hover:bg-muted/80 text-muted-foreground"
            }`}
          >
            {r.label}
          </button>
        ))}
      </div>

      {/* Viewport */}
      <div className="relative rounded-sm overflow-hidden border border-border bg-muted" style={{ aspectRatio: "16/9" }}>
        <img
          src={room.image}
          alt={room.label}
          className="w-full h-full object-cover"
        />

        {/* Overlay tint per selection */}
        {Object.entries(selections).map(([hotspotId, mat]) => {
          const hs = room.hotspots.find((h) => h.id === hotspotId);
          if (!hs) return null;
          return (
            <div
              key={hotspotId}
              className="absolute w-12 h-12 rounded-full overflow-hidden border-2 border-accent/50 shadow-lg pointer-events-none"
              style={{
                left: `${hs.x}%`,
                top: `${hs.y}%`,
                transform: "translate(-50%, -100%) translateY(-20px)",
              }}
            >
              <img src={mat.image} alt={mat.name} className="w-full h-full object-cover" />
            </div>
          );
        })}

        {/* Hotspot Markers */}
        {room.hotspots.map((hs) => (
          <HotspotMarker
            key={hs.id}
            x={hs.x}
            y={hs.y}
            label={hs.label}
            isActive={activeHotspot === hs.id}
            selectedMaterial={selections[hs.id] || null}
            onClick={() => setActiveHotspot(activeHotspot === hs.id ? null : hs.id)}
          />
        ))}

        {/* Material Panel (slides in from right) */}
        <MaterialPanel
          isOpen={!!activeHotspot && !!currentHotspot}
          onClose={() => setActiveHotspot(null)}
          hotspotLabel={currentHotspot?.label || ""}
          applicablePart={currentHotspot?.part || "worktop"}
          selectedMaterialId={activeHotspot ? selections[activeHotspot]?.id || null : null}
          onSelectMaterial={handleSelectMaterial}
        />

        {/* Selection count badge */}
        {Object.keys(selections).length > 0 && (
          <div className="absolute top-3 left-3 bg-accent text-accent-foreground text-xs font-bold px-2.5 py-1 rounded-full">
            {Object.keys(selections).length} material{Object.keys(selections).length > 1 ? "s" : ""} applied
          </div>
        )}

        {/* Instructions overlay when no hotspot selected */}
        {!activeHotspot && Object.keys(selections).length === 0 && (
          <div className="absolute bottom-3 left-1/2 -translate-x-1/2 bg-primary/80 text-primary-foreground text-xs px-4 py-2 rounded-full backdrop-blur-sm">
            Click any marker to customize materials
          </div>
        )}
      </div>

      {/* Action Bar */}
      <div className="flex flex-wrap gap-3 justify-between items-center">
        <div className="flex gap-2">
          <Button variant="outline" size="sm" className="gap-2 border-border hover:border-accent" onClick={handleReset}>
            <RotateCcw className="h-3.5 w-3.5" /> Reset
          </Button>
          <Button variant="outline" size="sm" className="gap-2 border-border hover:border-accent" onClick={handleSave}>
            <Save className="h-3.5 w-3.5" /> Save Config
          </Button>
          <Button variant="outline" size="sm" className="gap-2 border-border hover:border-accent" onClick={handleShare}>
            <Share2 className="h-3.5 w-3.5" /> Share
          </Button>
        </div>
        <Button size="sm" className="bg-accent hover:bg-accent/90 text-accent-foreground gap-2" asChild>
          <Link to="/quotation">Get Quote for This Layout</Link>
        </Button>
      </div>
    </div>
  );
};

export default RoomConfigurator;
