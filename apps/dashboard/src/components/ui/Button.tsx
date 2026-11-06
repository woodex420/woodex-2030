import type { ButtonHTMLAttributes } from "react";
import { cn } from "@/lib/cn";

export type ButtonVariant = "primary" | "secondary" | "ghost" | "danger" | "subtle";
export type ButtonSize = "sm" | "md" | "lg";

const base =
  "inline-flex items-center justify-center gap-2 rounded-control font-medium transition-[background-color,color,border-color,box-shadow,transform] duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 select-none active:translate-y-px disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transition-none";

const variants: Record<ButtonVariant, string> = {
  primary:
    "bg-primary-500 text-white shadow-cta hover:bg-primary-600 hover:shadow-md active:bg-primary-700 active:shadow-none",
  secondary:
    "border border-line-strong bg-white text-slate-700 hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 active:bg-primary-100",
  ghost: "text-slate-600 hover:bg-slate-100 hover:text-ink active:bg-slate-200",
  danger:
    "bg-danger text-white hover:bg-danger-strong focus-visible:ring-danger active:bg-danger-strong",
  subtle: "bg-primary-50 text-primary-700 hover:bg-primary-100 active:bg-primary-200",
};

const sizes: Record<ButtonSize, string> = {
  sm: "h-8 px-3 text-caption",
  md: "h-10 px-4 text-small",
  lg: "h-11 px-5 text-bodylg",
};

export function buttonCls(variant: ButtonVariant = "primary", size: ButtonSize = "md", className?: string) {
  return cn(base, variants[variant], sizes[size], className);
}

export function Button({
  variant = "primary",
  size = "md",
  className,
  loading = false,
  disabled,
  children,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
}) {
  return (
    <button
      className={buttonCls(variant, size, className)}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...rest}
    >
      {loading && (
        <span
          aria-hidden="true"
          className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current/35 border-t-current"
        />
      )}
      {children}
    </button>
  );
}

export function IconButton({
  label,
  className,
  children,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { label: string }) {
  return (
    <button
      aria-label={label}
      title={label}
      className={cn(
        "relative grid h-9 w-9 place-items-center rounded-control text-slate-600 transition-colors duration-150 hover:bg-slate-100 hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 active:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transition-none",
        className
      )}
      {...rest}
    >
      {children}
    </button>
  );
}
