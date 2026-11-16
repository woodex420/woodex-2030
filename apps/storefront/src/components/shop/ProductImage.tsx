import { useEffect, useState } from "react";
import type { CSSProperties } from "react";
import { cn } from "@/lib/utils";

/**
 * A product photograph that is allowed to fail.
 *
 * The catalogue names its photographs by address, and an address can stop
 * answering: a file removed from the server it lives on, a product loaded
 * without a picture. The browser's answer to that is a broken-image icon with
 * the alt text spilling out of it. This draws the product's initial in the
 * same box instead, and says in words that there is no photograph.
 *
 * `className` goes on the image and on the box that replaces it, so the layout
 * is the same either way.
 */
interface ProductImageProps {
  src?: string | null;
  /** The product's name: the alt text, and the initial shown when there is no picture. */
  name: string;
  alt?: string;
  className?: string;
  /** Size of the initial; the default suits a card. */
  initialClassName?: string;
  loading?: "lazy" | "eager";
  style?: CSSProperties;
}

export const ProductImage = ({ src, name, alt, className, initialClassName, loading = "lazy", style }: ProductImageProps) => {
  const [failed, setFailed] = useState(false);
  /* Another address is another chance: a gallery reuses this element. */
  useEffect(() => { setFailed(false); }, [src]);

  if (!src || failed) {
    return (
      <div role="img" aria-label={`${name}: no photograph`} className={cn("flex items-center justify-center bg-muted", className)}>
        <span aria-hidden="true" className={cn("text-4xl font-bold text-muted-foreground/40", initialClassName)}>
          {name.trim().charAt(0).toUpperCase()}
        </span>
      </div>
    );
  }
  return <img src={src} alt={alt ?? name} loading={loading} className={className} style={style} onError={() => setFailed(true)} />;
};

export default ProductImage;
