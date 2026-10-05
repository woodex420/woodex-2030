import { createContext, useCallback, useContext, useState } from "react";
import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import type { Tone } from "@/data/mock";
import { chipTone } from "@/components/ui/Badge";
import { CheckCircle, AlertTriangle, Sparkles, X } from "@/icons";

export type ToastInput = { tone?: Tone; title: string; desc?: ReactNode };
type ToastMsg = ToastInput & { id: number };

const ToastCtx = createContext<{ push: (t: ToastInput) => void }>({ push: () => {} });

export function useToast() {
  return useContext(ToastCtx);
}

const toneIcon: Record<Tone, { icon: ReactNode; color: string }> = {
  success: { icon: <CheckCircle size={16} />, color: "text-success-strong" },
  primary: { icon: <CheckCircle size={16} />, color: "text-primary-600" },
  info: { icon: <Sparkles size={16} />, color: "text-info-strong" },
  warning: { icon: <AlertTriangle size={16} />, color: "text-warning-strong" },
  danger: { icon: <AlertTriangle size={16} />, color: "text-danger-strong" },
  neutral: { icon: <Sparkles size={16} />, color: "text-slate-600" },
  muted: { icon: <Sparkles size={16} />, color: "text-slate-600" },
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastMsg[]>([]);

  const push = useCallback((t: ToastInput) => {
    const id = Date.now() + Math.random();
    setItems((x) => [...x, { ...t, id }]);
    window.setTimeout(() => setItems((x) => x.filter((i) => i.id !== id)), 3600);
  }, []);

  const dismiss = (id: number) => setItems((x) => x.filter((i) => i.id !== id));

  return (
    <ToastCtx.Provider value={{ push }}>
      {children}
      <div
        aria-live="polite"
        className="pointer-events-none fixed right-4 bottom-4 z-[100] flex w-[min(92vw,360px)] flex-col gap-2"
      >
        {items.map((t) => {
          const tone = t.tone ?? "success";
          return (
            <div
              key={t.id}
              role="status"
              className="pointer-events-auto flex animate-toast-in items-start gap-3 rounded-card border border-line bg-surface p-3.5 shadow-pop"
            >
              <span
                className={cn(
                  "mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full",
                  chipTone[tone],
                  toneIcon[tone].color
                )}
              >
                {toneIcon[tone].icon}
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-small font-semibold text-ink">{t.title}</p>
                {t.desc && <p className="mt-0.5 text-caption text-muted">{t.desc}</p>}
              </div>
              <button
                aria-label="Dismiss notification"
                onClick={() => dismiss(t.id)}
                className="text-subtle transition-colors hover:text-ink"
              >
                <X size={14} />
              </button>
            </div>
          );
        })}
      </div>
    </ToastCtx.Provider>
  );
}
