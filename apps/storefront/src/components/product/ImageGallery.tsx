import { useState, useRef } from "react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogTrigger } from "@/components/ui/dialog";
import { ZoomIn, RotateCcw, ChevronLeft, ChevronRight, Expand } from "lucide-react";
import { cn } from "@/lib/utils";

interface ImageGalleryProps {
  images: string[];
  productName: string;
}

const ImageGallery = ({ images, productName }: ImageGalleryProps) => {
  const [selectedIndex, setSelectedIndex] = useState(0);
  const [isZoomed, setIsZoomed] = useState(false);
  const [zoomPosition, setZoomPosition] = useState({ x: 50, y: 50 });
  const [rotation, setRotation] = useState(0);
  const [is360Mode, setIs360Mode] = useState(false);
  const imageRef = useRef<HTMLDivElement>(null);

  const handleMouseMove = (e: React.MouseEvent<HTMLDivElement>) => {
    if (!isZoomed || !imageRef.current) return;
    
    const rect = imageRef.current.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width) * 100;
    const y = ((e.clientY - rect.top) / rect.height) * 100;
    setZoomPosition({ x, y });
  };

  const handlePrev = () => {
    setSelectedIndex((prev) => (prev === 0 ? images.length - 1 : prev - 1));
    if (is360Mode) {
      setRotation((prev) => prev - 45);
    }
  };

  const handleNext = () => {
    setSelectedIndex((prev) => (prev === images.length - 1 ? 0 : prev + 1));
    if (is360Mode) {
      setRotation((prev) => prev + 45);
    }
  };

  const toggle360Mode = () => {
    setIs360Mode(!is360Mode);
    setRotation(0);
  };

  return (
    <div className="space-y-4">
      {/* Main Image */}
      <div className="relative group">
        <div
          ref={imageRef}
          className={cn(
            "relative aspect-square overflow-hidden rounded-lg bg-muted cursor-crosshair",
            isZoomed && "cursor-zoom-out"
          )}
          onMouseMove={handleMouseMove}
          onMouseEnter={() => !is360Mode && setIsZoomed(true)}
          onMouseLeave={() => setIsZoomed(false)}
          onClick={() => setIsZoomed(!isZoomed)}
        >
          <img
            src={images[selectedIndex]}
            alt={`${productName} - View ${selectedIndex + 1}`}
            className={cn(
              "w-full h-full object-cover transition-transform duration-300",
              is360Mode && `rotate-[${rotation}deg]`
            )}
            style={{
              transform: isZoomed
                ? `scale(2) translate(${50 - zoomPosition.x}%, ${50 - zoomPosition.y}%)`
                : is360Mode
                ? `rotateY(${rotation}deg)`
                : "scale(1)",
              transformOrigin: "center center",
            }}
          />

          {/* Zoom indicator */}
          {!is360Mode && (
            <div className="absolute top-4 right-4 bg-background/80 backdrop-blur-sm rounded-full p-2 opacity-0 group-hover:opacity-100 transition-opacity">
              <ZoomIn className="h-5 w-5" />
            </div>
          )}

          {/* 360 indicator */}
          {is360Mode && (
            <div className="absolute top-4 left-1/2 -translate-x-1/2 bg-accent text-accent-foreground text-sm font-medium px-3 py-1 rounded-full">
              360° View - Drag to rotate
            </div>
          )}
        </div>

        {/* Navigation Arrows */}
        <Button
          variant="secondary"
          size="icon"
          className="absolute left-2 top-1/2 -translate-y-1/2 opacity-0 group-hover:opacity-100 transition-opacity rounded-full shadow-lg"
          onClick={handlePrev}
        >
          <ChevronLeft className="h-5 w-5" />
        </Button>
        <Button
          variant="secondary"
          size="icon"
          className="absolute right-2 top-1/2 -translate-y-1/2 opacity-0 group-hover:opacity-100 transition-opacity rounded-full shadow-lg"
          onClick={handleNext}
        >
          <ChevronRight className="h-5 w-5" />
        </Button>

        {/* Action Buttons */}
        <div className="absolute bottom-4 right-4 flex gap-2">
          <Button
            variant={is360Mode ? "default" : "secondary"}
            size="sm"
            onClick={toggle360Mode}
            className="rounded-full shadow-lg"
          >
            <RotateCcw className="h-4 w-4 mr-1" />
            360°
          </Button>
          
          <Dialog>
            <DialogTrigger asChild>
              <Button variant="secondary" size="sm" className="rounded-full shadow-lg">
                <Expand className="h-4 w-4 mr-1" />
                Full Screen
              </Button>
            </DialogTrigger>
            <DialogContent className="max-w-5xl">
              <div className="aspect-square relative">
                <img
                  src={images[selectedIndex]}
                  alt={productName}
                  className="w-full h-full object-contain"
                />
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>

      {/* Thumbnails */}
      <div className="flex gap-2 overflow-x-auto pb-2">
        {images.map((image, index) => (
          <button
            key={index}
            onClick={() => setSelectedIndex(index)}
            className={cn(
              "shrink-0 w-20 h-20 rounded-md overflow-hidden border-2 transition-all",
              selectedIndex === index
                ? "border-accent ring-2 ring-accent/20"
                : "border-border hover:border-accent/50"
            )}
          >
            <img
              src={image}
              alt={`${productName} thumbnail ${index + 1}`}
              className="w-full h-full object-cover"
            />
          </button>
        ))}
      </div>
    </div>
  );
};

export default ImageGallery;
