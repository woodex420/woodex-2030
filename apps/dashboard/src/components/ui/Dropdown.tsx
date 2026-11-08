import { useEffect, useRef, useState } from "react";
import type { ReactNode } from "react";
import { cn } from "@/lib/cn";

/** Lightweight dropdown with click-outside + Escape handling. */
export function Dropdown({
  trigger,
  children,
  align = "right",
  panelClassName,
}: {
  trigger: (open: boolean) => ReactNode;
  children: ReactNode;
  align?: "left" | "right";
  panelClassName?: string;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  return (
    <div ref={ref} className="relative">
      <div onClick={() => setOpen((o) => !o)}>{trigger(open)}</div>
      {open && (
        <div
          onClick={() => setOpen(false)}
          className={cn(
            "absolute z-50 mt-2 w-max min-w-48 animate-fade-in overflow-hidden rounded-panel border border-line bg-surface shadow-pop",
            align === "right" ? "right-0" : "left-0",
            panelClassName
          )}
        >
          {children}
        </div>
      )}
    </div>
  );
}

export function DropdownItem({
  children,
  onClick,
  tone = "default",
  className,
}: {
  children: ReactNode;
  onClick?: () => void;
  tone?: "default" | "danger";
  className?: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "flex w-full items-center gap-2.5 px-3.5 py-2 text-left text-small transition-colors duration-150",
        tone === "danger"
          ? "text-danger-strong hover:bg-danger-soft"
          : "text-slate-700 hover:bg-slate-50 hover:text-ink",
        className
      )}
    >
      {children}
    </button>
  );
}

export function DropdownLabel({ children }: { children: ReactNode }) {
  return (
    <div className="px-3.5 pt-3 pb-1.5 text-caption font-medium tracking-wide text-subtle uppercase">
      {children}
    </div>
  );
}

export function DropdownDivider() {
  return <div className="my-1 h-px bg-line" />;
}
