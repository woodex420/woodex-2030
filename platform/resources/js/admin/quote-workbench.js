// The server's canonical calculator and PDF blade render current inputs without saving them.
// Save-on-blur stays independent; never substitute a browser-computed financial total.
export function registerQuoteWorkbench(Alpine) {
    Alpine.data('quoteWorkbench', ({url, csrf}) => ({
        previewStatus: 'Preview ready · saves when you leave a field',
        timer: null,
        revision: 0,
        observer: null,
        init() {
            this.observer = new ResizeObserver(() => this.fitSheet());
            this.$nextTick(() => { if (this.$refs.sheet) this.observer.observe(this.$refs.sheet); });
        },
        destroy() { clearTimeout(this.timer); this.observer?.disconnect(); this.revision++; },
        fitSheet() {
            const sheet = this.$refs.sheet;
            const doc = sheet?.contentDocument;
            if (!doc?.body) return;
            // Keep the real A4 geometry, fit it to the pane instead of reflowing its tables.
            doc.documentElement.style.zoom = String(Math.min(1, Math.max(.2, (sheet.clientWidth - 20) / 794)));
        },
        formState() {
            const form = {};
            this.$root.querySelectorAll('input, textarea, select').forEach((el) => {
                const attr = [...el.attributes].find((a) => a.name.startsWith('wire:model'));
                if (!attr) return;
                const path = attr.value.split('.');
                if (!['lines', 'sections', 'details', 'client', 'milestones', 'pdfTheme', 'quoteLayout'].includes(path[0])) return;
                if (['internal_notes', 'internal_cost'].includes(path.at(-1))) return;
                let target = form;
                path.slice(0, -1).forEach((key) => { target = target[key] ??= {}; });
                target[path.at(-1)] = el.type === 'checkbox' ? el.checked : el.value;
            });
            return form;
        },
        queuePreview(event) {
            if (event && ![...event.target.attributes].some((a) => a.name.startsWith('wire:model'))) return;
            const revision = ++this.revision;
            clearTimeout(this.timer);
            this.previewStatus = 'Updating preview…';
            this.timer = setTimeout(async () => {
                try {
                    const response = await fetch(url, {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                        body: JSON.stringify(this.formState()),
                    });
                    if (!response.ok && response.status !== 422) throw new Error('Preview request failed');
                    const result = await response.json();
                    if (revision !== this.revision) return;
                    if (result.error) { this.previewStatus = result.error; return; }
                    const sheet = this.$refs.sheet;
                    const y = sheet.contentWindow?.scrollY || 0;
                    sheet.onload = () => { this.fitSheet(); try { sheet.contentWindow?.scrollTo(0, y); } catch { /* iframe navigating */ } };
                    sheet.srcdoc = result.html;
                    this.previewStatus = 'Live editing preview · PDF uses saved values';
                } catch {
                    if (revision === this.revision) this.previewStatus = 'Preview unavailable · check your connection and edit again to retry';
                }
            }, 350);
        },
    }));
}
