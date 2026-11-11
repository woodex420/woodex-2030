// Shared with the pre-paint resolver in layouts/admin. Existing wx.theme values remain valid.
export function readStorage(key, fallback = null) {
    try { return window.localStorage.getItem(key) ?? fallback; } catch { return fallback; }
}
export function writeStorage(key, value) {
    try { window.localStorage.setItem(key, value); } catch { /* Session-only when storage is blocked. */ }
}
export function themeMode(value) {
    return ['light', 'dark', 'system'].includes(value) ? value : 'system';
}
export function resolvedDark(mode, systemDark) {
    return mode === 'dark' || (mode === 'system' && systemDark);
}
export function themeColor(dark) { return dark ? '#171717' : '#f6f6f6'; }
export function createThemeStore() {
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    return {
        mode: themeMode(readStorage('wx.theme')),
        dark: document.documentElement.classList.contains('dark'),
        init() {
            this.apply();
            media.addEventListener('change', () => { if (this.mode === 'system') this.apply(); });
            window.addEventListener('storage', (event) => {
                if (event.key === 'wx.theme' || event.key === null) {
                    this.mode = themeMode(event.newValue);
                    this.apply();
                }
            });
        },
        setMode(mode) {
            this.mode = themeMode(mode);
            writeStorage('wx.theme', this.mode);
            this.apply();
        },
        toggle() { this.setMode(this.dark ? 'light' : 'dark'); },
        apply() {
            this.dark = resolvedDark(this.mode, media.matches);
            document.documentElement.classList.toggle('dark', this.dark);
            document.documentElement.dataset.themeMode = this.mode;
            document.documentElement.style.colorScheme = this.dark ? 'dark' : 'light';
            document.querySelector('meta[name="theme-color"]')?.setAttribute('content', themeColor(this.dark));
            window.dispatchEvent(new CustomEvent('wx-theme-changed', { detail: { dark: this.dark, mode: this.mode } }));
        },
    };
}
