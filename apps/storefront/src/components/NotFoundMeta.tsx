import { useEffect } from "react";
import { title } from "@/config/brand";

/**
 * An address with nothing behind it still answers 200: the shop is one page for every address, and the page
 * finds out in the browser that nothing is there. So the page itself says so, where a search engine and the tab
 * of a browser read it: the title names what was not found, and "noindex" asks that the address is not kept.
 *
 * Rendered by every "not found" view. It draws nothing; it leaves the head as it found it.
 */
export function NotFoundMeta({ what = "Page not found" }: { what?: string }) {
  useEffect(() => {
    const before = document.title;
    document.title = title(what);
    const robots = document.createElement("meta");
    robots.name = "robots";
    robots.content = "noindex";
    robots.setAttribute("data-not-found", "");
    document.head.appendChild(robots);
    return () => {
      robots.remove();
      document.title = before;
    };
  }, [what]);
  return null;
}
