/*
 * Woodex public website bundle (phase P6). Public pages do not load Livewire, so this bundle
 * owns Alpine. Forms post to the stateless JSON API (cache-friendly, docs/07 D-13).
 */
import Alpine from 'alpinejs';

Alpine.data('leadForm', (options = {}) => ({
    endpoint: options.endpoint ?? '/api/public/leads',
    startedAt: Math.floor(Date.now() / 1000),
    sending: false,
    done: false,
    error: null,
    reference: null,
    whatsappUrl: null,
    errors: {},

    async submit(event) {
        this.sending = true;
        this.error = null;
        this.errors = {};

        const data = Object.fromEntries(new FormData(event.target).entries());
        const params = new URLSearchParams(window.location.search);
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid'].forEach((key) => {
            if (params.get(key)) data[key] = params.get(key);
        });
        data.page_url = window.location.href;
        data.referrer = document.referrer || null;
        data.form_started_at = this.startedAt;
        data.consent = data.consent === 'on';

        try {
            const response = await fetch(this.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(data),
            });
            const body = await response.json().catch(() => ({}));

            if (response.ok) {
                this.done = true;
                this.reference = body.reference;
                this.whatsappUrl = body.whatsapp_url;
            } else if (response.status === 422) {
                this.errors = body.errors ?? {};
                this.error = body.message ?? 'Please check the highlighted fields.';
            } else if (response.status === 429) {
                this.error = 'Too many attempts. Please wait a minute and try again.';
            } else {
                this.error = 'Something went wrong. Please call or WhatsApp us instead.';
            }
        } catch (e) {
            this.error = 'You appear to be offline. Please try again.';
        } finally {
            this.sending = false;
        }
    },
}));

window.Alpine = Alpine;
Alpine.start();
