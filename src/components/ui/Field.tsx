import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from "react";
import { cn } from "@/lib/cn";
import { ChevronDown } from "@/icons";

export const inputCls = (error?: boolean) =>
  cn(
    "h-10 w-full rounded-control border bg-white px-3 text-small text-ink transition-colors duration-150 placeholder:text-subtle focus:outline-none focus:ring-2 focus:ring-primary-500/25",
    error
      ? "border-danger focus:border-danger"
      : "border-line-strong hover:border-slate-400 focus:border-primary-500"
  );

export function Field({
  label,
  hint,
  error,
  required,
  className,
  children,
}: {
  label: string;
  hint?: ReactNode;
  error?: string;
  required?: boolean;
  className?: string;
  children: ReactNode;
}) {
  return (
    <label className={cn("block", className)}>
      <span className="mb-1.5 block text-small font-medium text-slate-700">
        {label}
        {required && <span className="ml-0.5 text-danger" aria-hidden>*</span>}
      </span>
      {children}
      {error ? (
        <span className="mt-1 block text-caption text-danger-strong">{error}</span>
      ) : (
        hint && <span className="mt-1 block text-caption text-subtle">{hint}</span>
      )}
    </label>
  );
}

export function Input({ error, className, ...rest }: InputHTMLAttributes<HTMLInputElement> & { error?: boolean }) {
  return <input className={cn(inputCls(error), className)} {...rest} />;
}

export function Textarea({ error, className, ...rest }: TextareaHTMLAttributes<HTMLTextAreaElement> & { error?: boolean }) {
  return <textarea className={cn(inputCls(error), "min-h-[76px] py-2 leading-relaxed", className)} {...rest} />;
}

export function Select({
  error,
  className,
  children,
  ...rest
}: SelectHTMLAttributes<HTMLSelectElement> & { error?: boolean }) {
  return (
    <span className="relative block">
      <select
        className={cn(inputCls(error), "appearance-none pr-9", className)}
        {...rest}
      >
        {children}
      </select>
      <ChevronDown
        size={15}
        className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-subtle"
      />
    </span>
  );
}

export function SearchInput({
  value,
  onChange,
  placeholder = "Search…",
  className,
}: {
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  className?: string;
}) {
  return (
    <span className={cn("relative block", className)}>
      <svg
        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-subtle"
        width="15"
        height="15"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        aria-hidden
      >
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-3.8-3.8" />
      </svg>
      <input
        type="search"
        role="searchbox"
        aria-label={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className={cn(inputCls(), "pl-9")}
      />
    </span>
  );
}
