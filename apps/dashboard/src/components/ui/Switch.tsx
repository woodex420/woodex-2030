import { cn } from "@/lib/cn";

export function Switch({
  checked,
  onChange,
  label,
  className,
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
  label: string;
  className?: string;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      onClick={() => onChange(!checked)}
      className={cn(
        "relative h-6 w-11 shrink-0 rounded-full border transition-colors duration-150",
        checked
          ? "border-primary-600 bg-primary-500"
          : "border-line-strong bg-slate-100 hover:bg-slate-200",
        className
      )}
    >
      <span
        className={cn(
          "absolute top-1/2 h-4.5 w-4.5 -translate-y-1/2 rounded-full bg-white shadow-sm transition-[left] duration-200",
          checked ? "left-[24px]" : "left-[3px]"
        )}
      />
    </button>
  );
}
