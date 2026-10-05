import { useState } from "react";
import { cn } from "@/lib/utils";
import { Check } from "lucide-react";
import { ProductColor } from "@/data/products";

interface ColorSwatchesProps {
  colors: ProductColor[];
  selectedColor: ProductColor;
  onColorChange: (color: ProductColor) => void;
}

const ColorSwatches = ({ colors, selectedColor, onColorChange }: ColorSwatchesProps) => {
  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium">Color</span>
        <span className="text-sm text-muted-foreground">{selectedColor.name}</span>
      </div>
      
      <div className="flex flex-wrap gap-3">
        {colors.map((color, index) => (
          <button
            key={index}
            onClick={() => onColorChange(color)}
            className={cn(
              "relative w-10 h-10 rounded-full transition-all duration-200",
              "ring-offset-2 ring-offset-background",
              selectedColor.hex === color.hex
                ? "ring-2 ring-accent scale-110"
                : "hover:scale-105 hover:ring-2 hover:ring-border"
            )}
            style={{ backgroundColor: color.hex }}
            title={color.name}
          >
            {selectedColor.hex === color.hex && (
              <span className="absolute inset-0 flex items-center justify-center">
                <Check 
                  className={cn(
                    "h-5 w-5",
                    // Use white check for dark colors, dark check for light colors
                    parseInt(color.hex.replace('#', ''), 16) < 0x808080
                      ? "text-white"
                      : "text-foreground"
                  )}
                />
              </span>
            )}
          </button>
        ))}
      </div>
    </div>
  );
};

export default ColorSwatches;
