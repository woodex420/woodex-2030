// Render real saved PDF bytes without the browser's iframe PDF plugin (blocked in embedded admin views).
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

export function registerDocumentPreview(Alpine) {
    Alpine.data('documentPreview', () => {
        // PDF.js objects must not be wrapped in Alpine's reactive proxies.
        let sequence = 0;
        let controller;
        let loadingTask;
        let pdf;
        let renderTask;
        let blob;
        const release = () => {
            controller?.abort();
            renderTask?.cancel();
            // PDF.js 6 owns the document/worker lifecycle on the loading task, not PDFDocumentProxy.
            loadingTask?.destroy().catch(() => {});
            if (blob) URL.revokeObjectURL(blob);
            controller = loadingTask = pdf = renderTask = blob = null;
        };
        return {
            open: false, url: '', label: 'Document preview', downloadUrl: '',
            loading: false, error: '', status: '', pageCount: 0, downloadName: 'Woodex-document.pdf',
            async show(detail = {}) {
                const id = ++sequence;
                release();
                this.open = true;
                this.label = detail.label || 'Document preview';
                this.error = '';
                this.status = 'Loading PDF…';
                this.loading = true;
                this.pageCount = 0;
                this.downloadUrl = '';
                this.downloadName = 'Woodex-document.pdf';
                this.url = '';
                await this.$nextTick();
                if (id !== sequence) return;
                this.$refs.pages?.replaceChildren();
                try {
                    const target = new URL(detail.url, location.href);
                    if (target.origin !== location.origin || !['http:', 'https:'].includes(target.protocol)) throw Error('Unsupported document URL');
                    this.url = target.href;
                    controller = new AbortController();
                    const response = await fetch(this.url, { credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/pdf' } });
                    if (!response.ok) throw Error('PDF request failed');
                    const filename = (response.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)/i)?.[1];
                    this.downloadName = filename || 'Woodex-document.pdf';
                    const bytes = new Uint8Array(await response.arrayBuffer());
                    if (id !== sequence) return;
                    if (bytes.length > 32 * 1024 * 1024 || String.fromCharCode(...bytes.subarray(0, 5)) !== '%PDF-') throw Error('Not a displayable PDF');
                    blob = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
                    this.downloadUrl = blob;
                    const engine = await import('pdfjs-dist/build/pdf.mjs');
                    if (id !== sequence) return;
                    engine.GlobalWorkerOptions.workerSrc = workerUrl;
                    loadingTask = engine.getDocument({ data: bytes, isEvalSupported: false });
                    pdf = await loadingTask.promise;
                    if (id !== sequence) return;
                    this.pageCount = pdf.numPages;
                    for (let number = 1; number <= pdf.numPages; number++) {
                        if (id !== sequence) return;
                        this.status = `Rendering page ${number} of ${pdf.numPages}…`;
                        const page = await pdf.getPage(number);
                        if (id !== sequence) return;
                        const original = page.getViewport({ scale: 1 });
                        const width = Math.min(900, Math.max(240, this.$refs.pages.clientWidth - 32));
                        const scale = width / original.width;
                        const viewport = page.getViewport({ scale: scale * Math.min(devicePixelRatio || 1, 2) });
                        const group = document.createElement('section');
                        group.className = 'mb-5 flex flex-col items-center gap-2';
                        group.setAttribute('aria-label', `PDF page ${number} of ${pdf.numPages}`);
                        const caption = document.createElement('p');
                        caption.className = 'text-xs text-gray-500';
                        caption.textContent = `Page ${number} of ${pdf.numPages}`;
                        const canvas = document.createElement('canvas');
                        canvas.width = Math.ceil(viewport.width);
                        canvas.height = Math.ceil(viewport.height);
                        canvas.style.cssText = `width:${width}px;max-width:100%;height:auto;display:block;background:white;box-shadow:0 2px 12px #0002`;
                        canvas.setAttribute('role', 'img');
                        canvas.setAttribute('aria-label', `${this.label}, page ${number} of ${pdf.numPages}`);
                        group.append(caption, canvas);
                        this.$refs.pages.append(group);
                        renderTask = page.render({ canvasContext: canvas.getContext('2d'), viewport });
                        await renderTask.promise;
                        page.cleanup();
                    }
                    if (id === sequence) this.status = `${this.pageCount} ${this.pageCount === 1 ? 'page' : 'pages'} ready · actual PDF`;
                } catch (error) {
                    if (id !== sequence || error?.name === 'AbortError' || error?.name === 'RenderingCancelledException') return;
                    this.error = 'The PDF could not be displayed. Retry, or download it. Your session may have expired, or the saved file may be unavailable.';
                    this.status = '';
                } finally {
                    if (id === sequence) this.loading = false;
                }
            },
            close() {
                ++sequence;
                release();
                this.open = false;
                this.loading = false;
                this.downloadUrl = '';
                this.$refs.pages?.replaceChildren();
            },
            destroy() { ++sequence; release(); },
        };
    });
}
