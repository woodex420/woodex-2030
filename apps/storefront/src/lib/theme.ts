import { useSyncExternalStore } from "react";

/* P6 Theme Engine — site-wide design tokens driven by GET /api/theme.
   Tailwind maps colors to hsl(var(--x)) and radius to var(--radius), so we
   inject ONE unlayered <style id="wx-theme"> with :root + .dark overrides.
   Unlayered beats @layer base, and .dark entries override the stock dark block. */

export type SiteTheme = {
  brand: string;
  darkBrand: string | null;
  radius: number;
  font: "sans" | "serif";
  mode: "light" | "dark" | "auto";
  tintNav: boolean;
  announce: { text: string; href: string | null } | null;
};

export const THEME_DEFAULTS: SiteTheme = {
  brand: "#16A34A", darkBrand: null, radius: 4, font: "sans", mode: "light", tintNav: false, announce: null,
};

/* ---- color math on HSL triplets (the token format) ---- */
function hexToHsl(hex: string): [number, number, number] {
  const m = /^#?([0-9a-f]{6})$/i.exec(String(hex).trim());
  if (!m) return [142, 71, 45];
  const v = parseInt(m[1], 16);
  const r = ((v >> 16) & 255) / 255, g = ((v >> 8) & 255) / 255, b = (v & 255) / 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
  const l = (max + min) / 2;
  const s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
  let h = 0;
  if (d !== 0) {
    if (max === r) h = ((g - b) / d) % 6;
    else if (max === g) h = (b - r) / d + 2;
    else h = (r - g) / d + 4;
    h = (h * 60 + 360) % 360;
  }
  return [Math.round(h), Math.round(s * 100), Math.round(l * 100)];
}
const tri = (h: number, s: number, l: number) => `${Math.round(h)} ${Math.max(0, Math.min(100, Math.round(s)))}% ${Math.max(0, Math.min(100, Math.round(l)))}%`;

export function tokenCss(t: SiteTheme): string {
  const [h, s, l] = hexToHsl(t.brand);
  const dark = t.darkBrand ? hexToHsl(t.darkBrand) : [h, Math.min(s, 68), Math.max(40, Math.min(58, l + 6))] as [number, number, number];
  const root = [
    `--accent: ${tri(h, s, l)}`,
    `--accent-foreground: 0 0% 100%`,
    `--ring: ${tri(h, s, l)}`,
    `--hon-green: ${tri(h, s, l)}`,
    `--hon-green-light: ${tri(h, s, l + 14)}`,
    `--hon-green-dark: ${tri(h, s, l - 10)}`,
    `--hon-green-pale: ${tri(h, Math.min(s, 42), 95)}`,
    `--badge-success: ${tri(h, s, l)}`,
    `--badge-success-foreground: 0 0% 100%`,
    `--section-light: ${tri(h, Math.min(s, 30), 97.5)}`,
    t.radius !== 4 ? `--radius: ${t.radius}px` : "",
    t.tintNav ? `--primary: ${tri(h, Math.min(s + 6, 90), Math.max(14, l - 16))}` : "",
    t.tintNav ? `--primary-foreground: 0 0% 100%` : "",
    t.tintNav ? `--utility-bar: ${tri(h, Math.min(s + 6, 90), Math.max(10, l - 22))}` : "",
  ].filter(Boolean).join(";\n  ");
  const darkBlock = [
    `--accent: ${tri(...dark)}`,
    `--ring: ${tri(...dark)}`,
    `--hon-green: ${tri(...dark)}`,
    `--hon-green-light: ${tri(dark[0], dark[1], Math.min(80, dark[2] + 14))}`,
    `--hon-green-dark: ${tri(dark[0], dark[1], Math.max(18, dark[2] - 14))}`,
    `--hon-green-pale: ${tri(dark[0], 24, 16)}`,
    `--badge-success: ${tri(...dark)}`,
    t.tintNav ? `--primary: ${tri(dark[0], dark[1], 20)}` : "",
    t.tintNav ? `--primary-foreground: 0 0% 96%` : "",
    t.tintNav ? `--utility-bar: ${tri(dark[0], dark[1], 12)}` : "",
  ].filter(Boolean).join(";\n  ");
  return [
    `:root {\n  ${root}\n}`,
    `.dark {\n  ${darkBlock}\n}`,
    t.font === "serif" ? `html.wx-serif body, html.wx-serif h1, html.wx-serif h2, html.wx-serif h3, html.wx-serif h4, html.wx-serif h5, html.wx-serif h6, html.wx-serif button { font-family: Georgia, 'Times New Roman', serif !important; }` : "",
    `html.wx-pagebar #wx-sitebar { display: none !important; }`,
  ].filter(Boolean).join("\n");
}

function applyTheme(t: SiteTheme) {
  let el = document.getElementById("wx-theme") as HTMLStyleElement | null;
  if (!el) { el = document.createElement("style"); el.id = "wx-theme"; document.head.appendChild(el); }
  el.textContent = tokenCss(t);
  document.documentElement.classList.toggle("wx-serif", t.font === "serif");
}

/* ---- store ---- */
const listeners = new Set<() => void>();
const notify = () => listeners.forEach((f) => f());
const mq = typeof window !== "undefined" ? window.matchMedia?.("(prefers-color-scheme: dark)") : undefined;

let theme: SiteTheme = THEME_DEFAULTS;
let override: "light" | "dark" | null = (() => {
  try { const v = localStorage.getItem("wx-mode"); return v === "dark" || v === "light" ? v : null; } catch { return null; }
})();
let resolved: "light" | "dark" = "light";
let snap = { theme, mode: theme.mode, override, resolved, booted: false };
const publish = () => {
  resolved = theme.mode === "auto" ? (override ?? (mq?.matches ? "dark" : "light")) : (override ?? theme.mode);
  document.documentElement.classList.toggle("dark", resolved === "dark");
  snap = { theme, mode: theme.mode, override, resolved, booted: true };
  notify();
};
function setTheme(t: SiteTheme) { theme = t; applyTheme(t); publish(); }

export function bootTheme() {
  try { const c = localStorage.getItem("wx-theme-json"); if (c) applyTheme({ ...THEME_DEFAULTS, ...JSON.parse(c) }); } catch { /* fresh visitor */ }
  if (mq?.addEventListener) mq.addEventListener("change", () => { if (theme.mode === "auto" && !override) publish(); });
  void refreshTheme();
}
export async function refreshTheme() {
  try {
    const res = await fetch("/api/theme");
    if (!res.ok) return;
    setTheme({ ...THEME_DEFAULTS, ...(await res.json()) });
    try { localStorage.setItem("wx-theme-json", JSON.stringify(theme)); } catch { /* private mode */ }
  } catch { /* offline — cache keeps serving */ }
}
export function setModeOverride(m: "light" | "dark" | null) {
  override = m;
  try { m ? localStorage.setItem("wx-mode", m) : localStorage.removeItem("wx-mode"); } catch { /* noop */ }
  publish();
}

export function useSiteTheme() {
  return useSyncExternalStore(
    (cb) => { listeners.add(cb); return () => listeners.delete(cb); },
    () => snap,
    () => snap
  );
}
